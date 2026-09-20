<?php

header('Content-Type: application/json');


// ---------------------------------------------------------
// CONFIGURATION
// ---------------------------------------------------------

$YT_DLP = '/data/data/com.termux/files/usr/bin/yt-dlp';
$FFMPEG = '/data/data/com.termux/files/usr/bin/ffmpeg';

// Default folder used when the user leaves "Save folder" blank.
$DOWNLOAD_DIR = getenv('HOME') . '/storage/downloads/mapiano';

// The full accessible storage area on the phone (created by
// `termux-setup-storage`). Any folder the user types in the UI
// is resolved relative to this, so downloads aren't stuck in
// one fixed folder.
$STORAGE_ROOT = getenv('HOME') . '/storage/shared';
if (!is_dir($STORAGE_ROOT)) {
    // termux-setup-storage hasn't been run - fall back to the
    // one folder we know we can write to.
    $STORAGE_ROOT = $DOWNLOAD_DIR;
}

$JOBS_DIR = $DOWNLOAD_DIR . '/.jobs';

// Optional: if you export your browser's YouTube (or other
// site) cookies to this exact file, downloads that need a
// logged-in session (age-restricted, "confirm you're not a
// bot", etc.) will use it automatically. Safe to leave absent.
$COOKIES_FILE = $STORAGE_ROOT . '/cookies.txt';

error_reporting(E_ALL);
ini_set('display_errors', '1');

foreach ([$DOWNLOAD_DIR, $JOBS_DIR] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        echo json_encode([
            'success' => false,
            'error' => 'Cannot create directory: ' . $dir
        ]);
        exit;
    }
}


// ---------------------------------------------------------
// HELPERS
// ---------------------------------------------------------

