/* ============================================================
   RendezVox Admin — Analytics
   ============================================================ */
var RendezVoxAnalytics = (function() {

  // ── Color palette ──────────────────────────────────────
  var COLORS = [
    '#00c8a0', '#3b82f6', '#f59e0b', '#ef4444', '#a855f7',
    '#ec4899', '#06b6d4', '#22c55e', '#f97316', '#6366f1'
  ];
  var GRID = '#232326';
  var TEXT = '#71717a';
  var TEXT_LIGHT = '#a1a1aa';

  function colorAt(i) { return COLORS[i % COLORS.length]; }

  function init() {
    RendezVoxAPI.getTimezone(); // pre-load station timezone
    initTabs();
    loadListenerChart('24h');
    loadPopularSongs();
    loadPopularRequests();
    loadLibraryStats();

    document.getElementById('listenerRange').addEventListener('change', function() {
      loadListenerChart(this.value);
    });
  }

  // ── Tab switching ──────────────────────────────────────
  function initTabs() {
    var tabs = document.querySelectorAll('#analyticsTabs .tab');
    tabs.forEach(function(tab) {
      tab.addEventListener('click', function() {
        switchTab(tab.getAttribute('data-tab'));
      });
    });
    // Restore tab from URL hash
    var hash = location.hash.replace('#', '');
    if (hash && document.getElementById('tab-' + hash)) {
      switchTab(hash);
    }
  }

  function switchTab(name) {
    var panels = document.querySelectorAll('.tab-panel');
    var tabs = document.querySelectorAll('#analyticsTabs .tab');
    panels.forEach(function(p) { p.classList.remove('active'); });
    tabs.forEach(function(t) { t.classList.remove('active'); });
    var panel = document.getElementById('tab-' + name);
    if (panel) panel.classList.add('active');
    var tab = document.querySelector('#analyticsTabs .tab[data-tab="' + name + '"]');
    if (tab) tab.classList.add('active');
    history.replaceState(null, '', '#' + name);

    // Redraw charts after tab becomes visible (canvas needs non-zero dimensions)
    requestAnimationFrame(function() {
      if (name === 'listeners') loadListenerChart(document.getElementById('listenerRange').value);
      if (name === 'played') loadPopularSongs();
      if (name === 'requested') loadPopularRequests();
      if (name === 'stats') loadLibraryStats();
    });
  }

  // ── Listener line chart ──────────────────────────────

  function loadListenerChart(range) {
    RendezVoxAPI.get('/admin/stats/listeners?range=' + range).then(function(data) {
      var canvas = document.getElementById('listenerChart');
      var emptyEl = document.getElementById('listenerEmpty');

      if (!data.points || data.points.length === 0) {
        canvas.style.display = 'none';
        emptyEl.classList.remove('hidden');
        return;
      }

      canvas.style.display = '';
      emptyEl.classList.add('hidden');
      drawLineChart(canvas, data.points);
    });
  }

  function drawLineChart(canvas, points) {
    var dpr = window.devicePixelRatio || 1;
    var rect = canvas.getBoundingClientRect();
    canvas.width  = rect.width * dpr;
    canvas.height = rect.height * dpr;
    var ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);

    var w = rect.width;
    var h = rect.height;
    var pad = { top: 16, right: 16, bottom: 32, left: 44 };
    var cw = w - pad.left - pad.right;
    var ch = h - pad.top - pad.bottom;

    var maxVal = 1;
    points.forEach(function(p) { if (p.count > maxVal) maxVal = p.count; });
    maxVal = Math.ceil(maxVal * 1.15) || 1;

    ctx.clearRect(0, 0, w, h);

    // Grid lines
    ctx.lineWidth = 0.5;
    var gridLines = 4;
    for (var i = 0; i <= gridLines; i++) {
      var y = pad.top + (ch / gridLines) * i;
      ctx.strokeStyle = GRID;
      ctx.beginPath();
      ctx.moveTo(pad.left, y);
      ctx.lineTo(w - pad.right, y);
      ctx.stroke();

      var val = Math.round(maxVal - (maxVal / gridLines) * i);
      ctx.fillStyle = TEXT;
      ctx.font = '10px -apple-system, sans-serif';
      ctx.textAlign = 'right';
      ctx.fillText(val, pad.left - 6, y + 3);
    }

    // X labels
    var xStep = Math.max(1, Math.floor(points.length / 6));
    ctx.textAlign = 'center';
    for (var i = 0; i < points.length; i += xStep) {
      var x = pad.left + (i / (points.length - 1 || 1)) * cw;
      var d = new Date(points[i].timestamp);
      var label = d.toLocaleTimeString([], Object.assign({ hour: '2-digit', minute: '2-digit' }, RendezVoxAPI.tzOpts()));
      ctx.fillStyle = TEXT;
      ctx.fillText(label, x, h - 6);
    }

    // Compute points
    var coords = [];
    points.forEach(function(p, idx) {
      coords.push({
        x: pad.left + (idx / (points.length - 1 || 1)) * cw,
        y: pad.top + ch - (p.count / maxVal) * ch
      });
    });

    // Area gradient fill
    ctx.beginPath();
    ctx.moveTo(coords[0].x, coords[0].y);
    for (var i = 1; i < coords.length; i++) {
      ctx.lineTo(coords[i].x, coords[i].y);
    }
    ctx.lineTo(coords[coords.length - 1].x, pad.top + ch);
    ctx.lineTo(coords[0].x, pad.top + ch);
    ctx.closePath();
    var grad = ctx.createLinearGradient(0, pad.top, 0, pad.top + ch);
    grad.addColorStop(0, 'rgba(0, 200, 160, 0.25)');
    grad.addColorStop(1, 'rgba(0, 200, 160, 0.02)');
    ctx.fillStyle = grad;
    ctx.fill();

    // Line with glow
    ctx.shadowColor = 'rgba(0, 200, 160, 0.4)';
    ctx.shadowBlur = 8;
    ctx.strokeStyle = COLORS[0];
    ctx.lineWidth = 2.5;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
    ctx.beginPath();
    coords.forEach(function(c, idx) {
      if (idx === 0) ctx.moveTo(c.x, c.y);
      else ctx.lineTo(c.x, c.y);
    });
    ctx.stroke();
    ctx.shadowColor = 'transparent';
    ctx.shadowBlur = 0;

    // Dots at data points (only if few enough)
    if (coords.length <= 30) {
      coords.forEach(function(c) {
        ctx.beginPath();
        ctx.arc(c.x, c.y, 3, 0, Math.PI * 2);
        ctx.fillStyle = '#141416';
        ctx.fill();
        ctx.strokeStyle = COLORS[0];
        ctx.lineWidth = 2;
        ctx.stroke();
      });
    }
  }

  // ── Popular songs bar chart ──────────────────────────

  function loadPopularSongs() {
    RendezVoxAPI.get('/admin/stats/popular-songs?limit=15').then(function(data) {
      renderPopularTable(data.songs);
      drawBarChart(document.getElementById('popularChart'), data.songs, 'play_count');
    });
  }

  function renderPopularTable(songs) {
    var tbody = document.getElementById('popularTable');
    if (!songs || songs.length === 0) {
      tbody.innerHTML = '<tr><td colspan="5" class="empty">No play history</td></tr>';
      return;
    }

    var html = '';
    songs.forEach(function(s, i) {
      var dot = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + colorAt(i) + ';margin-right:6px;vertical-align:middle"></span>';
      html += '<tr>' +
        '<td>' + dot + (i + 1) + '</td>' +
        '<td>' + escHtml(s.title) + '</td>' +
        '<td>' + escHtml(s.artist) + '</td>' +
        '<td>' + escHtml(s.category) + '</td>' +
        '<td><strong>' + s.play_count + '</strong></td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
  }

  function drawBarChart(canvas, items, countKey) {
    if (!items || items.length === 0) return;

    var dpr = window.devicePixelRatio || 1;
    var rect = canvas.getBoundingClientRect();
    canvas.width  = rect.width * dpr;
    canvas.height = rect.height * dpr;
    var ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);

    var w = rect.width;
    var h = rect.height;
    var pad = { top: 16, right: 16, bottom: 64, left: 44 };
    var cw = w - pad.left - pad.right;
    var ch = h - pad.top - pad.bottom;

    var maxVal = 1;
    items.forEach(function(s) { if (s[countKey] > maxVal) maxVal = s[countKey]; });
    maxVal = Math.ceil(maxVal * 1.15) || 1;

    ctx.clearRect(0, 0, w, h);

    // Y-axis grid
    var gridLines = 4;
    for (var i = 0; i <= gridLines; i++) {
      var y = pad.top + (ch / gridLines) * i;
      ctx.strokeStyle = GRID;
      ctx.lineWidth = 0.5;
      ctx.beginPath();
      ctx.moveTo(pad.left, y);
      ctx.lineTo(w - pad.right, y);
      ctx.stroke();

      var val = Math.round(maxVal - (maxVal / gridLines) * i);
      ctx.fillStyle = TEXT;
      ctx.font = '10px -apple-system, sans-serif';
      ctx.textAlign = 'right';
      ctx.fillText(val, pad.left - 6, y + 3);
    }

    var slotW = cw / items.length;
    var barW = slotW * 0.6;
    var barGap = slotW * 0.2;

    items.forEach(function(s, i) {
      var x = pad.left + slotW * i + barGap;
      var barH = (s[countKey] / maxVal) * ch;
      var y = pad.top + ch - barH;
      var color = colorAt(i);

      // Bar gradient
      var grad = ctx.createLinearGradient(x, y, x, pad.top + ch);
      grad.addColorStop(0, color);
      grad.addColorStop(1, hexAlpha(color, 0.5));
      ctx.fillStyle = grad;

      // Rounded bar
      ctx.beginPath();
      var r = Math.min(4, barW / 2);
      if (barH > r * 2) {
        ctx.moveTo(x, y + r);
        ctx.arcTo(x, y, x + r, y, r);
        ctx.arcTo(x + barW, y, x + barW, y + r, r);
        ctx.lineTo(x + barW, pad.top + ch);
        ctx.lineTo(x, pad.top + ch);
      } else {
        ctx.rect(x, y, barW, barH);
      }
      ctx.closePath();
      ctx.fill();

      // Value on top
      ctx.fillStyle = TEXT_LIGHT;
      ctx.font = 'bold 10px -apple-system, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText(s[countKey], x + barW / 2, y - 6);

      // Rotated label
      ctx.fillStyle = TEXT;
      ctx.font = '9px -apple-system, sans-serif';
      ctx.save();
      ctx.translate(x + barW / 2, pad.top + ch + 8);
      ctx.rotate(-Math.PI / 5);
      var label = s.title.length > 16 ? s.title.substring(0, 16) + '...' : s.title;
      ctx.textAlign = 'right';
      ctx.fillText(label, 0, 0);
      ctx.restore();
    });
  }

  // ── Popular requests ─────────────────────────────────

  function loadPopularRequests() {
    RendezVoxAPI.get('/admin/stats/popular-requests?limit=15').then(function(data) {
      renderRequestedTable(data.songs);
      drawHorizontalBarChart(document.getElementById('requestChart'), data.songs);
    });
  }

  function renderRequestedTable(songs) {
    var tbody = document.getElementById('requestedTable');
    if (!songs || songs.length === 0) {
      tbody.innerHTML = '<tr><td colspan="5" class="empty">No requests yet</td></tr>';
      return;
    }

    var html = '';
    songs.forEach(function(s, i) {
      var dot = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + colorAt(i) + ';margin-right:6px;vertical-align:middle"></span>';
      html += '<tr>' +
        '<td>' + dot + (i + 1) + '</td>' +
        '<td>' + escHtml(s.title) + '</td>' +
        '<td>' + escHtml(s.artist) + '</td>' +
        '<td><strong>' + s.request_count + '</strong></td>' +
        '<td>' + s.played_count + '</td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
  }

  function drawHorizontalBarChart(canvas, items) {
    if (!canvas || !items || items.length === 0) {
      if (canvas) canvas.style.display = 'none';
      return;
    }
    canvas.style.display = '';

    var dpr = window.devicePixelRatio || 1;
    var rect = canvas.getBoundingClientRect();
    canvas.width  = rect.width * dpr;
    canvas.height = rect.height * dpr;
    var ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);

    var w = rect.width;
    var h = rect.height;
    var pad = { top: 8, right: 40, bottom: 8, left: 140 };
    var cw = w - pad.left - pad.right;
    var ch = h - pad.top - pad.bottom;

    var maxVal = 1;
    items.forEach(function(s) { if (s.request_count > maxVal) maxVal = s.request_count; });

    var barH = Math.min(24, (ch / items.length) * 0.7);
    var slotH = ch / items.length;

    ctx.clearRect(0, 0, w, h);

    items.forEach(function(s, i) {
      var y = pad.top + slotH * i + (slotH - barH) / 2;
      var barW = (s.request_count / maxVal) * cw;
      var color = colorAt(i);

      // Bar with gradient
      var grad = ctx.createLinearGradient(pad.left, 0, pad.left + barW, 0);
      grad.addColorStop(0, hexAlpha(color, 0.7));
      grad.addColorStop(1, color);
      ctx.fillStyle = grad;

      ctx.beginPath();
      var r = Math.min(3, barH / 2);
      ctx.moveTo(pad.left, y);
      ctx.lineTo(pad.left + barW - r, y);
      ctx.arcTo(pad.left + barW, y, pad.left + barW, y + r, r);
      ctx.arcTo(pad.left + barW, y + barH, pad.left + barW - r, y + barH, r);
      ctx.lineTo(pad.left, y + barH);
      ctx.closePath();
      ctx.fill();

      // Song label (left)
      ctx.fillStyle = TEXT_LIGHT;
      ctx.font = '11px -apple-system, sans-serif';
      ctx.textAlign = 'right';
      var label = s.title.length > 20 ? s.title.substring(0, 20) + '...' : s.title;
      ctx.fillText(label, pad.left - 8, y + barH / 2 + 4);

      // Count (right of bar)
      ctx.fillStyle = color;
      ctx.font = 'bold 11px -apple-system, sans-serif';
      ctx.textAlign = 'left';
      ctx.fillText(s.request_count, pad.left + barW + 6, y + barH / 2 + 4);
    });
  }

  // ── Library stats ──────────────────────────────────────

  function loadLibraryStats() {
    RendezVoxAPI.get('/admin/library-stats').then(function(data) {
      renderLibraryStats(data);
    }).catch(function() {
      var el = document.getElementById('libraryStatsGrid');
      if (el) el.innerHTML = '<div class="empty">Could not load library stats</div>';
    });
  }

  function renderLibraryStats(data) {
    var el = document.getElementById('libraryStatsGrid');
    if (!el) return;

    var html = '';

    // Row 1 — Song counts
    html += '<h3 style="color:var(--accent);font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin:0 0 8px">Songs</h3>';
    html += '<div class="stats-grid" style="margin-bottom:16px">';
    html += statBox(fmtNum(data.songs_active), 'Active Songs');
    html += statBox(fmtNum(data.songs_inactive), 'Inactive Songs');
    html += statBox(fmtNum(data.songs_trashed), 'Trashed Songs');
    html += statBox(fmtNum(data.total_plays), 'Total Plays');
    html += statBox(fmtDuration(data.total_duration_ms), 'Total Duration');
    html += '</div>';

    // Row 2 — Composition
    html += '<h3 style="color:var(--accent);font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin:0 0 8px">Composition</h3>';
    html += '<div class="stats-grid" style="margin-bottom:16px">';
    html += statBox(fmtNum(data.artists), 'Artists');
    html += statBox(fmtNum(data.genres), 'Genres');
    html += statBox(fmtNum(data.active_playlists), 'Playlists');
    html += statBox(fmtNum(data.active_schedules), 'Schedules');
    html += '</div>';

    // Row 3 — Disk Usage
    html += '<h3 style="color:var(--accent);font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin:0 0 8px">Disk Usage</h3>';
    html += '<div class="stats-grid" style="margin-bottom:16px">';
    html += statBox(fmtBytes(data.disk_bytes), 'Library Size');
    html += statBox(fmtBytes(data.disk_free_bytes || 0), 'Disk Free');
    html += statBox(fmtBytes(data.disk_total_bytes || 0), 'Disk Total');

    // Disk usage progress bar (as a stat box)
    var diskUsedPct = 0;
    if (data.disk_total_bytes > 0) {
      diskUsedPct = Math.round(((data.disk_total_bytes - (data.disk_free_bytes || 0)) / data.disk_total_bytes) * 100);
    }
    var barColor = diskUsedPct < 60 ? '#4ade80' : diskUsedPct < 85 ? '#facc15' : '#f87171';
    html += '<div class="stat-box">';
    html += '<span class="stat-value">' + diskUsedPct + '%</span>';
    html += '<span class="stat-label">Disk Used</span>';
    html += '<div class="disk-bar-track"><div class="disk-bar-fill" style="width:' + diskUsedPct + '%;background:' + barColor + '"></div></div>';
    html += '</div>';
    html += '</div>';

    // Row 4 — Health Indicators
    html += '<h3 style="color:var(--accent);font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin:0 0 8px">Health</h3>';
    html += '<div class="stats-grid">';
    html += healthBox(data.untagged || 0, 'Untagged Songs');
    html += healthBox(data.unnormalized || 0, 'Unnormalized Songs');
    html += healthBox(data.dup_artists || 0, 'Duplicate Artists');
    html += healthBox(data.missing_files || 0, 'Missing Files');
    html += healthBox(data.pending_imports || 0, 'Pending Imports');
    html += '</div>';

    el.innerHTML = html;
  }

  function statBox(value, label) {
    return '<div class="stat-box">' +
      '<span class="stat-value">' + escHtml(String(value)) + '</span>' +
      '<span class="stat-label">' + escHtml(label) + '</span>' +
      '</div>';
  }

  function healthBox(count, label) {
    var cls = count > 0 ? ' warn' : '';
    return '<div class="stat-box' + cls + '">' +
      '<span class="stat-value">' + fmtNum(count) + '</span>' +
      '<span class="stat-label">' + escHtml(label) + '</span>' +
      '</div>';
  }

  // ── Formatting helpers ─────────────────────────────────

  function fmtBytes(bytes) {
    if (!bytes || bytes === 0) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
  }

  function fmtDuration(ms) {
    if (!ms || ms <= 0) return '0m';
    var totalMin = Math.floor(ms / 60000);
    var days = Math.floor(totalMin / 1440);
    var hours = Math.floor((totalMin % 1440) / 60);
    var mins = totalMin % 60;
    var parts = [];
    if (days > 0) parts.push(days + 'd');
    if (hours > 0) parts.push(hours + 'h');
    if (mins > 0 || parts.length === 0) parts.push(mins + 'm');
    return parts.join(' ');
  }

  function fmtNum(n) {
    if (n === undefined || n === null) return '0';
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
    return String(n);
  }

  // ── Helpers ──────────────────────────────────────────

  function hexAlpha(hex, alpha) {
    var r = parseInt(hex.slice(1, 3), 16);
    var g = parseInt(hex.slice(3, 5), 16);
    var b = parseInt(hex.slice(5, 7), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
  }

  function escHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  return { init: init };
})();
