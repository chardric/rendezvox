/* ============================================================
   RendezVox Admin — Playlists Management
   ============================================================ */
var RendezVoxPlaylists = (function() {

  var activePlaylistId   = null;
  var activePlaylistName = null;
  var activePlaylistType = null;
  var activeDetailSongs  = [];
  var currentSongId      = null;
  var nextSongId         = null;
  var searchTimer = null;
  var autoShuffledOrder  = null;  // stored song_id order after client-side shuffle
  var detailShowLimit    = 10;   // songs per page (0 = all)
  var detailCurrentPage  = 1;
  var tableShowLimit     = 10;  // playlists per page (0 = all)
  var tableCurrentPage   = 1;
  var tableRegularList   = [];  // cached filtered playlists for pagination

  // ── Emergency playlist state ──
  var emergencyPlaylist = null;  // null = not loaded yet, false = none exists

  // ── Folder list (shared by Add-from-Folder modal) ──
  var allFolders = [];

  // ── Add-song browser state ──
  var selectedSongIds = {};
  var playlistSongIds = {};
  var addSongArtists = [];
  var addSongCategories = [];

  // ── Auto-rules state ──
  var autoCategoriesData = null;
  var autoArtistsData    = null;
  var autoYearsData      = null;

  var PLAYLIST_COLORS = [
    '#ff7800', '#f87171', '#60a5fa', '#fbbf24', '#a78bfa',
    '#f472b6', '#2dd4bf', '#fb923c', '#818cf8', '#34d399',
    '#38bdf8', '#e879f9', '#facc15', '#4ade80', '#fb7185',
    '#22d3ee', '#c084fc', '#fdba74', '#a3e635', '#67e8f9',
    '#f9a8d4', '#86efac', '#94a3b8', '#fde68a'
  ];
  var allPlaylists = [];
  var currentStreamingPlaylistId = null;
  var colorsFixed = false;

  // ── Special playlists state ──
  var specialPlaylistIds = [];
  var specialSlots = [{ start: 4, end: 6 }, { start: 11, end: 13 }]; // hours

  function init() {
    loadPlaylists();

    document.getElementById('btnCreate').addEventListener('click', openCreateModal);
    document.getElementById('btnCancelPl').addEventListener('click', closeModal);
    document.getElementById('playlistForm').addEventListener('submit', handleSave);

    document.getElementById('plTypeSelect').addEventListener('change', function() {
      var type = this.value;
      document.getElementById('plType').value = type;
      toggleAutoRules(type);
    });

    document.getElementById('plColor').addEventListener('input', function() {
      document.getElementById('plColorHex').textContent = this.value;
    });

    document.getElementById('btnCloseDetail').addEventListener('click', function() {
      document.getElementById('detailPanel').classList.add('hidden');
      activePlaylistId   = null;
      activePlaylistName = null;
      activePlaylistType = null;
      autoShuffledOrder  = null;
    });

    document.getElementById('detailShowCount').addEventListener('change', function() {
      detailShowLimit = parseInt(this.value) || 0;
      detailCurrentPage = 1;
      if (activeDetailSongs.length > 0) renderDetailSongs(activeDetailSongs, activePlaylistType);
    });
    document.getElementById('btnPagePrev').addEventListener('click', function() {
      if (detailCurrentPage > 1) { detailCurrentPage--; renderDetailSongs(activeDetailSongs, activePlaylistType); }
    });
    document.getElementById('btnPageNext').addEventListener('click', function() {
      var totalPages = detailShowLimit > 0 ? Math.ceil(activeDetailSongs.length / detailShowLimit) : 1;
      if (detailCurrentPage < totalPages) { detailCurrentPage++; renderDetailSongs(activeDetailSongs, activePlaylistType); }
    });

    // ── Playlists table pagination ──
    document.getElementById('tableShowCount').addEventListener('change', function() {
      tableShowLimit = parseInt(this.value) || 0;
      tableCurrentPage = 1;
      renderTable(tableRegularList);
    });
    document.getElementById('btnTablePrev').addEventListener('click', function() {
      if (tableCurrentPage > 1) { tableCurrentPage--; renderTable(tableRegularList); }
    });
    document.getElementById('btnTableNext').addEventListener('click', function() {
      var totalPages = tableShowLimit > 0 ? Math.ceil(tableRegularList.length / tableShowLimit) : 1;
      if (tableCurrentPage < totalPages) { tableCurrentPage++; renderTable(tableRegularList); }
    });

    document.getElementById('btnShuffle').addEventListener('click', shufflePlaylist);

    // ── Add Songs modal ──
    document.getElementById('btnAddSong').addEventListener('click', openAddSongModal);
    document.getElementById('btnCancelAddSong').addEventListener('click', function() {
      document.getElementById('addSongModal').classList.add('hidden');
    });

    document.getElementById('addSongSearch').addEventListener('input', function() {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(loadAddSongTable, 300);
    });
    document.getElementById('addSongArtist').addEventListener('change', loadAddSongTable);
    document.getElementById('addSongCategory').addEventListener('change', loadAddSongTable);

    document.getElementById('addSongSelectAll').addEventListener('change', handleSelectAll);
    document.getElementById('btnAddSelected').addEventListener('click', addSelectedSongs);
    document.getElementById('btnAddAllFiltered').addEventListener('click', addAllFiltered);

    // ── Surprise Me modal ──
    document.getElementById('btnSurpriseMe').addEventListener('click', openSurpriseModal);
    document.getElementById('btnCancelSurprise').addEventListener('click', function() {
      document.getElementById('surpriseMeModal').classList.add('hidden');
    });
    document.getElementById('btnGoSurprise').addEventListener('click', handleSurpriseGo);

    // ── Surprise Me name generator ──
    document.getElementById('btnSurpriseName').addEventListener('click', function(e) {
      e.preventDefault();
      document.getElementById('plName').value = generateRandomName();
    });

    // ── Add from Folder modal ──
    loadFolders();
    document.getElementById('btnAddFolder').addEventListener('click', openAddFolderModal);
    document.getElementById('btnCancelAddFolder').addEventListener('click', function() {
      document.getElementById('addFolderModal').classList.add('hidden');
    });
    document.getElementById('btnConfirmAddFolder').addEventListener('click', handleAddFolder);

    // ── Bulk selection ──
    document.getElementById('plSelectAll').addEventListener('change', function() {
      var checked = this.checked;
      document.querySelectorAll('#playlistTable .pl-row-check:not(:disabled)').forEach(function(cb) {
        cb.checked = checked;
      });
      updateBulkBar();
    });
    document.getElementById('btnBulkDelete').addEventListener('click', bulkDelete);
    document.getElementById('btnBulkCancel').addEventListener('click', function() {
      document.querySelectorAll('#playlistTable .pl-row-check').forEach(function(cb) { cb.checked = false; });
      document.getElementById('plSelectAll').checked = false;
      hideBulkBar();
    });

    // ── Import from Folders modal ──
    document.getElementById('btnImportFolders').addEventListener('click', openImportFoldersModal);
    document.getElementById('btnCancelImportFolders').addEventListener('click', function() {
      document.getElementById('importFoldersModal').classList.add('hidden');
    });
    document.getElementById('btnConfirmImportFolders').addEventListener('click', handleImportFolders);
    document.getElementById('btnImportSelectAll').addEventListener('click', importSelectAll);
    document.getElementById('btnImportSelectNone').addEventListener('click', importSelectNone);

    // ── Special Playlists ──
    populateSlotDropdowns();
    loadSpecialConfig();
    document.getElementById('btnSaveSpecial').addEventListener('click', saveSpecialConfig);

    // No auto-refresh — user actions already reload data where needed
  }

  // ── Emergency Playlist Card ────────────────────────────

  function renderEmergencyCard(playlists) {
    var ep = null;
    for (var i = 0; i < playlists.length; i++) {
      if (playlists[i].type === 'emergency') { ep = playlists[i]; break; }
    }
    emergencyPlaylist = ep || false;

    var desc = document.getElementById('emergencyDesc');
    var actions = document.getElementById('emergencyActions');

    if (!ep) {
      desc.textContent = 'No emergency playlist configured. Create one to enable emergency mode.';
      actions.innerHTML = '<button type="button" class="btn btn-sm" style="background:var(--danger);color:#fff" onclick="RendezVoxPlaylists.createEmergency()">Create Emergency Playlist</button>';
    } else {
      var songLabel = ep.song_count !== null ? ep.song_count + ' songs' : '—';
      var statusBadge = ep.is_active
        ? '<span class="badge badge-active" title="This playlist will be used when emergency mode is triggered">Ready</span>'
        : '<span class="badge badge-inactive" title="Enable this playlist so it can be used during emergency mode">Disabled</span>';
      var epStreamBadge = (currentStreamingPlaylistId && ep.id === currentStreamingPlaylistId)
        ? ' <span class="streaming-icon" title="Now streaming">ON AIR</span>'
        : '';
      desc.innerHTML = '<strong>' + escHtml(ep.name) + '</strong> — ' + songLabel + ' ' + statusBadge + epStreamBadge;
      actions.innerHTML =
        '<button type="button" class="icon-btn" title="View" onclick="RendezVoxPlaylists.viewDetail(' + ep.id + ')">' + RendezVoxIcons.view + '</button> ' +
        '<button type="button" class="icon-btn" title="Edit" onclick="RendezVoxPlaylists.editPlaylist(' + ep.id + ')">' + RendezVoxIcons.edit + '</button> ' +
        '<button type="button" class="icon-btn danger" title="Delete" onclick="RendezVoxPlaylists.deletePlaylist(' + ep.id + ')">' + RendezVoxIcons.del + '</button>';
    }
  }

  // ── Special Playlists Card ────────────────────────────

  function formatHourLabel(h) {
    if (h === 0 || h === 24) return '12 AM';
    if (h === 12) return '12 PM';
    return (h % 12) + ' ' + (h >= 12 ? 'PM' : 'AM');
  }

  function populateSlotDropdowns() {
    ['specialSlot1Start', 'specialSlot2Start'].forEach(function(id) {
      var sel = document.getElementById(id);
      sel.innerHTML = '<option value="">\u2014 Off \u2014</option>';
      for (var h = 0; h < 24; h++) {
        var opt = document.createElement('option');
        opt.value = h;
        opt.textContent = formatHourLabel(h);
        sel.appendChild(opt);
      }
    });
    ['specialSlot1End', 'specialSlot2End'].forEach(function(id) {
      var sel = document.getElementById(id);
      sel.innerHTML = '';
      for (var h = 1; h <= 24; h++) {
        var opt = document.createElement('option');
        opt.value = h;
        opt.textContent = formatHourLabel(h);
        sel.appendChild(opt);
      }
    });
    applySlotValues();
  }

  function applySlotValues() {
    if (specialSlots[0]) {
      document.getElementById('specialSlot1Start').value = specialSlots[0].start;
      document.getElementById('specialSlot1End').value   = specialSlots[0].end;
    } else {
      document.getElementById('specialSlot1Start').value = '';
    }
    if (specialSlots[1]) {
      document.getElementById('specialSlot2Start').value = specialSlots[1].start;
      document.getElementById('specialSlot2End').value   = specialSlots[1].end;
    } else {
      document.getElementById('specialSlot2Start').value = '';
    }
  }

  function loadSpecialConfig() {
    RendezVoxAPI.get('/admin/settings').then(function(result) {
      (result.settings || []).forEach(function(s) {
        if (s.key === 'schedule_special_playlists') {
          try { specialPlaylistIds = JSON.parse(s.value || '[]'); } catch(e) { specialPlaylistIds = []; }
        }
        if (s.key === 'schedule_special_slots') {
          try { specialSlots = JSON.parse(s.value || '[]'); } catch(e) { specialSlots = []; }
        }
      });
      // Strip out any emergency playlist IDs that may be in the setting
      var emergencyIds = allPlaylists.filter(function(p) { return p.type === 'emergency'; }).map(function(p) { return p.id; });
      specialPlaylistIds = specialPlaylistIds.filter(function(id) { return emergencyIds.indexOf(id) === -1; });
      applySlotValues();
      renderSpecialCard(allPlaylists);
    });
  }

  function renderSpecialCard(playlists) {
    var container = document.getElementById('specialChips');
    if (!container) return;
    var candidates = playlists.filter(function(p) { return p.type !== 'emergency' && p.is_active; });
    if (candidates.length === 0) {
      container.innerHTML = '<span class="text-dim" style="font-size:.8rem">No active playlists</span>';
      return;
    }
    container.innerHTML = '';
    candidates.forEach(function(p) {
      var isSelected = specialPlaylistIds.indexOf(p.id) !== -1;
      var chip = document.createElement('button');
      chip.type = 'button';
      chip.style.cssText = 'display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:16px;font-size:.8rem;cursor:pointer;border:2px solid ' + (isSelected ? 'var(--accent)' : 'transparent') + ';background:' + (p.color || '#666') + '22;color:var(--text);transition:all .15s;line-height:1';
      chip.innerHTML = '<span style="width:8px;height:8px;border-radius:50%;background:' + escHtml(p.color || '#666') + ';flex-shrink:0"></span>' +
        escHtml(p.name) +
        (isSelected ? ' <svg viewBox="0 0 16 16" width="12" height="12" fill="var(--accent)" style="flex-shrink:0"><path d="M6.5 12.5l-4-4 1.4-1.4 2.6 2.6 5.6-5.6 1.4 1.4z"/></svg>' : '');
      chip.addEventListener('click', function() {
        var idx = specialPlaylistIds.indexOf(p.id);
        if (idx !== -1) specialPlaylistIds.splice(idx, 1);
        else specialPlaylistIds.push(p.id);
        renderSpecialCard(allPlaylists);
      });
      container.appendChild(chip);
    });
  }

  function readSlotValues() {
    specialSlots = [];
    var s1 = document.getElementById('specialSlot1Start').value;
    if (s1 !== '') {
      specialSlots.push({ start: parseInt(s1), end: parseInt(document.getElementById('specialSlot1End').value) });
    }
    var s2 = document.getElementById('specialSlot2Start').value;
    if (s2 !== '') {
      specialSlots.push({ start: parseInt(s2), end: parseInt(document.getElementById('specialSlot2End').value) });
    }
  }

  function fmtHHMM(totalMin) {
    if (totalMin === 1440) return '24:00';
    var h = Math.floor(totalMin / 60) % 24;
    var m = totalMin % 60;
    return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
  }

  function saveSpecialConfig() {
    readSlotValues();
    for (var i = 0; i < specialSlots.length; i++) {
      if (specialSlots[i].end <= specialSlots[i].start) {
        showToast('Slot ' + (i + 1) + ': end must be after start', 'error');
        return;
      }
    }
    var btn = document.getElementById('btnSaveSpecial');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    // Save settings
    Promise.all([
      RendezVoxAPI.put('/admin/settings/schedule_special_playlists', { value: JSON.stringify(specialPlaylistIds) }),
      RendezVoxAPI.put('/admin/settings/schedule_special_slots', { value: JSON.stringify(specialSlots) })
    ]).then(function() {
      // Sync actual schedule entries: clear old special, create new ones
      // Each slot gets ONE playlist per day. No same playlist repeats in a day.
      // If more playlists than slots, rotate daily so all get airtime.
      var entries = [];
      if (specialPlaylistIds.length > 0 && specialSlots.length > 0) {
        var pool = allPlaylists.filter(function(p) {
          return specialPlaylistIds.indexOf(p.id) !== -1 && p.is_active && p.type !== 'emergency';
        });
        if (pool.length > 0) {
          if (pool.length <= specialSlots.length) {
            // Fixed assignment — same playlist per slot every day
            var allDays = [0, 1, 2, 3, 4, 5, 6];
            for (var si = 0; si < specialSlots.length; si++) {
              entries.push({
                playlist_id: pool[si % pool.length].id,
                days_of_week: allDays,
                start_time: fmtHHMM(specialSlots[si].start * 60),
                end_time: fmtHHMM(specialSlots[si].end * 60),
                priority: 99
              });
            }
          } else {
            // More playlists than slots — rotate daily
            for (var d = 0; d < 7; d++) {
              for (var si2 = 0; si2 < specialSlots.length; si2++) {
                var pick = pool[(si2 + d) % pool.length];
                entries.push({
                  playlist_id: pick.id,
                  days_of_week: [d],
                  start_time: fmtHHMM(specialSlots[si2].start * 60),
                  end_time: fmtHHMM(specialSlots[si2].end * 60),
                  priority: 99
                });
              }
            }
          }
        }
      }
      return RendezVoxAPI.post('/admin/schedules/bulk', {
        clear_existing: true,
        clear_special: true,
        schedules: entries
      });
    }).then(function() {
      showToast('Special playlists saved');
    }).catch(function(err) {
      showToast((err && err.error) || 'Save failed', 'error');
    }).then(function() {
      btn.disabled = false;
      btn.textContent = 'Save';
    });
  }

  function createEmergency() {
    if (emergencyPlaylist) {
      showToast('Emergency playlist already exists', 'error');
      return;
    }

    RendezVoxAPI.post('/admin/playlists', {
      name: 'Emergency',
      description: 'Fallback playlist for emergency mode',
      type: 'emergency',
      is_active: true
    }).then(function(data) {
      showToast('Emergency playlist created');
      loadPlaylists();
      viewDetail(data.id);
    }).catch(function(err) {
      showToast((err && err.error) || 'Create failed', 'error');
    });
  }

  // ── Auto rules UI ────────────────────────────────────

  function toggleAutoRules(type, rules) {
    var sec = document.getElementById('autoRulesSection');
    if (type === 'auto') {
      sec.classList.remove('hidden');
      var r = rules || {};
      loadCategoriesForAuto(r.categories || []);
      loadArtistsForAuto(r.artists || []);
      loadYearsForAuto(r.years || []);
    } else {
      sec.classList.add('hidden');
    }
  }

  function loadCategoriesForAuto(selectedIds) {
    function renderCats(cats) {
      var sel = document.getElementById('autoCategories');
      if (!cats || cats.length === 0) {
        sel.innerHTML = '<option disabled>No music categories found</option>';
        return;
      }
      var html = '';
      cats.forEach(function(c) {
        var selected = selectedIds && selectedIds.indexOf(c.id) !== -1 ? ' selected' : '';
        html += '<option value="' + c.id + '"' + selected + '>' + escHtml(c.name) + '</option>';
      });
      sel.innerHTML = html;
    }

    if (autoCategoriesData) {
      renderCats(autoCategoriesData);
      return;
    }

    RendezVoxAPI.get('/admin/categories').then(function(data) {
      autoCategoriesData = (data.categories || []).filter(function(c) {
        return c.type === 'music' || !c.type;
      });
      renderCats(autoCategoriesData);
    });
  }

  function loadArtistsForAuto(selectedIds) {
    function renderArtists(artists) {
      var sel = document.getElementById('autoArtists');
      if (!artists || artists.length === 0) {
        sel.innerHTML = '<option disabled>No artists found</option>';
        return;
      }
      var html = '';
      artists.forEach(function(a) {
        var selected = selectedIds && selectedIds.indexOf(a.id) !== -1 ? ' selected' : '';
        html += '<option value="' + a.id + '"' + selected + '>' + escHtml(a.name) + '</option>';
      });
      sel.innerHTML = html;
    }

    if (autoArtistsData) {
      renderArtists(autoArtistsData);
      return;
    }

    RendezVoxAPI.get('/admin/artists').then(function(data) {
      autoArtistsData = (data.artists || []).sort(function(a, b) {
        return a.name.localeCompare(b.name);
      });
      renderArtists(autoArtistsData);
    });
  }

  function loadYearsForAuto(selectedYears) {
    function renderYears(years) {
      var sel = document.getElementById('autoYears');
      if (!years || years.length === 0) {
        sel.innerHTML = '<option disabled>No years found</option>';
        return;
      }
      var html = '';
      years.forEach(function(y) {
        var selected = selectedYears && selectedYears.indexOf(y) !== -1 ? ' selected' : '';
        html += '<option value="' + y + '"' + selected + '>' + y + '</option>';
      });
      sel.innerHTML = html;
    }

    if (autoYearsData) {
      renderYears(autoYearsData);
      return;
    }

    RendezVoxAPI.get('/admin/songs/years').then(function(data) {
      autoYearsData = data.years || [];
      renderYears(autoYearsData);
    });
  }

  function getAutoRulesFromForm() {
    var catSel = document.getElementById('autoCategories');
    var catIds = [];
    for (var i = 0; i < catSel.options.length; i++) {
      if (catSel.options[i].selected) catIds.push(parseInt(catSel.options[i].value));
    }

    var artSel = document.getElementById('autoArtists');
    var artIds = [];
    for (var i = 0; i < artSel.options.length; i++) {
      if (artSel.options[i].selected) artIds.push(parseInt(artSel.options[i].value));
    }

    var yearSel = document.getElementById('autoYears');
    var years = [];
    for (var i = 0; i < yearSel.options.length; i++) {
      if (yearSel.options[i].selected) years.push(parseInt(yearSel.options[i].value));
    }

    return {
      categories: catIds,
      artists: artIds,
      years: years,
      min_weight: parseFloat(document.getElementById('autoMinWeight').value) || 0,
    };
  }

  // ── Playlist CRUD ────────────────────────────────────

  function loadPlaylists() {
    RendezVoxAPI.get('/admin/playlists').then(function(data) {
      allPlaylists = data.playlists || [];
      currentStreamingPlaylistId = data.current_playlist_id || null;
      fixDuplicateColors();
    });
  }

  // Convert 6-digit hex to [r, g, b]
  function hexToRgb(hex) {
    var h = hex.replace('#', '');
    return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
  }

  // Euclidean RGB distance (0–441)
  function colorDistance(a, b) {
    var ra = hexToRgb(a), rb = hexToRgb(b);
    return Math.sqrt(Math.pow(ra[0]-rb[0],2) + Math.pow(ra[1]-rb[1],2) + Math.pow(ra[2]-rb[2],2));
  }

  // Detect visually similar colors (exact OR perceptually close) and silently reassign, then render
  // Only runs the fix-and-save once on first load; subsequent refreshes just render.
  function fixDuplicateColors() {
    var render = function() {
      renderEmergencyCard(allPlaylists);
      renderSpecialCard(allPlaylists);
      var regular = allPlaylists.filter(function(p) { return p.type !== 'emergency'; });
      renderTable(regular);
    };

    if (colorsFixed) { render(); return; }

    var DIST_THRESHOLD = 80;
    var confirmed = [];
    var fixes = [];

    allPlaylists.forEach(function(p) {
      if (!p.color) return;
      var tooClose = confirmed.some(function(c) { return colorDistance(p.color, c) < DIST_THRESHOLD; });
      if (tooClose) {
        fixes.push(p);
      } else {
        confirmed.push(p.color);
      }
    });

    colorsFixed = true;

    if (fixes.length === 0) { render(); return; }

    fixes.forEach(function(p) {
      var newColor = pickDistinctColor(confirmed);
      p.color = newColor;
      confirmed.push(newColor);
    });

    var saves = fixes.map(function(p) {
      return RendezVoxAPI.put('/admin/playlists/' + p.id, { color: p.color });
    });
    Promise.all(saves).then(render).catch(render);
  }

  function getUsedColors(excludeId) {
    var used = {};
    allPlaylists.forEach(function(p) {
      if (p.color && (!excludeId || p.id !== excludeId)) {
        used[p.color.toLowerCase()] = true;
      }
    });
    return used;
  }

  // Pick a color from the palette that is visually distinct from all colors in `takenColors`
  function pickDistinctColor(takenColors) {
    var DIST_THRESHOLD = 80;
    var available = PLAYLIST_COLORS.filter(function(c) {
      return takenColors.every(function(t) { return colorDistance(c, t) >= DIST_THRESHOLD; });
    });
    if (available.length > 0) return available[Math.floor(Math.random() * available.length)];
    // Fall back to least-similar color in the palette
    var best = PLAYLIST_COLORS[0], bestDist = 0;
    PLAYLIST_COLORS.forEach(function(c) {
      var minDist = Math.min.apply(null, takenColors.map(function(t) { return colorDistance(c, t); }));
      if (minDist > bestDist) { bestDist = minDist; best = c; }
    });
    return best;
  }

  function pickRandomColor(excludeId) {
    var used = getUsedColors(excludeId);
    var available = PLAYLIST_COLORS.filter(function(c) { return !used[c.toLowerCase()]; });
    if (available.length === 0) {
      // All preset colors taken — generate a random hex
      var r = Math.floor(Math.random() * 200 + 40);
      var g = Math.floor(Math.random() * 200 + 40);
      var b = Math.floor(Math.random() * 200 + 40);
      return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
    }
    return available[Math.floor(Math.random() * available.length)];
  }

  function isColorDuplicate(color, excludeId) {
    var used = getUsedColors(excludeId);
    return !!used[color.toLowerCase()];
  }

  function renderTable(playlists) {
    tableRegularList = playlists || [];
    var tbody = document.getElementById('playlistTable');
    if (tableRegularList.length === 0) {
      tbody.innerHTML = '<tr><td colspan="8" class="empty">No playlists</td></tr>';
      hideBulkBar();
      document.getElementById('tablePager').classList.add('hidden');
      return;
    }

    var totalCount = tableRegularList.length;
    var totalPages = (tableShowLimit > 0) ? Math.ceil(totalCount / tableShowLimit) : 1;
    if (tableCurrentPage > totalPages) tableCurrentPage = totalPages || 1;
    var startIdx = (tableShowLimit > 0) ? (tableCurrentPage - 1) * tableShowLimit : 0;
    var visible = (tableShowLimit > 0) ? tableRegularList.slice(startIdx, startIdx + tableShowLimit) : tableRegularList;

    var html = '';
    visible.forEach(function(p, idx) {
      var typeCls = p.type === 'auto' ? 'badge-request' : 'badge-rotation';
      var typeLabel = p.type.charAt(0).toUpperCase() + p.type.slice(1);
      var songCount = p.song_count !== null ? p.song_count : '<span title="Dynamic">&mdash;</span>';
      var swatch = p.color ? '<span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:' + escHtml(p.color) + ';vertical-align:middle;margin-right:6px"></span>' : '';
      var isStreaming = !!(currentStreamingPlaylistId && p.id === currentStreamingPlaylistId);
      var streamingBadge = isStreaming
        ? ' <span class="streaming-icon" title="Now streaming">ON AIR</span>'
        : '';
      var toggleChecked = p.is_active ? ' checked' : '';
      var trAttr = isStreaming
        ? ' class="on-air" style="--pl-color:' + escHtml(p.color || '#ff7800') + '"'
        : '';
      html += '<tr' + trAttr + '>' +
        '<td><input type="checkbox" class="pl-row-check" data-id="' + p.id + '" data-name="' + escHtml(p.name) + '"' + (isStreaming ? ' disabled title="Currently streaming"' : '') + ' style="width:16px;height:16px;cursor:' + (isStreaming ? 'not-allowed' : 'pointer') + ';accent-color:var(--accent)"></td>' +
        '<td>' + (startIdx + idx + 1) + '</td>' +
        '<td>' + swatch + escHtml(p.name) + streamingBadge + '</td>' +
        '<td><span class="badge ' + typeCls + '">' + typeLabel + '</span></td>' +
        '<td>' + songCount + '</td>' +
        '<td>' + p.cycle_count + '</td>' +
        '<td><label class="toggle toggle-sm"><input type="checkbox" onchange="RendezVoxPlaylists.toggleActive(' + p.id + ',this.checked)"' + toggleChecked + '><span class="slider"></span></label></td>' +
        '<td style="white-space:nowrap">' +
          '<button type="button" class="icon-btn" title="View" onclick="RendezVoxPlaylists.viewDetail(' + p.id + ')">' + RendezVoxIcons.view + '</button> ' +
          '<label class="icon-btn" title="Change color" style="position:relative;cursor:pointer;color:' + escHtml(p.color || '#ff7800') + '">' + RendezVoxIcons.palette + '<input type="color" value="' + escHtml(p.color || '#ff7800') + '" onchange="RendezVoxPlaylists.changeColor(' + p.id + ',this.value)" style="position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;cursor:pointer"></label> ' +
          '<button type="button" class="icon-btn" title="Edit" onclick="RendezVoxPlaylists.editPlaylist(' + p.id + ')">' + RendezVoxIcons.edit + '</button> ' +
          '<button type="button" class="icon-btn danger" title="Delete" onclick="RendezVoxPlaylists.deletePlaylist(' + p.id + ')">' + RendezVoxIcons.del + '</button>' +
        '</td>' +
        '</tr>';
    });
    tbody.innerHTML = html;

    // Wire up row checkboxes
    var checks = tbody.querySelectorAll('.pl-row-check');
    checks.forEach(function(cb) {
      cb.addEventListener('change', updateBulkBar);
    });
    document.getElementById('plSelectAll').checked = false;
    hideBulkBar();

    // Update pagination bar
    var pager = document.getElementById('tablePager');
    if (tableShowLimit > 0 && totalPages > 1) {
      pager.classList.remove('hidden');
      document.getElementById('tablePageInfo').textContent = 'Page ' + tableCurrentPage + ' of ' + totalPages;
      document.getElementById('btnTablePrev').disabled = (tableCurrentPage <= 1);
      document.getElementById('btnTableNext').disabled = (tableCurrentPage >= totalPages);
    } else {
      pager.classList.add('hidden');
    }
  }

  function getSelectedPlaylists() {
    var checks = document.querySelectorAll('#playlistTable .pl-row-check:checked');
    var selected = [];
    checks.forEach(function(cb) {
      selected.push({ id: parseInt(cb.getAttribute('data-id')), name: cb.getAttribute('data-name') });
    });
    return selected;
  }

  function updateBulkBar() {
    var selected = getSelectedPlaylists();
    var bar = document.getElementById('bulkBar');
    if (selected.length > 0) {
      bar.classList.remove('hidden');
      bar.style.display = 'flex';
      document.getElementById('bulkCount').textContent = selected.length + ' playlist' + (selected.length !== 1 ? 's' : '') + ' selected';
    } else {
      hideBulkBar();
    }
    // Update select-all state
    var all = document.querySelectorAll('#playlistTable .pl-row-check:not(:disabled)');
    var allChecked = all.length > 0;
    all.forEach(function(cb) { if (!cb.checked) allChecked = false; });
    document.getElementById('plSelectAll').checked = allChecked && all.length > 0;
  }

  function hideBulkBar() {
    var bar = document.getElementById('bulkBar');
    bar.classList.add('hidden');
    bar.style.display = 'none';
  }

  function bulkDelete() {
    var selected = getSelectedPlaylists();
    if (selected.length === 0) return;

    var names = selected.slice(0, 3).map(function(s) { return s.name; }).join(', ');
    if (selected.length > 3) names += ' + ' + (selected.length - 3) + ' more';

    RendezVoxConfirm('Delete ' + selected.length + ' playlist' + (selected.length !== 1 ? 's' : '') + '?\n' + names, {
      title: 'Bulk Delete', okLabel: 'Delete All'
    }).then(function(ok) {
      if (!ok) return;

      var btn = document.getElementById('btnBulkDelete');
      btn.disabled = true;
      btn.textContent = 'Deleting…';

      var items = selected.slice();
      var done = 0;
      var failedNames = [];

      function deleteNext() {
        if (items.length === 0) {
          btn.disabled = false;
          btn.textContent = 'Delete Selected';
          hideBulkBar();
          var msg = done + ' playlist' + (done !== 1 ? 's' : '') + ' deleted';
          if (failedNames.length > 0) msg += '. Failed: ' + failedNames.join(', ');
          showToast(msg, failedNames.length > 0 ? 'error' : 'success');
          loadPlaylists();
          if (done > 0) RendezVoxAPI.post('/admin/schedules/reload', {}).catch(function() {});
          return;
        }
        var item = items.shift();
        RendezVoxAPI.del('/admin/playlists/' + item.id).then(function() {
          done++;
          deleteNext();
        }).catch(function(err) {
          failedNames.push(item.name + ' (' + ((err && err.error) || 'error') + ')');
          deleteNext();
        });
      }
      deleteNext();
    });
  }

  function toggleActive(id, active) {
    RendezVoxAPI.put('/admin/playlists/' + id, { is_active: active }).then(function() {
      showToast(active ? 'Playlist activated' : 'Playlist deactivated');
      // Notify stream in case this playlist is currently scheduled
      RendezVoxAPI.post('/admin/schedules/reload', {}).catch(function() {});
    }).catch(function(err) {
      showToast((err && err.error) || 'Update failed', 'error');
      loadPlaylists();
    });
  }

  function changeColor(id, color) {
    if (isColorDuplicate(color, id)) {
      showToast('This color is already used by another playlist', 'error');
      loadPlaylists();
      return;
    }
    RendezVoxAPI.put('/admin/playlists/' + id, { color: color }).then(function() {
      loadPlaylists();
    }).catch(function(err) {
      showToast((err && err.error) || 'Update failed', 'error');
      loadPlaylists();
    });
  }

  function openCreateModal() {
    document.getElementById('playlistModalTitle').textContent = 'New Playlist';
    document.getElementById('plId').value = '';
    document.getElementById('plType').value = 'manual';
    document.getElementById('playlistForm').reset();
    document.getElementById('plActive').checked = true;
    document.getElementById('plTypeSelect').value = 'manual';
    document.getElementById('plTypeSelect').disabled = false;
    document.getElementById('typeSelectWrap').style.display = '';
    document.getElementById('autoMinWeight').value = '0';
    var nextColor = pickRandomColor();
    document.getElementById('plColor').value = nextColor;
    document.getElementById('plColorHex').textContent = nextColor;
    document.getElementById('colorPickerWrap').style.display = 'none';
    toggleAutoRules('manual');
    document.getElementById('playlistModal').classList.remove('hidden');
  }

  function editPlaylist(id) {
    RendezVoxAPI.get('/admin/playlists/' + id).then(function(data) {
      var p = data.playlist;
      document.getElementById('playlistModalTitle').textContent = 'Edit Playlist';
      document.getElementById('plId').value      = p.id;
      document.getElementById('plName').value    = p.name;
      document.getElementById('plDesc').value    = p.description || '';
      document.getElementById('plType').value    = p.type;
      document.getElementById('plActive').checked = p.is_active;
      var color = p.color || '#ff7800';
      document.getElementById('plColor').value = color;
      document.getElementById('plColorHex').textContent = color;
      document.getElementById('colorPickerWrap').style.display = '';

      // Hide type selector and color picker for emergency playlists
      var typeWrap = document.getElementById('typeSelectWrap');
      var colorWrap = document.getElementById('colorPickerWrap');
      var typeSelect = document.getElementById('plTypeSelect');
      if (p.type === 'emergency') {
        typeWrap.style.display = 'none';
        colorWrap.style.display = 'none';
      } else {
        typeWrap.style.display = '';
        colorWrap.style.display = '';
        typeSelect.value = p.type;
        typeSelect.disabled = true; // type is locked after creation
      }

      var rules = p.rules || {};
      document.getElementById('autoMinWeight').value = rules.min_weight || 0;
      toggleAutoRules(p.type, rules);

      document.getElementById('playlistModal').classList.remove('hidden');
    });
  }

  function closeModal() {
    document.getElementById('playlistModal').classList.add('hidden');
  }

  function handleSave(e) {
    e.preventDefault();
    var id   = document.getElementById('plId').value;
    var type = document.getElementById('plType').value;
    var color = document.getElementById('plColor').value;

    // Check color duplicate (for edits; creates use auto-picked color)
    if (id && isColorDuplicate(color, parseInt(id))) {
      showToast('This color is already used by another playlist', 'error');
      return;
    }

    var body = {
      name:        document.getElementById('plName').value.trim(),
      description: document.getElementById('plDesc').value.trim(),
      is_active:   document.getElementById('plActive').checked,
      color:       color,
      rules:       type === 'auto' ? getAutoRulesFromForm() : null,
    };

    // Only send type on create (not update — type is locked)
    if (!id) {
      body.type = type;
    }

    var promise = id
      ? RendezVoxAPI.put('/admin/playlists/' + id, body)
      : RendezVoxAPI.post('/admin/playlists', body);

    promise.then(function() {
      showToast(id ? 'Playlist updated' : 'Playlist created');
      closeModal();
      loadPlaylists();
      if (activePlaylistId) loadDetail(activePlaylistId);
    }).catch(function(err) {
      showToast((err && err.error) || 'Save failed', 'error');
    });
  }

  function deletePlaylist(id) {
    RendezVoxConfirm('Delete this playlist?', { title: 'Delete Playlist', okLabel: 'Delete' }).then(function(ok) {
      if (!ok) return;

      RendezVoxAPI.del('/admin/playlists/' + id).then(function() {
        showToast('Playlist deleted');
        if (activePlaylistId === id) {
          document.getElementById('detailPanel').classList.add('hidden');
          activePlaylistId   = null;
          activePlaylistName = null;
          activePlaylistType = null;
        }
        loadPlaylists();
        RendezVoxAPI.post('/admin/schedules/reload', {}).catch(function() {});
      }).catch(function(err) {
        showToast((err && err.error) || 'Delete failed', 'error');
      });
    });
  }

  // ── Detail / Songs ──────────────────────────────────

  function viewDetail(id) {
    if (activePlaylistId !== id) {
      autoShuffledOrder = null;
      detailCurrentPage = 1;
    }
    activePlaylistId = id;
    loadDetail(id);
  }

  function loadDetail(id) {
    RendezVoxAPI.get('/admin/playlists/' + id).then(function(data) {
      var pl     = data.playlist;
      var isAuto = pl.type === 'auto';
      activePlaylistType = pl.type;
      activePlaylistName = pl.name;

      // Track which songs are currently playing / up next
      currentSongId = data.current_song_id || null;
      nextSongId    = data.next_song_id || null;

      var songCount = (data.songs || []).length;
      document.getElementById('detailTitle').textContent =
        pl.name + (isAuto ? ' — ' + songCount + ' Matching Songs (dynamic)' : ' — ' + songCount + ' Songs');

      // Show/hide controls
      document.getElementById('btnShuffle').style.display     = '';
      document.getElementById('btnAddSong').style.display     = isAuto ? 'none' : '';
      document.getElementById('btnAddFolder').style.display   = isAuto ? 'none' : '';
      document.getElementById('btnSurpriseMe').style.display  = isAuto ? 'none' : '';
      var note = document.getElementById('autoDetailNote');
      if (isAuto) note.classList.remove('hidden');
      else        note.classList.add('hidden');

      activeDetailSongs = data.songs || [];
      renderDetailSongs(activeDetailSongs, pl.type);
      var panel = document.getElementById('detailPanel');
      panel.classList.remove('hidden');
      panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
  }

  function renderDetailSongs(songs, type) {
    var isAuto = type === 'auto';
    var thead  = document.getElementById('detailHead');
    var tbody  = document.getElementById('detailSongs');

    thead.innerHTML = '<tr><th>#</th><th>Title</th><th>Artist</th><th>Genre/Category</th><th>Duration</th><th>Played</th><th>Actions</th></tr>';

    if (!songs || songs.length === 0) {
      var msg = isAuto ? 'No songs match the current rules' : 'No songs in playlist';
      tbody.innerHTML = '<tr><td colspan="7" class="empty">' + msg + '</td></tr>';
      return;
    }

    // Re-sort: Played (Yes) → Playing → Up Next → Unplayed (No)
    var playedSongs = [];
    var curSong = null;
    var nxtSong = null;
    var unplayedSongs = [];
    songs.forEach(function(s) {
      if (s.song_id === currentSongId) curSong = s;
      else if (s.song_id === nextSongId) nxtSong = s;
      else if (s.played_in_cycle) playedSongs.push(s);
      else unplayedSongs.push(s);
    });

    // If auto playlist has a stored shuffle order, preserve it
    if (isAuto && autoShuffledOrder) {
      var orderMap = {};
      autoShuffledOrder.forEach(function(id, idx) { orderMap[id] = idx; });
      var byOrder = function(a, b) {
        var ai = orderMap[a.song_id] !== undefined ? orderMap[a.song_id] : 999999;
        var bi = orderMap[b.song_id] !== undefined ? orderMap[b.song_id] : 999999;
        return ai - bi;
      };
      playedSongs.sort(byOrder);
      unplayedSongs.sort(byOrder);
    }

    songs = playedSongs.slice();
    if (curSong) songs.push(curSong);
    if (nxtSong) songs.push(nxtSong);
    songs = songs.concat(unplayedSongs);
    for (var p = 0; p < songs.length; p++) {
      songs[p] = Object.assign({}, songs[p], { position: p + 1 });
    }

    var totalCount = songs.length;
    var totalPages = (detailShowLimit > 0) ? Math.ceil(totalCount / detailShowLimit) : 1;
    if (detailCurrentPage > totalPages) detailCurrentPage = totalPages || 1;
    var startIdx = (detailShowLimit > 0) ? (detailCurrentPage - 1) * detailShowLimit : 0;
    var visible = (detailShowLimit > 0) ? songs.slice(startIdx, startIdx + detailShowLimit) : songs;

    var html = '';
    visible.forEach(function(s, idx) {
      var rowNum = s.position;
      var playedCol;
      if (s.song_id === currentSongId) {
        playedCol = '<span class="badge" style="background:var(--accent);color:#fff">Playing</span>';
      } else if (s.song_id === nextSongId) {
        playedCol = '<span class="badge" style="background:#6366f1;color:#fff">Up Next</span>';
      } else if (s.played_in_cycle) {
        playedCol = 'Yes';
      } else {
        playedCol = 'No';
      }
      var actionCell = '<button type="button" class="icon-btn danger" title="Remove" onclick="RendezVoxPlaylists.removeSong(' + s.song_id + ')">' + RendezVoxIcons.remove + '</button>';
      var rowAttr    = isAuto
        ? ''
        : ' draggable="true" data-song-id="' + s.song_id + '"';

      html += '<tr' + rowAttr + '>' +
        '<td>' + rowNum + '</td>' +
        '<td>' + escHtml(s.title) + '</td>' +
        '<td>' + escHtml(s.artist) + '</td>' +
        '<td>' + escHtml(s.category) + '</td>' +
        '<td>' + formatDuration(s.duration_ms) + '</td>' +
        '<td>' + playedCol + '</td>' +
        '<td>' + actionCell + '</td>' +
        '</tr>';
    });
    tbody.innerHTML = html;

    // Update pagination bar
    var pager = document.getElementById('detailPager');
    if (detailShowLimit > 0 && totalPages > 1) {
      pager.classList.remove('hidden');
      document.getElementById('pageInfo').textContent = 'Page ' + detailCurrentPage + ' of ' + totalPages;
      document.getElementById('btnPagePrev').disabled = (detailCurrentPage <= 1);
      document.getElementById('btnPageNext').disabled = (detailCurrentPage >= totalPages);
    } else {
      pager.classList.add('hidden');
    }

    if (!isAuto) {
      setupDragDrop();
    }
  }

  function removeSong(songId) {
    if (!activePlaylistId) return;

    RendezVoxAPI.del('/admin/playlists/' + activePlaylistId + '/songs/' + songId).then(function() {
      showToast('Song removed');
      loadDetail(activePlaylistId);
      loadPlaylists();
    }).catch(function(err) {
      showToast((err && err.error) || 'Remove failed', 'error');
    });
  }

  function shufflePlaylist() {
    if (!activePlaylistId) return;

    if (activePlaylistType === 'auto') {
      // Client-side shuffle: only shuffle unplayed songs
      // Keep played at top, Playing/Up Next at the boundary
      var played = [];
      var curSong = null;
      var nxtSong = null;
      var unplayed = [];
      activeDetailSongs.forEach(function(s) {
        if (s.song_id === currentSongId) curSong = s;
        else if (s.song_id === nextSongId) nxtSong = s;
        else if (s.played_in_cycle) played.push(s);
        else unplayed.push(s);
      });
      // Fisher-Yates on unplayed portion only
      for (var i = unplayed.length - 1; i > 0; i--) {
        var j = Math.floor(Math.random() * (i + 1));
        var tmp = unplayed[i]; unplayed[i] = unplayed[j]; unplayed[j] = tmp;
      }
      var arr = played.slice();
      if (curSong) arr.push(curSong);
      if (nxtSong) arr.push(nxtSong);
      arr = arr.concat(unplayed);
      // Re-number positions
      for (var k = 0; k < arr.length; k++) {
        arr[k] = Object.assign({}, arr[k], { position: k + 1 });
      }
      // Store shuffled order so auto-refresh preserves it
      autoShuffledOrder = arr.map(function(s) { return s.song_id; });
      activeDetailSongs = arr;
      renderDetailSongs(arr, 'auto');
      showToast(unplayed.length + ' unplayed songs shuffled');
      return;
    }

    RendezVoxAPI.post('/admin/playlists/' + activePlaylistId + '/shuffle', {}).then(function() {
      showToast('Playlist shuffled');
      loadDetail(activePlaylistId);
    }).catch(function(err) {
      showToast((err && err.error) || 'Shuffle failed', 'error');
    });
  }

  // ── Add Songs browser ─────────────────────────────────

  function openAddSongModal() {
    playlistSongIds = {};
    var rows = document.getElementById('detailSongs').querySelectorAll('tr[data-song-id]');
    rows.forEach(function(r) {
      playlistSongIds[r.getAttribute('data-song-id')] = true;
    });

    selectedSongIds = {};
    document.getElementById('addSongSearch').value = '';
    document.getElementById('addSongArtist').value = '';
    document.getElementById('addSongCategory').value = '';
    document.getElementById('addSongSelectAll').checked = false;
    document.getElementById('addSongModalTitle').textContent =
      'Add Songs to ' + (activePlaylistName || 'Playlist');
    updateSelectionCount();

    if (addSongArtists.length === 0) {
      RendezVoxAPI.get('/admin/artists').then(function(data) {
        addSongArtists = data.artists || [];
        populateArtistDropdown();
      });
    }
    if (addSongCategories.length === 0) {
      RendezVoxAPI.get('/admin/categories').then(function(data) {
        addSongCategories = data.categories || [];
        populateCategoryDropdown();
      });
    }

    loadAddSongTable();
    document.getElementById('addSongModal').classList.remove('hidden');
  }

  function populateArtistDropdown() {
    var sel = document.getElementById('addSongArtist');
    var html = '<option value="">All Artists</option>';
    addSongArtists.forEach(function(a) {
      html += '<option value="' + a.id + '">' + escHtml(a.name) + '</option>';
    });
    sel.innerHTML = html;
  }

  function populateCategoryDropdown() {
    var sel = document.getElementById('addSongCategory');
    var html = '<option value="">All Genres/Categories</option>';
    addSongCategories.forEach(function(c) {
      html += '<option value="' + c.id + '">' + escHtml(c.name) + '</option>';
    });
    sel.innerHTML = html;
  }

  function loadAddSongTable() {
    var search   = document.getElementById('addSongSearch').value.trim();
    var artistId = document.getElementById('addSongArtist').value;
    var catId    = document.getElementById('addSongCategory').value;

    var q = '?per_page=500&active=true';
    if (search)   q += '&search='      + encodeURIComponent(search);
    if (artistId) q += '&artist_id='   + artistId;
    if (catId)    q += '&category_id=' + catId;

    RendezVoxAPI.get('/admin/songs' + q).then(function(data) {
      var songs = data.songs || [];
      renderAddSongTable(songs);
      var available = songs.filter(function(s) { return !playlistSongIds[s.id]; }).length;
      document.getElementById('addSongResultCount').textContent =
        songs.length + ' songs found' + (available < songs.length ? ' (' + available + ' available)' : '');
    });
  }

  function renderAddSongTable(songs) {
    var tbody = document.getElementById('addSongTable');
    if (!songs || songs.length === 0) {
      tbody.innerHTML = '<tr><td colspan="5" class="empty">No songs found</td></tr>';
      return;
    }

    var html = '';
    songs.forEach(function(s) {
      var inPlaylist = !!playlistSongIds[s.id];
      var checked = !!selectedSongIds[s.id];
      var rowStyle = inPlaylist ? ' style="opacity:0.4"' : '';
      var rowTitle = inPlaylist ? ' title="Already in playlist"' : '';

      html += '<tr' + rowStyle + rowTitle + '>' +
        '<td style="text-align:center">' +
          (inPlaylist
            ? '<input type="checkbox" disabled style="width:16px;height:16px;cursor:not-allowed">'
            : '<input type="checkbox" class="song-check" data-id="' + s.id + '"' +
              (checked ? ' checked' : '') + ' style="width:16px;height:16px;cursor:pointer;accent-color:var(--accent)">') +
        '</td>' +
        '<td>' + escHtml(s.title) + '</td>' +
        '<td>' + escHtml(s.artist_name) + '</td>' +
        '<td>' + escHtml(s.category_name) + '</td>' +
        '<td>' + formatDuration(s.duration_ms) + '</td>' +
        '</tr>';
    });
    tbody.innerHTML = html;

    var checks = tbody.querySelectorAll('.song-check');
    checks.forEach(function(cb) {
      cb.addEventListener('change', function() {
        var sid = parseInt(this.getAttribute('data-id'));
        if (this.checked) {
          selectedSongIds[sid] = true;
        } else {
          delete selectedSongIds[sid];
        }
        updateSelectionCount();
        updateSelectAllState();
      });
    });

    updateSelectAllState();
  }

  function updateSelectionCount() {
    var count = Object.keys(selectedSongIds).length;
    var btnAdd = document.getElementById('btnAddSelected');
    var btnAll = document.getElementById('btnAddAllFiltered');
    btnAdd.textContent = 'Add Selected (' + count + ')';
    btnAdd.disabled = count === 0;
    // Promote "Add All" when nothing manually selected, demote when selections exist
    if (count > 0) {
      btnAdd.className = 'btn btn-primary';
      btnAll.className = 'btn btn-ghost';
    } else {
      btnAll.className = 'btn btn-primary';
      btnAdd.className = 'btn btn-ghost';
    }
  }

  function updateSelectAllState() {
    var checks = document.querySelectorAll('#addSongTable .song-check');
    var allChecked = checks.length > 0;
    checks.forEach(function(cb) {
      if (!cb.checked) allChecked = false;
    });
    document.getElementById('addSongSelectAll').checked = allChecked && checks.length > 0;
  }

  function handleSelectAll() {
    var checked = document.getElementById('addSongSelectAll').checked;
    var checks = document.querySelectorAll('#addSongTable .song-check');
    checks.forEach(function(cb) {
      cb.checked = checked;
      var sid = parseInt(cb.getAttribute('data-id'));
      if (checked) {
        selectedSongIds[sid] = true;
      } else {
        delete selectedSongIds[sid];
      }
    });
    updateSelectionCount();
  }

  function addSelectedSongs() {
    if (!activePlaylistId) return;
    var ids = Object.keys(selectedSongIds).map(Number);
    if (ids.length === 0) return;

    var btn = document.getElementById('btnAddSelected');
    btn.disabled = true;
    btn.textContent = 'Adding…';

    RendezVoxAPI.post('/admin/playlists/' + activePlaylistId + '/songs/bulk', {
      song_ids: ids
    }).then(function(data) {
      showToast(ids.length + ' song' + (ids.length !== 1 ? 's' : '') + ' added');
      document.getElementById('addSongModal').classList.add('hidden');
      selectedSongIds = {};
      loadDetail(activePlaylistId);
      loadPlaylists();
    }).catch(function(err) {
      btn.disabled = false;
      btn.textContent = 'Add Selected (' + ids.length + ')';
      showToast((err && err.error) || 'Bulk add failed', 'error');
    });
  }

  function addAllFiltered() {
    if (!activePlaylistId) return;

    var search   = document.getElementById('addSongSearch').value.trim();
    var artistId = document.getElementById('addSongArtist').value;
    var catId    = document.getElementById('addSongCategory').value;

    var filterDesc = 'all songs';
    if (search) {
      filterDesc = 'songs matching "' + search + '"';
    } else if (artistId) {
      var sel = document.getElementById('addSongArtist');
      filterDesc = 'all songs by ' + sel.options[sel.selectedIndex].text;
    } else if (catId) {
      var sel2 = document.getElementById('addSongCategory');
      filterDesc = 'all songs in ' + sel2.options[sel2.selectedIndex].text;
    }

    RendezVoxConfirm('Add ' + filterDesc + ' to this playlist?', { title: 'Add Songs', okLabel: 'Add', okClass: 'btn-primary' }).then(function(ok) {
      if (!ok) return;

      var q = '?per_page=5000&active=true';
      if (search)   q += '&search='      + encodeURIComponent(search);
      if (artistId) q += '&artist_id='   + artistId;
      if (catId)    q += '&category_id=' + catId;

      var btn = document.getElementById('btnAddAllFiltered');
      btn.disabled = true;
      btn.textContent = 'Adding…';

      RendezVoxAPI.get('/admin/songs' + q).then(function(data) {
        var ids = data.songs.map(function(s) { return s.id; });
        ids = ids.filter(function(sid) { return !playlistSongIds[sid]; });

        if (ids.length === 0) {
          showToast('All matching songs are already in the playlist');
          btn.disabled = false;
          btn.textContent = 'Add All Filtered';
          return;
        }

        return RendezVoxAPI.post('/admin/playlists/' + activePlaylistId + '/songs/bulk', {
          song_ids: ids
        }).then(function(result) {
          showToast(ids.length + ' song' + (ids.length !== 1 ? 's' : '') + ' added');
          document.getElementById('addSongModal').classList.add('hidden');
          selectedSongIds = {};
          loadDetail(activePlaylistId);
          loadPlaylists();
        });
      }).catch(function(err) {
        showToast((err && err.error) || 'Add all failed', 'error');
      }).finally(function() {
        btn.disabled = false;
        btn.textContent = 'Add All Filtered';
      });
    });
  }

  // ── Shared collapsible folder tree ──────────────────

  // Folders to skip as tree nodes (their children get promoted)
  var SKIP_FOLDERS = { '/': true, '/imports': true, '/upload': true, '/tagged': true };

  /**
   * Build a tree structure from flat folder list.
   * Each node: { name, path, depth, song_count?, children: [] }
   * Skips wrapper folders like /imports, /tagged and /upload, promoting their children.
   */
  function buildFolderTree(folders) {
    var roots = [];
    var stack = []; // stack of { node, depth }

    folders.forEach(function(f) {
      if (SKIP_FOLDERS[f.path]) {
        // Clean up the stack so children of skipped folders don't nest under previous siblings
        while (stack.length > 0 && stack[stack.length - 1].depth >= f.depth) {
          stack.pop();
        }
        return;
      }

      var node = {
        name: f.name,
        path: f.path,
        depth: f.depth,
        song_count: f.song_count,
        file_count: f.file_count,
        children: []
      };

      // Pop stack until we find a parent (depth < current depth)
      while (stack.length > 0 && stack[stack.length - 1].depth >= f.depth) {
        stack.pop();
      }

      if (stack.length === 0) {
        roots.push(node);
      } else {
        stack[stack.length - 1].node.children.push(node);
      }
      stack.push({ node: node, depth: f.depth });
    });
    return roots;
  }

  /**
   * Render a collapsible folder tree into a container.
   * opts.mode = 'select' (single click to select) or 'check' (checkboxes)
   * opts.onSelect(path)  — called when a folder is clicked in select mode
   * opts.onChange()       — called when a checkbox changes in check mode
   * opts.existingNames   — map of lowercase names that already exist (for badges)
   */
  function renderFolderTree(container, roots, opts) {
    if (roots.length === 0) {
      container.innerHTML = '<p class="text-dim" style="margin:0;font-size:.85rem">No folders found</p>';
      return;
    }
    container.innerHTML = '';
    roots.forEach(function(node) {
      renderTreeNode(container, node, opts, 0);
    });
  }

  function renderTreeNode(parent, node, opts, level) {
    var hasChildren = node.children.length > 0;
    var fileCount = node.file_count || 0;
    var songCount = node.song_count || 0;
    var exists = opts.existingNames && !!opts.existingNames[node.name.toLowerCase()];
    // Disable only if playlist exists OR no audio files on disk at all
    var disabled = opts.mode === 'check' && (exists || fileCount === 0);

    // Row
    var row = document.createElement('div');
    row.style.cssText = 'display:flex;align-items:center;gap:6px;padding:2px 0;padding-left:' + (level * 18) + 'px;';
    if (disabled) row.style.opacity = '0.5';

    // Toggle arrow
    var arrow = document.createElement('span');
    arrow.style.cssText = 'width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;cursor:' + (hasChildren ? 'pointer' : 'default') + ';font-size:12px;color:var(--text-dim);user-select:none;';
    arrow.textContent = hasChildren ? '\u25B6' : '';
    row.appendChild(arrow);

    if (opts.mode === 'check') {
      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.className = 'import-folder-check';
      cb.setAttribute('data-path', node.path);
      cb.setAttribute('data-name', node.name);
      cb.disabled = disabled;
      cb.style.cssText = 'width:16px;height:16px;flex-shrink:0;cursor:' + (disabled ? 'not-allowed' : 'pointer') + ';accent-color:var(--accent);margin:0;';
      cb.addEventListener('change', function() {
        // Propagate to child checkboxes only if this node has a children container
        if (hasChildren) {
          var childBox = row.nextElementSibling;
          if (childBox) {
            var childChecks = childBox.querySelectorAll('.import-folder-check:not(:disabled)');
            childChecks.forEach(function(c) { c.checked = cb.checked; });
          }
        }
        if (opts.onChange) opts.onChange();
      });
      row.appendChild(cb);
    }

    // Folder name (clickable in select mode)
    var label = document.createElement('span');
    label.style.cssText = 'font-size:.85rem;cursor:' + (opts.mode === 'select' ? 'pointer' : 'default') + ';flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
    label.textContent = node.name;
    if (opts.mode === 'select') {
      label.setAttribute('data-path', node.path);
      label.addEventListener('click', function() {
        // Deselect previous
        var prev = parent.closest('.modal').querySelector('[data-folder-sel]');
        if (prev) { prev.style.background = ''; prev.removeAttribute('data-folder-sel'); }
        row.style.background = 'rgba(0,200,160,0.15)';
        row.setAttribute('data-folder-sel', '1');
        if (opts.onSelect) opts.onSelect(node.path, node.name);
      });
      label.addEventListener('mouseenter', function() { if (!row.hasAttribute('data-folder-sel')) row.style.background = 'rgba(255,255,255,0.05)'; });
      label.addEventListener('mouseleave', function() { if (!row.hasAttribute('data-folder-sel')) row.style.background = ''; });
    }
    row.appendChild(label);

    // File count (actual audio files on disk)
    if (node.file_count !== undefined) {
      var count = document.createElement('span');
      count.className = 'text-dim';
      count.style.cssText = 'font-size:.75rem;margin-left:auto;white-space:nowrap;flex-shrink:0;';
      count.textContent = fileCount + ' files';
      row.appendChild(count);
    }

    // Badges
    if (exists) {
      var badge = document.createElement('span');
      badge.className = 'badge badge-inactive';
      badge.style.cssText = 'font-size:.7rem;padding:1px 5px;flex-shrink:0;';
      badge.textContent = 'exists';
      row.appendChild(badge);
    } else if (fileCount > 0 && songCount === 0) {
      var unscanBadge = document.createElement('span');
      unscanBadge.className = 'badge';
      unscanBadge.style.cssText = 'font-size:.7rem;padding:1px 5px;flex-shrink:0;background:#fbbf24;color:#000;';
      unscanBadge.textContent = 'not scanned';
      row.appendChild(unscanBadge);
    }

    parent.appendChild(row);

    // Children container (hidden by default)
    if (hasChildren) {
      var childBox = document.createElement('div');
      childBox.style.display = 'none';

      var expanded = false;
      arrow.addEventListener('click', function() {
        expanded = !expanded;
        arrow.textContent = expanded ? '\u25BC' : '\u25B6';
        childBox.style.display = expanded ? '' : 'none';
      });

      // Also toggle on double-click of the label
      label.addEventListener('dblclick', function() {
        expanded = !expanded;
        arrow.textContent = expanded ? '\u25BC' : '\u25B6';
        childBox.style.display = expanded ? '' : 'none';
      });

      node.children.forEach(function(child) {
        renderTreeNode(childBox, child, opts, level + 1);
      });
      parent.appendChild(childBox);
    }
  }

  // ── Add from Folder ──────────────────────────────────

  function loadFolders() {
    RendezVoxAPI.get('/admin/media/folders').then(function(data) {
      allFolders = data.folders || [];
    }).catch(function() {
      allFolders = [];
    });
  }

  function openAddFolderModal() {
    if (!activePlaylistId) return;

    var treeEl = document.getElementById('addFolderTree');
    var input  = document.getElementById('addFolderSel');
    input.value = '';

    var roots = buildFolderTree(allFolders);
    renderFolderTree(treeEl, roots, {
      mode: 'select',
      onSelect: function(path, name) {
        input.value = path;
      }
    });

    document.getElementById('addFolderRecursive').checked = true;
    document.getElementById('addFolderNote').textContent  = '';
    var btn = document.getElementById('btnConfirmAddFolder');
    btn.disabled    = false;
    btn.textContent = 'Add Songs';

    document.getElementById('addFolderModal').classList.remove('hidden');
  }

  function handleAddFolder() {
    if (!activePlaylistId) return;

    var folderPath = document.getElementById('addFolderSel').value;
    var recursive  = document.getElementById('addFolderRecursive').checked;

    if (!folderPath) {
      showToast('Please select a folder', 'error');
      return;
    }

    var btn = document.getElementById('btnConfirmAddFolder');
    btn.disabled    = true;
    btn.textContent = 'Adding\u2026';

    RendezVoxAPI.post('/admin/playlists/' + activePlaylistId + '/songs/folder', {
      folder_path: folderPath,
      recursive:   recursive
    }).then(function(data) {
      showToast(data.message);
      document.getElementById('addFolderModal').classList.add('hidden');
      loadDetail(activePlaylistId);
      loadPlaylists();
    }).catch(function(err) {
      showToast((err && err.error) || 'Add from folder failed', 'error');
    }).finally(function() {
      btn.disabled    = false;
      btn.textContent = 'Add Songs';
    });
  }

  // ── Import Playlists from Folders ─────────────────────

  function openImportFoldersModal() {
    var tree = document.getElementById('importFolderTree');
    tree.innerHTML = '<p class="text-dim" style="margin:0;font-size:.85rem">Loading folders…</p>';
    document.getElementById('btnConfirmImportFolders').disabled = true;
    document.getElementById('btnConfirmImportFolders').textContent = 'Import (0)';
    document.getElementById('importRecursive').checked = true;
    document.getElementById('importFoldersModal').classList.remove('hidden');

    // Fetch folders with song counts
    RendezVoxAPI.get('/admin/media/folders?counts=true').then(function(data) {
      var folders = data.folders || [];

      // Build set of existing playlist names (case-insensitive)
      var existingNames = {};
      allPlaylists.forEach(function(p) {
        existingNames[p.name.toLowerCase()] = true;
      });

      var roots = buildFolderTree(folders);

      if (roots.length === 0) {
        tree.innerHTML = '<p class="text-dim" style="margin:0;font-size:.85rem">No folders found</p>';
        return;
      }

      renderFolderTree(tree, roots, {
        mode: 'check',
        existingNames: existingNames,
        onChange: updateImportCount
      });
    }).catch(function() {
      tree.innerHTML = '<p class="text-dim" style="margin:0;font-size:.85rem;color:var(--danger)">Failed to load folders</p>';
    });
  }

  function updateImportCount() {
    var checks = document.querySelectorAll('#importFolderTree .import-folder-check:checked');
    var count = checks.length;
    var btn = document.getElementById('btnConfirmImportFolders');
    btn.textContent = 'Import (' + count + ')';
    btn.disabled = count === 0;
  }

  function importSelectAll() {
    var checks = document.querySelectorAll('#importFolderTree .import-folder-check:not(:disabled)');
    checks.forEach(function(cb) { cb.checked = true; });
    updateImportCount();
  }

  function importSelectNone() {
    var checks = document.querySelectorAll('#importFolderTree .import-folder-check');
    checks.forEach(function(cb) { cb.checked = false; });
    updateImportCount();
  }

  function handleImportFolders() {
    var checks = document.querySelectorAll('#importFolderTree .import-folder-check:checked');
    if (checks.length === 0) return;

    var recursive = document.getElementById('importRecursive').checked;

    // Collect all checked paths
    var allChecked = [];
    checks.forEach(function(cb) {
      allChecked.push({
        path: cb.getAttribute('data-path'),
        name: cb.getAttribute('data-name')
      });
    });

    // When recursive: filter out folders whose ancestor is also checked
    // (the ancestor's recursive import already covers subfolder songs)
    var folders;
    if (recursive) {
      var checkedPaths = {};
      allChecked.forEach(function(f) { checkedPaths[f.path] = true; });
      folders = allChecked.filter(function(f) {
        var parts = f.path.split('/').filter(Boolean);
        // Check if any ancestor path is also checked
        for (var i = 1; i < parts.length; i++) {
          var ancestor = '/' + parts.slice(0, i).join('/');
          if (checkedPaths[ancestor]) return false;
        }
        return true;
      });
    } else {
      folders = allChecked;
    }

    if (folders.length === 0) {
      showToast('No new folders to import (all covered by parent selections)', 'error');
      return;
    }

    var btn = document.getElementById('btnConfirmImportFolders');
    btn.disabled = true;
    btn.textContent = 'Starting import…';

    RendezVoxAPI.post('/admin/playlists/batch-import', {
      folders: folders,
      recursive: recursive
    }).then(function(data) {
      if (data.status === 'started') {
        // Background job started — poll for progress
        showToast('Import started — scanning ' + folders.length + ' folder(s)…');
        pollBatchImportStatus();
      } else {
        // Synchronous response (shouldn't happen with new handler, but handle gracefully)
        document.getElementById('importFoldersModal').classList.add('hidden');
        var msg = data.message || (data.created.length + ' playlist(s) created');
        showToast(msg);
        loadPlaylists();
        loadFolders();
      }
    }).catch(function(err) {
      showToast((err && err.error) || 'Import failed', 'error');
      btn.disabled = false;
      updateImportCount();
    });
  }

  var batchImportPollTimer = null;

  function pollBatchImportStatus() {
    if (batchImportPollTimer) clearInterval(batchImportPollTimer);
    var idleCount = 0;
    var progressWrap = document.getElementById('batchImportProgress');
    if (progressWrap) progressWrap.style.display = 'block';

    batchImportPollTimer = setInterval(function() {
      RendezVoxAPI.get('/admin/playlists/batch-import').then(function(data) {
        if (!data || data.status === 'idle') {
          if (++idleCount >= 3) {
            clearInterval(batchImportPollTimer);
            batchImportPollTimer = null;
            finishBatchImportUI();
          }
          return;
        }
        idleCount = 0;
        showBatchImportProgress(data);

        if (data.status !== 'running') {
          clearInterval(batchImportPollTimer);
          batchImportPollTimer = null;

          if (data.status === 'done') {
            var msg = (data.playlists_created || 0) + ' playlist(s) created';
            if (data.songs_scanned > 0) msg += ', ' + data.songs_scanned + ' songs scanned';
            showToast(msg);
          } else if (data.status === 'stopped') {
            showToast('Import stopped — ' + (data.playlists_created || 0) + ' playlist(s) created so far');
          }
          finishBatchImportUI();
        }
      }).catch(function() {
        // Network error — keep polling
      });
    }, 2000);
  }

  function showBatchImportProgress(p) {
    var progressWrap = document.getElementById('batchImportProgress');
    if (!progressWrap) return;
    progressWrap.style.display = 'block';

    var total = p.total_folders || 1;
    var processed = p.folders_processed || 0;
    var pct = Math.round((processed / total) * 100);

    var label = 'Importing playlists…';
    if (p.current_folder) label = 'Scanning: ' + p.current_folder;
    if (p.status === 'done') label = 'Import complete';
    if (p.status === 'stopped') label = 'Import stopped';

    document.getElementById('batchImportLabel').textContent = label;
    document.getElementById('batchImportPct').textContent = pct + '%';
    document.getElementById('batchImportBar').style.width = pct + '%';
    document.getElementById('batchImportDetails').textContent =
      processed + ' / ' + total + ' folders — ' +
      (p.playlists_created || 0) + ' created, ' +
      (p.songs_scanned || 0) + ' songs scanned';
  }

  function finishBatchImportUI() {
    var progressWrap = document.getElementById('batchImportProgress');
    if (progressWrap) {
      setTimeout(function() { progressWrap.style.display = 'none'; }, 3000);
    }
    document.getElementById('importFoldersModal').classList.add('hidden');
    var btn = document.getElementById('btnConfirmImportFolders');
    btn.disabled = false;
    updateImportCount();
    loadPlaylists();
    loadFolders();
  }

  // ── Drag and drop reorder ───────────────────────────

  function setupDragDrop() {
    var tbody = document.getElementById('detailSongs');
    var rows = tbody.querySelectorAll('tr[draggable]');
    var dragSrc = null;

    rows.forEach(function(row) {
      row.addEventListener('dragstart', function(e) {
        dragSrc = this;
        this.style.opacity = '0.4';
        e.dataTransfer.effectAllowed = 'move';
      });

      row.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        this.style.borderTop = '2px solid var(--accent)';
      });

      row.addEventListener('dragleave', function() {
        this.style.borderTop = '';
      });

      row.addEventListener('drop', function(e) {
        e.preventDefault();
        this.style.borderTop = '';
        if (dragSrc !== this) {
          tbody.insertBefore(dragSrc, this);
          saveOrder();
        }
      });

      row.addEventListener('dragend', function() {
        this.style.opacity = '';
        rows.forEach(function(r) { r.style.borderTop = ''; });
      });
    });
  }

  function saveOrder() {
    if (!activePlaylistId) return;
    var rows = document.getElementById('detailSongs').querySelectorAll('tr[data-song-id]');
    var songIds = [];
    rows.forEach(function(r) {
      songIds.push(parseInt(r.getAttribute('data-song-id')));
    });

    RendezVoxAPI.put('/admin/playlists/' + activePlaylistId + '/reorder', { song_ids: songIds }).then(function() {
      showToast('Order saved');
      loadDetail(activePlaylistId);
    }).catch(function(err) {
      showToast((err && err.error) || 'Reorder failed', 'error');
    });
  }

  // ── Random name generator ──────────────────────────────

  var surpriseAdjectives = [
    'Cosmic', 'Velvet', 'Electric', 'Golden', 'Midnight', 'Crystal', 'Neon',
    'Chill', 'Sunset', 'Dreamy', 'Funky', 'Retro', 'Tropical', 'Wild',
    'Mellow', 'Blazing', 'Stellar', 'Groovy', 'Amber', 'Silver',
    'Mystic', 'Atomic', 'Crimson', 'Sapphire', 'Infinite', 'Turbo',
    'Lazy', 'Radiant', 'Thunder', 'Ocean'
  ];
  var surpriseNouns = [
    'Vibes', 'Sunset', 'Dreams', 'Waves', 'Groove', 'Highway', 'Echo',
    'Horizon', 'Spark', 'Rhythm', 'Journey', 'Mirage', 'Thunder', 'Breeze',
    'Fusion', 'Orbit', 'Phoenix', 'Bloom', 'Cascade', 'Pulse',
    'Safari', 'Galaxy', 'Drift', 'Flame', 'Storm', 'Paradise',
    'Moonlight', 'Voltage', 'Skyline', 'Wavelength'
  ];
  var surpriseSuffixes = [
    'Mix', 'Radio', 'Session', 'Beats', 'Playlist', 'Jams',
    'Hour', 'Rotation', 'Channel', 'Selections'
  ];

  function generateRandomName() {
    var adj = surpriseAdjectives[Math.floor(Math.random() * surpriseAdjectives.length)];
    var noun = surpriseNouns[Math.floor(Math.random() * surpriseNouns.length)];
    var sfx = surpriseSuffixes[Math.floor(Math.random() * surpriseSuffixes.length)];
    return adj + ' ' + noun + ' ' + sfx;
  }

  // ── Surprise Me flow ─────────────────────────────────

  var surpriseAvgDurationMs = 0;

  function getSurpriseTotalHours() {
    var days = parseInt(document.getElementById('surpriseDays').value) || 0;
    var hrs  = parseInt(document.getElementById('surpriseHours').value) || 0;
    return Math.max(1, days * 24 + hrs);
  }

  function openSurpriseModal() {
    if (!activePlaylistId) return;

    document.getElementById('surpriseDays').value = '1';
    document.getElementById('surpriseHours').value = '0';
    document.getElementById('surpriseEstimate').textContent = '';
    document.getElementById('surpriseAvailable').textContent = 'Checking available songs…';
    document.getElementById('btnGoSurprise').disabled = false;
    document.getElementById('btnGoSurprise').textContent = 'Go!';
    document.getElementById('surpriseMeModal').classList.remove('hidden');

    // Pre-check availability and get avg duration
    RendezVoxAPI.get('/admin/songs/random?count=1&exclude_playlist_id=' + activePlaylistId).then(function(data) {
      var avail = data.total_available || 0;
      surpriseAvgDurationMs = data.avg_duration_ms || 210000;
      document.getElementById('surpriseAvailable').textContent =
        avail + ' unique song' + (avail !== 1 ? 's' : '') + ' available';
      updateSurpriseEstimate();
    }).catch(function() {
      document.getElementById('surpriseAvailable').textContent = '';
    });

    // Update estimate when either input changes
    document.getElementById('surpriseDays').oninput = updateSurpriseEstimate;
    document.getElementById('surpriseHours').oninput = updateSurpriseEstimate;
  }

  function updateSurpriseEstimate() {
    var totalHours = getSurpriseTotalHours();
    if (surpriseAvgDurationMs > 0) {
      var estSongs = Math.ceil((totalHours * 3600 * 1000) / surpriseAvgDurationMs);
      var avgMin = Math.round(surpriseAvgDurationMs / 60000 * 10) / 10;
      document.getElementById('surpriseEstimate').textContent =
        '~' + estSongs + ' songs (' + totalHours + 'h, avg ' + avgMin + ' min/song)';
    }
  }

  function handleSurpriseGo() {
    if (!activePlaylistId) return;

    var hours = getSurpriseTotalHours();
    var btn = document.getElementById('btnGoSurprise');
    btn.disabled = true;
    btn.textContent = 'Adding…';

    RendezVoxAPI.get('/admin/songs/random?hours=' + hours + '&exclude_playlist_id=' + activePlaylistId).then(function(data) {
      var songs = data.songs || [];
      if (songs.length === 0) {
        showToast('No songs available to add', 'error');
        btn.disabled = false;
        btn.textContent = 'Go!';
        return;
      }

      var ids = songs.map(function(s) { return s.id; });
      var totalMs = data.total_duration_ms || 0;
      var totalHrs = totalMs > 0 ? Math.round(totalMs / 3600000 * 10) / 10 : 0;

      return RendezVoxAPI.post('/admin/playlists/' + activePlaylistId + '/songs/bulk', {
        song_ids: ids
      }).then(function() {
        var msg = ids.length + ' random song' + (ids.length !== 1 ? 's' : '') + ' added';
        if (totalHrs > 0) msg += ' (~' + totalHrs + 'h of music)';
        showToast(msg);
        document.getElementById('surpriseMeModal').classList.add('hidden');
        loadDetail(activePlaylistId);
        loadPlaylists();
      });
    }).catch(function(err) {
      showToast((err && err.error) || 'Surprise Me failed', 'error');
    }).finally(function() {
      btn.disabled = false;
      btn.textContent = 'Go!';
    });
  }

  // ── Helpers ──────────────────────────────────────────

  function formatDuration(ms) {
    if (!ms) return '—';
    var s = Math.floor(ms / 1000);
    var m = Math.floor(s / 60);
    s = s % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
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

  return {
    init: init,
    viewDetail: viewDetail,
    editPlaylist: editPlaylist,
    deletePlaylist: deletePlaylist,
    removeSong: removeSong,
    createEmergency: createEmergency,
    toggleActive: toggleActive,
    changeColor: changeColor
  };
})();
