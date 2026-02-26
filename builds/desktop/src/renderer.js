'use strict';

const DEFAULT_SERVER = 'https://radio.chadlinuxtech.net';
let BASE_URL = DEFAULT_SERVER;
let STREAM_URL = `${BASE_URL}/stream/live`;

// ── DOM refs ─────────────────────────────────────────────
const $  = id => document.getElementById(id);
const stationName    = $('station-name');
const stationTagline = $('station-tagline');
const songTitle      = $('song-title');
const songArtist     = $('song-artist');
const connectingMsg  = $('connecting-msg');
const connectingBar  = $('connecting-bar');
const coverArt       = $('cover-art');
const coverWrap      = $('cover-wrap');
const vinyl          = $('vinyl');
const upNext         = $('up-next');
const listenerCount  = $('listener-count');
const progressWrap   = $('progress-wrap');
const progressFill   = $('progress-fill');
const timeElapsed    = $('time-elapsed');
const timeRemain     = $('time-remain');
const playBtn        = $('play-btn');
const iconPlay       = $('icon-play');
const iconStop       = $('icon-stop');
const iconSpinner    = $('icon-spinner');
const volSlider      = $('volume-slider');
const volIcon        = $('vol-icon');
const dedicationCard = $('dedication-card');
const dedicationName = $('dedication-name');
const dedicationMsg  = $('dedication-message');
const requestBtn     = $('request-btn');

// ── Media keys ───────────────────────────────────────────
if (window.electronAPI) {
  window.electronAPI.onMediaKey(action => {
    if (action === 'toggle') togglePlayback();
    if (action === 'stop')   stopPlayback();
  });
}

// ── State ────────────────────────────────────────────────
let audio = null;
let isPlaying = false;
let isBuffering = false;
let currentSongId = 0;
let durationMs = 0;
let startedAtMs = 0;
let progressInterval = null;
let sseSource = null;
let listenerInterval = null;
let resolvedSong = null;
let searchTimeout = null;
let failCount = 0;
const OFFLINE_THRESHOLD = 3;

// ── Audio ─────────────────────────────────────────────────
function createAudio() {
  if (audio) {
    audio.pause();
    audio.src = '';
  }
  audio = new Audio();
  audio.volume = parseFloat(volSlider.value);
  audio.preload = 'none';

  audio.addEventListener('waiting',  () => setBuffering(true));
  audio.addEventListener('playing',  () => { setBuffering(false); setPlayState(true); });
  audio.addEventListener('pause',    () => setPlayState(false));
  audio.addEventListener('ended',    () => { setPlayState(false); setBuffering(false); });
  audio.addEventListener('error',    () => {
    setPlayState(false);
    setBuffering(false);
    if (isPlaying) setTimeout(() => startPlayback(), 5000);
  });
}

function startPlayback() {
  createAudio();
  audio.src = `${STREAM_URL}?t=${Date.now()}`;
  setBuffering(true);
  setConnecting(true);
  audio.play().catch(() => { setBuffering(false); setConnecting(false); });
}

function stopPlayback() {
  if (audio) {
    audio.pause();
    audio.src = '';
  }
  isPlaying = false;
  setPlayState(false);
  setBuffering(false);
  setConnecting(false);
}

function togglePlayback() {
  if (isPlaying || isBuffering) {
    stopPlayback();
  } else {
    startPlayback();
  }
}

// Use CSS classes instead of inline styles (CSP blocks inline style attrs)
function showIcon(which) {
  iconPlay.classList.remove('active');
  iconStop.classList.remove('active');
  iconSpinner.classList.remove('active');
  which.classList.add('active');
}

function setPlayState(playing) {
  isPlaying = playing;
  if (isBuffering) {
    showIcon(iconSpinner);
  } else if (playing) {
    showIcon(iconStop);
  } else {
    showIcon(iconPlay);
  }

  if (playing) {
    coverWrap.classList.add('spin');
  } else if (!isBuffering) {
    coverWrap.classList.remove('spin');
  }
  setConnecting(false);
}

