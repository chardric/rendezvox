/* ============================================================
   RendezVox Admin — Schedule Manager (Drag-and-Drop Calendar)
   ============================================================ */
var RendezVoxSchedules = (function() {

  var DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  var HOUR_H   = 60;  // pixels per hour
  var SNAP_MIN = 15;  // snap to 15-minute increments
  var DEFAULT_COLOR = '#ff7800';

  var schedules = [];
  var playlists = [];
  var playlistMap = {}; // id -> playlist

  // ── Clipboard state (for copy/paste) ────────────────
  var clipboard = null; // { playlist_id, start_time, end_time, priority }

  // ── Drag state ──────────────────────────────────────
  var dragState = null; // { type: 'create'|'move'|'resize-top'|'resize-bottom', ... }

  // ── Palette drag state (HTML5 DnD + touch) ────────────────
  var paletteDragData = null; // { playlistId, color, name }
  var paletteDragging = false;

  // ── Touch drag state ──────────────────────────────
  var touchGhost = null;    // floating clone element
  var touchScrollRAF = 0;   // requestAnimationFrame ID for edge-scroll
  var touchDragTimer = 0;   // long-press timer for block move
  var touchStartPos = null; // { x, y } at touchstart

  // ── Bulk operation guard (pauses auto-refresh) ────
  var bulkBusy = false;

  // ── Special playlists config (loaded from settings) ──
  var specialIds = [];
  var specialSlots = [];

  // ── Multi-select state ────────────────────────────
  var selectedIds = {}; // schedule IDs selected for bulk delete

  // ── Day index conversion helpers ─────────────────────
  // Calendar columns: 0=Mon, 1=Tue, ..., 6=Sun
  // DB days_of_week:  0=Sun, 1=Mon, ..., 6=Sat  (JS getDay() convention)
  function colToDow(col) { return (col + 1) % 7; }
  function dowToCol(dow) { return (dow + 6) % 7; }

  // ── Timezone helpers ────────────────────────────────
  function getCurrentDay() {
    var now = new Date();
    var opts = RendezVoxAPI.tzOpts();
    var dayName = now.toLocaleDateString('en-US', Object.assign({ weekday: 'short' }, opts));
    var map = { Mon: 0, Tue: 1, Wed: 2, Thu: 3, Fri: 4, Sat: 5, Sun: 6 };
    return map[dayName] !== undefined ? map[dayName] : (now.getDay() === 0 ? 6 : now.getDay() - 1);
  }

  function getStationTime() {
    var now = new Date();
    var opts = RendezVoxAPI.tzOpts();
    var h = parseInt(now.toLocaleString('en-US', Object.assign({ hour: '2-digit', hour12: false }, opts)), 10);
    var m = parseInt(now.toLocaleString('en-US', Object.assign({ minute: '2-digit' }, opts)), 10);
    return { h: h, m: m };
  }

  function getStationDateStr() {
    var now = new Date();
    var opts = RendezVoxAPI.tzOpts();
    var y = now.toLocaleString('en-US', Object.assign({ year: 'numeric' }, opts));
    var m = now.toLocaleString('en-US', Object.assign({ month: '2-digit' }, opts));
    var d = now.toLocaleString('en-US', Object.assign({ day: '2-digit' }, opts));
    return y + '-' + m + '-' + d;
  }

  function isScheduleActiveNow(s) {
    if (!s.is_active || !s.playlist_active) return false;
    var today = getCurrentDay();
    if (s.days_of_week !== null && s.days_of_week.indexOf(colToDow(today)) === -1) return false;
    var todayStr = getStationDateStr();
    if (s.start_date && todayStr < s.start_date) return false;
    if (s.end_date && todayStr > s.end_date) return false;
    var st = getStationTime();
    var nowMin = st.h * 60 + st.m;
    var start = parseTime(s.start_time);
    var end = parseTime(s.end_time);
    return nowMin >= start.h * 60 + start.m && nowMin < end.h * 60 + end.m;
  }

  function parseTime(str) {
    var parts = str.split(':');
    return { h: parseInt(parts[0]), m: parseInt(parts[1] || '0') };
  }

  function formatTime12(str) {
    var parts = str.substring(0, 5).split(':');
    var h = parseInt(parts[0]);
    var m = parts[1];
    var suffix = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12 || 12;
    return h12 + ':' + m + ' ' + suffix;
  }

  function formatTimeHHMM(totalMin) {
    if (totalMin === 1440) return '24:00';
    var h = Math.floor(totalMin / 60) % 24;
    var m = totalMin % 60;
    return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
  }

  function snapMinutes(min) {
    return Math.round(min / SNAP_MIN) * SNAP_MIN;
  }

  // ── Init ────────────────────────────────────────────
  function loadSpecialConfig() {
    RendezVoxAPI.get('/admin/settings').then(function(result) {
      (result.settings || []).forEach(function(s) {
        if (s.key === 'schedule_special_playlists') {
          try { specialIds = JSON.parse(s.value || '[]'); } catch(e) { specialIds = []; }
        }
        if (s.key === 'schedule_special_slots') {
          try { specialSlots = JSON.parse(s.value || '[]'); } catch(e) { specialSlots = []; }
        }
      });
      renderCalendar();
    });
  }

  function isSpecialSchedule(s) {
    return s.priority === 99;
  }

  function init() {
    RendezVoxAPI.getTimezone();
    loadPlaylists();
    loadSchedules();
    loadSpecialConfig();

    // Global mouse events for drag operations
    document.addEventListener('mousemove', onMouseMove);
    document.addEventListener('mouseup', onMouseUp);

    // Global touch events for calendar block drag
    document.addEventListener('touchmove', onCalBlockTouchMove, { passive: false });
    document.addEventListener('touchend', onCalBlockTouchEnd);
    document.addEventListener('touchcancel', onCalBlockTouchEnd);

    // Context menu for right-click on calendar blocks
    createContextMenu();
    document.addEventListener('click', hideContextMenu);
    document.addEventListener('touchstart', function(e) {
      if (ctxMenu && ctxMenu.style.display !== 'none' && !ctxMenu.contains(e.target)) {
        hideContextMenu();
      }
    }, { passive: true });
    document.addEventListener('contextmenu', function(e) {
      // Suppress native context menu during touch drag/resize
      if (dragState || touchStartPos || paletteDragging) {
        e.preventDefault();
        return;
      }
      var block = e.target.closest('.cal-block');
      if (block) {
        e.preventDefault();
        showContextMenu(e.clientX, e.clientY, parseInt(block.getAttribute('data-schedule-id')));
      } else {
        var col = e.target.closest('.cal-day-col');
        if (col && clipboard !== null) {
          e.preventDefault();
          var dayIndex = parseInt(col.getAttribute('data-day'));
          showContextMenu(e.clientX, e.clientY, null, dayIndex);
        } else {
          hideContextMenu();
        }
      }
    });

    // Surprise Me! button + modal
    document.getElementById('btnSurpriseSchedule').addEventListener('click', openSurpriseScheduleModal);
    document.getElementById('btnCancelSurpriseSchedule').addEventListener('click', function() {
      document.getElementById('surpriseScheduleModal').classList.add('hidden');
    });
    document.getElementById('btnGoSurpriseSchedule').addEventListener('click', handleSurpriseScheduleGo);
    document.getElementById('btnClearSchedules').addEventListener('click', handleClearSchedules);
    document.getElementById('btnDeleteSelected').addEventListener('click', deleteSelected);

    // Escape clears selection
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && Object.keys(selectedIds).length > 0) {
        clearSelection();
      }
    });

    // No auto-refresh — user actions already reload data where needed
  }

  /**
   * Tell the streaming engine to check for schedule changes.
   * Only skips the current track if a different playlist should be playing.
   */
  function notifyStreamReload() {
    RendezVoxAPI.post('/admin/schedules/reload', {}).catch(function() {});
  }

  function loadPlaylists() {
    RendezVoxAPI.get('/admin/playlists').then(function(data) {
      playlists = (data.playlists || []).filter(function(p) { return p.type !== 'emergency'; });
      playlistMap = {};
      playlists.forEach(function(p) { playlistMap[p.id] = p; });
      renderPalette();
    });
  }

  function loadSchedules() {
    RendezVoxAPI.get('/admin/schedules').then(function(data) {
      schedules = data.schedules;
      renderCalendar();
    });
  }

  // ── Playlist palette ───────────────────────────────
  function renderPalette() {
    if (paletteDragging) return; // Don't rebuild while user is dragging
    var container = document.getElementById('paletteChips');
    if (!container) return;
    var html = '';
    playlists.forEach(function(p) {
      if (!p.is_active) return;
      var color = p.color || DEFAULT_COLOR;
      html += '<div class="palette-chip" draggable="true" ' +
        'data-playlist-id="' + p.id + '" ' +
        'style="background:' + escHtml(color) + '">' +
        escHtml(p.name) + '</div>';
    });
    container.innerHTML = html;
    container.querySelectorAll('.palette-chip').forEach(function(chip) {
      chip.addEventListener('dragstart', onPaletteDragStart);
      chip.addEventListener('dragend', onPaletteDragEnd);
      chip.addEventListener('touchstart', onPaletteTouchStart, { passive: false });
    });
  }

  function onPaletteDragStart(e) {
    var chip = e.target.closest('.palette-chip');
    if (!chip) return;
    var playlistId = parseInt(chip.getAttribute('data-playlist-id'));
    var p = playlistMap[playlistId];
    if (!p) return;

    paletteDragging = true;
    paletteDragData = { playlistId: p.id, color: p.color || DEFAULT_COLOR, name: p.name };

    e.dataTransfer.effectAllowed = 'copy';
    e.dataTransfer.setData('text/plain', String(p.id));
    chip.classList.add('dragging');

    // Custom drag image
    var ghost = document.createElement('div');
    ghost.style.cssText = 'position:absolute;top:-1000px;left:-1000px;' +
      'padding:4px 10px;border-radius:5px;font-size:.75rem;font-weight:600;' +
      'color:#fff;white-space:nowrap;background:' + paletteDragData.color;
    ghost.textContent = p.name;
    document.body.appendChild(ghost);
    e.dataTransfer.setDragImage(ghost, 0, 0);
    setTimeout(function() { ghost.remove(); }, 0);
  }

  function onPaletteDragEnd() {
    paletteDragging = false;
    paletteDragData = null;
    document.querySelectorAll('.palette-chip.dragging').forEach(function(c) {
      c.classList.remove('dragging');
    });
    document.querySelectorAll('.cal-day-col.drag-over').forEach(function(col) {
      col.classList.remove('drag-over');
    });
    document.querySelectorAll('.cal-preview').forEach(function(p) {
      p.style.display = 'none';
    });
  }

  function onCalendarDragOver(e) {
    if (!paletteDragData) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';

    var col = e.target.closest('.cal-day-col');
    if (!col) return;
    col.classList.add('drag-over');

    // Auto-scroll near edges
    var calWrap = document.querySelector('.cal-wrap');
    if (calWrap) {
      var wrapRect = calWrap.getBoundingClientRect();
      var EDGE = 40, SPEED = 8;
      if (e.clientY - wrapRect.top < EDGE) calWrap.scrollTop -= SPEED;
      else if (wrapRect.bottom - e.clientY < EDGE) calWrap.scrollTop += SPEED;
    }

    // Show colored preview
    var colRect = col.getBoundingClientRect();
    var yInCol = e.clientY - colRect.top;
    var startMin = snapMinutes(Math.floor((yInCol / HOUR_H) * 60));
    startMin = Math.max(0, Math.min(startMin, 23 * 60));
    var endMin = Math.min(startMin + 60, 24 * 60);

    var preview = col.querySelector('.cal-preview');
    if (preview) {
      preview.style.display = 'block';
      preview.style.top = (startMin / 60) * HOUR_H + 'px';
      preview.style.height = ((endMin - startMin) / 60) * HOUR_H + 'px';
      preview.style.background = hexToRgba(paletteDragData.color, 0.3);
      preview.style.borderColor = paletteDragData.color;
      preview.style.color = '#fff';
      preview.textContent = paletteDragData.name + '  ' +
        formatTimeHHMM(startMin) + ' – ' + formatTimeHHMM(endMin);
    }
  }

  function onCalendarDragLeave(e) {
    var col = e.target.closest('.cal-day-col');
    if (!col) return;
    var related = e.relatedTarget;
    if (related && col.contains(related)) return;
    col.classList.remove('drag-over');
    var preview = col.querySelector('.cal-preview');
    if (preview) preview.style.display = 'none';
  }

  function onCalendarDrop(e) {
    e.preventDefault();
    if (!paletteDragData) return;

    var col = e.target.closest('.cal-day-col');
    if (!col) return;

    var day = parseInt(col.getAttribute('data-day'));
    var colRect = col.getBoundingClientRect();
    var yInCol = e.clientY - colRect.top;
    var startMin = snapMinutes(Math.floor((yInCol / HOUR_H) * 60));
    startMin = Math.max(0, Math.min(startMin, 23 * 60));
    var endMin = Math.min(startMin + 60, 24 * 60);

    var body = {
      playlist_id: paletteDragData.playlistId,
      days_of_week: [colToDow(day)],
      start_date: null,
      end_date: null,
      start_time: formatTimeHHMM(startMin),
      end_time: formatTimeHHMM(endMin),
      priority: 0,
      is_active: true
    };

    // Clean up
    col.classList.remove('drag-over');
    var preview = col.querySelector('.cal-preview');
    if (preview) preview.style.display = 'none';
    paletteDragging = false;
    paletteDragData = null;

    RendezVoxAPI.post('/admin/schedules', body)
      .then(function() {
        showToast('Schedule created');
        loadSchedules();
        notifyStreamReload();
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Create failed', 'error');
      });
  }

  // ── Touch drag support (mobile) ─────────────────────
  function onPaletteTouchStart(e) {
    var chip = e.target.closest('.palette-chip');
    if (!chip) return;
    var playlistId = parseInt(chip.getAttribute('data-playlist-id'));
    var p = playlistMap[playlistId];
    if (!p) return;

    e.preventDefault();
    paletteDragging = true;
    paletteDragData = { playlistId: p.id, color: p.color || DEFAULT_COLOR, name: p.name };
    chip.classList.add('dragging');

    // Create floating ghost
    touchGhost = document.createElement('div');
    touchGhost.style.cssText = 'position:fixed;z-index:9999;pointer-events:none;' +
      'padding:4px 10px;border-radius:5px;font-size:.75rem;font-weight:600;' +
      'color:#fff;white-space:nowrap;background:' + paletteDragData.color +
      ';box-shadow:0 2px 8px rgba(0,0,0,.3);transform:translate(-50%,-120%)';
    touchGhost.textContent = p.name;
    document.body.appendChild(touchGhost);

    var touch = e.touches[0];
    touchGhost.style.left = touch.clientX + 'px';
    touchGhost.style.top = touch.clientY + 'px';

    document.addEventListener('touchmove', onPaletteTouchMove, { passive: false });
    document.addEventListener('touchend', onPaletteTouchEnd);
    document.addEventListener('touchcancel', onPaletteTouchEnd);
  }

  function onPaletteTouchMove(e) {
    if (!paletteDragData) return;
    e.preventDefault();

    var touch = e.touches[0];
    if (touchGhost) {
      touchGhost.style.left = touch.clientX + 'px';
      touchGhost.style.top = touch.clientY + 'px';
    }

    // Find calendar column under finger
    var col = getColumnAtPoint(touch.clientX, touch.clientY);

    // Clear previous highlights
    document.querySelectorAll('.cal-day-col.drag-over').forEach(function(c) {
      if (c !== col) {
        c.classList.remove('drag-over');
        var pv = c.querySelector('.cal-preview');
        if (pv) pv.style.display = 'none';
      }
    });

    if (!col) return;
    col.classList.add('drag-over');

    // Auto-scroll near edges
    var calWrap = document.querySelector('.cal-wrap');
    if (calWrap) {
      var wrapRect = calWrap.getBoundingClientRect();
      var EDGE = 40, SPEED = 8;
      if (touch.clientY - wrapRect.top < EDGE) calWrap.scrollTop -= SPEED;
      else if (wrapRect.bottom - touch.clientY < EDGE) calWrap.scrollTop += SPEED;
    }

    // Show preview
    var colRect = col.getBoundingClientRect();
    var yInCol = touch.clientY - colRect.top;
    var startMin = snapMinutes(Math.floor((yInCol / HOUR_H) * 60));
    startMin = Math.max(0, Math.min(startMin, 23 * 60));
    var endMin = Math.min(startMin + 60, 24 * 60);

    var preview = col.querySelector('.cal-preview');
    if (preview) {
      preview.style.display = 'block';
      preview.style.top = (startMin / 60) * HOUR_H + 'px';
      preview.style.height = ((endMin - startMin) / 60) * HOUR_H + 'px';
      preview.style.background = hexToRgba(paletteDragData.color, 0.3);
      preview.style.borderColor = paletteDragData.color;
      preview.style.color = '#fff';
      preview.textContent = paletteDragData.name + '  ' +
        formatTimeHHMM(startMin) + ' – ' + formatTimeHHMM(endMin);
    }
  }

  function onPaletteTouchEnd(e) {
    document.removeEventListener('touchmove', onPaletteTouchMove);
    document.removeEventListener('touchend', onPaletteTouchEnd);
    document.removeEventListener('touchcancel', onPaletteTouchEnd);

    if (touchGhost) { touchGhost.remove(); touchGhost = null; }

    if (!paletteDragData) return;

    // Find column under final touch point
    var touch = (e.changedTouches && e.changedTouches[0]) || null;
    var col = touch ? getColumnAtPoint(touch.clientX, touch.clientY) : null;

    if (col) {
      var day = parseInt(col.getAttribute('data-day'));
      var colRect = col.getBoundingClientRect();
      var yInCol = touch.clientY - colRect.top;
      var startMin = snapMinutes(Math.floor((yInCol / HOUR_H) * 60));
      startMin = Math.max(0, Math.min(startMin, 23 * 60));
      var endMin = Math.min(startMin + 60, 24 * 60);

      var body = {
        playlist_id: paletteDragData.playlistId,
        days_of_week: [colToDow(day)],
        start_date: null,
        end_date: null,
        start_time: formatTimeHHMM(startMin),
        end_time: formatTimeHHMM(endMin),
        priority: 0,
        is_active: true
      };

      RendezVoxAPI.post('/admin/schedules', body)
        .then(function() {
          showToast('Schedule created');
          loadSchedules();
          notifyStreamReload();
        })
        .catch(function(err) {
          showToast((err && err.error) || 'Create failed', 'error');
        });
    }

    // Clean up
    onPaletteDragEnd();
  }

  function getColumnAtPoint(x, y) {
    var cols = document.querySelectorAll('.cal-day-col');
    for (var i = 0; i < cols.length; i++) {
      var rect = cols[i].getBoundingClientRect();
      if (x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom) {
        return cols[i];
      }
    }
    return null;
  }

  var MONTHS_FULL = ['January','February','March','April','May','June','July','August','September','October','November','December'];

  /** Get array of Date objects for Mon–Sun of the current week (station tz). */
  function getWeekDates() {
    var now = new Date();
    var opts = RendezVoxAPI.tzOpts();
    // Get current date parts in station timezone
    var y = parseInt(now.toLocaleString('en-US', Object.assign({ year: 'numeric' }, opts)));
    var m = parseInt(now.toLocaleString('en-US', Object.assign({ month: 'numeric' }, opts))) - 1;
    var d = parseInt(now.toLocaleString('en-US', Object.assign({ day: 'numeric' }, opts)));
    var local = new Date(y, m, d);
    // JS getDay: 0=Sun, convert to Mon=0
    var jsDay = local.getDay();
    var mondayOffset = jsDay === 0 ? -6 : 1 - jsDay;
    var monday = new Date(local);
    monday.setDate(local.getDate() + mondayOffset);
    var dates = [];
    for (var i = 0; i < 7; i++) {
      var dt = new Date(monday);
      dt.setDate(monday.getDate() + i);
      dates.push(dt);
    }
    return dates;
  }

  // ── Calendar rendering ──────────────────────────────
  function renderCalendar() {
    var grid = document.getElementById('calGrid');
    var today = getCurrentDay();
    var st = getStationTime();
    var weekDates = getWeekDates();

    // Month/year label
    var monthLabel = document.getElementById('calMonthLabel');
    if (monthLabel) {
      var firstMonth = weekDates[0].getMonth();
      var lastMonth = weekDates[6].getMonth();
      var year = weekDates[6].getFullYear();
      if (firstMonth === lastMonth) {
        monthLabel.textContent = MONTHS_FULL[firstMonth] + ' ' + year;
      } else {
        monthLabel.textContent = MONTHS_FULL[firstMonth] + ' – ' + MONTHS_FULL[lastMonth] + ' ' + year;
      }
    }

    var html = '';

    // Header row
    html += '<div class="cal-header"></div>';
    DAYS.forEach(function(d, i) {
      var dd = weekDates[i].getDate();
      html += '<div class="cal-header' + (i === today ? ' today' : '') + '">' +
        '<span class="cal-day-name">' + d + '</span>' +
        '<span class="cal-day-date">' + dd + '</span>' +
        '</div>';
    });

    // Time labels
    html += '<div class="cal-times">';
    for (var h = 0; h < 24; h++) {
      var suffix = h >= 12 ? 'PM' : 'AM';
      var h12 = h % 12 || 12;
      html += '<div class="cal-time-label">' + h12 + ' ' + suffix + '</div>';
    }
    html += '</div>';

    // Day columns
    for (var d = 0; d < 7; d++) {
      html += '<div class="cal-day-col' + (d === today ? ' today' : '') + '" data-day="' + d + '">';

      // Hour lines
      for (var h2 = 0; h2 < 24; h2++) {
        html += '<div class="cal-hour-line" style="top:' + (h2 * HOUR_H) + 'px"></div>';
        html += '<div class="cal-half-line" style="top:' + (h2 * HOUR_H + HOUR_H / 2) + 'px"></div>';
      }
      html += '<div class="cal-hour-line" style="top:' + (24 * HOUR_H) + 'px"></div>';

      // Schedule blocks for this day
      schedules.forEach(function(s) {
        if (!s.playlist_active) return;
        if (s.days_of_week !== null && s.days_of_week.indexOf(colToDow(d)) === -1) return;

        var start = parseTime(s.start_time);
        var end = parseTime(s.end_time);
        var startMin = start.h * 60 + start.m;
        var endMin = end.h * 60 + end.m;
        if (endMin <= startMin) endMin += 24 * 60;

        var topPx = (startMin / 60) * HOUR_H;
        var heightPx = ((endMin - startMin) / 60) * HOUR_H;
        if (heightPx < 15) heightPx = 15;

        var color = s.playlist_color || playlistColorFallback(s.playlist_id);
        var isNow = d === today && isScheduleActiveNow(s);
        var opacity = s.is_active ? 1 : 0.4;

        var displayH = Math.max(15, heightPx - 4); // 4px gap between adjacent blocks
        var selClass = selectedIds[s.id] ? ' selected' : '';
        var isLocked = isSpecialSchedule(s);
        html += '<div class="cal-block' + (isNow ? ' now' : '') + selClass + (isLocked ? ' locked' : '') + '" ' +
          'data-schedule-id="' + s.id + '" data-day="' + d + '" ' +
          'style="top:' + topPx + 'px;height:' + displayH + 'px;' +
          'background:' + hexToRgba(color, 0.85) + ';' +
          'border-left:3px solid ' + color + ';' +
          'opacity:' + opacity + '">';
        if (!isLocked) html += '<div class="resize-top"></div>';
        html += '<div class="block-title">' + escHtml(s.playlist_name) +
          (isLocked ? ' <svg viewBox="0 0 16 16" width="10" height="10" fill="currentColor" style="opacity:.7;vertical-align:-1px"><path d="M12 7h-1V5a3 3 0 00-6 0v2H4a1 1 0 00-1 1v5a1 1 0 001 1h8a1 1 0 001-1V8a1 1 0 00-1-1zM6 5a2 2 0 014 0v2H6V5z"/></svg>' : '') +
          '</div>';
        if (displayH >= 32) {
          html += '<div class="block-time">' + formatTime12(s.start_time) + ' – ' + formatTime12(s.end_time) + '</div>';
        }
        if (!isLocked) html += '<div class="resize-bottom"></div>';
        html += '</div>';
      });

      // Now line
      if (d === today) {
        var nowPx = ((st.h * 60 + st.m) / 60) * HOUR_H;
        html += '<div class="cal-now-line" style="top:' + nowPx + 'px"></div>';
      }

      // Drag preview element
      html += '<div class="cal-preview" data-day="' + d + '"></div>';

      html += '</div>';
    }

    grid.innerHTML = html;

    // Attach mousedown + touchstart listeners to day columns and blocks
    var cols = grid.querySelectorAll('.cal-day-col');
    cols.forEach(function(col) {
      col.addEventListener('mousedown', onDayMouseDown);
      col.addEventListener('touchstart', onCalBlockTouchStart, { passive: false });
      // HTML5 DnD for palette drops
      col.addEventListener('dragover', onCalendarDragOver);
      col.addEventListener('dragleave', onCalendarDragLeave);
      col.addEventListener('drop', onCalendarDrop);
    });

    // Scroll to current hour on first render
    var calWrap = document.querySelector('.cal-wrap');
    if (calWrap && !calWrap._scrolled) {
      var scrollTarget = Math.max(0, ((st.h - 1) * HOUR_H));
      calWrap.scrollTop = scrollTarget;
      calWrap._scrolled = true;
    }

    updateSelectionUI();
  }

  function playlistColorFallback(playlistId) {
    var p = playlistMap[playlistId];
    return (p && p.color) || DEFAULT_COLOR;
  }

  function hexToRgba(hex, alpha) {
    hex = hex.replace('#', '');
    var r = parseInt(hex.substring(0, 2), 16);
    var g = parseInt(hex.substring(2, 4), 16);
    var b = parseInt(hex.substring(4, 6), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
  }

  // ── Mouse event handlers for drag ──────────────────
  function onDayMouseDown(e) {
    // Ignore right-clicks
    if (e.button !== 0) return;

    var block = e.target.closest('.cal-block');
    var col = e.target.closest('.cal-day-col');
    if (!col) return;

    var day = parseInt(col.getAttribute('data-day'));
    var rect = col.getBoundingClientRect();

    if (block) {
      var schedId = parseInt(block.getAttribute('data-schedule-id'));
      var schedule = schedules.find(function(s) { return s.id === schedId; });
      if (!schedule) return;

      // Special (locked) blocks cannot be moved/resized/selected
      if (isSpecialSchedule(schedule)) return;

      var isResizeTop = e.target.classList.contains('resize-top');
      var isResizeBottom = e.target.classList.contains('resize-bottom');

      if (isResizeTop) {
        e.preventDefault();
        var end = parseTime(schedule.end_time);
        dragState = {
          type: 'resize-top',
          schedule: schedule,
          day: day,
          colRect: rect,
          endMin: end.h * 60 + end.m,
          startY: e.clientY
        };
      } else if (isResizeBottom) {
        e.preventDefault();
        var start = parseTime(schedule.start_time);
        dragState = {
          type: 'resize-bottom',
          schedule: schedule,
          day: day,
          colRect: rect,
          startMin: start.h * 60 + start.m,
          startY: e.clientY
        };
      } else {
        // Move
        e.preventDefault();
        var startT = parseTime(schedule.start_time);
        var endT = parseTime(schedule.end_time);
        var startMin = startT.h * 60 + startT.m;
        var endMin = endT.h * 60 + endT.m;
        var duration = endMin - startMin;
        if (duration <= 0) duration += 24 * 60;

        var offsetY = e.clientY - block.getBoundingClientRect().top;

        dragState = {
          type: 'move',
          schedule: schedule,
          day: day,
          origDay: day,
          colRect: rect,
          startMin: startMin,
          duration: duration,
          offsetY: offsetY,
          startY: e.clientY,
          hasMoved: false
        };
        block.classList.add('dragging');
      }
    } else {
      // Create by dragging on empty space
      e.preventDefault();
      var yInCol = e.clientY - rect.top;
      var startMin = snapMinutes(Math.floor((yInCol / HOUR_H) * 60));
      startMin = Math.max(0, Math.min(startMin, 23 * 60 + 45));

      dragState = {
        type: 'create',
        day: day,
        colRect: rect,
        anchorMin: startMin,
        currentMin: startMin,
        startY: e.clientY
      };
    }
  }

  function onMouseMove(e) {
    if (!dragState) return;
    e.preventDefault();

    var cols = document.querySelectorAll('.cal-day-col');

    if (dragState.type === 'create') {
      var col = cols[dragState.day];
      if (!col) return;
      var colRect = col.getBoundingClientRect();
      var yInCol = e.clientY - colRect.top;
      var min = snapMinutes(Math.floor((yInCol / HOUR_H) * 60));
      min = Math.max(0, Math.min(min, 24 * 60));
      dragState.currentMin = min;

      var fromMin = Math.min(dragState.anchorMin, min);
      var toMin = Math.max(dragState.anchorMin, min);
      if (toMin - fromMin < SNAP_MIN) toMin = fromMin + SNAP_MIN;

      var preview = col.querySelector('.cal-preview');
      if (preview) {
        preview.style.display = 'block';
        preview.style.top = (fromMin / 60) * HOUR_H + 'px';
        preview.style.height = ((toMin - fromMin) / 60) * HOUR_H + 'px';
        preview.textContent = formatTimeHHMM(fromMin) + ' – ' + formatTimeHHMM(toMin);
      }

    } else if (dragState.type === 'move') {
      dragState.hasMoved = true;
      var block = document.querySelector('.cal-block.dragging[data-schedule-id="' + dragState.schedule.id + '"]');
      if (!block) return;

      // Use the column under the mouse for position calculation
      var newDay = dragState.day;
      for (var d = 0; d < cols.length; d++) {
        var cr = cols[d].getBoundingClientRect();
        if (e.clientX >= cr.left && e.clientX < cr.right) {
          newDay = parseInt(cols[d].getAttribute('data-day'));
          break;
        }
      }

      var targetCol = cols[newDay];
      if (!targetCol) return;
      var colRect = targetCol.getBoundingClientRect();
      var yInCol = e.clientY - colRect.top - dragState.offsetY;
      var newStartMin = snapMinutes(Math.floor((yInCol / HOUR_H) * 60));
      newStartMin = Math.max(0, Math.min(newStartMin, 24 * 60 - dragState.duration));

      block.style.top = (newStartMin / 60) * HOUR_H + 'px';
      dragState.startMin = newStartMin;
      dragState.day = newDay;

      // If day changed, move the dragged block's DOM element
      if (block.parentElement !== targetCol) {
        targetCol.appendChild(block);
        block.setAttribute('data-day', newDay);
      }

    } else if (dragState.type === 'resize-top') {
      var col = cols[dragState.day];
      if (!col) return;
      var colRect = col.getBoundingClientRect();
      var yInCol = e.clientY - colRect.top;
      var newStart = snapMinutes(Math.floor((yInCol / HOUR_H) * 60));
      newStart = Math.max(0, Math.min(newStart, dragState.endMin - SNAP_MIN));

      var block = col.querySelector('.cal-block[data-schedule-id="' + dragState.schedule.id + '"]');
      if (block) {
        block.style.top = (newStart / 60) * HOUR_H + 'px';
        block.style.height = ((dragState.endMin - newStart) / 60) * HOUR_H + 'px';
      }
      dragState.newStartMin = newStart;

    } else if (dragState.type === 'resize-bottom') {
      var col = cols[dragState.day];
      if (!col) return;
      var colRect = col.getBoundingClientRect();
      var yInCol = e.clientY - colRect.top;
      var newEnd = snapMinutes(Math.ceil((yInCol / HOUR_H) * 60));
      newEnd = Math.max(dragState.startMin + SNAP_MIN, Math.min(newEnd, 24 * 60));

      var block = col.querySelector('.cal-block[data-schedule-id="' + dragState.schedule.id + '"]');
      if (block) {
        block.style.height = ((newEnd - dragState.startMin) / 60) * HOUR_H + 'px';
      }
      dragState.newEndMin = newEnd;
    }
  }

  function onMouseUp(e) {
    if (!dragState) return;

    var state = dragState;
    dragState = null;

    // Hide all previews
    var previews = document.querySelectorAll('.cal-preview');
    previews.forEach(function(p) { p.style.display = 'none'; });

    // Remove dragging class
    var dragging = document.querySelectorAll('.cal-block.dragging');
    dragging.forEach(function(b) { b.classList.remove('dragging'); });

    if (state.type === 'create') {
      // Click on empty area (no drag) → clear selection
      if (state.anchorMin === state.currentMin) {
        clearSelection();
      }
      return;

    } else if (state.type === 'move') {
      if (!state.hasMoved) {
        // Click without move → toggle selection
        toggleSelection(state.schedule.id);
        return;
      }
      var newStart = formatTimeHHMM(state.startMin);
      var newEnd = formatTimeHHMM(state.startMin + state.duration);
      var dow = state.schedule.days_of_week;
      var isMultiDay = (dow === null) || (dow.length > 1);

      if (isMultiDay) {
        // Split: remove dragged day from original, create new schedule for it
        splitDaySchedule(state.schedule, state.origDay, state.day, newStart, newEnd);
      } else {
        // Single-day schedule — just update time and optionally day
        var body = { start_time: newStart, end_time: newEnd };
        if (state.day !== state.origDay) {
          body.days_of_week = [colToDow(state.day)];
        }
        RendezVoxAPI.put('/admin/schedules/' + state.schedule.id, body)
          .then(function() {
            showToast('Schedule moved');
            loadSchedules();
            notifyStreamReload();
          })
          .catch(function(err) {
            showToast((err && err.error) || 'Move failed', 'error');
            loadSchedules();
          });
      }

    } else if (state.type === 'resize-top' && state.newStartMin !== undefined) {
      var newStartStr = formatTimeHHMM(state.newStartMin);
      var dow = state.schedule.days_of_week;
      var isMultiDay = (dow === null) || (dow.length > 1);

      if (isMultiDay) {
        var endStr = state.schedule.end_time.substring(0, 5);
        splitDaySchedule(state.schedule, state.day, state.day, newStartStr, endStr);
      } else {
        RendezVoxAPI.put('/admin/schedules/' + state.schedule.id, { start_time: newStartStr })
          .then(function() { showToast('Schedule updated'); loadSchedules(); notifyStreamReload(); })
          .catch(function(err) { showToast((err && err.error) || 'Resize failed', 'error'); loadSchedules(); });
      }

    } else if (state.type === 'resize-bottom' && state.newEndMin !== undefined) {
      var newEndStr = formatTimeHHMM(state.newEndMin);
      var dow = state.schedule.days_of_week;
      var isMultiDay = (dow === null) || (dow.length > 1);

      if (isMultiDay) {
        var startStr = state.schedule.start_time.substring(0, 5);
        splitDaySchedule(state.schedule, state.day, state.day, startStr, newEndStr);
      } else {
        RendezVoxAPI.put('/admin/schedules/' + state.schedule.id, { end_time: newEndStr })
          .then(function() { showToast('Schedule updated'); loadSchedules(); notifyStreamReload(); })
          .catch(function(err) { showToast((err && err.error) || 'Resize failed', 'error'); loadSchedules(); });
      }

    } else {
      // No meaningful change, just re-render
      renderCalendar();
    }
  }

  // ── Touch handlers for calendar block move/resize ──
  function onCalBlockTouchStart(e) {
    if (paletteDragging) return; // palette touch drag takes priority
    var block = e.target.closest('.cal-block');
    if (!block) return; // only blocks — empty space scroll is native

    var col = e.target.closest('.cal-day-col');
    if (!col) return;

    var schedId = parseInt(block.getAttribute('data-schedule-id'));
    var schedule = schedules.find(function(s) { return s.id === schedId; });
    if (!schedule || isSpecialSchedule(schedule)) return;

    var touch = e.touches[0];
    touchStartPos = { x: touch.clientX, y: touch.clientY };

    // Long-press (300ms) to start drag — avoids conflict with scroll
    clearTimeout(touchDragTimer);
    touchDragTimer = setTimeout(function() {
      if (!touchStartPos) return;
      var day = parseInt(col.getAttribute('data-day'));
      var rect = col.getBoundingClientRect();

      // Detect resize by touch position relative to block edges (more reliable than target)
      var blockRect = block.getBoundingClientRect();
      var EDGE = 20; // px from edge = resize zone
      var nearTop = touch.clientY - blockRect.top < EDGE;
      var nearBottom = blockRect.bottom - touch.clientY < EDGE;

      if (nearTop) {
        var end = parseTime(schedule.end_time);
        dragState = { type: 'resize-top', schedule: schedule, day: day, colRect: rect,
          endMin: end.h * 60 + end.m, startY: touch.clientY };
      } else if (nearBottom) {
        var start = parseTime(schedule.start_time);
        dragState = { type: 'resize-bottom', schedule: schedule, day: day, colRect: rect,
          startMin: start.h * 60 + start.m, startY: touch.clientY };
      } else {
        var startT = parseTime(schedule.start_time);
        var endT = parseTime(schedule.end_time);
        var startMin = startT.h * 60 + startT.m;
        var endMin = endT.h * 60 + endT.m;
        var duration = endMin - startMin;
        if (duration <= 0) duration += 24 * 60;
        var offsetY = touch.clientY - blockRect.top;
        dragState = { type: 'move', schedule: schedule, day: day, origDay: day, colRect: rect,
          startMin: startMin, duration: duration, offsetY: offsetY,
          startY: touch.clientY, hasMoved: false };
        block.classList.add('dragging');
      }
      // Haptic feedback — double pulse for resize to signal different mode
      if (navigator.vibrate) navigator.vibrate(nearTop || nearBottom ? [30, 50, 30] : 30);
    }, 300);
  }

  function onCalBlockTouchMove(e) {
    // Cancel long-press if finger moved too far before timer fires
    if (touchStartPos && !dragState) {
      var t = e.touches[0];
      var dx = t.clientX - touchStartPos.x;
      var dy = t.clientY - touchStartPos.y;
      if (Math.abs(dx) > 10 || Math.abs(dy) > 10) {
        clearTimeout(touchDragTimer);
        touchStartPos = null;
      }
      return;
    }
    if (!dragState) return;

    e.preventDefault(); // block scroll while dragging
    var touch = e.touches[0];

    // Reuse the existing mouse logic by faking a clientX/clientY
    var fakeEvent = { clientX: touch.clientX, clientY: touch.clientY, preventDefault: function() {} };
    onMouseMove(fakeEvent);

    // Auto-scroll near edges
    var calWrap = document.querySelector('.cal-wrap');
    if (calWrap) {
      var wrapRect = calWrap.getBoundingClientRect();
      var EDGE = 40, SPEED = 8;
      if (touch.clientY - wrapRect.top < EDGE) calWrap.scrollTop -= SPEED;
      else if (wrapRect.bottom - touch.clientY < EDGE) calWrap.scrollTop += SPEED;
    }
  }

  function onCalBlockTouchEnd(e) {
    clearTimeout(touchDragTimer);
    touchStartPos = null;
    if (!dragState) return;

    var touch = (e.changedTouches && e.changedTouches[0]) || null;
    // Reuse mouse up logic
    var fakeEvent = { clientX: touch ? touch.clientX : 0, clientY: touch ? touch.clientY : 0 };
    onMouseUp(fakeEvent);
  }

  // ── Context menu (right-click) ─────────────────────
  var ctxMenu = null;
  var ctxScheduleId = null;
  var ctxDay = null; // day column index for paste target
  var ctxToggleBtn = null;
  var ctxCopyBtn = null;
  var ctxApplyBtns = []; // array of {btn, days} for apply-to options
  var ctxApplyLabel = null;
  var ctxDeleteBtn = null;
  var ctxPasteBtn = null;

  function createContextMenu() {
    ctxMenu = document.createElement('div');
    ctxMenu.style.cssText = 'display:none;position:fixed;z-index:100;background:var(--bg-card);border:1px solid var(--border);border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.4);padding:4px 0;min-width:160px;font-size:.8rem';

    function makeBtn(label, color) {
      var btn = document.createElement('button');
      btn.textContent = label;
      btn.style.cssText = 'display:block;width:100%;text-align:left;padding:8px 14px;background:none;border:none;color:' + color + ';cursor:pointer;font-size:.8rem';
      btn.addEventListener('mouseenter', function() { btn.style.background = 'var(--bg-hover,rgba(255,255,255,.06))'; });
      btn.addEventListener('mouseleave', function() { btn.style.background = 'none'; });
      return btn;
    }

    // Toggle enable/disable for today
    ctxToggleBtn = makeBtn('Disable for today', 'var(--text)');
    ctxToggleBtn.addEventListener('click', function() {
      var id = ctxScheduleId;
      hideContextMenu();
      if (!id) return;
      var s = schedules.find(function(x) { return x.id === id; });
      if (!s) return;
      var todayDow = colToDow(getCurrentDay());
      var todayActive = s.is_active && (s.days_of_week === null || s.days_of_week.indexOf(todayDow) !== -1);
      toggleActive(id, !todayActive);
    });

    // Copy
    ctxCopyBtn = makeBtn('Copy', 'var(--text)');
    ctxCopyBtn.addEventListener('click', function() {
      var id = ctxScheduleId;
      hideContextMenu();
      if (!id) return;
      var s = schedules.find(function(x) { return x.id === id; });
      if (!s) return;
      clipboard = {
        playlist_id: s.playlist_id,
        start_time: s.start_time.substring(0, 5),
        end_time: s.end_time.substring(0, 5),
        priority: s.priority
      };
      showToast('Schedule copied');
    });

    // "Apply to..." label + day-pattern buttons
    ctxApplyLabel = document.createElement('div');
    ctxApplyLabel.textContent = 'Apply to\u2026';
    ctxApplyLabel.style.cssText = 'padding:6px 14px 2px;font-size:.7rem;color:var(--text-muted,#888);text-transform:uppercase;letter-spacing:.04em';

    var applyPresets = [
      { label: '24 hours',  days: null, fullDay: true },
      { label: 'All days',  days: null, fullDay: false },
      { label: 'MWF',       days: [1, 3, 5], fullDay: false },
      { label: 'TTh',       days: [2, 4], fullDay: false },
      { label: 'Weekends',  days: [0, 6], fullDay: false }
    ];

    ctxApplyBtns = applyPresets.map(function(preset) {
      var btn = makeBtn('  ' + preset.label, 'var(--text)');
      btn.addEventListener('click', function() {
        var id = ctxScheduleId;
        hideContextMenu();
        if (!id) return;
        applyDays(id, preset.days, preset.fullDay);
      });
      return { btn: btn, days: preset.days };
    });

    // Delete
    ctxDeleteBtn = makeBtn('Delete', 'var(--danger)');
    ctxDeleteBtn.addEventListener('click', function() {
      var id = ctxScheduleId;
      hideContextMenu();
      if (id) deleteSchedule(id);
    });

    // Paste (shown only on empty area right-click)
    ctxPasteBtn = makeBtn('Paste', 'var(--text)');
    ctxPasteBtn.addEventListener('click', function() {
      var day = ctxDay;
      hideContextMenu();
      if (clipboard === null || day === null) return;
      var body = {
        playlist_id: clipboard.playlist_id,
        days_of_week: [colToDow(day)],
        start_time: clipboard.start_time,
        end_time: clipboard.end_time,
        priority: clipboard.priority,
        is_active: true
      };
      RendezVoxAPI.post('/admin/schedules', body)
        .then(function() {
          showToast('Schedule pasted');
          loadSchedules();
          notifyStreamReload();
        })
        .catch(function(err) {
          showToast((err && err.error) || 'Paste failed', 'error');
        });
    });

    ctxMenu.appendChild(ctxToggleBtn);
    ctxMenu.appendChild(ctxCopyBtn);
    ctxMenu.appendChild(ctxApplyLabel);
    ctxApplyBtns.forEach(function(item) { ctxMenu.appendChild(item.btn); });
    ctxMenu.appendChild(ctxDeleteBtn);
    ctxMenu.appendChild(ctxPasteBtn);
    document.body.appendChild(ctxMenu);
  }

  function showContextMenu(x, y, schedId, dayIndex) {
    ctxScheduleId = schedId;
    ctxDay = dayIndex !== undefined ? dayIndex : null;

    var isBlockMenu = (schedId !== null && schedId !== undefined);

    // Show/hide buttons based on context
    var isLocked = false;
    if (isBlockMenu) {
      var sched = schedules.find(function(x) { return x.id === schedId; });
      isLocked = sched && isSpecialSchedule(sched);
    }
    ctxToggleBtn.style.display = (isBlockMenu && !isLocked) ? 'block' : 'none';
    ctxCopyBtn.style.display = isBlockMenu ? 'block' : 'none';
    ctxApplyLabel.style.display = (isBlockMenu && !isLocked) ? 'block' : 'none';
    ctxApplyBtns.forEach(function(item) { item.btn.style.display = (isBlockMenu && !isLocked) ? 'block' : 'none'; });
    ctxDeleteBtn.style.display = (isBlockMenu && !isLocked) ? 'block' : 'none';
    ctxPasteBtn.style.display = !isBlockMenu ? 'block' : 'none';

    // Update toggle label based on current state
    if (isBlockMenu) {
      var s = schedules.find(function(x) { return x.id === schedId; });
      if (s && ctxToggleBtn) {
        var todayDow = colToDow(getCurrentDay());
        var todayActive = s.is_active && (s.days_of_week === null || s.days_of_week.indexOf(todayDow) !== -1);
        ctxToggleBtn.textContent = todayActive ? 'Disable for today' : 'Enable for today';
      }
    }

    ctxMenu.style.display = 'block';
    ctxMenu.style.left = x + 'px';
    ctxMenu.style.top = y + 'px';

    // Keep within viewport
    var rect = ctxMenu.getBoundingClientRect();
    if (rect.right > window.innerWidth) ctxMenu.style.left = (x - rect.width) + 'px';
    if (rect.bottom > window.innerHeight) ctxMenu.style.top = (y - rect.height) + 'px';
  }

  function hideContextMenu() {
    if (ctxMenu) ctxMenu.style.display = 'none';
    ctxScheduleId = null;
    ctxDay = null;
  }

  // ── Apply days helper ──────────────────────────────
  function applyDays(id, targetDays, fullDay) {
    var s = schedules.find(function(x) { return x.id === id; });
    if (!s) return;

    if (fullDay) {
      // 24 hours: only change time to 00:00–24:00, keep existing days unchanged
      RendezVoxAPI.put('/admin/schedules/' + id, { start_time: '00:00', end_time: '24:00' })
        .then(function() {
          showToast('Set to 24 hours');
          loadSchedules();
          notifyStreamReload();
        })
        .catch(function(err) {
          showToast((err && err.error) || 'Update failed', 'error');
          loadSchedules();
        });
      return;
    }

    // Figure out which days this schedule already covers
    var startTime = s.start_time.substring(0, 5);
    var endTime = s.end_time.substring(0, 5);
    var startMin = parseTime(startTime);
    var endMin = parseTime(endTime);
    var sMin = startMin.h * 60 + startMin.m;
    var eMin = endMin.h * 60 + endMin.m;
    var existingDays = s.days_of_week || [0,1,2,3,4,5,6];
    var wantDays = targetDays || [0,1,2,3,4,5,6];

    // Find days that need a new schedule (not already covered by this schedule)
    var newDays = wantDays.filter(function(d) { return existingDays.indexOf(d) === -1; });

    // Check each new day for time overlap with ANY other schedule
    var freeDays = newDays.filter(function(d) {
      for (var i = 0; i < schedules.length; i++) {
        var x = schedules[i];
        if (x.id === id || !x.is_active) continue;
        var xDays = x.days_of_week || [0,1,2,3,4,5,6];
        if (xDays.indexOf(d) === -1) continue;
        var xs = parseTime(x.start_time);
        var xe = parseTime(x.end_time);
        var xsMin = xs.h * 60 + xs.m;
        var xeMin = xe.h * 60 + xe.m;
        // Time overlap check (touching boundaries are OK)
        if (sMin < xeMin && xsMin < eMin && sMin !== xeMin && eMin !== xsMin) return false;
      }
      return true;
    });

    var skipped = newDays.length - freeDays.length;

    if (freeDays.length === 0 && newDays.length === 0) {
      showToast('Already on all requested days');
      return;
    }
    if (freeDays.length === 0) {
      showToast('All ' + newDays.length + ' day(s) have overlapping schedules — skipped', 'error');
      return;
    }

    // Create a new schedule for each free day
    var createPromises = freeDays.map(function(d) {
      return RendezVoxAPI.post('/admin/schedules', {
        playlist_id: s.playlist_id,
        days_of_week: [d],
        start_time: startTime,
        end_time: endTime,
        priority: s.priority,
        is_active: true
      });
    });

    Promise.all(createPromises)
      .then(function() {
        var addedLabel = freeDays.map(function(d) { return DAYS[dowToCol(d)]; }).join(', ');
        var msg = 'Added to ' + addedLabel;
        if (skipped > 0) msg += ' (' + skipped + ' skipped)';
        showToast(msg);
        loadSchedules();
        notifyStreamReload();
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Create failed', 'error');
        loadSchedules();
      });
  }

  // ── CRUD ────────────────────────────────────────────

  /**
   * Split a single day out of a multi-day schedule.
   * Removes origDay from the original schedule's days_of_week,
   * then creates a new single-day schedule for targetDay with the new time.
   */
  function splitDaySchedule(schedule, origDay, targetDay, newStart, newEnd) {
    // Convert column indices to DB day-of-week values
    var origDow = colToDow(origDay);
    var targetDow = colToDow(targetDay);

    // Build remaining days for the original schedule
    var allDays = schedule.days_of_week;
    if (allDays === null) {
      allDays = [0, 1, 2, 3, 4, 5, 6];
    }
    var remaining = allDays.filter(function(d) { return d !== origDow; });

    // Step 1: Update original schedule to remove the dragged day
    var updateBody = {};
    if (remaining.length === 0) {
      // Was only one day after all — just update time and day
      updateBody.start_time = newStart;
      updateBody.end_time = newEnd;
      if (targetDay !== origDay) updateBody.days_of_week = [targetDow];
      RendezVoxAPI.put('/admin/schedules/' + schedule.id, updateBody)
        .then(function() { showToast('Schedule moved'); loadSchedules(); notifyStreamReload(); })
        .catch(function(err) { showToast((err && err.error) || 'Move failed', 'error'); loadSchedules(); });
      return;
    }

    updateBody.days_of_week = remaining;

    RendezVoxAPI.put('/admin/schedules/' + schedule.id, updateBody)
      .then(function() {
        // Step 2: Create new schedule for just the dragged day
        var createBody = {
          playlist_id: schedule.playlist_id,
          days_of_week: [targetDow],
          start_date: schedule.start_date || null,
          end_date: schedule.end_date || null,
          start_time: newStart,
          end_time: newEnd,
          priority: schedule.priority,
          is_active: schedule.is_active
        };
        return RendezVoxAPI.post('/admin/schedules', createBody);
      })
      .then(function() {
        showToast('Schedule split — ' + DAYS[targetDay] + ' moved independently');
        loadSchedules();
        notifyStreamReload();
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Move failed', 'error');
        loadSchedules();
      });
  }

  function toggleActive(id, active) {
    var s = schedules.find(function(x) { return x.id === id; });
    if (!s) return;
    var todayDow = colToDow(getCurrentDay());
    var body;

    if (!active) {
      // Turning OFF — remove today only
      var days = s.days_of_week !== null ? s.days_of_week.slice() : [0,1,2,3,4,5,6];
      var remaining = days.filter(function(d) { return d !== todayDow; });
      if (remaining.length > 0 && remaining.length < days.length) {
        // Remove today, keep other days
        body = { days_of_week: remaining };
      } else {
        // Today was the only day, or today wasn't in the schedule — deactivate entirely
        body = { is_active: false };
      }
    } else {
      // Turning ON
      if (!s.is_active) {
        // Schedule was fully deactivated — re-enable it
        body = { is_active: true };
      } else {
        // Schedule is active but today is missing — add today back
        var days = s.days_of_week !== null ? s.days_of_week.slice() : [0,1,2,3,4,5,6];
        if (days.indexOf(todayDow) === -1) {
          days.push(todayDow);
          days.sort(function(a, b) { return a - b; });
        }
        body = { days_of_week: days };
      }
    }

    RendezVoxAPI.put('/admin/schedules/' + id, body).then(function() {
      showToast(active ? 'Schedule enabled for today' : 'Schedule disabled for today');
      loadSchedules();
      notifyStreamReload();
    }).catch(function(err) {
      showToast((err && err.error) || 'Update failed', 'error');
      loadSchedules();
    });
  }

  // ── Multi-select helpers ─────────────────────────
  function toggleSelection(id) {
    if (selectedIds[id]) delete selectedIds[id];
    else selectedIds[id] = true;
    renderCalendar();
  }

  function clearSelection() {
    selectedIds = {};
    renderCalendar();
  }

  function updateSelectionUI() {
    var ids = Object.keys(selectedIds);
    var btn = document.getElementById('btnDeleteSelected');
    if (!btn) return;
    if (ids.length > 0) {
      btn.style.display = '';
      btn.textContent = 'Delete Selected (' + ids.length + ')';
    } else {
      btn.style.display = 'none';
    }
  }

  function deleteSelected() {
    var ids = Object.keys(selectedIds).filter(function(id) {
      var s = schedules.find(function(x) { return x.id === parseInt(id); });
      return !s || !isSpecialSchedule(s);
    });
    if (!ids.length) return;
    RendezVoxConfirm('Delete ' + ids.length + ' selected schedule(s)?', {
      title: 'Delete Selected',
      okLabel: 'Delete'
    }).then(function(ok) {
      if (!ok) return;
      var promises = ids.map(function(id) {
        return RendezVoxAPI.del('/admin/schedules/' + id);
      });
      Promise.all(promises).then(function() {
        selectedIds = {};
        showToast(ids.length + ' schedules deleted');
        loadSchedules();
        notifyStreamReload();
      }).catch(function(err) {
        showToast((err && err.error) || 'Delete failed', 'error');
        loadSchedules();
      });
    });
  }

  function deleteSchedule(id) {
    var s = schedules.find(function(x) { return x.id === id; });
    if (s && isSpecialSchedule(s)) {
      showToast('Special playlists are managed from the Playlists page', 'error');
      return;
    }
    RendezVoxConfirm('Delete this schedule?', { title: 'Delete Schedule', okLabel: 'Delete' }).then(function(ok) {
      if (!ok) return;
      RendezVoxAPI.del('/admin/schedules/' + id).then(function() {
        showToast('Schedule deleted');
        loadSchedules();
        notifyStreamReload();
      }).catch(function(err) {
        showToast((err && err.error) || 'Delete failed', 'error');
      });
    });
  }

  // ── Helpers ─────────────────────────────────────────
  function escHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  // ── Surprise Me! ──────────────────────────────────
  function formatHourLabel(h) {
    if (h === 0 || h === 24) return '12 AM';
    if (h === 12) return '12 PM';
    return (h % 12) + ' ' + (h >= 12 ? 'PM' : 'AM');
  }

  function openSurpriseScheduleModal() {
    var startSel = document.getElementById('schedStartHour');
    var endSel = document.getElementById('schedEndHour');

    // Populate start: 0 (12 AM) through 23 (11 PM), default 5 (5 AM)
    startSel.innerHTML = '';
    for (var h = 0; h < 24; h++) {
      var opt = document.createElement('option');
      opt.value = h;
      opt.textContent = formatHourLabel(h);
      if (h === 5) opt.selected = true;
      startSel.appendChild(opt);
    }

    // Populate end: 1 (1 AM) through 24 (12 AM midnight), default 24
    endSel.innerHTML = '';
    for (var h = 1; h <= 24; h++) {
      var opt = document.createElement('option');
      opt.value = h;
      opt.textContent = formatHourLabel(h);
      if (h === 24) opt.selected = true;
      endSel.appendChild(opt);
    }

    document.getElementById('surpriseScheduleModal').classList.remove('hidden');
  }

  function handleSurpriseScheduleGo() {
    var startHour = parseInt(document.getElementById('schedStartHour').value);
    var endHour = parseInt(document.getElementById('schedEndHour').value);
    var blockMin = parseInt(document.getElementById('schedBlockDuration').value);

    if (endHour <= startHour) {
      showToast('End hour must be after start hour', 'error');
      return;
    }

    // Get active non-emergency playlists
    var active = playlists.filter(function(p) { return p.is_active; });
    if (active.length === 0) {
      showToast('No active playlists available', 'error');
      return;
    }

    // Close modal
    document.getElementById('surpriseScheduleModal').classList.add('hidden');
    bulkBusy = true;

    // Re-fetch special config before generating (may have changed on Playlists page)
    RendezVoxAPI.get('/admin/settings').then(function(result) {
      (result.settings || []).forEach(function(s) {
        if (s.key === 'schedule_special_playlists') {
          try { specialIds = JSON.parse(s.value || '[]'); } catch(e) { specialIds = []; }
        }
        if (s.key === 'schedule_special_slots') {
          try { specialSlots = JSON.parse(s.value || '[]'); } catch(e) { specialSlots = []; }
        }
      });
      generateSurpriseSchedule(startHour, endHour, blockMin, active);
    }).catch(function() {
      generateSurpriseSchedule(startHour, endHour, blockMin, active);
    });
  }

  function generateSurpriseSchedule(startHour, endHour, blockMin, active) {
    // Exclude special playlists from the rotation pool
    var pool = active.filter(function(p) {
      return specialIds.indexOf(p.id) === -1;
    });
    if (pool.length === 0) pool = active; // fallback if all are special

    var schedStart = startHour * 60;
    var schedEnd = endHour * 60;
    var bulkSchedules = [];

    // Build reserved ranges from special slots (clipped to schedule window)
    var reserved = [];
    for (var i = 0; i < specialSlots.length; i++) {
      var rStart = Math.max(specialSlots[i].start * 60, schedStart);
      var rEnd   = Math.min(specialSlots[i].end * 60, schedEnd);
      if (rStart < rEnd) reserved.push({ start: rStart, end: rEnd });
    }
    var sorted = reserved.slice().sort(function(a, b) { return a.start - b.start; });

    // Shuffle pool
    var shuffled = pool.slice();
    for (var fi = shuffled.length - 1; fi > 0; fi--) {
      var fj = Math.floor(Math.random() * (fi + 1));
      var ftmp = shuffled[fi]; shuffled[fi] = shuffled[fj]; shuffled[fj] = ftmp;
    }
    var N = shuffled.length;

    // Fill free ranges around reserved slots with regular playlists
    for (var d = 0; d < 7; d++) {
      var freeRanges = [];
      var cursor = schedStart;
      for (var si = 0; si < sorted.length; si++) {
        if (cursor < sorted[si].start) freeRanges.push({ start: cursor, end: sorted[si].start });
        cursor = Math.max(cursor, sorted[si].end);
      }
      if (cursor < schedEnd) freeRanges.push({ start: cursor, end: schedEnd });

      var bi = 0;
      for (var fri = 0; fri < freeRanges.length; fri++) {
        var c = freeRanges[fri].start;
        while (c < freeRanges[fri].end) {
          var blockEnd = Math.min(c + blockMin, freeRanges[fri].end);
          var pick = shuffled[(bi + d) % N];
          bulkSchedules.push({
            playlist_id: pick.id,
            days_of_week: [colToDow(d)],
            start_time: formatTimeHHMM(c),
            end_time: formatTimeHHMM(blockEnd),
            priority: 0
          });
          c = blockEnd;
          bi++;
        }
      }
    }

    // Clears regular schedules (preserves special priority-99), then creates new ones
    RendezVoxAPI.post('/admin/schedules/bulk', {
      clear_existing: true,
      schedules: bulkSchedules
    }).then(function(data) {
      bulkBusy = false;
      showToast(data.created + ' schedules created');
      loadSchedules();
      RendezVoxAPI.post('/admin/schedules/reload', { force: true }).catch(function() {});
    }).catch(function(err) {
      bulkBusy = false;
      showToast((err && err.error) || 'Surprise Me failed', 'error');
      loadSchedules();
    });
  }

  function handleClearSchedules() {
    var regularCount = schedules.filter(function(s) { return !isSpecialSchedule(s); }).length;
    if (!regularCount) {
      showToast('No schedules to clear');
      return;
    }
    var msg = 'Delete ' + regularCount + ' schedule(s)?';
    var specialCount = schedules.length - regularCount;
    if (specialCount > 0) msg += ' (' + specialCount + ' special entries will be kept)';
    RendezVoxConfirm(msg, { title: 'Clear Schedules', okLabel: 'Clear' }).then(function(ok) {
      if (!ok) return;
      RendezVoxAPI.post('/admin/schedules/bulk', {
        clear_existing: true,
        schedules: []
      }).then(function() {
        showToast('Schedules cleared');
        loadSchedules();
        RendezVoxAPI.post('/admin/schedules/reload', { force: true }).catch(function() {});
      }).catch(function(err) {
        showToast((err && err.error) || 'Clear failed', 'error');
        loadSchedules();
      });
    });
  }

  function showToast(msg, type) {
    var container = document.getElementById('toasts');
    var toast = document.createElement('div');
    toast.className = 'toast toast-' + (type || 'success');
    toast.textContent = msg;
    container.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 4000);
  }

  return {
    init: init,
    deleteSchedule: deleteSchedule,
    toggleActive: toggleActive
  };
})();
