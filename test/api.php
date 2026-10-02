<?php

header('Content-Type: application/json');


// ---------------------------------------------------------
// CONFIGURATION
// ---------------------------------------------------------

$YT_DLP = '/data/data/com.termux/files/usr/bin/yt-dlp';
$FFMPEG = '/data/data/com.termux/files/usr/bin/ffmpeg';
$ARIA2C = '/data/data/com.termux/files/usr/bin/aria2c';

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

// Default folder for torrents when "Save folder" is left blank -
// kept separate from the video/audio default so the two don't mix.
$DEFAULT_TORRENT_DIR = $STORAGE_ROOT . '/Torrents';

$JOBS_DIR = $DOWNLOAD_DIR . '/.jobs';
$TORRENT_UPLOADS_DIR = $JOBS_DIR . '/torrent-uploads';
$TOKENS_DIR = $JOBS_DIR . '/tokens';

// Optional: if you export your browser's YouTube (or other
// site) cookies to this exact file, downloads that need a
// logged-in session (age-restricted, "confirm you're not a
// bot", etc.) will use it automatically. Safe to leave absent.
$COOKIES_FILE = $STORAGE_ROOT . '/cookies.txt';

// Leave blank to keep this open with no login, exactly as before.
// Set a password here once you start sharing the link with other
// people - every request will then require it. Change this and
// nothing else needs touching; the app already knows how to ask
// for and send it.
$ACCESS_PASSWORD = '';

error_reporting(E_ALL);
ini_set('display_errors', '1');

foreach ([$DOWNLOAD_DIR, $JOBS_DIR, $TORRENT_UPLOADS_DIR, $TOKENS_DIR] as $dir) {
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
    // Multipart form posts (file uploads) populate $_POST natively -
    // check that first. Our normal JSON API calls send
    // application/json, which PHP does NOT put into $_POST, so this
    // falls through to the JSON branch for those as before.
    if (!empty($_POST)) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');

    if ($raw) {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }
    }

    // Plain GET requests (e.g. the fetch_file download link, which
    // has to be a normal browser navigation/anchor click for the
    // browser to natively trigger its save-file behavior) carry
    // their params in the query string instead.
    return $_GET;
}

/**
 * No-ops entirely when $ACCESS_PASSWORD is empty (the default) -
 * the app stays exactly as open as it always was. Once a
 * password is set, every action except 'login' and 'auth_status'
 * requires a valid token, obtained by calling 'login' with the
 * correct password.
 */