function setBuffering(buffering) {
  isBuffering = buffering;
  if (buffering) {
    showIcon(iconSpinner);
  } else if (isPlaying) {
    showIcon(iconStop);
  } else {
    showIcon(iconPlay);
  }
}

function setConnecting(connecting) {
  connectingMsg.classList.toggle('visible', connecting);
  connectingBar.classList.toggle('visible', connecting);
  songTitle.classList.toggle('hidden', connecting);
  songArtist.classList.toggle('hidden', connecting);
}

// ── Volume ────────────────────────────────────────────────
volSlider.addEventListener('input', () => {
  const v = parseFloat(volSlider.value);
  if (audio) audio.volume = v;
  const muted = v === 0;
  volIcon.innerHTML = muted
    ? '<path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/>'
    : '<path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02z"/>';
});

playBtn.addEventListener('click', togglePlayback);

// ── Cover art ─────────────────────────────────────────────
function loadCoverArt(songId, hasCoverArt) {
  if (hasCoverArt && songId > 0) {
    const url = `${BASE_URL}/api/cover?id=${songId}&t=${Date.now()}`;
    const img = new Image();
    img.onload = () => {
      coverArt.src = url;
      coverArt.classList.add('visible');
    };
    img.onerror = () => {
      coverArt.classList.remove('visible');
    };
    img.src = url;
  } else {
    coverArt.classList.remove('visible');
  }
}

// ── Now playing ───────────────────────────────────────────
function handleNowPlaying(data) {
  const song = data.song;

  if (song) {
    if (song.id !== currentSongId) {
      currentSongId = song.id || 0;
      loadCoverArt(song.id, song.has_cover_art);
    }
    songTitle.textContent  = song.title  || '\u2014';
    songArtist.textContent = song.artist || '';
    durationMs   = song.duration_ms || 0;
  } else {
    songTitle.textContent  = '\u2014';
    songArtist.textContent = '';
    durationMs   = 0;
    currentSongId = 0;
    loadCoverArt(0, false);
  }

  if (data.started_at) {
    startedAtMs = parseISO(data.started_at);
  } else {
    startedAtMs = 0;
  }

  const next = data.next_track;
  upNext.textContent = (next && next.title)
    ? `${next.title}${next.artist ? ' \u2014 ' + next.artist : ''}`
    : '\u2014';

  // Dedication
  const req = data.request;
  if (req && (req.listener_name || req.message)) {
    dedicationName.textContent = req.listener_name || 'A listener';
    dedicationMsg.textContent  = req.message ? `\u201C${req.message}\u201D` : '';
    dedicationMsg.classList.toggle('hidden', !req.message);
    dedicationCard.classList.add('visible');
  } else {
    dedicationCard.classList.remove('visible');
  }

  // Emergency mode
  requestBtn.disabled = data.is_emergency || false;

  updateProgress();
}

// ── Progress ──────────────────────────────────────────────
function updateProgress() {
  if (progressInterval) clearInterval(progressInterval);

  if (!durationMs || !startedAtMs) {
    progressWrap.classList.remove('visible');
    return;
  }
  progressWrap.classList.add('visible');

  function tick() {
    const now     = Date.now();
    const elapsed = Math.max(0, Math.min(now - startedAtMs, durationMs));
    const remain  = Math.max(0, durationMs - elapsed);
    const pct     = durationMs > 0 ? (elapsed / durationMs) * 100 : 0;
    progressFill.style.width = pct.toFixed(2) + '%';
    timeElapsed.textContent  = formatMs(elapsed);
    timeRemain.textContent   = '-' + formatMs(remain);
  }

  tick();
  progressInterval = setInterval(tick, 1000);
}

function formatMs(ms) {
  const s   = Math.floor(ms / 1000);
  const min = Math.floor(s / 60);
  const sec = s % 60;
  return `${min}:${sec.toString().padStart(2, '0')}`;
}

