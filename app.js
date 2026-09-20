// ---------------------------------------------------------
// ELEMENT REFERENCES
// ---------------------------------------------------------

const urlInput = document.getElementById("url");
const analyzeBtn = document.getElementById("analyzeBtn");

const saveDirInput = document.getElementById("saveDir");
const saveDirMoviesBtn = document.getElementById("saveDirMoviesBtn");
const saveDirMusicBtn = document.getElementById("saveDirMusicBtn");

const formatsSection = document.getElementById("formats");
const audioList = document.getElementById("audioList");
const videoList = document.getElementById("videoList");
const downloadAudio = document.getElementById("downloadAudio");
const downloadVideo = document.getElementById("downloadVideo");

const loading = document.getElementById("loading");
const errorBox = document.getElementById("errorBox");

const videoInfo = document.getElementById("videoInfo");
const thumbnail = document.getElementById("thumbnail");
const videoTitle = document.getElementById("videoTitle");
const videoMeta = document.getElementById("videoMeta");

const playlistSection = document.getElementById("playlistSection");
const playlistTitle = document.getElementById("playlistTitle");
const playlistCount = document.getElementById("playlistCount");
const playlistList = document.getElementById("playlistList");
const selectAllPlaylist = document.getElementById("selectAllPlaylist");
const playlistQuality = document.getElementById("playlistQuality");
const addPlaylistToQueueBtn = document.getElementById("addPlaylistToQueueBtn");

const queueSection = document.getElementById("queueSection");
const queueList = document.getElementById("queueList");
const startQueueBtn = document.getElementById("startQueueBtn");
const queueSummary = document.getElementById("queueSummary");

let currentURL = "";
let currentTitle = "";
let selectedAudio = null;
let selectedVideo = null;
let playlistItems = [];

// ---------------------------------------------------------
// QUEUE STATE + PERSISTENCE
// ---------------------------------------------------------

let queue = [];
let queueRunning = false;
let queueIdCounter = 0;

const QUEUE_STORAGE_KEY = "vertexvstore_queue";
const SAVE_DIR_STORAGE_KEY = "vertexvstore_savedir";

function saveQueueToStorage() {
    try {
        localStorage.setItem(QUEUE_STORAGE_KEY, JSON.stringify(queue));
    } catch (e) {
        // storage full/unavailable - not fatal
    }
}

