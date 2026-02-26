/* ============================================================
   RendezVox Admin — Persistent Mini-Player
   Streams /stream/live across all admin pages using sessionStorage.
   On dashboard, the bar is hidden (DJ Booth handles UI there).
   ============================================================ */
var RendezVoxMiniPlayer = (function() {

  var STREAM_URL   = '/stream/live';
  var SSE_URL      = '/api/sse/now-playing';
  var SK_PLAYING   = 'rvox_mp_playing';
  var SK_VOLUME    = 'rvox_mp_volume';
  var SK_MINIMIZED = 'rvox_mp_minimized';

  var audio    = null;
  var sse      = null;
  var barEl    = null;
  var expandEl = null;
  var playing  = false;
  var stopping = false;
  var isDashboard = false;

  var trackCbs = [];
  var stateCbs = [];

  var ICON_PLAY = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
  var ICON_STOP = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><rect x="6" y="6" width="12" height="12" rx="1"/></svg>';
  var ICON_VOL  = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 010 7.07"/></svg>';
  var ICON_MIN  = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>';
  var ICON_RADIO = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14"/><path d="M15.54 8.46a5 5 0 010 7.07"/></svg>';

  function init() {
    isDashboard = window.location.pathname === '/admin/dashboard';
    injectBar();
    createAudio();
    restoreState();
    connectSSE();

    // Close SSE immediately on page unload to free PHP-FPM worker
    window.addEventListener('beforeunload', function() {
      if (sse) { sse.close(); sse = null; }
    });
  }

  // ── DOM injection ─────────────────────────────────────

  function injectBar() {
    var app = document.querySelector('.app');
    if (!app) return;

    barEl = document.createElement('div');
    barEl.id = 'miniPlayer';
    barEl.className = 'mp-bar';
    barEl.innerHTML =
      '<div class="mp-live" id="mpLive">' +
        '<span class="mp-dot"></span>' +
        '<span id="mpLiveLabel">LIVE</span>' +
      '</div>' +
      '<div class="mp-info">' +
        '<span class="mp-title" id="mpTitle">Stream Idle</span>' +
        '<span class="mp-artist" id="mpArtist"></span>' +
      '</div>' +
      '<div class="mp-controls">' +
        '<button type="button" class="mp-btn" id="mpPlayBtn" title="Play stream">' + ICON_PLAY + '</button>' +
        '<div class="mp-vol">' +
          ICON_VOL +
          '<input type="range" id="mpVolume" min="0" max="100" value="80">' +
        '</div>' +
        '<button type="button" class="mp-btn mp-btn-min" id="mpMinBtn" title="Minimize">' + ICON_MIN + '</button>' +
      '</div>';
    app.appendChild(barEl);

    // Expand button (visible when minimized)
    expandEl = document.createElement('button');
    expandEl.type = 'button';
    expandEl.id = 'mpExpandBtn';
    expandEl.className = 'mp-expand-btn';
    expandEl.title = 'Show player';
    expandEl.innerHTML = ICON_RADIO;
    app.appendChild(expandEl);

    // Hide bar + expand button on dashboard (DJ Booth handles UI there)
    if (isDashboard) {
      barEl.style.display = 'none';
      expandEl.style.display = 'none';
    }

    // Events
    document.getElementById('mpPlayBtn').addEventListener('click', toggle);
    document.getElementById('mpVolume').addEventListener('input', onVolumeInput);
    document.getElementById('mpMinBtn').addEventListener('click', minimize);
    expandEl.addEventListener('click', expand);

    // Add bottom padding to .main so content isn't hidden behind the bar
    if (!isDashboard) {
      var mainEl = document.querySelector('.main');
      if (mainEl) mainEl.style.paddingBottom = '64px';
    }
  }

  // ── Audio ─────────────────────────────────────────────

  function createAudio() {
    audio = document.createElement('audio');
    audio.preload = 'none';
    document.body.appendChild(audio);

    audio.addEventListener('playing', function() {
      playing = true;
      stopping = false;
      sessionStorage.setItem(SK_PLAYING, 'true');
      updateBtn();
      updateLive(true);
      fireStateCbs();
    });
    audio.addEventListener('pause', function() {
      if (!stopping) {
        playing = false;
        // Don't clear sessionStorage here — only stop() clears intent
      }
      updateBtn();
      fireStateCbs();
    });
    audio.addEventListener('ended', function() {
      playing = false;
      updateBtn();
      updateLive(false);
      fireStateCbs();
    });
    audio.addEventListener('error', function() {
      if (stopping) { stopping = false; return; }
      playing = false;
      updateBtn();
      updateLive(false);
      fireStateCbs();
    });
  }

  function play() {
    if (!audio) return;
    audio.src = STREAM_URL + '?_=' + Date.now();
    audio.play().catch(function() {
      // Autoplay may be blocked on new page load — keep intent in
      // sessionStorage so we retry on next user gesture.
      playing = false;
      updateBtn();
    });
  }

  function stop() {
    if (!audio) return;
    stopping = true;
    audio.pause();
    audio.removeAttribute('src');
    audio.load();
    playing = false;
    sessionStorage.setItem(SK_PLAYING, 'false');
    updateBtn();
    updateLive(false);
    fireStateCbs();
  }

  function toggle() {
    if (playing) { stop(); } else { play(); }
  }

  function onVolumeInput() {
    var v = parseInt(document.getElementById('mpVolume').value, 10);
    if (audio) audio.volume = v / 100;
    sessionStorage.setItem(SK_VOLUME, String(v));
  }

  function setVolume(v) {
    v = Math.max(0, Math.min(100, v));
    if (audio) audio.volume = v / 100;
    var slider = document.getElementById('mpVolume');
    if (slider) slider.value = v;
    sessionStorage.setItem(SK_VOLUME, String(v));
  }

  function restoreState() {
    // Restore minimized state
    if (sessionStorage.getItem(SK_MINIMIZED) === 'true' && barEl) {
      barEl.classList.add('mp-minimized');
    }

    var savedVol = sessionStorage.getItem(SK_VOLUME);
    var vol = savedVol !== null ? parseInt(savedVol, 10) : 80;
    if (isNaN(vol)) vol = 80;
    var slider = document.getElementById('mpVolume');
    if (slider) slider.value = vol;
    if (audio) audio.volume = vol / 100;

    if (sessionStorage.getItem(SK_PLAYING) === 'true') {
      play();
      // If browser blocks autoplay, retry on first user interaction
      var gestureResume = function() {
        document.removeEventListener('click', gestureResume, true);
        document.removeEventListener('keydown', gestureResume, true);
        if (sessionStorage.getItem(SK_PLAYING) === 'true' && !playing) {
          play();
        }
      };
      document.addEventListener('click', gestureResume, true);
      document.addEventListener('keydown', gestureResume, true);
    }
  }

  // ── UI updates ────────────────────────────────────────

  function updateBtn() {
    var btn = document.getElementById('mpPlayBtn');
    if (!btn) return;
    btn.innerHTML = playing ? ICON_STOP : ICON_PLAY;
    btn.title = playing ? 'Stop stream' : 'Play stream';
    btn.classList.toggle('active', playing);
  }

  function updateLive(on) {
    var el = document.getElementById('mpLive');
    if (el) el.classList.toggle('on', on);
  }

  function minimize() {
    if (barEl) barEl.classList.add('mp-minimized');
    sessionStorage.setItem(SK_MINIMIZED, 'true');
  }

  function expand() {
    if (barEl) barEl.classList.remove('mp-minimized');
    sessionStorage.setItem(SK_MINIMIZED, 'false');
  }

  // ── SSE ───────────────────────────────────────────────

  function connectSSE() {
    if (typeof EventSource === 'undefined') return;
    sse = new EventSource(SSE_URL);

    sse.addEventListener('now-playing', function(e) {
      try {
        var data = JSON.parse(e.data);
        // Update mini-player track info
        if (data.song) {
          var t = document.getElementById('mpTitle');
          var a = document.getElementById('mpArtist');
          if (t) t.textContent = data.song.title || 'Stream Idle';
          if (a) a.textContent = data.song.artist || '';
        }
        // Fire registered callbacks (dashboard uses this)
        for (var i = 0; i < trackCbs.length; i++) {
          try { trackCbs[i](data); } catch (err) {}
        }
      } catch (err) {}
    });
  }

  function fireStateCbs() {
    for (var i = 0; i < stateCbs.length; i++) {
      try { stateCbs[i](playing); } catch (err) {}
    }
  }

  // ── Public API ────────────────────────────────────────

  function onTrackChange(fn) { trackCbs.push(fn); }
  function onStateChange(fn) { stateCbs.push(fn); }
  function isStreaming() { return playing; }
  function getAudio() { return audio; }
  function getVolume() {
    var s = sessionStorage.getItem(SK_VOLUME);
    return s !== null ? parseInt(s, 10) : 80;
  }

  return {
    init: init,
    play: play,
    stop: stop,
    toggle: toggle,
    setVolume: setVolume,
    getVolume: getVolume,
    isStreaming: isStreaming,
    getAudio: getAudio,
    onTrackChange: onTrackChange,
    onStateChange: onStateChange
  };
})();
