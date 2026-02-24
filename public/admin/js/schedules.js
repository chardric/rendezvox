/* ============================================================
   iRadio Admin — Schedule Manager (Drag-and-Drop Calendar)
   ============================================================ */
var iRadioSchedules = (function() {

  var DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  var HOUR_H   = 60;  // pixels per hour
  var SNAP_MIN = 15;  // snap to 15-minute increments
  var DEFAULT_COLOR = '#00c8a0';

  var schedules = [];
  var playlists = [];
  var playlistMap = {}; // id -> playlist

  // ── Clipboard state (for copy/paste) ────────────────
  var clipboard = null; // { playlist_id, start_time, end_time, priority }

  // ── Drag state ──────────────────────────────────────
  var dragState = null; // { type: 'create'|'move'|'resize-top'|'resize-bottom', ... }

  // ── Palette drag state (HTML5 DnD) ────────────────
  var paletteDragData = null; // { playlistId, color, name }
  var paletteDragging = false;

  // ── Bulk operation guard (pauses auto-refresh) ────
  var bulkBusy = false;

  // ── Timezone helpers ────────────────────────────────
  function getCurrentDay() {
    var now = new Date();
    var opts = iRadioAPI.tzOpts();
    var dayName = now.toLocaleDateString('en-US', Object.assign({ weekday: 'short' }, opts));
    var map = { Mon: 0, Tue: 1, Wed: 2, Thu: 3, Fri: 4, Sat: 5, Sun: 6 };
    return map[dayName] !== undefined ? map[dayName] : (now.getDay() === 0 ? 6 : now.getDay() - 1);
  }

  function getStationTime() {
    var now = new Date();
    var opts = iRadioAPI.tzOpts();
    var h = parseInt(now.toLocaleString('en-US', Object.assign({ hour: '2-digit', hour12: false }, opts)), 10);
    var m = parseInt(now.toLocaleString('en-US', Object.assign({ minute: '2-digit' }, opts)), 10);
    return { h: h, m: m };
  }

  function getStationDateStr() {
    var now = new Date();
    var opts = iRadioAPI.tzOpts();
    var y = now.toLocaleString('en-US', Object.assign({ year: 'numeric' }, opts));
    var m = now.toLocaleString('en-US', Object.assign({ month: '2-digit' }, opts));
    var d = now.toLocaleString('en-US', Object.assign({ day: '2-digit' }, opts));
    return y + '-' + m + '-' + d;
  }

  function isScheduleActiveNow(s) {
    if (!s.is_active || !s.playlist_active) return false;
    var today = getCurrentDay();
    if (s.days_of_week !== null && s.days_of_week.indexOf(today) === -1) return false;
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
  function init() {
    iRadioAPI.getTimezone();
    loadPlaylists();
    loadSchedules();

    // Global mouse events for drag operations
    document.addEventListener('mousemove', onMouseMove);
    document.addEventListener('mouseup', onMouseUp);

    // Context menu for right-click on calendar blocks
    createContextMenu();
    document.addEventListener('click', hideContextMenu);
    document.addEventListener('contextmenu', function(e) {
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

    // Auto-refresh every 5 seconds (picks up changes from other windows/tabs)
    setInterval(function() { if (!bulkBusy) { loadPlaylists(); loadSchedules(); } }, 5000);
  }

  /**
   * Tell the streaming engine to check for schedule changes.
   * Only skips the current track if a different playlist should be playing.
   */
  function notifyStreamReload() {
    iRadioAPI.post('/admin/schedules/reload', {}).catch(function() {});
  }

  function loadPlaylists() {
    iRadioAPI.get('/admin/playlists').then(function(data) {
      playlists = (data.playlists || []).filter(function(p) { return p.type !== 'emergency'; });
      playlistMap = {};
      playlists.forEach(function(p) { playlistMap[p.id] = p; });
      renderPalette();
    });
  }

  function loadSchedules() {
    iRadioAPI.get('/admin/schedules').then(function(data) {
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
      days_of_week: [day],
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

    iRadioAPI.post('/admin/schedules', body)
      .then(function() {
        showToast('Schedule created');
        loadSchedules();
        notifyStreamReload();
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Create failed', 'error');
      });
  }

  var MONTHS_FULL = ['January','February','March','April','May','June','July','August','September','October','November','December'];

  /** Get array of Date objects for Mon–Sun of the current week (station tz). */
  function getWeekDates() {
    var now = new Date();
    var opts = iRadioAPI.tzOpts();
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
        if (s.days_of_week !== null && s.days_of_week.indexOf(d) === -1) return;

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
        html += '<div class="cal-block' + (isNow ? ' now' : '') + '" ' +
          'data-schedule-id="' + s.id + '" data-day="' + d + '" ' +
          'style="top:' + topPx + 'px;height:' + displayH + 'px;' +
          'background:' + hexToRgba(color, 0.85) + ';' +
          'border-left:3px solid ' + color + ';' +
          'opacity:' + opacity + '">';
        html += '<div class="resize-top"></div>';
        html += '<div class="block-title">' + escHtml(s.playlist_name) + '</div>';
        if (displayH >= 32) {
          html += '<div class="block-time">' + formatTime12(s.start_time) + ' – ' + formatTime12(s.end_time) + '</div>';
        }
        html += '<div class="resize-bottom"></div>';
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

    // Attach mousedown listeners to day columns and blocks
    var cols = grid.querySelectorAll('.cal-day-col');
    cols.forEach(function(col) {
      col.addEventListener('mousedown', onDayMouseDown);
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
      // No-op: use the playlist palette to drag-create schedules
      return;

    } else if (state.type === 'move') {
      if (!state.hasMoved) {
        // Click without move — no action needed
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
          body.days_of_week = [state.day];
        }
        iRadioAPI.put('/admin/schedules/' + state.schedule.id, body)
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
        iRadioAPI.put('/admin/schedules/' + state.schedule.id, { start_time: newStartStr })
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
        iRadioAPI.put('/admin/schedules/' + state.schedule.id, { end_time: newEndStr })
          .then(function() { showToast('Schedule updated'); loadSchedules(); notifyStreamReload(); })
          .catch(function(err) { showToast((err && err.error) || 'Resize failed', 'error'); loadSchedules(); });
      }

    } else {
      // No meaningful change, just re-render
      renderCalendar();
    }
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
      var today = getCurrentDay();
      var todayActive = s.is_active && (s.days_of_week === null || s.days_of_week.indexOf(today) !== -1);
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
      { label: 'MWF',       days: [0, 2, 4], fullDay: false },
      { label: 'TTh',       days: [1, 3], fullDay: false },
      { label: 'Weekends',  days: [5, 6], fullDay: false }
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
        days_of_week: [day],
        start_time: clipboard.start_time,
        end_time: clipboard.end_time,
        priority: clipboard.priority,
        is_active: true
      };
      iRadioAPI.post('/admin/schedules', body)
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
    ctxToggleBtn.style.display = isBlockMenu ? 'block' : 'none';
    ctxCopyBtn.style.display = isBlockMenu ? 'block' : 'none';
    ctxApplyLabel.style.display = isBlockMenu ? 'block' : 'none';
    ctxApplyBtns.forEach(function(item) { item.btn.style.display = isBlockMenu ? 'block' : 'none'; });
    ctxDeleteBtn.style.display = isBlockMenu ? 'block' : 'none';
    ctxPasteBtn.style.display = !isBlockMenu ? 'block' : 'none';

    // Update toggle label based on current state
    if (isBlockMenu) {
      var s = schedules.find(function(x) { return x.id === schedId; });
      if (s && ctxToggleBtn) {
        var today = getCurrentDay();
        var todayActive = s.is_active && (s.days_of_week === null || s.days_of_week.indexOf(today) !== -1);
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
      iRadioAPI.put('/admin/schedules/' + id, { start_time: '00:00', end_time: '24:00' })
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
      return iRadioAPI.post('/admin/schedules', {
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
        var addedLabel = freeDays.map(function(d) { return DAYS[d]; }).join(', ');
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
    // Build remaining days for the original schedule
    var allDays = schedule.days_of_week;
    if (allDays === null) {
      allDays = [0, 1, 2, 3, 4, 5, 6];
    }
    var remaining = allDays.filter(function(d) { return d !== origDay; });

    // Step 1: Update original schedule to remove the dragged day
    var updateBody = {};
    if (remaining.length === 0) {
      // Was only one day after all — just update time and day
      updateBody.start_time = newStart;
      updateBody.end_time = newEnd;
      if (targetDay !== origDay) updateBody.days_of_week = [targetDay];
      iRadioAPI.put('/admin/schedules/' + schedule.id, updateBody)
        .then(function() { showToast('Schedule moved'); loadSchedules(); notifyStreamReload(); })
        .catch(function(err) { showToast((err && err.error) || 'Move failed', 'error'); loadSchedules(); });
      return;
    }

    updateBody.days_of_week = remaining;

    iRadioAPI.put('/admin/schedules/' + schedule.id, updateBody)
      .then(function() {
        // Step 2: Create new schedule for just the dragged day
        var createBody = {
          playlist_id: schedule.playlist_id,
          days_of_week: [targetDay],
          start_date: schedule.start_date || null,
          end_date: schedule.end_date || null,
          start_time: newStart,
          end_time: newEnd,
          priority: schedule.priority,
          is_active: schedule.is_active
        };
        return iRadioAPI.post('/admin/schedules', createBody);
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
    var today = getCurrentDay();
    var body;

    if (!active) {
      // Turning OFF — remove today only
      var days = s.days_of_week !== null ? s.days_of_week.slice() : [0,1,2,3,4,5,6];
      var remaining = days.filter(function(d) { return d !== today; });
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
        if (days.indexOf(today) === -1) {
          days.push(today);
          days.sort(function(a, b) { return a - b; });
        }
        body = { days_of_week: days };
      }
    }

    iRadioAPI.put('/admin/schedules/' + id, body).then(function() {
      showToast(active ? 'Schedule enabled for today' : 'Schedule disabled for today');
      loadSchedules();
      notifyStreamReload();
    }).catch(function(err) {
      showToast((err && err.error) || 'Update failed', 'error');
      loadSchedules();
    });
  }

  function deleteSchedule(id) {
    iRadioConfirm('Delete this schedule?', { title: 'Delete Schedule', okLabel: 'Delete' }).then(function(ok) {
      if (!ok) return;
      iRadioAPI.del('/admin/schedules/' + id).then(function() {
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

    // Fetch reserved keywords setting, then generate schedule
    iRadioAPI.get('/admin/settings').then(function(result) {
      var keywordsRaw = '';
      (result.settings || []).forEach(function(s) {
        if (s.key === 'schedule_reserved_keywords') keywordsRaw = s.value || '';
      });
      generateSurpriseSchedule(startHour, endHour, blockMin, active, keywordsRaw);
    }).catch(function() {
      // If settings fetch fails, proceed without reserved keywords
      generateSurpriseSchedule(startHour, endHour, blockMin, active, '');
    });
  }

  function generateSurpriseSchedule(startHour, endHour, blockMin, active, keywordsRaw) {
    // Parse keywords
    var keywords = keywordsRaw.split(',')
      .map(function(k) { return k.trim().toLowerCase(); })
      .filter(function(k) { return k.length > 0; });

    // Split playlists into reserved and regular
    var reserved = [];
    var regular = [];
    if (keywords.length > 0) {
      active.forEach(function(p) {
        var nameLower = p.name.toLowerCase();
        var isReserved = keywords.some(function(kw) { return nameLower.indexOf(kw) !== -1; });
        if (isReserved) reserved.push(p);
        else regular.push(p);
      });
    }

    // If no reserved playlists found, treat all as regular
    var hasReserved = reserved.length > 0 && keywords.length > 0;
    if (!hasReserved) {
      regular = active;
    }

    var schedStart = startHour * 60;
    var schedEnd = endHour * 60;
    var bulkSchedules = [];

    // ── Reserved slots: own full-duration entries, independent of blockMin ──
    // reservedSlots[d] = array of {start, end} for that day
    var reservedSlots = [];
    for (var d = 0; d < 7; d++) reservedSlots[d] = [];

    if (hasReserved) {
      // 4 AM – 6 AM daily (all 7 days), clipped to user's range
      var earlyStart = Math.max(4 * 60, schedStart);
      var earlyEnd = Math.min(6 * 60, schedEnd);
      if (earlyStart < earlyEnd) {
        for (var d = 0; d < 7; d++) {
          reservedSlots[d].push({ start: earlyStart, end: earlyEnd });
        }
      }

      // 11 AM – 1 PM on 2-3 random weekdays (Mon–Fri = days 0-4)
      var weekdays = [0, 1, 2, 3, 4];
      for (var i = weekdays.length - 1; i > 0; i--) {
        var j = Math.floor(Math.random() * (i + 1));
        var tmp = weekdays[i]; weekdays[i] = weekdays[j]; weekdays[j] = tmp;
      }
      var midDayCount = 2 + Math.floor(Math.random() * 2); // 2 or 3
      var midDayDays = weekdays.slice(0, midDayCount);

      var midStart = Math.max(11 * 60, schedStart);
      var midEnd = Math.min(13 * 60, schedEnd);
      if (midStart < midEnd) {
        for (var i = 0; i < midDayDays.length; i++) {
          reservedSlots[midDayDays[i]].push({ start: midStart, end: midEnd });
        }
      }

      // Create one schedule entry per reserved slot, rotating playlists
      var lastPicked = null;
      for (var d = 0; d < 7; d++) {
        for (var si = 0; si < reservedSlots[d].length; si++) {
          var slot = reservedSlots[d][si];
          var candidates = reserved.filter(function(p) { return p.id !== lastPicked; });
          if (candidates.length === 0) candidates = reserved;
          var pick = candidates[Math.floor(Math.random() * candidates.length)];
          lastPicked = pick.id;
          bulkSchedules.push({
            playlist_id: pick.id,
            days_of_week: [d],
            start_time: formatTimeHHMM(slot.start),
            end_time: formatTimeHHMM(slot.end),
            priority: 0
          });
        }
      }
    }

    // ── Regular blocks: split around reserved slots per day ──
    // Compute free (non-reserved) time ranges for each day
    var fillPool = hasReserved ? regular : active;
    // allBlocks[d] = [{start, end, playlist}] — includes BOTH reserved and regular
    // for accurate cross-day adjacency checks
    var allBlocks = [];

    for (var d = 0; d < 7; d++) {
      // Sort reserved slots for this day by start time
      var sorted = reservedSlots[d].slice().sort(function(a, b) { return a.start - b.start; });

      // Build free ranges by subtracting reserved slots
      var freeRanges = [];
      var cursor = schedStart;
      for (var si = 0; si < sorted.length; si++) {
        if (cursor < sorted[si].start) {
          freeRanges.push({ start: cursor, end: sorted[si].start });
        }
        cursor = Math.max(cursor, sorted[si].end);
      }
      if (cursor < schedEnd) {
        freeRanges.push({ start: cursor, end: schedEnd });
      }

      // Divide each free range into blocks of blockMin
      var blocks = [];
      for (var ri = 0; ri < freeRanges.length; ri++) {
        var range = freeRanges[ri];
        var c = range.start;
        while (c < range.end) {
          var blockEnd = Math.min(c + blockMin, range.end);
          blocks.push({ start: c, end: blockEnd });
          c = blockEnd;
        }
      }

      // Build lookup of reserved entries for this day (from bulkSchedules)
      var reservedEntries = [];
      for (var si = 0; si < reservedSlots[d].length; si++) {
        // Find the matching bulkSchedule entry for this reserved slot + day
        var slot = reservedSlots[d][si];
        for (var bsi = 0; bsi < bulkSchedules.length; bsi++) {
          var bs = bulkSchedules[bsi];
          if (bs.days_of_week[0] === d &&
              bs.start_time === formatTimeHHMM(slot.start) &&
              bs.end_time === formatTimeHHMM(slot.end)) {
            var rp = null;
            for (var ri2 = 0; ri2 < reserved.length; ri2++) {
              if (reserved[ri2].id === bs.playlist_id) { rp = reserved[ri2]; break; }
            }
            if (rp) reservedEntries.push({ start: slot.start, end: slot.end, playlist: rp });
            break;
          }
        }
      }

      // Assign playlists — no same playlist adjacent on same day,
      // and avoid matching the overlapping block on previous day
      var prevId = null;
      // If the first regular block starts right after a reserved slot,
      // seed prevId with that reserved playlist
      if (blocks.length > 0 && reservedEntries.length > 0) {
        for (var ri3 = 0; ri3 < reservedEntries.length; ri3++) {
          if (reservedEntries[ri3].end === blocks[0].start) {
            prevId = reservedEntries[ri3].playlist.id;
            break;
          }
        }
      }

      for (var bi = 0; bi < blocks.length; bi++) {
        var exclude = {};
        if (prevId) exclude[prevId] = true;

        // Check if a reserved slot follows this block — exclude its playlist too
        for (var ri4 = 0; ri4 < reservedEntries.length; ri4++) {
          if (reservedEntries[ri4].start === blocks[bi].end) {
            exclude[reservedEntries[ri4].playlist.id] = true;
          }
        }

        // Cross-day: find previous day's block (regular OR reserved) that overlaps
        if (d > 0 && allBlocks[d - 1]) {
          for (var pi = 0; pi < allBlocks[d - 1].length; pi++) {
            var prev = allBlocks[d - 1][pi];
            if (prev.start < blocks[bi].end && prev.end > blocks[bi].start) {
              exclude[prev.playlist.id] = true;
              break;
            }
          }
        }

        var candidates = fillPool.filter(function(p) { return !exclude[p.id]; });
        if (candidates.length === 0) candidates = fillPool;
        if (candidates.length === 0) candidates = active;
        var pick = candidates[Math.floor(Math.random() * candidates.length)];

        blocks[bi].playlist = pick;
        prevId = pick.id;

        // If a reserved slot follows, update prevId to the reserved playlist
        for (var ri5 = 0; ri5 < reservedEntries.length; ri5++) {
          if (reservedEntries[ri5].start === blocks[bi].end) {
            prevId = reservedEntries[ri5].playlist.id;
          }
        }

        bulkSchedules.push({
          playlist_id: pick.id,
          days_of_week: [d],
          start_time: formatTimeHHMM(blocks[bi].start),
          end_time: formatTimeHHMM(blocks[bi].end),
          priority: 0
        });
      }

      // Merge regular + reserved into allBlocks for cross-day checks
      allBlocks[d] = blocks.concat(reservedEntries);
      allBlocks[d].sort(function(a, b) { return a.start - b.start; });
    }

    // Single bulk request: clears existing + creates all new schedules
    iRadioAPI.post('/admin/schedules/bulk', {
      clear_existing: true,
      schedules: bulkSchedules
    }).then(function(data) {
      bulkBusy = false;
      showToast(data.created + ' schedules created');
      loadSchedules();
      iRadioAPI.post('/admin/schedules/reload', { force: true }).catch(function() {});
    }).catch(function(err) {
      bulkBusy = false;
      showToast((err && err.error) || 'Surprise Me failed', 'error');
      loadSchedules();
    });
  }

  function handleClearSchedules() {
    if (!schedules.length) {
      showToast('No schedules to clear');
      return;
    }
    iRadioConfirm('Delete all ' + schedules.length + ' schedules?', { title: 'Clear All Schedules', okLabel: 'Clear All' }).then(function(ok) {
      if (!ok) return;
      iRadioAPI.post('/admin/schedules/bulk', {
        clear_existing: true,
        schedules: []
      }).then(function() {
        showToast('All schedules cleared');
        loadSchedules();
        iRadioAPI.post('/admin/schedules/reload', { force: true }).catch(function() {});
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