function response($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function getInput()
{
    $raw = file_get_contents('php://input');

    if (!$raw) {
        return $_POST;
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function validUrl($url)
{
    return filter_var($url, FILTER_VALIDATE_URL);
}

function runCommand($command)
{
    $output = [];
    $returnCode = 0;

    exec($command . ' 2>&1', $output, $returnCode);

    return [
        'code' => $returnCode,
        'output' => implode("\n", $output)
    ];
}

/**
 * Resolves a user-typed folder (e.g. "Movies/Anime") to a real,
 * safe path under $storageRoot. Strips a redundant leading
 * "storage/shared" in case someone pastes the full relative
 * path by mistake. Blocks '..' traversal and any attempt to
 * escape the storage sandbox. Falls back to $fallbackDir if the
 * input is empty or turns out unsafe.
 */
function resolveOutputDir($requested, $storageRoot, $fallbackDir)
{
    $requested = trim(str_replace('\\', '/', (string) $requested));
    $requested = ltrim($requested, '/');
    $requested = preg_replace('#^storage/shared/#', '', $requested);

    if ($requested === '') {
        return $fallbackDir;
    }

    $parts = explode('/', $requested);
    $safeParts = array_filter($parts, function ($p) {
        return $p !== '' && $p !== '.' && $p !== '..';
    });

    $safeRelative = implode('/', $safeParts);

    if ($safeRelative === '') {
        return $fallbackDir;
    }

    $candidate = rtrim($storageRoot, '/') . '/' . $safeRelative;

    if (!is_dir($candidate)) {
        @mkdir($candidate, 0755, true);
    }

    $real = realpath($candidate);
    $realRoot = realpath($storageRoot);

    if ($real === false || $realRoot === false || strpos($real, $realRoot) !== 0) {
        return $fallbackDir;
    }

    return $candidate;
}

/**
 * Builds the yt-dlp download command. %URL% is substituted by
 * the caller after escapeshellarg-ing it, so the same template
 * can be reused for logging/debugging without leaking the URL
 * into shell-building logic twice.
 *
 * player_client=android,ios,tv works around the "HTTP Error
 * 403: Forbidden" YouTube throws on some formats when yt-dlp
 * has no JS runtime to solve the signature challenge - trying
 * several clients in one go covers more cases than any single
 * one. It's a no-op on non-YouTube sites.
 */
/**
 * Builds the yt-dlp download command as a primary attempt plus
 * an automatic fallback, chained with shell `||`.
 *
 * The primary attempt uses yt-dlp's default client - the same
 * one 'info' used to list formats - so the exact format_id the
 * user picked is guaranteed to exist. Only if that fails does
 * it retry with player_client=android,ios,tv (which works
 * around YouTube's 403 on some formats, but exposes a
 * different set of format IDs, so it uses a generic
 * best-quality selector instead of the original numeric ID).
 */
function buildDownloadCommand($YT_DLP, $outputDir, $type, $format, $audioQuality, $cookiesFile = null)
{
    $outputTemplate = $outputDir . '/%(title)s.%(ext)s';

    $cookiesArg = '';
    if ($cookiesFile && is_readable($cookiesFile)) {
        $cookiesArg = ' --cookies ' . escapeshellarg($cookiesFile);
    }

    $fallbackClientArg = ' --extractor-args ' . escapeshellarg('youtube:player_client=android,ios,tv');

    if ($type === 'audio') {

        $quality = preg_replace('/[^0-9]/', '', $audioQuality);
        if (!$quality) {
            $quality = '192';
        }

        $common =
            ' --no-playlist --newline' .
            $cookiesArg .
            ' -x --audio-format mp3' .
            ' --audio-quality ' . escapeshellarg($quality . 'K') .
            ' --restrict-filenames' .
            ' -o ' . escapeshellarg($outputTemplate);

        $primary = escapeshellcmd($YT_DLP) . $common . ' -f ' . escapeshellarg($format) . ' %URL%';
        $fallback = escapeshellcmd($YT_DLP) . $common . $fallbackClientArg . ' -f ' . escapeshellarg('bestaudio') . ' %URL%';

        return "($primary) || ($fallback)";
    }

    $common =
        ' --no-playlist --newline' .
        $cookiesArg .
        ' --merge-output-format mp4' .
        ' --restrict-filenames' .
        ' -o ' . escapeshellarg($outputTemplate);

    $primary = escapeshellcmd($YT_DLP) . $common . ' -f ' . escapeshellarg($format) . ' %URL%';
    $fallback = escapeshellcmd($YT_DLP) . $common . $fallbackClientArg . ' -f ' . escapeshellarg('bestvideo+bestaudio/best') . ' %URL%';

    return "($primary) || ($fallback)";
}

function isPidRunning($pid)
{
    if (!$pid) {
        return false;
    }

    exec('kill -0 ' . intval($pid) . ' > /dev/null 2>&1', $out, $code);

    return $code === 0;
}

/**
 * Parses a yt-dlp --newline job log for progress and outcome.
 * stage is one of: downloading | processing | done | error.
 */
function parseJobLog($logContents)
{
    $percent = null;
    $speed = null;
    $eta = null;
    $stage = 'downloading';

    if (preg_match_all(
        '/\[download\]\s+([\d.]+)%(?:\s+of[^\n]*?at\s+(\S+))?(?:.*?ETA\s+(\S+))?/',
        $logContents,
        $matches,
        PREG_SET_ORDER
    )) {
        $last = end($matches);
        if ($last) {
            $percent = (float) $last[1];
            $speed = $last[2] ?? null;
            $eta = $last[3] ?? null;
        }
    }

    if (strpos($logContents, '[Merger]') !== false || strpos($logContents, '[ExtractAudio]') !== false) {
        $stage = 'processing';
    }

    $friendlyError = null;

    if (preg_match('/JOB_EXIT_CODE:(-?\d+)/', $logContents, $m)) {
        $exitCode = (int) $m[1];

        if ($exitCode === 0) {
            $stage = 'done';
        } else {
            $stage = 'error';

            if (stripos($logContents, 'Requested format is not available') !== false) {
                $friendlyError = 'That format is no longer available for this video.';
            } elseif (stripos($logContents, '403') !== false) {
                $friendlyError = 'The site blocked the download (403 Forbidden).';
            } elseif (stripos($logContents, 'Sign in') !== false || stripos($logContents, 'login') !== false) {
                $friendlyError = 'This video needs a logged-in session to download.';
            } elseif (stripos($logContents, 'Private video') !== false) {
                $friendlyError = 'This video is private.';
            } else {
                $friendlyError = 'Download failed. See log below.';
            }
        }
    }

    return [
        'percent' => $percent,
        'speed' => $speed,
        'eta' => $eta,
        'stage' => $stage,
        'error' => $friendlyError
    ];
}


// ---------------------------------------------------------
// INPUT
// ---------------------------------------------------------

$data = getInput();
$action = $data['action'] ?? '';


// ---------------------------------------------------------
// CHECK YT-DLP / FFMPEG
// ---------------------------------------------------------

if ($action === 'check') {

    $result = runCommand(escapeshellcmd($YT_DLP) . ' --version');
    $ffmpegResult = runCommand(escapeshellcmd($FFMPEG) . ' -version');

    response([
        'success' => $result['code'] === 0,
        'yt_dlp' => trim($result['output']),
        'ffmpeg' => $ffmpegResult['code'] === 0,
        'cookies_file_found' => is_readable($COOKIES_FILE),
        'cookies_file_path' => $COOKIES_FILE
    ]);
}


// ---------------------------------------------------------
// GET VIDEO / PLAYLIST / MIX INFORMATION
// ---------------------------------------------------------

if ($action === 'info') {

    $url = trim($data['url'] ?? '');

    if (!$url || !validUrl($url)) {
        response(['success' => false, 'error' => 'Please enter a valid URL.'], 400);
    }

    // Step 1: quick flat probe to see if this is a playlist/mix
    // before doing the slow, full per-format lookup.
    $cookiesArg = '';
    if ($COOKIES_FILE && is_readable($COOKIES_FILE)) {
        $cookiesArg = ' --cookies ' . escapeshellarg($COOKIES_FILE);
    }

    $flatCommand =
        escapeshellcmd($YT_DLP) .
        ' --flat-playlist --dump-single-json --skip-download --no-warnings' .
        $cookiesArg . ' ' .
        escapeshellarg($url);

    $flatResult = runCommand($flatCommand);

    if ($flatResult['code'] !== 0) {

        // YouTube Mixes/Radio (list=RD...) can't be browsed as a
        // standalone playlist page - "This playlist type is
        // unviewable" is YouTube's own message for that. If the
        // URL carries a v= parameter, fall through and treat it
        // as a normal single-video lookup for that seed video
        // instead of hard-failing.
        $isUnviewableMix = stripos($flatResult['output'], 'unviewable') !== false;

        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $queryParams);
        $seedVideoId = $queryParams['v'] ?? null;

        if ($isUnviewableMix && $seedVideoId) {
            $url = 'https://www.youtube.com/watch?v=' . $seedVideoId;
            // fall through to the single-video lookup below
        } else {
            $message = $isUnviewableMix
                ? 'This is a YouTube Mix/Radio link that can\'t be listed. Try copying the link again from the "Start radio" share option, or paste the individual video link instead.'
                : $flatResult['output'];

            response(['success' => false, 'error' => $message], 500);
        }

    } else {

        $flatInfo = json_decode($flatResult['output'], true);

        if (!$flatInfo) {
            response(['success' => false, 'error' => 'Could not decode yt-dlp information.'], 500);
        }

        $entries = $flatInfo['entries'] ?? null;

        if (is_array($entries) && count($entries) > 1) {

            $items = [];

            foreach ($entries as $entry) {

                $vid = $entry['id'] ?? '';
                $entryUrl = $entry['url'] ?? '';

                if (!$entryUrl && $vid) {
                    $entryUrl = 'https://www.youtube.com/watch?v=' . $vid;
                }

                if (!$entryUrl) {
                    continue;
                }

                $thumb = '';
                if (!empty($entry['thumbnails']) && is_array($entry['thumbnails'])) {
                    $last = end($entry['thumbnails']);
                    $thumb = $last['url'] ?? '';
                } elseif (!empty($entry['thumbnail'])) {
                    $thumb = $entry['thumbnail'];
                }

                $items[] = [
                    'id' => $vid,
                    'title' => $entry['title'] ?? 'Unknown title',
                    'url' => $entryUrl,
                    'duration' => $entry['duration'] ?? null,
                    'thumbnail' => $thumb
                ];
            }

            response([
                'success' => true,
                'type' => 'playlist',
                'title' => $flatInfo['title'] ?? 'Playlist',
                'count' => count($items),
                'items' => $items
            ]);
        }

    }

    // Step 2: single video/post - full lookup with real
    // per-format details.
    $command =
        escapeshellcmd($YT_DLP) .
        ' --dump-single-json --no-playlist --skip-download --no-warnings' .
        $cookiesArg . ' ' .
        escapeshellarg($url);

    $result = runCommand($command);

    if ($result['code'] !== 0) {
        response(['success' => false, 'error' => $result['output']], 500);
    }

    $info = json_decode($result['output'], true);

    if (!$info) {
        response(['success' => false, 'error' => 'Could not decode yt-dlp information.'], 500);
    }

    $formats = $info['formats'] ?? [];
    $audio = [];
    $video = [];

    foreach ($formats as $format) {

        $formatId = $format['format_id'] ?? '';
        $ext = strtolower($format['ext'] ?? '');
        $vcodec = $format['vcodec'] ?? 'none';
        $acodec = $format['acodec'] ?? 'none';
        $height = $format['height'] ?? null;
        $width = $format['width'] ?? null;
        $fps = $format['fps'] ?? null;
        $tbr = $format['tbr'] ?? null;
        $abr = $format['abr'] ?? null;
        $filesize = $format['filesize'] ?? $format['filesize_approx'] ?? null;
        $formatNote = $format['format_note'] ?? '';
        $resolution = $format['resolution'] ?? '';

        if ($vcodec === 'none' && $acodec !== 'none') {
            $audio[] = [
                'id' => $formatId,
                'ext' => $ext,
                'abr' => $abr,
                'tbr' => $tbr,
                'size' => $filesize,
                'codec' => $acodec,
                'note' => $formatNote
            ];
        }

        if ($vcodec !== 'none' && $height && $ext === 'mp4') {
            $video[] = [
                'id' => $formatId,
                'ext' => $ext,
                'width' => $width,
                'height' => $height,
                'fps' => $fps,
                'tbr' => $tbr,
                'size' => $filesize,
                'codec' => $vcodec,
                'audio' => $acodec !== 'none',
                'note' => $formatNote,
                'resolution' => $resolution
            ];
        }
    }

    usort($audio, fn($a, $b) => ($b['abr'] ?? 0) <=> ($a['abr'] ?? 0));
    usort($video, fn($a, $b) => ($b['height'] ?? 0) <=> ($a['height'] ?? 0));

    $dedupe = function ($list) {
        return array_values(array_reduce($list, function ($carry, $item) {
            $carry[$item['id']] = $item;
            return $carry;
        }, []));
    };

    $audio = $dedupe($audio);
    $video = $dedupe($video);

    response([
        'success' => true,
        'type' => 'video',
        'no_formats' => (count($audio) === 0 && count($video) === 0),
        'title' => $info['title'] ?? 'Unknown title',
        'uploader' => $info['uploader'] ?? '',
        'duration' => $info['duration'] ?? null,
        'thumbnail' => $info['thumbnail'] ?? '',
        'webpage_url' => $info['webpage_url'] ?? $url,
        'audio' => $audio,
        'video' => $video
    ]);
}


// ---------------------------------------------------------
// START A BACKGROUND DOWNLOAD JOB
//
// The yt-dlp process is detached with setsid so it keeps
// running on the server even if the browser tab or the
// connection is closed. The client polls 'job_status' with
// the returned job_id to watch progress and find out when
// it's done.
// ---------------------------------------------------------

if ($action === 'start_download') {

    $url = trim($data['url'] ?? '');
    $type = $data['type'] ?? '';
    $format = trim($data['format'] ?? '');
    $audioQuality = $data['audio_quality'] ?? '192';
    $requestedDir = $data['directory'] ?? '';

    if (!$url || !validUrl($url)) {
        response(['success' => false, 'error' => 'Invalid URL.'], 400);
    }

    if (!$format) {
        response(['success' => false, 'error' => 'No format selected.'], 400);
    }

    if (!in_array($type, ['audio', 'video'], true)) {
        response(['success' => false, 'error' => 'Unknown download type.'], 400);
    }

    $outputDir = resolveOutputDir($requestedDir, $STORAGE_ROOT, $DOWNLOAD_DIR);

    $jobId = preg_replace('/[^a-zA-Z0-9_.]/', '', uniqid('job_', true));
    $logFile = $JOBS_DIR . '/' . $jobId . '.log';

    $template = buildDownloadCommand($YT_DLP, $outputDir, $type, $format, $audioQuality, $COOKIES_FILE);
    $innerCommand = str_replace('%URL%', escapeshellarg($url), $template);

    // Wrap so we can capture the real exit code after the
    // process finishes, then detach it from this PHP request
    // entirely with setsid so it survives the request ending.
    $wrapped = $innerCommand . '; echo "JOB_EXIT_CODE:$?"';

    $bgCommand =
        'setsid bash -c ' . escapeshellarg($wrapped) .
        ' > ' . escapeshellarg($logFile) .
        ' 2>&1 < /dev/null & echo $!';

    $pidOutput = [];
    exec($bgCommand, $pidOutput);
    $pid = trim($pidOutput[0] ?? '');

    file_put_contents($JOBS_DIR . '/' . $jobId . '.pid', $pid);

    response([
        'success' => true,
        'job_id' => $jobId,
        'directory' => $outputDir
    ]);
}


// ---------------------------------------------------------
// CHECK A BACKGROUND DOWNLOAD JOB'S STATUS
// ---------------------------------------------------------

if ($action === 'job_status') {

    $jobId = preg_replace('/[^a-zA-Z0-9_.]/', '', $data['job_id'] ?? '');

    if (!$jobId) {
        response(['success' => false, 'error' => 'Missing job_id.'], 400);
    }

    $logFile = $JOBS_DIR . '/' . $jobId . '.log';
    $pidFile = $JOBS_DIR . '/' . $jobId . '.pid';

    if (!file_exists($logFile)) {
        response(['success' => false, 'error' => 'Unknown job.'], 404);
    }

    $logContents = file_get_contents($logFile);
    $parsed = parseJobLog($logContents);

    $pid = trim(@file_get_contents($pidFile) ?: '');

    // If yt-dlp never wrote our exit marker but the process is
    // also no longer running, something killed it externally -
    // surface that as an error instead of polling forever.
    if (in_array($parsed['stage'], ['downloading', 'processing'], true) && !isPidRunning($pid)) {
        $parsed['stage'] = 'error';
        $parsed['error'] = 'The download process stopped unexpectedly (was Termux closed or killed?).';
    }

    $logLines = explode("\n", trim($logContents));
    $logTail = implode("\n", array_slice($logLines, -8));

    response([
        'success' => true,
        'status' => $parsed['stage'],
        'percent' => $parsed['percent'],
        'speed' => $parsed['speed'],
        'eta' => $parsed['eta'],
        'error' => $parsed['error'],
        'log_tail' => $logTail
    ]);
}


// ---------------------------------------------------------
// LIST DOWNLOADS
// ---------------------------------------------------------

if ($action === 'downloads') {

    $files = [];

    foreach (scandir($DOWNLOAD_DIR) as $file) {

        if ($file === '.' || $file === '..' || $file === '.jobs') {
            continue;
        }

        $path = $DOWNLOAD_DIR . '/' . $file;

        if (is_file($path)) {
            $files[] = [
                'name' => $file,
                'size' => filesize($path),
                'modified' => filemtime($path)
            ];
        }
    }

    usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);

    response(['success' => true, 'files' => $files]);
}


// ---------------------------------------------------------
// UNKNOWN ACTION
// ---------------------------------------------------------

response(['success' => false, 'error' => 'Unknown API action.'], 400);