function requireAuth($accessPassword, $tokensDir, $data)
{
    if ($accessPassword === '') {
        return;
    }

    $token = preg_replace('/[^a-zA-Z0-9]/', '', $data['token'] ?? '');

    if ($token === '' || !file_exists($tokensDir . '/' . $token)) {
        response([
            'success' => false,
            'auth_required' => true,
            'error' => 'Please enter the access password.'
        ], 401);
    }
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
 *
 * Audio downloads embed a cover image automatically. yt-dlp has
 * no access to real album art (that would need a separate music
 * metadata service) - it embeds the source video's own
 * thumbnail as the cover, converting it to JPEG first since
 * some sites serve WEBP thumbnails that won't embed cleanly.
 *
 * $overwrite=true adds --force-overwrites so a file the user
 * chose to "Replace" is actually replaced. Left false, yt-dlp's
 * own default applies: if a same-named file already exists it's
 * left alone and treated as already downloaded.
 */
function buildDownloadCommand($YT_DLP, $outputDir, $type, $format, $audioQuality, $cookiesFile = null, $overwrite = false)
{
    $outputTemplate = $outputDir . '/%(title)s.%(ext)s';

    $cookiesArg = '';
    if ($cookiesFile && is_readable($cookiesFile)) {
        $cookiesArg = ' --cookies ' . escapeshellarg($cookiesFile);
    }

    $overwriteArg = $overwrite ? ' --force-overwrites' : '';
    $fallbackClientArg = ' --extractor-args ' . escapeshellarg('youtube:player_client=android,ios,tv');

    if ($type === 'audio') {

        $quality = preg_replace('/[^0-9]/', '', $audioQuality);
        if (!$quality) {
            $quality = '192';
        }

        $common =
            ' --no-playlist --newline' .
            $cookiesArg .
            $overwriteArg .
            ' -x --audio-format mp3' .
            ' --audio-quality ' . escapeshellarg($quality . 'K') .
            ' --embed-thumbnail --convert-thumbnails jpg --add-metadata' .
            ' --restrict-filenames' .
            ' -o ' . escapeshellarg($outputTemplate);

        $primary = escapeshellcmd($YT_DLP) . $common . ' -f ' . escapeshellarg($format) . ' %URL%';
        $fallback = escapeshellcmd($YT_DLP) . $common . $fallbackClientArg . ' -f ' . escapeshellarg('bestaudio') . ' %URL%';

        return "($primary) || ($fallback)";
    }

    $common =
        ' --no-playlist --newline' .
        $cookiesArg .
        $overwriteArg .
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
 * Records where a job's output is going, when it started, and
 * (for media jobs) the title that was requested - so that once
 * it finishes we can figure out which file it actually produced.
 * yt-dlp/aria2c pick the exact filename themselves, so we can't
 * know it in advance; we find it afterwards, first by mtime and,
 * failing that, by matching the title (see resolveJobResultFile).
 */
function writeJobMeta($jobsDir, $jobId, $outputDir, $title = null)
{
    file_put_contents(
        $jobsDir . '/' . $jobId . '.meta',
        json_encode(['directory' => $outputDir, 'started_at' => time(), 'title' => $title])
    );
}

function normalizeForMatch($s)
{
    $s = strtolower((string) $s);
    $s = preg_replace('/\.[a-z0-9]{2,4}$/', '', $s); // strip extension
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim($s);
}

/**
 * Once a job is done, finds the file it most likely produced.
 *
 * First tries the newest file in its output directory that
 * appeared at or after the job started - the normal case.
 *
 * If nothing qualifies, falls back to matching by title. This
 * covers the case where yt-dlp/aria2c silently skip the download
 * because an identical file already exists (e.g. someone else
 * sharing this app already downloaded the same title) - without
 * this fallback, that person's job would report "done" but have
 * no file to actually hand back to them.
 *
 * Caches whichever result is found so repeated status polls
 * don't rescan the folder.
 */
function resolveJobResultFile($jobsDir, $jobId)
{
    $resultFile = $jobsDir . '/' . $jobId . '.result';

    if (file_exists($resultFile)) {
        $path = trim(file_get_contents($resultFile));
        return ($path && is_file($path)) ? $path : null;
    }

    $metaFile = $jobsDir . '/' . $jobId . '.meta';
    if (!file_exists($metaFile)) {
        return null;
    }

    $meta = json_decode(file_get_contents($metaFile), true);
    $dir = $meta['directory'] ?? null;
    $startedAt = $meta['started_at'] ?? 0;
    $title = $meta['title'] ?? null;

    if (!$dir || !is_dir($dir)) {
        return null;
    }

    $skipExtensions = ['part', 'ytdl', 'aria2', 'tmp'];
    $best = null;
    $bestMtime = $startedAt - 2; // small buffer for clock skew

    foreach (scandir($dir) as $file) {
        if ($file === '.' || $file === '..' || $file === '.jobs') {
            continue;
        }

        $fullPath = $dir . '/' . $file;
        if (!is_file($fullPath)) {
            continue;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, $skipExtensions, true)) {
            continue;
        }

        $mtime = filemtime($fullPath);
        if ($mtime >= $bestMtime) {
            $best = $fullPath;
            $bestMtime = $mtime;
        }
    }

    // Fallback: nothing new appeared, but a matching title might
    // already exist from an earlier, unrelated request.
    if (!$best && $title) {
        $needle = normalizeForMatch($title);
        $bestFallbackMtime = -1;

        if ($needle !== '') {
            foreach (scandir($dir) as $file) {
                if ($file === '.' || $file === '..' || $file === '.jobs') {
                    continue;
                }

                $fullPath = $dir . '/' . $file;
                if (!is_file($fullPath)) {
                    continue;
                }

                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, $skipExtensions, true)) {
                    continue;
                }

                if (strpos(normalizeForMatch($file), $needle) !== false) {
                    $mtime = filemtime($fullPath);
                    if ($mtime > $bestFallbackMtime) {
                        $best = $fullPath;
                        $bestFallbackMtime = $mtime;
                    }
                }
            }
        }
    }

    if ($best) {
        file_put_contents($resultFile, $best);
    }

    return $best;
}

