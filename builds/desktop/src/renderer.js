'use strict';

const APP_VERSION = '1.0.0';
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
const turntable      = $('turntable');
const vinylWrap      = $('vinyl-wrap');
const vinylLabel     = $('vinyl-label');
const tonearmEl      = $('tonearm');
const bgArt          = $('bgArt');
const eqBars         = $('eq-bars');
const liveBadge      = $('live-badge');
const upNext         = $('up-next');
const listenerCount  = $('listener-count');
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

  reconnectAudioChain();
}

function startPlayback() {
  createAudio();
  audio.src = `${STREAM_URL}?t=${Date.now()}`;
  setBuffering(true);
  setConnecting(true);
  updateTonearm();
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

function setPlayState(playing) {
  isPlaying = playing;
  const isLive = playing && !isBuffering;
  turntable.classList.toggle('is-playing', isLive);
  vinylWrap.classList.toggle('spinning', isLive);
  eqBars.classList.toggle('eq-active', isLive);
  liveBadge.style.display = isLive ? 'inline-flex' : 'none';
  updateTonearm();
  setConnecting(false);
}

function setBuffering(buffering) {
  isBuffering = buffering;
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

// ── Cover art ─────────────────────────────────────────────
function loadCoverArt(songId, hasCoverArt) {
  if (hasCoverArt && songId > 0) {
    const url = `${BASE_URL}/api/cover?id=${songId}&t=${Date.now()}`;
    const img = new Image();
    img.onload = () => {
      vinylLabel.innerHTML = '';
      vinylLabel.appendChild(img);
      bgArt.style.backgroundImage = `url(${url})`;
      bgArt.style.opacity = '1';
    };
    img.onerror = () => {
      vinylLabel.innerHTML = '';
      bgArt.style.opacity = '0';
      setTimeout(() => { bgArt.style.backgroundImage = ''; }, 800);
    };
    img.src = url;
    img.alt = 'Cover art';
  } else {
    vinylLabel.innerHTML = '';
    bgArt.style.opacity = '0';
    setTimeout(() => { bgArt.style.backgroundImage = ''; }, 800);
  }
}

// ── Tonearm ───────────────────────────────────────────────
function updateTonearm() {
  const active = isPlaying || isBuffering;
  if (!active || !startedAtMs || !durationMs) {
    tonearmEl.style.transform = 'rotate(-20deg) translateY(-2px)';
    return;
  }
  let elapsed = Date.now() - startedAtMs;
  if (elapsed < 0) elapsed = 0;
  if (elapsed > durationMs) elapsed = durationMs;
  const pct = elapsed / durationMs;
  const angle = pct * 20;
  tonearmEl.style.transform = `rotate(${angle}deg)`;
}

// Click turntable to play/stop
turntable.addEventListener('click', togglePlayback);

// ── Now playing ───────────────────────────────────────────
function handleNowPlaying(data) {
  const song = data.song;
  const nowPlayingWrap = $('now-playing-wrap');

  if (song) {
    const songChanged = song.id !== currentSongId;
    if (songChanged) {
      currentSongId = song.id || 0;
      loadCoverArt(song.id, song.has_cover_art);

      // Song change animation
      if (currentSongId > 0) {
        nowPlayingWrap.classList.remove('song-changing');
        void nowPlayingWrap.offsetWidth;
        nowPlayingWrap.classList.add('song-changing');
        nowPlayingWrap.addEventListener('animationend', function handler() {
          nowPlayingWrap.classList.remove('song-changing');
          nowPlayingWrap.removeEventListener('animationend', handler);
        });
      }
    }
    songTitle.textContent  = song.title  || '\u2014';
    songArtist.textContent = song.artist || '';
    durationMs   = song.duration_ms || 0;
    // Marquee for long titles
    requestAnimationFrame(() => {
      checkMarquee(songTitle);
      checkMarquee(songArtist);
    });
  } else {
    songTitle.textContent  = '\u2014';
    songArtist.textContent = '';
    durationMs   = 0;
    currentSongId = 0;
    loadCoverArt(0, false);
  }

  const startedAt = data.started_at || (song && song.started_at);
  if (startedAt) {
    startedAtMs = parseISO(startedAt);
  } else if (!song) {
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
  if (data.is_emergency) {
    requestBtn.textContent = 'Requests Unavailable';
  } else {
    requestBtn.textContent = 'Request a Song';
  }
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

// ── Marquee for long titles ──────────────────────────────
function checkMarquee(el) {
  const existing = el.querySelector('.marquee-inner');
  if (existing) return;
  if (el.scrollWidth > el.clientWidth) {
    const text = el.textContent;
    const span = document.createElement('span');
    span.className = 'marquee-inner';
    span.textContent = text + '\u00a0\u00a0\u00a0\u00a0\u00a0' + text;
    el.textContent = '';
    el.appendChild(span);
  }
}

// ── Recently played ─────────────────────────────────────
let historyLoaded = false;
let historyInterval = null;

$('history-toggle').addEventListener('click', () => {
  $('history').classList.toggle('open');
  if (!historyLoaded) {
    historyLoaded = true;
    fetchRecentPlays();
    historyInterval = setInterval(fetchRecentPlays, 60000);
  }
});

async function fetchRecentPlays() {
  try {
    const r = await fetch(`${BASE_URL}/api/recent-plays`);
    const data = await r.json();
    const list = $('history-list');
    if (!data.plays || !data.plays.length) {
      list.innerHTML = '<div class="history-item"><span class="hi-title" style="color:var(--text-dim)">No recent tracks</span></div>';
      return;
    }
    list.innerHTML = data.plays.map(p =>
      `<div class="history-item"><span class="hi-title">${esc(p.title)}</span><span class="hi-artist">${esc(p.artist)}</span></div>`
    ).join('');
  } catch (_) {}
}

// ── Time-of-day ambient color ───────────────────────────
function updateAmbient() {
  const h = new Date().getHours();
  let color;
  if (h >= 20 || h < 5) color = 'rgba(20,30,80,.15)';
  else if (h >= 5 && h < 11) color = 'rgba(80,60,20,.12)';
  else if (h >= 11 && h < 16) color = 'transparent';
  else color = 'rgba(80,40,30,.12)';
  document.documentElement.style.setProperty('--ambient', color);
}
updateAmbient();
setInterval(updateAmbient, 1800000);

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

// ── Update check ─────────────────────────────────────────
function compareVersions(a, b) {
  const pa = a.split('.').map(Number);
  const pb = b.split('.').map(Number);
  for (let i = 0; i < 3; i++) {
    const va = pa[i] || 0;
    const vb = pb[i] || 0;
    if (va < vb) return -1;
    if (va > vb) return 1;
  }
  return 0;
}

function showUpdateBanner(version, changelog) {
  let banner = $('update-banner');
  if (banner) return;
  banner = document.createElement('div');
  banner.id = 'update-banner';

  const text = document.createElement('div');
  text.className = 'update-text';
  text.innerHTML = `<strong>Update available: v${esc(version)}</strong>`;
  if (changelog) {
    const lines = changelog.split('\n').filter(l => l.trim());
    if (lines.length) {
      text.innerHTML += '<div class="update-changelog">' + lines.map(l => esc(l)).join('<br>') + '</div>';
    }
  }

  const dismiss = document.createElement('button');
  dismiss.className = 'update-dismiss';
  dismiss.textContent = '\u2715';
  dismiss.addEventListener('click', () => banner.remove());

  banner.appendChild(text);
  banner.appendChild(dismiss);

  const content = $('content');
  content.parentNode.insertBefore(banner, content);
}

async function checkForUpdate() {
  if (!window.electronAPI || !window.electronAPI.checkUpdate) return;
  try {
    const data = await window.electronAPI.checkUpdate(BASE_URL);
    if (!data || !data.version) return;
    if (compareVersions(APP_VERSION, data.version) < 0) {
      showUpdateBanner(data.version, data.changelog || '');
    }
  } catch (_) {}
}

// ── Schedule ──────────────────────────────────────────────
let scheduleData = null;
const DAY_NAMES = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

$('btn-schedule').addEventListener('click', openSchedule);
$('schedule-close').addEventListener('click', () => $('schedule-overlay').classList.remove('open'));
$('schedule-overlay').addEventListener('click', e => {
  if (e.target === $('schedule-overlay')) $('schedule-overlay').classList.remove('open');
});

async function openSchedule() {
  $('schedule-overlay').classList.add('open');
  if (!scheduleData) await fetchSchedule();
  renderSchedule(scheduleData || []);
}

async function fetchSchedule() {
  try {
    const r = await fetch(`${BASE_URL}/api/schedule`);
    if (r.ok) {
      const json = await r.json();
      scheduleData = json.schedules || [];
    }
  } catch (_) {}
}

function renderSchedule(schedules) {
  const now = new Date();
  const tz = 'Asia/Manila';
  const todayDow = new Date(now.toLocaleString('en-US', { timeZone: tz })).getDay();

  const byDay = {};
  for (let d = 0; d < 7; d++) byDay[d] = [];
  schedules.forEach(s => {
    if (s.days_of_week === null) {
      for (let d = 0; d < 7; d++) byDay[d].push(s);
    } else {
      s.days_of_week.forEach(d => { if (byDay[d]) byDay[d].push(s); });
    }
  });

  let html = '';
  if (schedules.length === 0) {
    html = '<div class="sched-empty">No schedules configured.</div>';
  } else {
    for (let i = 0; i < 7; i++) {
      const dow = (todayDow + i) % 7;
      const isToday = i === 0;
      const items = byDay[dow];
      html += `<div class="sched-day"><div class="sched-day-title">${DAY_NAMES[dow]}`;
      if (isToday) html += ' <span class="sched-today">TODAY</span>';
      html += '</div>';
      if (items.length === 0) {
        html += '<div class="sched-empty">No scheduled programs</div>';
      } else {
        items.forEach(s => {
          const color = esc(s.playlist_color || '#666');
          const showNow = isToday && isNowActive(s.start_time, s.end_time);
          html += '<div class="sched-block">';
          html += `<span class="sched-dot" style="background:${color}"></span>`;
          html += `<span class="sched-time">${fmtTime(s.start_time)} \u2013 ${fmtTime(s.end_time)}</span>`;
          html += `<span class="sched-name">${esc(s.name || s.playlist_name)}</span>`;
          if (showNow) html += '<span class="sched-now">NOW</span>';
          html += '</div>';
        });
      }
      html += '</div>';
    }
  }
  $('schedule-content').innerHTML = html;
}

function fmtTime(t) {
  if (!t) return '';
  const parts = t.split(':');
  let h = parseInt(parts[0], 10);
  const m = parts[1] || '00';
  const ampm = h >= 12 ? 'PM' : 'AM';
  if (h === 0) h = 12;
  else if (h > 12) h -= 12;
  return `${h}:${m} ${ampm}`;
}

function isNowActive(start, end) {
  const now = new Date();
  const tz = 'Asia/Manila';
  const nowStr = now.toLocaleString('en-US', { timeZone: tz, hour12: false, hour: '2-digit', minute: '2-digit' });
  const nowParts = nowStr.split(':');
  const nowMin = parseInt(nowParts[0], 10) * 60 + parseInt(nowParts[1], 10);
  const sParts = start.split(':');
  const sMin = parseInt(sParts[0], 10) * 60 + parseInt(sParts[1], 10);
  const eParts = end.split(':');
  let eMin = parseInt(eParts[0], 10) * 60 + parseInt(eParts[1], 10);
  if (eMin <= sMin) return nowMin >= sMin || nowMin < eMin;
  return nowMin >= sMin && nowMin < eMin;
}

// ── Equalizer ─────────────────────────────────────────────
const EQ_FREQS = [32, 64, 125, 250, 500, 1000, 2000, 4000, 8000, 16000];
const FREQ_LABELS = ['32','64','125','250','500','1K','2K','4K','8K','16K'];
const EQ_PRESETS = {
  flat:           [0,0,0,0,0,0,0,0,0,0],
  bass_boost:     [6,5,4,2,0,0,0,0,0,0],
  treble_boost:   [0,0,0,0,0,2,3,4,5,6],
  vocal:          [-2,-1,0,2,4,4,3,1,0,-1],
  rock:           [4,3,1,-1,-2,1,3,4,4,3],
  pop:            [-1,1,3,4,3,0,-1,-1,1,2],
  jazz:           [3,2,0,2,-2,-2,0,2,3,4],
  classical:      [4,3,2,1,0,0,0,1,2,3],
  loudness:       [6,4,0,0,-2,0,-1,-4,4,2],
  small_speakers: [5,4,3,1,0,1,2,3,3,2],
  earphones:      [-1,-1,0,1,2,2,1,0,-1,-2],
  headphones:     [2,1,0,0,-1,0,1,2,2,1]
};

let audioCtx = null;
let eqFilters = [];
let spatialGains = {};
let spatialDelays = {};
let eqInited = false;
let currentPreset = 'flat';
let currentBands = [0,0,0,0,0,0,0,0,0,0];
let currentSpatial = 'off';
let customBands = [0,0,0,0,0,0,0,0,0,0];
let eqSliderInputs = [];

// Load saved EQ settings
try {
  const saved = localStorage.getItem('rendezvox_eq');
  if (saved) {
    const d = JSON.parse(saved);
    currentPreset = d.preset || 'flat';
    currentSpatial = d.spatial || 'off';
    customBands = d.customBands || [0,0,0,0,0,0,0,0,0,0];
    currentBands = currentPreset === 'custom'
      ? customBands.slice()
      : (EQ_PRESETS[currentPreset] || EQ_PRESETS.flat).slice();
  }
} catch (_) {}

function saveEq() {
  try {
    localStorage.setItem('rendezvox_eq', JSON.stringify({
      preset: currentPreset, spatial: currentSpatial, customBands
    }));
  } catch (_) {}
}

function initAudioChain() {
  if (eqInited) {
    if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
    return;
  }
  if (!audio) return;
  try {
    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    let prev = audioCtx.createMediaElementSource(audio);

    eqFilters = [];
    EQ_FREQS.forEach((freq, i) => {
      const f = audioCtx.createBiquadFilter();
      f.type = 'peaking';
      f.frequency.value = freq;
      f.Q.value = 1.4;
      f.gain.value = currentBands[i] || 0;
      prev.connect(f);
      eqFilters.push(f);
      prev = f;
    });

    const splitter = audioCtx.createChannelSplitter(2);
    const merger = audioCtx.createChannelMerger(2);
    spatialGains.lMain = audioCtx.createGain();
    spatialGains.rMain = audioCtx.createGain();
    spatialGains.lToR = audioCtx.createGain();
    spatialGains.rToL = audioCtx.createGain();
    spatialDelays.lToR = audioCtx.createDelay(0.1);
    spatialDelays.rToL = audioCtx.createDelay(0.1);

    prev.connect(splitter);
    splitter.connect(spatialGains.lMain, 0);
    spatialGains.lMain.connect(merger, 0, 0);
    splitter.connect(spatialGains.rToL, 1);
    spatialGains.rToL.connect(spatialDelays.rToL);
    spatialDelays.rToL.connect(merger, 0, 0);
    splitter.connect(spatialGains.rMain, 1);
    spatialGains.rMain.connect(merger, 0, 1);
    splitter.connect(spatialGains.lToR, 0);
    spatialGains.lToR.connect(spatialDelays.lToR);
    spatialDelays.lToR.connect(merger, 0, 1);
    merger.connect(audioCtx.destination);

    applySpatial();
    eqInited = true;
  } catch (_) { audioCtx = null; }
}

function reconnectAudioChain() {
  if (!eqInited || !audioCtx || !audio) return;
  try {
    const newSource = audioCtx.createMediaElementSource(audio);
    newSource.connect(eqFilters[0]);
  } catch (_) {}
}

function applyBands() {
  eqFilters.forEach((f, i) => { f.gain.value = currentBands[i] || 0; });
}

function applySpatial() {
  const modes = {
    off:          { l: 1, r: 1, lr: 0, rl: 0, d: 0 },
    stereo_wide:  { l: 1.15, r: 1.15, lr: -0.25, rl: -0.25, d: 0.012 },
    surround:     { l: 1.3, r: 1.3, lr: -0.4, rl: -0.4, d: 0.018 },
    crossfeed:    { l: 0.85, r: 0.85, lr: 0.3, rl: 0.3, d: 0.004 }
  };
  const m = modes[currentSpatial] || modes.off;
  if (spatialGains.lMain) {
    spatialGains.lMain.gain.value = m.l;
    spatialGains.rMain.gain.value = m.r;
    spatialGains.lToR.gain.value = m.lr;
    spatialGains.rToL.gain.value = m.rl;
    spatialDelays.lToR.delayTime.value = m.d;
    spatialDelays.rToL.delayTime.value = m.d;
  }
}

function fmtEqVal(v) { return v > 0 ? '+' + v : '' + v; }

function buildEqSliders() {
  let html = '';
  FREQ_LABELS.forEach((label, i) => {
    html += `<div class="eq-band">`
      + `<div class="eq-band-val" id="eq-val-${i}">${fmtEqVal(currentBands[i])}</div>`
      + `<input type="range" min="-12" max="12" step="1" value="${currentBands[i]}" data-idx="${i}" orient="vertical">`
      + `<div class="eq-band-label">${label}</div></div>`;
  });
  $('eq-sliders').innerHTML = html;
  eqSliderInputs = Array.from($('eq-sliders').querySelectorAll('input[type="range"]'));
  eqSliderInputs.forEach(inp => {
    inp.addEventListener('input', function() {
      const idx = parseInt(this.dataset.idx, 10);
      currentBands[idx] = parseInt(this.value, 10);
      $(`eq-val-${idx}`).textContent = fmtEqVal(currentBands[idx]);
      currentPreset = 'custom';
      $('eq-preset').value = 'custom';
      customBands = currentBands.slice();
      applyBands();
      saveEq();
    });
  });
}

function updateEqSliders() {
  eqSliderInputs.forEach((inp, i) => {
    inp.value = currentBands[i];
    $(`eq-val-${i}`).textContent = fmtEqVal(currentBands[i]);
  });
}

// EQ modal handlers
$('btn-eq').addEventListener('click', () => {
  initAudioChain();
  if (!eqSliderInputs.length) buildEqSliders();
  updateEqSliders();
  $('eq-preset').value = currentPreset;
  $('eq-spatial').value = currentSpatial;
  if (!eqInited) {
    $('eq-unavailable').style.display = '';
    $('eq-controls').style.display = 'none';
  } else {
    $('eq-unavailable').style.display = 'none';
    $('eq-controls').style.display = '';
  }
  $('eq-overlay').classList.add('open');
});

$('eq-close').addEventListener('click', () => $('eq-overlay').classList.remove('open'));
$('eq-overlay').addEventListener('click', e => {
  if (e.target === $('eq-overlay')) $('eq-overlay').classList.remove('open');
});

$('eq-preset').addEventListener('change', function() {
  currentPreset = this.value;
  currentBands = currentPreset === 'custom'
    ? customBands.slice()
    : (EQ_PRESETS[currentPreset] || EQ_PRESETS.flat).slice();
  updateEqSliders();
  applyBands();
  saveEq();
});

$('eq-spatial').addEventListener('change', function() {
  currentSpatial = this.value;
  applySpatial();
  saveEq();
});

$('eq-reset').addEventListener('click', () => {
  currentPreset = 'flat';
  currentBands = EQ_PRESETS.flat.slice();
  currentSpatial = 'off';
  $('eq-preset').value = 'flat';
  $('eq-spatial').value = 'off';
  updateEqSliders();
  applyBands();
  applySpatial();
  saveEq();
});

// ── App start ────────────────────────────────────────────
let nowPlayingInterval = null;
let tonearmInterval = null;

async function startApp() {
  failCount = 0;
  $('offline-banner').classList.remove('visible');
  await fetchConfig();
  await fetchNowPlaying();
  connectSSE();
  nowPlayingInterval = setInterval(fetchNowPlaying, 30_000);
  listenerInterval = setInterval(fetchListeners, 15_000);
  tonearmInterval = setInterval(updateTonearm, 1000);
  fetchListeners();
  checkForUpdate();
}

// ── Page Visibility API ─────────────────────────────────
document.addEventListener('visibilitychange', () => {
  if (document.hidden) {
    if (nowPlayingInterval) { clearInterval(nowPlayingInterval); nowPlayingInterval = null; }
    if (listenerInterval) { clearInterval(listenerInterval); listenerInterval = null; }
    if (tonearmInterval) { clearInterval(tonearmInterval); tonearmInterval = null; }
    if (historyInterval) { clearInterval(historyInterval); historyInterval = null; }
    if (sseSource) { sseSource.close(); sseSource = null; }
  } else {
    fetchNowPlaying();
    fetchListeners();
    connectSSE();
    nowPlayingInterval = setInterval(fetchNowPlaying, 30_000);
    listenerInterval = setInterval(fetchListeners, 15_000);
    tonearmInterval = setInterval(updateTonearm, 1000);
    if (historyLoaded) historyInterval = setInterval(fetchRecentPlays, 60_000);
  }
});

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