function loadQueueFromStorage() {
    try {
        const raw = localStorage.getItem(QUEUE_STORAGE_KEY);
        if (!raw) return;

        const saved = JSON.parse(raw);
        if (!Array.isArray(saved)) return;

        queue = saved;
        queueIdCounter = queue.reduce((max, q) => Math.max(max, q.id || 0), 0);
    } catch (e) {
        queue = [];
    }
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

// ---------------------------------------------------------
// SAVE FOLDER (persisted, changeable any time)
// ---------------------------------------------------------

try {
    const savedDir = localStorage.getItem(SAVE_DIR_STORAGE_KEY);
    if (savedDir) {
        saveDirInput.value = savedDir;
    }
} catch (e) {
    // ignore
}

saveDirInput.addEventListener("change", function () {
    try {
        localStorage.setItem(SAVE_DIR_STORAGE_KEY, saveDirInput.value.trim());
    } catch (e) {
        // ignore
    }
});

saveDirMoviesBtn.addEventListener("click", function () {
    saveDirInput.value = "Movies";
    saveDirInput.dispatchEvent(new Event("change"));
});

saveDirMusicBtn.addEventListener("click", function () {
    saveDirInput.value = "Music";
    saveDirInput.dispatchEvent(new Event("change"));
});

// ---------------------------------------------------------
// HELPERS
// ---------------------------------------------------------

function show(el) { el.classList.remove("hidden"); }
function hide(el) { el.classList.add("hidden"); }

function formatBytes(bytes) {
    if (!bytes || bytes <= 0) return "Unknown size";
    const units = ["B", "KB", "MB", "GB", "TB"];
    let i = 0, size = bytes;
    while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
    return size.toFixed(1) + " " + units[i];
}

function formatDuration(seconds) {
    if (!seconds) return "";
    seconds = Number(seconds);
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);
    if (h > 0) return `${h}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
    return `${m}:${String(s).padStart(2, "0")}`;
}

function showError(message) {
    errorBox.textContent = message;
    show(errorBox);
}

function clearError() {
    errorBox.textContent = "";
    hide(errorBox);
}

function escapeHtml(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

const escapeAttribute = escapeHtml;

// ---------------------------------------------------------
// ANALYZE (single video/post, OR playlist / mix)
// ---------------------------------------------------------

analyzeBtn.addEventListener("click", analyze);

urlInput.addEventListener("keydown", function (e) {
    if (e.key === "Enter") analyze();
});

async function analyze() {
    const url = urlInput.value.trim();

    if (!url) {
        showError("Enter a video, playlist, or mix URL first.");
        return;
    }

    currentURL = url;
    currentTitle = "";
    selectedAudio = null;
    selectedVideo = null;
    playlistItems = [];

    clearError();
    hide(formatsSection);
    hide(videoInfo);
    hide(playlistSection);
    show(loading);

    downloadAudio.disabled = true;
    downloadVideo.disabled = true;

    analyzeBtn.disabled = true;
    analyzeBtn.textContent = "Reading...";

    try {
        const response = await fetch("api.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ action: "info", url })
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || "yt-dlp failed.");
        }

        if (data.type === "playlist") {
            displayPlaylist(data);
        } else if (data.no_formats) {
            displayVideoInfo(data);
            showError("No downloadable formats were found for this video. It may be private, DRM-protected, or otherwise unsupported.");
        } else {
            displayVideoInfo(data);
            displayAudio(data.audio || []);
            displayVideo(data.video || []);
            show(formatsSection);
        }
    } catch (error) {
        showError(error.message);
    } finally {
        hide(loading);
        analyzeBtn.disabled = false;
        analyzeBtn.textContent = "Get Formats";
    }
}

// ---------------------------------------------------------
// PLAYLIST / MIX
// ---------------------------------------------------------

function displayPlaylist(data) {
    playlistItems = data.items || [];

    playlistTitle.textContent = data.title || "Playlist";
    playlistCount.textContent = `${playlistItems.length} video${playlistItems.length === 1 ? "" : "s"} found`;

    playlistList.innerHTML = "";

    playlistItems.forEach((item, index) => {
        const row = document.createElement("label");
        row.className = "format-item playlist-item";

        const duration = item.duration ? formatDuration(item.duration) : "";

        row.innerHTML = `
            <input type="checkbox" class="playlist-check" data-index="${index}" checked>
            <div class="format-main">
                <div class="format-title">${escapeHtml(item.title)}</div>
                <div class="format-info">${duration}</div>
            </div>
        `;

        playlistList.appendChild(row);
    });

    show(playlistSection);
}

selectAllPlaylist.addEventListener("change", function () {
    playlistList.querySelectorAll(".playlist-check").forEach((box) => {
        box.checked = selectAllPlaylist.checked;
    });
});

addPlaylistToQueueBtn.addEventListener("click", function () {
    const checked = playlistList.querySelectorAll(".playlist-check:checked");

    if (checked.length === 0) {
        showError("Select at least one video from the playlist.");
        return;
    }

    const type = document.querySelector('input[name="playlistType"]:checked').value;
    const quality = playlistQuality.value;

    let format;
    if (type === "audio") {
        format = "bestaudio";
    } else if (quality === "best") {
        format = "bestvideo+bestaudio/best";
    } else {
        format = `bestvideo[height<=${quality}]+bestaudio/best[height<=${quality}]`;
    }

    const directory = saveDirInput.value.trim();

    checked.forEach((box) => {
        const item = playlistItems[Number(box.dataset.index)];
        addToQueue({
            title: item.title,
            url: item.url,
            type,
            format,
            audio_quality: "192",
            directory
        });
    });

    clearError();
});

// ---------------------------------------------------------
// SINGLE VIDEO INFO + FORMATS
// ---------------------------------------------------------

function displayVideoInfo(data) {
    currentTitle = data.title || "Unknown title";
    videoTitle.textContent = currentTitle;

    let meta = "";
    if (data.uploader) meta += `<span>${escapeHtml(data.uploader)}</span>`;
    if (data.duration) meta += `<span>Duration: ${formatDuration(data.duration)}</span>`;
    videoMeta.innerHTML = meta;

    if (data.thumbnail) {
        thumbnail.src = data.thumbnail;
        show(videoInfo);
    }
}

function displayAudio(formats) {
    audioList.innerHTML = "";

    if (formats.length === 0) {
        audioList.innerHTML = `<div class="empty">No audio formats found.</div>`;
        return;
    }

    formats.forEach((format, index) => {
        const bitrate = format.abr ? Math.round(format.abr) + " kbps" : "Unknown bitrate";
        const size = format.size ? formatBytes(format.size) : "Size unknown";

        const item = document.createElement("label");
        item.className = "format-item";
        item.innerHTML = `
            <input type="radio" name="audioFormat" value="${escapeAttribute(format.id)}" ${index === 0 ? "checked" : ""}>
            <div class="format-main">
                <div class="format-title">${bitrate}<span class="badge">MP3</span></div>
                <div class="format-info">${escapeHtml(format.codec || "unknown")} · ${size} · ID ${escapeHtml(format.id)}</div>
            </div>
            <div class="radio-circle"></div>
        `;
        audioList.appendChild(item);
    });

    const selected = audioList.querySelector('input[name="audioFormat"]:checked');
    if (selected) {
        selectedAudio = selected.value;
        downloadAudio.disabled = false;
    }

    audioList.querySelectorAll('input[name="audioFormat"]').forEach((input) => {
        input.addEventListener("change", function () {
            selectedAudio = this.value;
            downloadAudio.disabled = false;
        });
    });
}

function displayVideo(formats) {
    videoList.innerHTML = "";

    if (formats.length === 0) {
        videoList.innerHTML = `<div class="empty">No MP4 video formats found.</div>`;
        return;
    }

    formats.forEach((format, index) => {
        const resolution = format.height ? `${format.height}p` : "Unknown";
        const fps = format.fps ? `${Math.round(format.fps)} FPS` : "";
        const size = format.size ? formatBytes(format.size) : "Size unknown";

        const ytFormat = format.audio ? format.id : `${format.id}+bestaudio`;
        const audioLabel = format.audio ? "Audio included" : "Video + best audio";

        const item = document.createElement("label");
        item.className = "format-item";
        item.innerHTML = `
            <input type="radio" name="videoFormat" value="${escapeAttribute(ytFormat)}" ${index === 0 ? "checked" : ""}>
            <div class="format-main">
                <div class="format-title">${resolution}<span class="badge">MP4</span></div>
                <div class="format-info">${escapeHtml(format.codec || "unknown")} · ${fps} · ${size}</div>
                <div class="format-extra">${audioLabel} · ID ${escapeHtml(format.id)}</div>
            </div>
            <div class="radio-circle"></div>
        `;
        videoList.appendChild(item);
    });

    const selected = videoList.querySelector('input[name="videoFormat"]:checked');
    if (selected) {
        selectedVideo = selected.value;
        downloadVideo.disabled = false;
    }

    videoList.querySelectorAll('input[name="videoFormat"]').forEach((input) => {
        input.addEventListener("change", function () {
            selectedVideo = this.value;
            downloadVideo.disabled = false;
        });
    });
}

downloadAudio.addEventListener("click", function () {
    if (!selectedAudio) {
        showError("Select an audio format first.");
        return;
    }
    addToQueue({
        title: currentTitle || currentURL,
        url: currentURL,
        type: "audio",
        format: selectedAudio,
        audio_quality: "192",
        directory: saveDirInput.value.trim()
    });
    clearError();
});

downloadVideo.addEventListener("click", function () {
    if (!selectedVideo) {
        showError("Select a video format first.");
        return;
    }
    addToQueue({
        title: currentTitle || currentURL,
        url: currentURL,
        type: "video",
        format: selectedVideo,
        directory: saveDirInput.value.trim()
    });
    clearError();
});

// ---------------------------------------------------------
// QUEUE
// ---------------------------------------------------------

function addToQueue(item) {
    queueIdCounter += 1;

    queue.push({
        id: queueIdCounter,
        title: item.title,
        url: item.url,
        type: item.type,
        format: item.format,
        audio_quality: item.audio_quality || "192",
        directory: item.directory || "",
        status: "pending", // pending | downloading | processing | done | error
        job_id: null,
        percent: 0,
        speed: "",
        eta: "",
        message: ""
    });

    renderQueue();
    saveQueueToStorage();
}

function removeFromQueue(id) {
    queue = queue.filter((q) => q.id !== id);
    renderQueue();
    saveQueueToStorage();
}

function retryQueueItem(id) {
    const item = queue.find((q) => q.id === id);
    if (!item) return;

    item.status = "pending";
    item.job_id = null;
    item.percent = 0;
    item.speed = "";
    item.eta = "";
    item.message = "";

    renderQueue();
    saveQueueToStorage();

    if (!queueRunning) {
        processQueue();
    }
}

function clearCompletedItems() {
    queue = queue.filter((q) => q.status !== "done");
    renderQueue();
    saveQueueToStorage();
}

function renderQueue() {
    if (queue.length === 0) {
        hide(queueSection);
        queueList.innerHTML = "";
        return;
    }

    show(queueSection);
    queueList.innerHTML = "";

    queue.forEach((q) => {
        const row = document.createElement("div");
        row.className = `queue-item queue-${q.status}`;

        const badge = q.type === "audio" ? "MP3" : "MP4";
        const statusLabel = {
            pending: "Waiting",
            downloading: "Downloading...",
            processing: "Converting / merging...",
            done: "Done",
            error: "Failed"
        }[q.status];

        const showProgress = q.status === "downloading" || q.status === "processing";
        const pct = Math.max(0, Math.min(100, Math.round(q.percent || 0)));

        const progressHtml = showProgress ? `
            <div style="background:rgba(0,0,0,0.08);border-radius:4px;height:8px;overflow:hidden;margin-top:4px;">
                <div style="width:${pct}%;height:100%;background:currentColor;transition:width 0.3s;"></div>
            </div>
            <div style="font-size:0.85em;opacity:0.8;">
                ${pct}%${q.speed ? " · " + escapeHtml(q.speed) : ""}${q.eta ? " · ETA " + escapeHtml(q.eta) : ""}
            </div>
        ` : "";

        let actionsHtml = "";
        if (q.status === "pending" || q.status === "done") {
            actionsHtml = `<button class="queue-remove" data-action="remove" data-id="${q.id}">Complete</button>`;
        } else if (q.status === "error") {
            actionsHtml = `
                <button class="queue-remove" data-action="retry" data-id="${q.id}">Retry</button>
                <button class="queue-remove" data-action="remove" data-id="${q.id}">Remove</button>
            `;
        }

        row.innerHTML = `
            <div class="queue-main">
                <div class="queue-title">${escapeHtml(q.title)} <span class="badge">${badge}</span></div>
                <div class="queue-status">${statusLabel}${q.message ? " · " + escapeHtml(q.message) : ""}</div>
                ${progressHtml}
            </div>
            ${actionsHtml}
        `;

        queueList.appendChild(row);
    });

    queueList.querySelectorAll("[data-action='remove']").forEach((btn) => {
        btn.addEventListener("click", () => removeFromQueue(Number(btn.dataset.id)));
    });

    queueList.querySelectorAll("[data-action='retry']").forEach((btn) => {
        btn.addEventListener("click", () => retryQueueItem(Number(btn.dataset.id)));
    });

    const pending = queue.filter((q) => q.status === "pending").length;
    const active = queue.filter((q) => q.status === "downloading" || q.status === "processing").length;
    const done = queue.filter((q) => q.status === "done").length;
    const failed = queue.filter((q) => q.status === "error").length;

    queueSummary.textContent = `${queue.length} in queue · ${pending} waiting · ${done} done · ${failed} failed`;
    queueSummary.innerHTML += done > 0
        ? ` <button id="clearCompletedBtn" class="queue-remove" style="margin-left:8px;">Clear Completed</button>`
        : "";

    const clearBtn = document.getElementById("clearCompletedBtn");
    if (clearBtn) {
        clearBtn.addEventListener("click", clearCompletedItems);
    }

    startQueueBtn.disabled = queueRunning || (pending === 0 && active === 0);
}

startQueueBtn.addEventListener("click", processQueue);

async function pollJobUntilFinished(jobId, onProgress) {
    while (true) {
        const response = await fetch("api.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ action: "job_status", job_id: jobId })
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || "Could not check download status.");
        }

        onProgress(data);

        if (data.status === "done") return data;

        if (data.status === "error") {
            const detail = data.log_tail ? " (" + data.log_tail.split("\n").slice(-1)[0] + ")" : "";
            throw new Error((data.error || "Download failed.") + detail);
        }

        await sleep(1500);
    }
}

// Only items that are 'pending' get started fresh. A 'downloading'
// or 'processing' item (resumed after a reload) is polled without
// starting a new job. 'error' items are skipped entirely - they
// only run again via the explicit Retry button, so a batch of old
// failures never delays newly queued downloads.
async function processQueue() {
    if (queueRunning) return;

    queueRunning = true;
    startQueueBtn.disabled = true;
    startQueueBtn.textContent = "Downloading...";

    for (const q of queue) {
        if (q.status === "done" || q.status === "error") {
            continue;
        }

        try {
            if (!q.job_id || (q.status !== "downloading" && q.status !== "processing")) {
                q.status = "downloading";
                q.percent = 0;
                q.speed = "";
                q.eta = "";
                q.message = "";
                renderQueue();
                saveQueueToStorage();

                const startResponse = await fetch("api.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        action: "start_download",
                        url: q.url,
                        type: q.type,
                        format: q.format,
                        audio_quality: q.audio_quality,
                        directory: q.directory
                    })
                });

                const startData = await startResponse.json();

                if (!startData.success) {
                    throw new Error(startData.error || "Could not start download.");
                }

                q.job_id = startData.job_id;
                q.directory = startData.directory || q.directory;
                saveQueueToStorage();
            }

            await pollJobUntilFinished(q.job_id, (data) => {
                q.status = data.status;
                q.percent = data.percent ?? q.percent;
                q.speed = data.speed || "";
                q.eta = data.eta || "";
                renderQueue();
                saveQueueToStorage();
            });

            q.status = "done";
            q.message = "";
        } catch (error) {
            q.status = "error";
            q.message = error.message;
        }

        renderQueue();
        saveQueueToStorage();
    }

    queueRunning = false;
    startQueueBtn.textContent = "Download";
    renderQueue();
}

// ---------------------------------------------------------
// RESTORE QUEUE ON PAGE LOAD
// (only resumes jobs that were actually still running -
// pending/error items wait for an explicit user action)
// ---------------------------------------------------------

loadQueueFromStorage();
renderQueue();

if (queue.some((q) => q.status === "downloading" || q.status === "processing")) {
    processQueue();
}

