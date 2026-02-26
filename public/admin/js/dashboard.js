/* ============================================================
   RendezVox Admin — Dashboard
   ============================================================ */
var RendezVoxDashboard = (function() {

  var pollTimer    = null;
  var progressTimer = null;

  // SVG icons for the play/stop button
  var ICON_PLAY = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
  var ICON_STOP = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M6 6h12v12H6z"/></svg>';
  var lastCoverId = null;

  function init() {
    initTabs();

    // Init DJ Booth, stats polling, and mini-player integration
    initDJBooth();
    fetchStats();
    pollTimer = setInterval(fetchStats, 30000);

    // Register with mini-player for real-time updates (replaces connectSSE).
    // Mini-player is loaded asynchronously by nav.js, so wait for the ready event.
    if (window.RendezVoxMiniPlayer) {
      connectMiniPlayer();
    } else {
      document.addEventListener('miniplayer:ready', connectMiniPlayer);
    }

    document.getElementById('emergencyToggle').addEventListener('change', toggleEmergency);
    document.getElementById('streamToggle').addEventListener('click', toggleStreamBroadcast);
    document.getElementById('btnRefreshStats').addEventListener('click', loadLibraryStats);
    document.getElementById('autoApproveToggle').addEventListener('change', toggleSetting('request_auto_approve', 'autoApproveToggle', 'autoApproveStatus', 'Auto-approve'));
    document.getElementById('autoTagToggle').addEventListener('change', toggleSetting('auto_tag_enabled', 'autoTagToggle', 'autoTagStatus', 'Auto-tag'));
    document.getElementById('autoNormToggle').addEventListener('change', toggleSetting('auto_normalize_enabled', 'autoNormToggle', 'autoNormStatus', 'Auto-normalize'));
    document.getElementById('autoSyncToggle').addEventListener('change', toggleSetting('auto_library_sync_enabled', 'autoSyncToggle', 'autoSyncStatus', 'Auto-sync'));
    document.getElementById('autoDedupArtistsToggle').addEventListener('change', toggleSetting('auto_artist_dedup_enabled', 'autoDedupArtistsToggle', 'autoDedupArtistsStatus', 'Auto-dedup artists'));
    document.getElementById('autoDedupSongsToggle').addEventListener('change', toggleSetting('auto_dedup_songs_enabled', 'autoDedupSongsToggle', 'autoDedupSongsStatus', 'Auto-dedup songs'));
    document.getElementById('autoRenameToggle').addEventListener('change', toggleSetting('auto_rename_paths_enabled', 'autoRenameToggle', 'autoRenameStatus', 'Auto-rename'));
    loadLibraryStats();
    loadQuickSettings();

    // Load station timezone (auto-detected from server), then start clock
    RendezVoxAPI.getTimezone().then(function() {
      updateClock();
      setInterval(updateClock, 1000);
    });

    // Fetch weather from server-configured location, then every 30 minutes
    fetchWeather();
    setInterval(fetchWeather, 1800000);
  }

  // ── Tab switching ───────────────────────────────────

  function initTabs() {
    var tabs = document.querySelectorAll('#dashboardTabs .tab');
    tabs.forEach(function(tab) {
      tab.addEventListener('click', function() {
        switchTab(tab.getAttribute('data-tab'));
      });
    });
    var hash = location.hash.replace('#', '');
    if (hash && document.getElementById('tab-' + hash)) {
      switchTab(hash);
    }
  }

  function switchTab(name) {
    var panels = document.querySelectorAll('.tab-panel');
    var tabs = document.querySelectorAll('#dashboardTabs .tab');
    panels.forEach(function(p) { p.classList.remove('active'); });
    tabs.forEach(function(t) { t.classList.remove('active'); });
    var panel = document.getElementById('tab-' + name);
    if (panel) panel.classList.add('active');
    var tab = document.querySelector('#dashboardTabs .tab[data-tab="' + name + '"]');
    if (tab) tab.classList.add('active');
    history.replaceState(null, '', '#' + name);
  }

  // ── DJ Clock ─────────────────────────────────────────

  function updateClock() {
    var now = new Date();
    var opts = RendezVoxAPI.tzOpts();

    var h  = parseInt(now.toLocaleString('en-US', Object.assign({ hour: '2-digit', hour12: false }, opts)), 10);
    var m  = parseInt(now.toLocaleString('en-US', Object.assign({ minute: '2-digit' }, opts)), 10);
    var s  = parseInt(now.toLocaleString('en-US', Object.assign({ second: '2-digit' }, opts)), 10);

    // Convert to 12-hour format
    var period = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12;
    if (h12 === 0) h12 = 12;
    var hh = (h12 < 10 ? '0' : '') + h12;
    var mm = (m < 10 ? '0' : '') + m;
    var ss = (s < 10 ? '0' : '') + s;

    var timeEl = document.getElementById('djClockTime');
    if (timeEl) {
      timeEl.innerHTML = hh + ':' + mm +
        '<span class="dj-clock-seconds">:' + ss + '</span>' +
        '<span class="dj-clock-period">' + period + '</span>';
    }

    var dateStr = now.toLocaleDateString('en-US', Object.assign({
      month: 'long', day: 'numeric', year: 'numeric'
    }, opts));
    var tz = RendezVoxAPI.tz();
    var tzLabel = tz ? ' (' + tz.replace(/_/g, ' ') + ')' : '';
    var dateEl = document.getElementById('djClockDate');
    if (dateEl) {
      dateEl.textContent = dateStr + tzLabel;
    }
  }

  // ── Mini-player integration ─────────────────────────

  function connectMiniPlayer() {
    if (!window.RendezVoxMiniPlayer) return;
    RendezVoxMiniPlayer.onTrackChange(function(data) {
      if (data.song) {
        renderNowPlaying({
          song_id:     data.song.id,
          title:       data.song.title,
          artist:      data.song.artist,
          category:    data.song.category,
          playlist:    data.song.playlist,
          duration_ms: data.song.duration_ms,
          source:      data.song.source,
          started_at:  data.song.started_at,
          is_playing:  true
        });
        updateDJBooth({
          song_id:       data.song.id,
          title:         data.song.title,
          artist:        data.song.artist,
          duration_ms:   data.song.duration_ms,
          has_cover_art: data.song.has_cover_art,
          started_at:    data.song.started_at,
          is_playing:    true
        });
        renderEmergency(data.is_emergency);
      }
      renderUpNext(data.next_track);
      fetchStats();
    });
    RendezVoxMiniPlayer.onStateChange(function() {
      updatePlayBtn();
    });
    // Sync DJ Booth volume and play state now that mini-player is available
    var volSlider = document.getElementById('djVolume');
    if (volSlider) volSlider.value = RendezVoxMiniPlayer.getVolume();
    updatePlayBtn();

    // Auto-play stream on dashboard load (restores old autoPlayMonitor behaviour)
    if (!RendezVoxMiniPlayer.isStreaming()) {
      RendezVoxMiniPlayer.play();
    }
  }

  // ── DJ Booth ─────────────────────────────────────────

  function initDJBooth() {
    document.getElementById('djPlayBtn').addEventListener('click', function() {
      if (window.RendezVoxMiniPlayer) RendezVoxMiniPlayer.toggle();
    });
    document.getElementById('djSkipBtn').addEventListener('click', skipTrack);
    document.getElementById('djVolume').addEventListener('input', function() {
      if (window.RendezVoxMiniPlayer) RendezVoxMiniPlayer.setVolume(parseInt(this.value, 10));
    });
  }

  function updatePlayBtn() {
    var btn = document.getElementById('djPlayBtn');
    var streaming = window.RendezVoxMiniPlayer && RendezVoxMiniPlayer.isStreaming();
    if (streaming) {
      btn.innerHTML = ICON_STOP;
      btn.title     = 'Stop monitoring';
      btn.classList.add('active');
    } else {
      btn.innerHTML = ICON_PLAY;
      btn.title     = 'Monitor stream';
      btn.classList.remove('active');
    }
  }

  function skipTrack() {
    var btn = document.getElementById('djSkipBtn');
    btn.disabled = true;
    RendezVoxAPI.post('/admin/skip-track', {}).then(function() {
      showToast('Skipped to next track');
      if (window.RendezVoxMiniPlayer && RendezVoxMiniPlayer.isStreaming()) {
        RendezVoxMiniPlayer.stop();
        setTimeout(function() {
          RendezVoxMiniPlayer.play();
        }, 1500);
      }
      setTimeout(function() {
        btn.disabled = false;
        fetchStats();
      }, 2000);
    }).catch(function(err) {
      showToast((err && err.error) || 'Skip failed', 'error');
      btn.disabled = false;
    });
  }

  function setCoverArt(el, songId) {
    var label = document.getElementById('djCoverLabel');
    if (!label) return;
    if (songId === lastCoverId) return;
    lastCoverId = songId;
    if (!songId) {
      label.innerHTML = '';
      return;
    }
    var img = new Image();
    img.onload = function() { label.innerHTML = ''; label.appendChild(img); };
    img.onerror = function() { label.innerHTML = ''; };
    img.src = '/api/cover?id=' + songId;
    img.alt = 'Cover art';
  }

  function updateDJBooth(np) {
    var liveEl    = document.getElementById('djLive');
    var labelEl   = document.getElementById('djLiveLabel');
    var titleEl   = document.getElementById('djTitle');
    var artistEl  = document.getElementById('djArtist');
    var fillEl    = document.getElementById('djProgressFill');
    var elapsedEl = document.getElementById('djElapsed');
    var remEl     = document.getElementById('djRemaining');
    var coverEl   = document.getElementById('djCover');

    clearInterval(progressTimer);

    if (!np || !np.is_playing) {
      liveEl.classList.remove('on');
      labelEl.textContent  = 'IDLE';
      titleEl.textContent  = '— Idle —';
      artistEl.textContent = '';
      fillEl.style.width   = '0%';
      elapsedEl.textContent = '0:00';
      remEl.textContent     = '—';
      coverEl.classList.remove('spinning');
      setCoverArt(coverEl, null);
      return;
    }

    liveEl.classList.add('on');
    coverEl.classList.add('spinning');
    labelEl.textContent  = 'LIVE';
    titleEl.textContent  = np.title;
    artistEl.textContent = np.artist;
    setCoverArt(coverEl, np.has_cover_art ? np.song_id : null);

    var duration  = np.duration_ms || 0;
    var startedAt = np.started_at ? new Date(np.started_at).getTime() : Date.now();

    function tick() {
      var elapsedMs = Date.now() - startedAt;
      if (elapsedMs < 0)        elapsedMs = 0;
      if (elapsedMs > duration) elapsedMs = duration;

      var pct = duration > 0 ? (elapsedMs / duration * 100) : 0;
      fillEl.style.width    = Math.min(pct, 100).toFixed(2) + '%';
      elapsedEl.textContent = fmtMs(elapsedMs);
      var rem = duration - elapsedMs;
      remEl.textContent     = '\u2212' + fmtMs(rem > 0 ? rem : 0);
    }

    tick();
    progressTimer = setInterval(tick, 500);
  }

  function fmtMs(ms) {
    var s = Math.floor(ms / 1000);
    var m = Math.floor(s / 60);
    s = s % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  // ── Stream broadcast control ────────────────────────

  function toggleStreamBroadcast() {
    var btn = document.getElementById('streamToggle');
    btn.disabled = true;
    var isActive = btn.classList.contains('active');
    var action = isActive ? 'stop' : 'start';

    RendezVoxAPI.post('/admin/stream-control', { action: action })
      .then(function(data) {
        renderStreamStatus(data.stream_active);
        showToast(data.message, action === 'stop' ? 'error' : 'success');
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Failed to toggle stream', 'error');
      })
      .then(function() {
        btn.disabled = false;
      });
  }

  function renderStreamStatus(active) {
    var btn    = document.getElementById('streamToggle');
    var label  = document.getElementById('streamLabel');
    var status = document.getElementById('streamStatus');
    var icon   = document.getElementById('streamIcon');

    if (active) {
      btn.classList.add('active');
      icon.setAttribute('d', 'M6 6h12v12H6z');           // stop square
      label.textContent  = 'Stop Stream';
      status.textContent = 'Broadcasting';
      status.style.color = '#4ade80';
    } else {
      btn.classList.remove('active');
      icon.setAttribute('d', 'M8 5v14l11-7z');            // play triangle
      label.textContent  = 'Start Stream';
      status.textContent = 'Off Air';
      status.style.color = '#f87171';
    }
  }

  // ── Stats polling ─────────────────────────────────────

  function fetchStats() {
    RendezVoxAPI.get('/admin/stats/dashboard')
      .then(function(data) {
        renderNowPlaying(data.now_playing);
        renderUpNext(data.next_track);
        renderListeners(data.listeners_current, data.listeners_peak_today);
        renderEmergency(data.emergency_mode);
        renderStreamStatus(data.stream_active);
        renderRequests(data.pending_requests, data.approved_requests);
        renderRecentPlays(data.recent_plays);
        updateDJBooth(data.now_playing);
      })
      .catch(function(err) {
        console.error('Dashboard fetch error:', err);
      });
  }

  function renderNowPlaying(np) {
    var titleEl    = document.getElementById('npTitle');
    var artistEl   = document.getElementById('npArtist');
    var badgeEl    = document.getElementById('npBadge');
    var playlistEl = document.getElementById('npPlaylist');

    if (!np) {
      titleEl.textContent  = '— Idle —';
      artistEl.textContent = '';
      if (playlistEl) playlistEl.textContent = '';
      badgeEl.innerHTML    = '';
      return;
    }

    titleEl.textContent  = np.title;
    artistEl.textContent = np.artist;
    if (playlistEl) {
      var parts = [];
      if (np.playlist) parts.push(np.playlist);
      if (np.category) parts.push(np.category);
      playlistEl.textContent = parts.length ? parts.join(' \u00B7 ') : '';
    }
    badgeEl.innerHTML = sourceBadge(np.source);
  }

  function renderUpNext(nt) {
    var titleEl    = document.getElementById('ntTitle');
    var artistEl   = document.getElementById('ntArtist');
    var badgeEl    = document.getElementById('ntBadge');
    var playlistEl = document.getElementById('ntPlaylist');

    if (!nt) {
      titleEl.textContent  = '—';
      artistEl.textContent = '';
      if (playlistEl) playlistEl.textContent = '';
      badgeEl.innerHTML    = '';
      return;
    }

    titleEl.textContent  = nt.title;
    artistEl.textContent = nt.artist;
    if (playlistEl) {
      var parts = [];
      if (nt.playlist) parts.push(nt.playlist);
      if (nt.category) parts.push(nt.category);
      playlistEl.textContent = parts.length ? parts.join(' \u00B7 ') : '';
    }
    badgeEl.innerHTML = sourceBadge(nt.source);
  }

  function renderListeners(current, peak) {
    document.getElementById('listenerCount').textContent = current;
    document.getElementById('listenerPeak').textContent  = peak;
  }

  function renderEmergency(isActive) {
    var toggle = document.getElementById('emergencyToggle');
    var status = document.getElementById('emergencyStatus');

    toggle.checked     = isActive;
    status.textContent = isActive ? 'ACTIVE' : 'Inactive';
    status.style.color = isActive ? 'var(--danger)' : '';
  }

  function renderRequests(pending, approved) {
    document.getElementById('pendingRequests').textContent  = pending;
    document.getElementById('approvedRequests').textContent = approved;
  }

  // ── Library Overview ──────────────────────────────────

  function loadLibraryStats() {
    RendezVoxAPI.get('/admin/library-stats').then(function(data) {
      renderLibraryStats(data);
    }).catch(function() {
      var el = document.getElementById('libraryStats');
      if (el) el.innerHTML = '<span style="color:var(--text-dim)">Could not load library stats</span>';
    });
  }

  function renderLibraryStats(data) {
    var el = document.getElementById('libraryStats');
    if (!el) return;

    var durationHrs = Math.floor(data.total_duration_ms / 3600000);
    var durationMin = Math.floor((data.total_duration_ms % 3600000) / 60000);
    var durationStr = durationHrs > 0 ? durationHrs + 'h ' + durationMin + 'm' : durationMin + 'm';
    var diskStr = formatBytes(data.disk_bytes);

    var diskFreeStr = formatBytes(data.disk_free_bytes || 0);
    var diskTotalStr = formatBytes(data.disk_total_bytes || 0);

    var html = '';
    html += statBox(data.songs_active, 'Active Songs', data.songs_inactive > 0 ? data.songs_inactive + ' inactive' : '');
    html += statBox(data.artists, 'Artists', '');
    html += statBox(data.genres, 'Genres', '');
    html += statBox(durationStr, 'Total Duration', '');
    html += statBox(formatNumber(data.total_plays), 'Total Plays', '');
    html += statBox(diskStr, 'Disk Usage', diskFreeStr + ' free of ' + diskTotalStr);
    html += statBox(data.active_playlists, 'Playlists', '');
    html += statBox(data.active_schedules, 'Schedules', '');

    el.innerHTML = html;

    // Notices — always show all health indicators with links to act on them
    var notices = [
      { count: data.pending_imports || 0, label: 'Pending Uploads', href: '/admin/media.html#uploads' },
      { count: data.untagged || 0,        label: 'Untagged',        href: '/admin/media.html' },
      { count: data.unnormalized || 0,    label: 'Unnormalized' },
      { count: data.dup_songs || 0,       label: 'Duplicate Songs', href: '/admin/duplicates.html' },
      { count: data.dup_artists || 0,     label: 'Duplicate Artists',href: '/admin/duplicates.html' },
      { count: data.zero_byte_files || 0, label: 'Zero-byte Files', href: '/admin/duplicates.html' },
      { count: data.missing_files || 0,   label: 'Missing Files' },
      { count: data.songs_trashed || 0,   label: 'In Trash' },
    ];

    var wrap = document.getElementById('libraryNotices');
    var grid = document.getElementById('libraryNoticesGrid');
    var hasAny = notices.some(function(n) { return n.count > 0; });
    if (hasAny) {
      var nhtml = '';
      notices.forEach(function(n) {
        if (n.count === 0) return;
        var tag = n.href ? 'a' : 'div';
        var hrefAttr = n.href ? ' href="' + n.href + '"' : '';
        var style = n.href ? ' style="text-decoration:none;cursor:pointer"' : '';
        nhtml += '<' + tag + ' class="stat-box notice-box"' + hrefAttr + style + '>' +
          '<span class="stat-value">' + escHtml(String(n.count)) + '</span>' +
          '<span class="stat-label">' + escHtml(n.label) + '</span>' +
          '</' + tag + '>';
      });
      grid.innerHTML = nhtml;
      wrap.style.display = '';
    } else {
      wrap.style.display = 'none';
    }
  }

  function statBox(value, label, sub) {
    var html = '<div class="stat-box">';
    html += '<span class="stat-value">' + escHtml(String(value)) + '</span>';
    html += '<span class="stat-label">' + escHtml(label) + '</span>';
    if (sub) html += '<span class="stat-sub">' + escHtml(sub) + '</span>';
    html += '</div>';
    return html;
  }

  function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
  }

  function formatNumber(n) {
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
    return String(n);
  }

  function renderRecentPlays(plays) {
    var tbody = document.getElementById('recentPlays');

    if (!plays || plays.length === 0) {
      tbody.innerHTML = '<tr><td colspan="5" class="empty">No recent activity</td></tr>';
      return;
    }

    var html = '';
    plays.forEach(function(p) {
      var time = formatTime(p.ended_at);
      html += '<tr>' +
        '<td>' + time + '</td>' +
        '<td>' + escHtml(p.title) + '</td>' +
        '<td>' + escHtml(p.artist) + '</td>' +
        '<td>' + escHtml(p.category) + '</td>' +
        '<td>' + sourceBadge(p.source) + '</td>' +
        '</tr>';
    });

    tbody.innerHTML = html;
  }

  function toggleEmergency() {
    var enabled = document.getElementById('emergencyToggle').checked;

    RendezVoxAPI.post('/admin/toggle-emergency', { enabled: enabled })
      .then(function() {
        showToast(enabled ? 'Emergency mode ACTIVATED' : 'Emergency mode deactivated',
                  enabled ? 'error' : 'success');
        fetchStats();
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Failed to toggle emergency mode', 'error');
        document.getElementById('emergencyToggle').checked = !enabled;
      });
  }

  // ── Weather ─────────────────────────────────────────

  var WX_ICONS = {
    sun:       '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
    'cloud-sun':'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="M20 12h2"/><path d="m19.07 4.93-1.41 1.41"/><path d="M15.947 12.65a4 4 0 0 0-5.925-4.128"/><path d="M13 22H7a5 5 0 1 1 .9-9.908A6 6 0 0 1 17 13.83"/><path d="M17 17a3 3 0 1 0 0 6h3"/></svg>',
    cloud:     '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>',
    rain:      '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="M16 14v6"/><path d="M8 14v6"/><path d="M12 16v6"/></svg>',
    drizzle:   '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="M8 15v1"/><path d="M8 19v1"/><path d="M12 17v1"/><path d="M12 21v1"/><path d="M16 15v1"/><path d="M16 19v1"/></svg>',
    snow:      '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="M8 15h.01"/><path d="M8 19h.01"/><path d="M12 17h.01"/><path d="M12 21h.01"/><path d="M16 15h.01"/><path d="M16 19h.01"/></svg>',
    storm:     '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="M13 12l-3 5h4l-3 5"/></svg>',
    fog:       '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="M16 17H7"/><path d="M17 21H9"/></svg>',
    thermometer:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"/></svg>',
    droplet:   '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>',
    wind:      '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.7 7.7a2.5 2.5 0 1 1 1.8 4.3H2"/><path d="M9.6 4.6A2 2 0 1 1 11 8H2"/><path d="M12.6 19.4A2 2 0 1 0 14 16H2"/></svg>',
    pin:       '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>',
  };

  function wxItem(icon, value, tooltip) {
    return '<span class="wx-item">' + icon +
      '<span class="wx-val">' + escHtml(value) + '</span>' +
      '<span class="wx-tip">' + escHtml(tooltip) + '</span></span>';
  }

  function fetchWeather() {
    RendezVoxAPI.get('/weather').then(function(data) {
      var el = document.getElementById('djClockWeather');
      if (!el || !data || data.error) {
        if (el) el.classList.remove('visible');
        return;
      }
      var condIcon = WX_ICONS[data.icon] || WX_ICONS.cloud;
      el.innerHTML =
        wxItem(condIcon, '', data.description) +
        wxItem(WX_ICONS.thermometer, data.temperature + data.unit, 'Temperature') +
        wxItem(WX_ICONS.droplet, data.humidity + '%', 'Humidity') +
        wxItem(WX_ICONS.wind, data.wind_speed + ' ' + data.wind_unit, 'Wind speed') +
        wxItem(WX_ICONS.pin, data.location, data.location);
      el.classList.add('visible');
    }).catch(function() {
      var el = document.getElementById('djClockWeather');
      if (el) el.classList.remove('visible');
    });
  }

  // ── Quick Settings toggles ─────────────────────────────

  function loadQuickSettings() {
    RendezVoxAPI.get('/admin/settings').then(function(result) {
      var map = {};
      result.settings.forEach(function(s) { map[s.key] = s.value; });

      setToggle('autoApproveToggle', 'autoApproveStatus', map['request_auto_approve'] === 'true');
      setToggle('autoTagToggle', 'autoTagStatus', map['auto_tag_enabled'] === 'true');
      setToggle('autoNormToggle', 'autoNormStatus', map['auto_normalize_enabled'] === 'true');
      setToggle('autoSyncToggle', 'autoSyncStatus', map['auto_library_sync_enabled'] === 'true');
      setToggle('autoDedupArtistsToggle', 'autoDedupArtistsStatus', map['auto_artist_dedup_enabled'] === 'true');
      setToggle('autoDedupSongsToggle', 'autoDedupSongsStatus', map['auto_dedup_songs_enabled'] === 'true');
      setToggle('autoRenameToggle', 'autoRenameStatus', map['auto_rename_paths_enabled'] === 'true');
    }).catch(function() {});
  }

  function setToggle(inputId, statusId, active) {
    var el = document.getElementById(inputId);
    var st = document.getElementById(statusId);
    if (el) el.checked = active;
    if (st) {
      st.textContent = active ? 'On' : 'Off';
      st.style.color = active ? 'var(--success, #4ade80)' : '';
    }
  }

  function toggleSetting(settingKey, inputId, statusId, label) {
    return function() {
      var el = document.getElementById(inputId);
      var val = el.checked ? 'true' : 'false';

      RendezVoxAPI.put('/admin/settings/' + encodeURIComponent(settingKey), { value: val })
        .then(function() {
          setToggle(inputId, statusId, el.checked);
          showToast(label + ' ' + (el.checked ? 'enabled' : 'disabled'));
        })
        .catch(function(err) {
          el.checked = !el.checked;
          showToast((err && err.error) || 'Save failed', 'error');
        });
    };
  }

  // ── Helpers ──────────────────────────────────────────

  function sourceBadge(source) {
    var cls = 'badge-rotation';
    if (source === 'request')   cls = 'badge-request';
    if (source === 'emergency') cls = 'badge-emergency';
    if (source === 'manual')    cls = 'badge-pending';
    return '<span class="badge ' + cls + '">' + escHtml(source || 'rotation') + '</span>';
  }

  function formatTime(isoStr) {
    if (!isoStr) return '—';
    var d = new Date(isoStr);
    var opts = Object.assign({ hour: '2-digit', minute: '2-digit', second: '2-digit' }, RendezVoxAPI.tzOpts());
    return d.toLocaleTimeString([], opts);
  }

  function escHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  function showToast(msg, type) {
    var container = document.getElementById('toasts');
    var toast = document.createElement('div');
    toast.className = 'toast toast-' + (type || 'success');
    toast.textContent = msg;
    container.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 4000);
  }

  return { init: init };
})();