function parseISO(str) {
  const d = new Date(str);
  return isNaN(d.getTime()) ? 0 : d.getTime();
}

// ── SSE ───────────────────────────────────────────────────
function connectSSE() {
  if (sseSource) { sseSource.close(); sseSource = null; }

  sseSource = new EventSource(`${BASE_URL}/api/sse/now-playing`);

  sseSource.addEventListener('now-playing', e => {
    try {
      handleNowPlaying(JSON.parse(e.data));
      markOnline();
    } catch (_) {}
  });

  sseSource.onerror = () => {
    sseSource.close();
    sseSource = null;
    markFailure();
    setTimeout(connectSSE, 5000);
  };
}

// ── Fetch helpers ────────────────────────────────────────
async function fetchNowPlaying() {
  try {
    const r = await fetch(`${BASE_URL}/api/now-playing`);
    if (r.ok) {
      handleNowPlaying(await r.json());
      markOnline();
    } else {
      markFailure();
    }
  } catch (_) {
    markFailure();
  }
}

async function fetchListeners() {
  try {
    const r = await fetch(`${BASE_URL}/stream/status-json.xsl`);
    if (!r.ok) return;
    const json = await r.json();
    const src  = json?.icestats?.source;
    let count  = 0;
    if (Array.isArray(src)) {
      const mount = src.find(s => s.listenurl?.includes('/live'));
      count = mount?.listeners ?? 0;
    } else if (src) {
      count = src.listeners ?? 0;
    }
    listenerCount.textContent = count;
  } catch (_) {}
}

async function fetchConfig() {
  try {
    const r = await fetch(`${BASE_URL}/api/config`, {cache: 'no-store'});
    if (r.ok) {
      const cfg = await r.json();
      stationName.textContent    = cfg.station_name || 'RendezVox';
      stationTagline.textContent = cfg.tagline       || 'Online Radio';
      document.title = cfg.station_name || 'RendezVox';
      applyAccentColor(cfg.accent_color || '#ff7800');
    }
  } catch (_) {}
}