/**
 * Builds the aria2c command for a torrent/magnet download.
 * $source can be a magnet: URI, an http(s) URL to a .torrent
 * file, or a local path to an uploaded .torrent file - aria2c
 * accepts all three transparently as the final argument.
 * --seed-time=0 stops immediately once the download finishes
 * instead of continuing to seed (kinder to battery/data on a
 * phone that isn't meant to run as a permanent seedbox).
 */
function buildTorrentCommand($ARIA2C, $outputDir, $source)
{
    return
        escapeshellcmd($ARIA2C) .
        ' --dir=' . escapeshellarg($outputDir) .
        ' --seed-time=0' .
        ' --summary-interval=1' .
        ' --console-log-level=notice' .
        ' --allow-overwrite=true' .
        ' --file-allocation=none' .
        ' ' . escapeshellarg($source);
}

/**
 * Parses a job log for progress and outcome. Handles both job
 * types - yt-dlp's --newline output and aria2c's periodic
 * summary lines - based on $isTorrent. stage is one of:
 * downloading | processing | done | error.
 */
function parseJobLog($logContents, $isTorrent = false)
{
    $percent = null;
    $speed = null;
    $eta = null;
    $stage = 'downloading';

    if ($isTorrent) {

        // e.g. [#1fbe80 SIZE:105MiB/650MiB(16%) CN:8 SEED:0 DL:1.5MiB ETA:5m47s]
        if (preg_match_all(
            '/\((\d{1,3})%\).*?DL:(\S+)(?:.*?ETA:(\S+))?/',
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

    } else {

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
    }

    $friendlyError = null;

    if (preg_match('/JOB_EXIT_CODE:(-?\d+)/', $logContents, $m)) {
        $exitCode = (int) $m[1];

        if ($exitCode === 0) {
            $stage = 'done';
        } else {
            $stage = 'error';

            if ($isTorrent) {
                if (stripos($logContents, 'certificate') !== false) {
                    $friendlyError = 'A network/certificate error stopped the torrent.';
                } elseif (stripos($logContents, 'No files to download') !== false) {
                    $friendlyError = 'That torrent has no downloadable files.';
                } elseif (preg_match('/CN:0\b/', $logContents) || stripos($logContents, 'no peers') !== false) {
                    $friendlyError = 'No peers could be found for this torrent.';
                } else {
                    $friendlyError = 'Torrent download failed. See log below.';
                }
            } else {
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

if (!in_array($action, ['login', 'auth_status'], true)) {
    requireAuth($ACCESS_PASSWORD, $TOKENS_DIR, $data);
}


// ---------------------------------------------------------
// LOGIN / AUTH STATUS
// (requireAuth() above already let these two through even
// without a token - they're how a token gets obtained/checked
// in the first place)
// ---------------------------------------------------------

if ($action === 'auth_status') {
    response(['success' => true, 'auth_required' => $ACCESS_PASSWORD !== '']);
}

if ($action === 'login') {

    $submitted = (string) ($data['password'] ?? '');

    if ($ACCESS_PASSWORD === '' || !hash_equals($ACCESS_PASSWORD, $submitted)) {
        response(['success' => false, 'error' => 'Incorrect password.'], 401);
    }

    $token = bin2hex(random_bytes(24));
    file_put_contents($TOKENS_DIR . '/' . $token, (string) time());

    response(['success' => true, 'token' => $token]);
}


// ---------------------------------------------------------
// CHECK YT-DLP / FFMPEG
// ---------------------------------------------------------

if ($action === 'check') {

    $result = runCommand(escapeshellcmd($YT_DLP) . ' --version');
    $ffmpegResult = runCommand(escapeshellcmd($FFMPEG) . ' -version');
    $aria2Result = runCommand(escapeshellcmd($ARIA2C) . ' --version');

    response([
        'success' => $result['code'] === 0,
        'yt_dlp' => trim($result['output']),
        'ffmpeg' => $ffmpegResult['code'] === 0,
        'aria2c' => $aria2Result['code'] === 0,
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
    $overwrite = !empty($data['overwrite']);
    $title = trim($data['title'] ?? '') ?: null;

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

    $template = buildDownloadCommand($YT_DLP, $outputDir, $type, $format, $audioQuality, $COOKIES_FILE, $overwrite);
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
    writeJobMeta($JOBS_DIR, $jobId, $outputDir, $title);

    response([
        'success' => true,
        'job_id' => $jobId,
        'directory' => $outputDir
    ]);
}


// ---------------------------------------------------------
// UPLOAD A LOCAL .TORRENT FILE
//
// Just saves the file and hands back its server-side path -
// the actual aria2c job is started separately via
// 'start_torrent' once the item's turn comes up in the queue.
// ---------------------------------------------------------

if ($action === 'upload_torrent_file') {

    if (!isset($_FILES['torrent_file']) || $_FILES['torrent_file']['error'] !== UPLOAD_ERR_OK) {
        response(['success' => false, 'error' => 'No .torrent file was received.'], 400);
    }

    $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', basename($_FILES['torrent_file']['name']));
    $destPath = $TORRENT_UPLOADS_DIR . '/' . uniqid('t_') . '_' . $safeName;

    if (!move_uploaded_file($_FILES['torrent_file']['tmp_name'], $destPath)) {
        response(['success' => false, 'error' => 'Could not save the uploaded file.'], 500);
    }

    response(['success' => true, 'path' => $destPath]);
}


// ---------------------------------------------------------
// START A BACKGROUND TORRENT JOB (magnet link, .torrent URL,
// or an already-uploaded local .torrent file). Same detached
// setsid pattern as start_download, so it survives the
// connection dropping and is polled via the same job_status.
// ---------------------------------------------------------

if ($action === 'start_torrent') {

    $source = trim($data['source'] ?? '');
    $requestedDir = $data['directory'] ?? '';

    if ($source === '') {
        response(['success' => false, 'error' => 'No magnet link, torrent URL, or file provided.'], 400);
    }

    $isMagnet = stripos($source, 'magnet:') === 0;
    $isUrl = (bool) validUrl($source);
    $isUploadedFile = is_file($source) &&
        strpos(realpath($source) ?: '', realpath($TORRENT_UPLOADS_DIR) ?: "\0") === 0;

    if (!$isMagnet && !$isUrl && !$isUploadedFile) {
        response(['success' => false, 'error' => "That doesn't look like a valid magnet link, .torrent URL, or uploaded file."], 400);
    }

    $outputDir = resolveOutputDir($requestedDir, $STORAGE_ROOT, $DEFAULT_TORRENT_DIR);

    $jobId = preg_replace('/[^a-zA-Z0-9_.]/', '', uniqid('torrent_', true));
    $logFile = $JOBS_DIR . '/' . $jobId . '.log';

    $innerCommand = buildTorrentCommand($ARIA2C, $outputDir, $source);
    $wrapped = $innerCommand . '; echo "JOB_EXIT_CODE:$?"';

    $bgCommand =
        'setsid bash -c ' . escapeshellarg($wrapped) .
        ' > ' . escapeshellarg($logFile) .
        ' 2>&1 < /dev/null & echo $!';

    $pidOutput = [];
    exec($bgCommand, $pidOutput);
    $pid = trim($pidOutput[0] ?? '');

    file_put_contents($JOBS_DIR . '/' . $jobId . '.pid', $pid);
    writeJobMeta($JOBS_DIR, $jobId, $outputDir);

    response([
        'success' => true,
        'job_id' => $jobId,
        'directory' => $outputDir
    ]);
}


// ---------------------------------------------------------
// PAUSE / RESUME / CANCEL A RUNNING JOB
//
// setsid made each job's process its own session AND process
// group leader, so signalling the negative PID (-PID) reaches
// the whole group - yt-dlp/aria2c/ffmpeg children included -
// not just the top-level bash wrapper.
//
// Pause/resume use SIGSTOP/SIGCONT (the OS just freezes/
// unfreezes scheduling; the process and its open connection
// stay intact). Cancel uses SIGTERM, escalating to SIGKILL if
// it doesn't die quickly.
// ---------------------------------------------------------

if ($action === 'pause_job') {

    $jobId = preg_replace('/[^a-zA-Z0-9_.]/', '', $data['job_id'] ?? '');
    $pid = trim(@file_get_contents($JOBS_DIR . '/' . $jobId . '.pid') ?: '');

    if (!$jobId || !$pid || !isPidRunning($pid)) {
        response(['success' => false, 'error' => 'Job is not currently running.'], 400);
    }

    exec('kill -STOP -' . intval($pid) . ' 2>&1');
    file_put_contents($JOBS_DIR . '/' . $jobId . '.paused', '1');

    response(['success' => true]);
}

if ($action === 'resume_job') {

    $jobId = preg_replace('/[^a-zA-Z0-9_.]/', '', $data['job_id'] ?? '');
    $pid = trim(@file_get_contents($JOBS_DIR . '/' . $jobId . '.pid') ?: '');

    if (!$jobId || !$pid) {
        response(['success' => false, 'error' => 'Unknown job.'], 404);
    }

    exec('kill -CONT -' . intval($pid) . ' 2>&1');
    @unlink($JOBS_DIR . '/' . $jobId . '.paused');

    response(['success' => true]);
}

if ($action === 'cancel_job') {

    $jobId = preg_replace('/[^a-zA-Z0-9_.]/', '', $data['job_id'] ?? '');

    if (!$jobId) {
        response(['success' => false, 'error' => 'Missing job_id.'], 400);
    }

    $pid = trim(@file_get_contents($JOBS_DIR . '/' . $jobId . '.pid') ?: '');

    if ($pid) {
        exec('kill -TERM -' . intval($pid) . ' 2>&1');
        usleep(300000);
        if (isPidRunning($pid)) {
            exec('kill -KILL -' . intval($pid) . ' 2>&1');
        }
    }

    @unlink($JOBS_DIR . '/' . $jobId . '.paused');
    file_put_contents($JOBS_DIR . '/' . $jobId . '.cancelled', '1');

    response(['success' => true]);
}


// ---------------------------------------------------------
// CHECK IF SOMETHING MATCHING THIS TITLE ALREADY EXISTS IN
// THE TARGET FOLDER (used to offer Replace / Skip before
// actually downloading)
// ---------------------------------------------------------

if ($action === 'check_exists') {

    $title = trim($data['title'] ?? '');
    $requestedDir = $data['directory'] ?? '';

    if ($title === '') {
        response(['success' => true, 'exists' => false, 'matches' => []]);
    }

    $outputDir = resolveOutputDir($requestedDir, $STORAGE_ROOT, $DOWNLOAD_DIR);

    $normalize = function ($s) {
        $s = strtolower($s);
        $s = preg_replace('/\.[a-z0-9]{2,4}$/', '', $s); // strip extension
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim($s);
    };

    $needle = $normalize($title);
    $matches = [];

    if ($needle !== '' && is_dir($outputDir)) {
        foreach (scandir($outputDir) as $file) {
            if ($file === '.' || $file === '..' || $file === '.jobs') {
                continue;
            }
            if (!is_file($outputDir . '/' . $file)) {
                continue;
            }
            if (strpos($normalize($file), $needle) !== false || strpos($needle, $normalize($file)) !== false) {
                $matches[] = $file;
            }
        }
    }

    response([
        'success' => true,
        'exists' => count($matches) > 0,
        'matches' => $matches,
        'directory' => $outputDir
    ]);
}


// ---------------------------------------------------------
// LIST SUPPORTED SITES (cached - the full list is ~1800+
// entries and takes a couple of seconds to generate)
// ---------------------------------------------------------

if ($action === 'list_extractors') {

    $cacheFile = $DOWNLOAD_DIR . '/.extractors_cache.txt';
    $maxAge = 60 * 60 * 24 * 30; // 30 days - this list barely changes

    if (!file_exists($cacheFile) || (time() - filemtime($cacheFile)) > $maxAge) {

        $result = runCommand(escapeshellcmd($YT_DLP) . ' --list-extractors');

        if ($result['code'] !== 0) {
            response(['success' => false, 'error' => $result['output']], 500);
        }

        file_put_contents($cacheFile, $result['output']);
    }

    $lines = file($cacheFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    // Drop the ":<something>" suffixes some extractors have
    // (e.g. "youtube:tab") down to their base site name, then
    // de-duplicate and sort for a clean dropdown.
    $sites = array_map(function ($line) {
        return preg_replace('/:.*/', '', trim($line));
    }, $lines ?: []);

    $sites = array_values(array_unique(array_filter($sites)));
    sort($sites, SORT_STRING | SORT_FLAG_CASE);

    response(['success' => true, 'sites' => $sites]);
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
    $isTorrent = strpos($jobId, 'torrent_') === 0;
    $parsed = parseJobLog($logContents, $isTorrent);

    $pid = trim(@file_get_contents($pidFile) ?: '');

    $cancelledMarker = $JOBS_DIR . '/' . $jobId . '.cancelled';
    $pausedMarker = $JOBS_DIR . '/' . $jobId . '.paused';

    if (file_exists($cancelledMarker)) {
        // Explicitly cancelled by the user - report this
        // distinctly instead of as a generic error.
        $parsed['stage'] = 'cancelled';
        $parsed['error'] = null;
    } elseif (file_exists($pausedMarker) && isPidRunning($pid)) {
        // SIGSTOP freezes the process but leaves it in the
        // process table, so isPidRunning still reports true.
        $parsed['stage'] = 'paused';
        $parsed['error'] = null;
    } elseif (in_array($parsed['stage'], ['downloading', 'processing'], true) && !isPidRunning($pid)) {
        // Never explicitly cancelled/paused, yet no longer
        // running and no exit marker - something killed it
        // externally.
        $parsed['stage'] = 'error';
        $parsed['error'] = 'The download process stopped unexpectedly (was Termux closed or killed?).';
    }

    $logLines = explode("\n", trim($logContents));
    $logTail = implode("\n", array_slice($logLines, -8));

    $resultFile = null;
    if ($parsed['stage'] === 'done') {
        $resolvedPath = resolveJobResultFile($JOBS_DIR, $jobId);
        if ($resolvedPath) {
            $resultFile = basename($resolvedPath);
        }
    }

    response([
        'success' => true,
        'status' => $parsed['stage'],
        'percent' => $parsed['percent'],
        'speed' => $parsed['speed'],
        'eta' => $parsed['eta'],
        'error' => $parsed['error'],
        'log_tail' => $logTail,
        'result_file' => $resultFile
    ]);
}


// ---------------------------------------------------------
// SERVE A FINISHED FILE BACK TO WHOEVER'S BROWSER ASKED FOR
// IT - this is what makes a completed download actually save
// to the VISITOR's device instead of only living on the
// server's own storage.
//
// Deliberately takes only a job_id, never a raw path/filename
// from the request - the server looks up what that specific
// job actually produced (via its own .result file, written by
// resolveJobResultFile()) so nobody can request an arbitrary
// path on the server.
// ---------------------------------------------------------

if ($action === 'fetch_file') {

    $jobId = preg_replace('/[^a-zA-Z0-9_.]/', '', $data['job_id'] ?? '');

    if (!$jobId) {
        response(['success' => false, 'error' => 'Missing job_id.'], 400);
    }

    $path = resolveJobResultFile($JOBS_DIR, $jobId);

    if (!$path || !is_file($path)) {
        response(['success' => false, 'error' => 'That file is not ready or no longer exists.'], 404);
    }

    // Override the JSON content-type set at the top of the file -
    // nothing has been echoed yet, so this is safe.
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');

    readfile($path);
    exit;
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

