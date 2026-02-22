/* ============================================================
   iRadio Admin — Playlists Management
   ============================================================ */
var iRadioPlaylists = (function() {

  var activePlaylistId   = null;
  var activePlaylistName = null;
  var activePlaylistType = null;
  var activeDetailSongs  = [];
  var currentSongId      = null;
  var nextSongId         = null;
  var searchTimer = null;
  var autoShuffledOrder  = null;  // stored song_id order after client-side shuffle

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
    '#00c8a0', '#f87171', '#34d399', '#fbbf24', '#60a5fa',
    '#a78bfa', '#f472b6', '#2dd4bf', '#fb923c', '#818cf8'
  ];
  var allPlaylists = [];

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

    // Auto-refresh every 5 seconds (picks up changes from other windows/tabs)
    setInterval(function() {
      loadPlaylists();
      if (activePlaylistId) loadDetail(activePlaylistId);
    }, 5000);
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
      actions.innerHTML = '<button class="btn btn-sm" style="background:var(--danger);color:#fff" onclick="iRadioPlaylists.createEmergency()">Create Emergency Playlist</button>';
    } else {
      var songLabel = ep.song_count !== null ? ep.song_count + ' songs' : '—';
      var statusBadge = ep.is_active
        ? '<span class="badge badge-active" title="This playlist will be used when emergency mode is triggered">Ready</span>'
        : '<span class="badge badge-inactive" title="Enable this playlist so it can be used during emergency mode">Disabled</span>';
      desc.innerHTML = '<strong>' + escHtml(ep.name) + '</strong> — ' + songLabel + ' ' + statusBadge;
      actions.innerHTML =
        '<button class="icon-btn" title="View" onclick="iRadioPlaylists.viewDetail(' + ep.id + ')">' + iRadioIcons.view + '</button> ' +
        '<button class="icon-btn" title="Edit" onclick="iRadioPlaylists.editPlaylist(' + ep.id + ')">' + iRadioIcons.edit + '</button> ' +
        '<button class="icon-btn danger" title="Delete" onclick="iRadioPlaylists.deletePlaylist(' + ep.id + ')">' + iRadioIcons.del + '</button>';
    }
  }

  function createEmergency() {
    if (emergencyPlaylist) {
      showToast('Emergency playlist already exists', 'error');
      return;
    }

    iRadioAPI.post('/admin/playlists', {
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

    iRadioAPI.get('/admin/categories').then(function(data) {
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

    iRadioAPI.get('/admin/artists').then(function(data) {
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

    iRadioAPI.get('/admin/songs/years').then(function(data) {
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
    iRadioAPI.get('/admin/playlists').then(function(data) {
      allPlaylists = data.playlists || [];
      renderEmergencyCard(allPlaylists);
      // Filter out emergency from main table
      var regular = allPlaylists.filter(function(p) { return p.type !== 'emergency'; });
      renderTable(regular);
    });
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
    var tbody = document.getElementById('playlistTable');
    if (!playlists || playlists.length === 0) {
      tbody.innerHTML = '<tr><td colspan="7" class="empty">No playlists</td></tr>';
      return;
    }

    var html = '';
    playlists.forEach(function(p, idx) {
      var typeCls = p.type === 'auto' ? 'badge-request' : 'badge-rotation';
      var songCount = p.song_count !== null ? p.song_count : '<span title="Dynamic">&mdash;</span>';
      var swatch = p.color ? '<span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:' + escHtml(p.color) + ';vertical-align:middle;margin-right:6px"></span>' : '';
      var toggleChecked = p.is_active ? ' checked' : '';
      html += '<tr>' +
        '<td>' + (idx + 1) + '</td>' +
        '<td>' + swatch + escHtml(p.name) + '</td>' +
        '<td><span class="badge ' + typeCls + '">' + p.type + '</span></td>' +
        '<td>' + songCount + '</td>' +
        '<td>' + p.cycle_count + '</td>' +
        '<td><label class="toggle toggle-sm"><input type="checkbox" onchange="iRadioPlaylists.toggleActive(' + p.id + ',this.checked)"' + toggleChecked + '><span class="slider"></span></label></td>' +
        '<td style="white-space:nowrap">' +
          '<button class="icon-btn" title="View" onclick="iRadioPlaylists.viewDetail(' + p.id + ')">' + iRadioIcons.view + '</button> ' +
          '<label class="icon-btn" title="Change color" style="position:relative;cursor:pointer;color:' + escHtml(p.color || '#00c8a0') + '">' + iRadioIcons.palette + '<input type="color" value="' + escHtml(p.color || '#00c8a0') + '" onchange="iRadioPlaylists.changeColor(' + p.id + ',this.value)" style="position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;cursor:pointer"></label> ' +
          '<button class="icon-btn" title="Edit" onclick="iRadioPlaylists.editPlaylist(' + p.id + ')">' + iRadioIcons.edit + '</button> ' +
          '<button class="icon-btn danger" title="Delete" onclick="iRadioPlaylists.deletePlaylist(' + p.id + ')">' + iRadioIcons.del + '</button>' +
        '</td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
  }

  function toggleActive(id, active) {
    iRadioAPI.put('/admin/playlists/' + id, { is_active: active }).then(function() {
      showToast(active ? 'Playlist activated' : 'Playlist deactivated');
      // Notify stream in case this playlist is currently scheduled
      iRadioAPI.post('/admin/schedules/reload', {}).catch(function() {});
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
    iRadioAPI.put('/admin/playlists/' + id, { color: color }).then(function() {
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
    iRadioAPI.get('/admin/playlists/' + id).then(function(data) {
      var p = data.playlist;
      document.getElementById('playlistModalTitle').textContent = 'Edit Playlist';
      document.getElementById('plId').value      = p.id;
      document.getElementById('plName').value    = p.name;
      document.getElementById('plDesc').value    = p.description || '';
      document.getElementById('plType').value    = p.type;
      document.getElementById('plActive').checked = p.is_active;
      var color = p.color || '#00c8a0';
      document.getElementById('plColor').value = color;
      document.getElementById('plColorHex').textContent = color;
      document.getElementById('colorPickerWrap').style.display = '';

      // Hide type selector and color picker for emergency playlists
      var typeWrap = document.getElementById('typeSelectWrap');
      var colorWrap = document.getElementById('colorPickerWrap');
      if (p.type === 'emergency') {
        typeWrap.style.display = 'none';
        colorWrap.style.display = 'none';
      } else {
        typeWrap.style.display = '';
        colorWrap.style.display = '';
        document.getElementById('plTypeSelect').value = p.type;
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
      ? iRadioAPI.put('/admin/playlists/' + id, body)
      : iRadioAPI.post('/admin/playlists', body);

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
    if (!confirm('Delete this playlist?')) return;

    iRadioAPI.del('/admin/playlists/' + id).then(function() {
      showToast('Playlist deleted');
      if (activePlaylistId === id) {
        document.getElementById('detailPanel').classList.add('hidden');
        activePlaylistId   = null;
        activePlaylistName = null;
        activePlaylistType = null;
      }
      loadPlaylists();
    }).catch(function(err) {
      showToast((err && err.error) || 'Delete failed', 'error');
    });
  }

  // ── Detail / Songs ──────────────────────────────────

  function viewDetail(id) {
    if (activePlaylistId !== id) {
      autoShuffledOrder = null;  // clear shuffle order when switching playlists
    }
    activePlaylistId = id;
    loadDetail(id);
  }

  function loadDetail(id) {
    iRadioAPI.get('/admin/playlists/' + id).then(function(data) {
      var pl     = data.playlist;
      var isAuto = pl.type === 'auto';
      activePlaylistType = pl.type;
      activePlaylistName = pl.name;

      // Track which songs are currently playing / up next
      currentSongId = data.current_song_id || null;
      nextSongId    = data.next_song_id || null;

      document.getElementById('detailTitle').textContent =
        pl.name + (isAuto ? ' — Matching Songs (dynamic)' : ' — Songs');

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
      document.getElementById('detailPanel').classList.remove('hidden');
    });
  }

  function renderDetailSongs(songs, type) {
    var isAuto = type === 'auto';
    var thead  = document.getElementById('detailHead');
    var tbody  = document.getElementById('detailSongs');

    thead.innerHTML = '<tr><th>#</th><th>Title</th><th>Artist</th><th>Genre</th><th>Duration</th><th>Played</th><th>Actions</th></tr>';

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

    var html = '';
    songs.forEach(function(s, idx) {
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
      var actionCell = '<button class="icon-btn danger" title="Remove" onclick="iRadioPlaylists.removeSong(' + s.song_id + ')">' + iRadioIcons.remove + '</button>';
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

    if (!isAuto) {
      setupDragDrop();
    }
  }

  function removeSong(songId) {
    if (!activePlaylistId) return;

    iRadioAPI.del('/admin/playlists/' + activePlaylistId + '/songs/' + songId).then(function() {
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

    iRadioAPI.post('/admin/playlists/' + activePlaylistId + '/shuffle', {}).then(function() {
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
      iRadioAPI.get('/admin/artists').then(function(data) {
        addSongArtists = data.artists || [];
        populateArtistDropdown();
      });
    }
    if (addSongCategories.length === 0) {
      iRadioAPI.get('/admin/categories').then(function(data) {
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
    var html = '<option value="">All Genres</option>';
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

    iRadioAPI.get('/admin/songs' + q).then(function(data) {
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

    iRadioAPI.post('/admin/playlists/' + activePlaylistId + '/songs/bulk', {
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

    if (!confirm('Add ' + filterDesc + ' to this playlist?')) return;

    var q = '?per_page=500&active=true';
    if (search)   q += '&search='      + encodeURIComponent(search);
    if (artistId) q += '&artist_id='   + artistId;
    if (catId)    q += '&category_id=' + catId;

    var btn = document.getElementById('btnAddAllFiltered');
    btn.disabled = true;
    btn.textContent = 'Adding…';

    iRadioAPI.get('/admin/songs' + q).then(function(data) {
      var ids = data.songs.map(function(s) { return s.id; });
      ids = ids.filter(function(sid) { return !playlistSongIds[sid]; });

      if (ids.length === 0) {
        showToast('All matching songs are already in the playlist');
        btn.disabled = false;
        btn.textContent = 'Add All Filtered';
        return;
      }

      return iRadioAPI.post('/admin/playlists/' + activePlaylistId + '/songs/bulk', {
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
  }

  // ── Add from Folder ──────────────────────────────────

  function loadFolders() {
    iRadioAPI.get('/admin/media/folders').then(function(data) {
      allFolders = data.folders || [];
    }).catch(function() {
      allFolders = [];
    });
  }

  function openAddFolderModal() {
    if (!activePlaylistId) return;

    var sel  = document.getElementById('addFolderSel');
    var html = '';
    allFolders.forEach(function(f) {
      var indent = '';
      if (f.depth >= 0) {
        for (var i = 0; i < f.depth; i++) { indent += '\u00a0\u00a0'; }
        indent += '\u2514\u2500 ';
      }
      html += '<option value="' + escHtml(f.path) + '">' + indent + escHtml(f.name) + '</option>';
    });
    sel.innerHTML = html || '<option value="">No folders available</option>';

    document.getElementById('addFolderRecursive').checked = false;
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

    iRadioAPI.post('/admin/playlists/' + activePlaylistId + '/songs/folder', {
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

    iRadioAPI.put('/admin/playlists/' + activePlaylistId + '/reorder', { song_ids: songIds }).then(function() {
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
    iRadioAPI.get('/admin/songs/random?count=1&exclude_playlist_id=' + activePlaylistId).then(function(data) {
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

    iRadioAPI.get('/admin/songs/random?hours=' + hours + '&exclude_playlist_id=' + activePlaylistId).then(function(data) {
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

      return iRadioAPI.post('/admin/playlists/' + activePlaylistId + '/songs/bulk', {
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