function applyAccentColor(hex) {
  if (!/^#[0-9a-fA-F]{6}$/.test(hex)) return;
  const root = document.documentElement;
  root.style.setProperty('--accent', hex);
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  // Lighten by 18% for --accent-light
  const lr = Math.min(255, Math.round(r + (255 - r) * 0.18));
  const lg = Math.min(255, Math.round(g + (255 - g) * 0.18));
  const lb = Math.min(255, Math.round(b + (255 - b) * 0.18));
  root.style.setProperty('--accent-light', '#' + ((1 << 24) + (lr << 16) + (lg << 8) + lb).toString(16).slice(1));
  // Update dedication colors to match accent
  root.style.setProperty('--dedication-bg', `rgba(${r},${g},${b},0.10)`);
  root.style.setProperty('--dedication-border', `rgba(${r},${g},${b},0.28)`);
  // Lighten by 50% for dedication text
  const tr = Math.min(255, Math.round(r + (255 - r) * 0.5));
  const tg = Math.min(255, Math.round(g + (255 - g) * 0.5));
  const tb = Math.min(255, Math.round(b + (255 - b) * 0.5));
  root.style.setProperty('--dedication-text', '#' + ((1 << 24) + (tr << 16) + (tg << 8) + tb).toString(16).slice(1));
}

// ── Song Request Modal ────────────────────────────────────
requestBtn.addEventListener('click', openModal);
$('modal-close').addEventListener('click',  closeModal);
$('modal-cancel').addEventListener('click', closeModal);
$('modal-overlay').addEventListener('click', e => {
  if (e.target === $('modal-overlay')) closeModal();
});

function openModal() {
  resetModal();
  $('modal-overlay').classList.add('open');
  setTimeout(() => $('req-title').focus(), 100);
}
function closeModal() {
  $('modal-overlay').classList.remove('open');
}

// ── About modal ──
$('btn-about').addEventListener('click', () => {
  $('about-server-url').textContent = BASE_URL;
  $('about-overlay').classList.add('open');
});
$('about-close').addEventListener('click', () => $('about-overlay').classList.remove('open'));
$('about-overlay').addEventListener('click', e => {
  if (e.target === $('about-overlay')) $('about-overlay').classList.remove('open');
});
$('change-server').addEventListener('click', e => {
  e.preventDefault();
  $('about-overlay').classList.remove('open');
  showServerScreen();
});
function resetModal() {
  $('req-title').value   = '';
  $('req-artist').value  = '';
  $('req-name').value    = '';
  $('req-message').value = '';
  $('modal-status').textContent = '';
  $('modal-status').className   = '';
  $('resolved-indicator').classList.remove('visible');
  $('suggestions-list').innerHTML = '';
  $('modal-submit').disabled = false;
  $('modal-submit').innerHTML = 'Submit';
  resolvedSong = null;
}

$('req-title').addEventListener('input', () => {
  resolvedSong = null;
  $('resolved-indicator').classList.remove('visible');
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(doSearch, 350);
});
$('req-artist').addEventListener('input', () => {
  resolvedSong = null;
  $('resolved-indicator').classList.remove('visible');
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(doSearch, 350);
});

async function doSearch() {
  const title  = $('req-title').value.trim();
  const artist = $('req-artist').value.trim();
  if (title.length < 2 && artist.length < 2) {
    $('suggestions-list').innerHTML = '';
    resolvedSong = null;
    $('resolved-indicator').classList.remove('visible');
    return;
  }
  try {
    const params = [];
    if (title)  params.push(`title=${encodeURIComponent(title)}`);
    if (artist) params.push(`artist=${encodeURIComponent(artist)}`);
    let url = `${BASE_URL}/api/search-song?${params.join('&')}`;
    const r    = await fetch(url);
    const data = await r.json();

    if (data.resolved && data.songs?.length > 0) {
      selectSong(data.songs[0]);
    } else {
      renderSuggestions(data.songs || []);
    }
  } catch (_) {}
}

function renderSuggestions(songs) {
  const list = $('suggestions-list');
  list.innerHTML = '';
  songs.forEach(s => {
    const item = document.createElement('div');
    item.className = 'suggestion-item';
    item.innerHTML = `<div class="suggestion-title">${esc(s.title)}</div><div class="suggestion-artist">${esc(s.artist)}</div>`;
    item.addEventListener('click', () => selectSong(s));
    list.appendChild(item);
  });
}

function selectSong(song) {
  resolvedSong = song;
  $('req-title').value  = song.title;
  $('req-artist').value = song.artist;
  $('suggestions-list').innerHTML = '';
  $('resolved-text').textContent  = `${song.title} \u2014 ${song.artist}`;
  $('resolved-indicator').classList.add('visible');
}

$('modal-submit').addEventListener('click', async () => {
  const title  = $('req-title').value.trim();
  const artist = $('req-artist').value.trim();
  if (!title && !artist) {
    showStatus('Enter a song title or artist name', false);
    return;
  }

  const btn = $('modal-submit');
  btn.disabled = true;
  btn.innerHTML = '<div class="spinner"></div>';

  const body = {};
  if (title)  body.title = title;
  if (artist) body.artist = artist;
  const name = $('req-name').value.trim();
  const msg  = $('req-message').value.trim();
  if (name) body.listener_name = name;
  if (msg)  body.message = msg;

  try {
    const r    = await fetch(`${BASE_URL}/api/request`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
    const data = await r.json();

    if (r.ok && data.song) {
      showStatus(`Requested: ${data.song.title} \u2014 ${data.song.artist}`, true);
      setTimeout(closeModal, 2000);
    } else if (r.status === 422 && data.suggestions?.length) {
      showStatus('Multiple matches \u2014 please select one:', false);
      renderSuggestions(data.suggestions);
      btn.disabled = false;
      btn.innerHTML = 'Submit';
    } else {
      showStatus(data.error || 'Request failed', false);
      btn.disabled = false;
      btn.innerHTML = 'Submit';
    }
  } catch (_) {
    showStatus('Network error', false);
    btn.disabled = false;
    btn.innerHTML = 'Submit';
  }
});

function showStatus(msg, success) {
  const el = $('modal-status');
  el.textContent = msg;
  el.className   = success ? 'success' : 'error';
}

function esc(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// ── Autostart toggle ─────────────────────────────────────
const autostartToggle = $('autostart-toggle');
if (window.electronAPI) {
  window.electronAPI.getAutostart().then(enabled => {
    autostartToggle.checked = enabled;
  });
  autostartToggle.addEventListener('change', () => {
    window.electronAPI.setAutostart(autostartToggle.checked);
  });
}

// ── Offline tracking ─────────────────────────────────────
function markFailure() {
  failCount++;
  if (failCount >= OFFLINE_THRESHOLD) {
    $('offline-banner').classList.add('visible');
  }
}

function markOnline() {
  failCount = 0;
  $('offline-banner').classList.remove('visible');
}

// ── Server selection ─────────────────────────────────────
const serverRadios = document.querySelectorAll('input[name="server-choice"]');
const customUrlInput = $('custom-url');

serverRadios.forEach(r => r.addEventListener('change', () => {
  customUrlInput.classList.toggle('visible', r.value === 'custom' && r.checked);
}));

function showServerScreen() {
  stopPlayback();
  if (sseSource) { sseSource.close(); sseSource = null; }
  if (listenerInterval) { clearInterval(listenerInterval); listenerInterval = null; }
  $('server-error').textContent = '';
  $('app').classList.add('hidden');
  $('server-screen').classList.add('open');
}

async function connectToServer(url) {
  url = url.replace(/\/+$/, '');
  $('server-error').textContent = '';

  const btn = $('server-connect');
  btn.disabled = true;
  btn.textContent = 'Connecting…';

  try {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 5000);
    const r = await fetch(`${url}/api/config`, { signal: controller.signal, cache: 'no-store' });
    clearTimeout(timeout);

    if (!r.ok) throw new Error('Server returned an error');

    BASE_URL = url;
    STREAM_URL = `${BASE_URL}/stream/live`;
    localStorage.setItem('serverUrl', url);
    $('server-screen').classList.remove('open');
    $('app').classList.remove('hidden');
    startApp();
  } catch (e) {
    $('server-error').textContent = e.name === 'AbortError'
      ? 'Connection timed out — check the URL and try again'
      : 'Could not connect to server — check the URL and try again';
  } finally {
    btn.disabled = false;
    btn.textContent = 'Connect';
  }
}

$('server-connect').addEventListener('click', () => {
  const choice = document.querySelector('input[name="server-choice"]:checked').value;
  if (choice === 'official') {
    connectToServer(DEFAULT_SERVER);
  } else {
    const url = customUrlInput.value.trim();
    if (!url) {
      $('server-error').textContent = 'Please enter a server URL';
      return;
    }
    if (!/^https?:\/\/.+/i.test(url)) {
      $('server-error').textContent = 'URL must start with http:// or https://';
      return;
    }
    connectToServer(url);
  }
});

// ── App start ────────────────────────────────────────────
async function startApp() {
  failCount = 0;
  $('offline-banner').classList.remove('visible');
  await fetchConfig();
  await fetchNowPlaying();
  connectSSE();
  setInterval(fetchNowPlaying, 30_000);
  listenerInterval = setInterval(fetchListeners, 15_000);
  fetchListeners();
}

// ── Init ──────────────────────────────────────────────────
(function init() {
  const saved = localStorage.getItem('serverUrl');
  if (saved) {
    BASE_URL = saved;
    STREAM_URL = `${BASE_URL}/stream/live`;
    startApp();
  } else {
    $('app').classList.add('hidden');
    $('server-screen').classList.add('open');
  }
})();
