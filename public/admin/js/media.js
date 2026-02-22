/* ============================================================
   iRadio Admin — Media Library (genre/artist filter-based)
   ============================================================ */
var iRadioMedia = (function () {

  // ── Icons ─────────────────────────────────────────────
  var IC = {
    edit: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
    del:  '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>',
    restore: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>',
  };

  // ── State ─────────────────────────────────────────────
  var artists      = [];
  var categories   = [];
  var selectedIds  = new Set();   // song IDs of checked rows
  var searchTimer  = null;
  var pendingTimer = null;
  var dupGroups    = [];           // duplicate scan results

  // Pagination / filter state
  var currentPage = 1;
  var _storedPP   = localStorage.getItem('iradio_media_perpage');
  var perPage     = _storedPP === 'all' ? 'all' : (parseInt(_storedPP) || 25);

  function numericPerPage() { return perPage === 'all' ? 10000 : perPage; }
  var totalSongs  = 0;
  var totalPages  = 0;

  // View state: 'library' or 'trash'
  var currentView = 'library';

  // ── Init ──────────────────────────────────────────────

  function init() {
    loadArtists();
    loadCategories();

    // Header upload button
    document.getElementById('btnUpload').addEventListener('click', openUpload);

    // View tabs
    document.getElementById('tabLibrary').addEventListener('click', function () {
      switchView('library');
    });
    document.getElementById('tabTrash').addEventListener('click', function () {
      switchView('trash');
    });
    document.getElementById('tabDuplicates').addEventListener('click', function () {
      switchView('duplicates');
    });

    // Duplicate buttons
    document.getElementById('btnDupScan').addEventListener('click', dupScan);
    document.getElementById('btnDupDeleteAll').addEventListener('click', dupResolveAll);

    // Empty trash button
    document.getElementById('btnEmptyTrash').addEventListener('click', emptyTrash);

    // Filter dropdowns
    document.getElementById('filterGenre').addEventListener('change', function () {
      currentPage = 1;
      loadSongs();
    });
    document.getElementById('filterArtist').addEventListener('change', function () {
      currentPage = 1;
      loadSongs();
    });

    // Show inactive toggle
    document.getElementById('showInactive').addEventListener('change', function () {
      currentPage = 1;
      loadSongs();
    });

    // Search
    document.getElementById('songSearch').addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () { currentPage = 1; loadSongs(); }, 300);
    });

    // Per-page selector
    var perPageSel = document.getElementById('perPageSel');
    perPageSel.value = String(perPage);
    perPageSel.addEventListener('change', function () {
      perPage = this.value === 'all' ? 'all' : (parseInt(this.value) || 25);
      localStorage.setItem('iradio_media_perpage', perPage);
      currentPage = 1;
      loadSongs();
    });

    // Select-all checkbox
    document.getElementById('checkAll').addEventListener('change', function () {
      var checked = this.checked;
      document.querySelectorAll('.song-check').forEach(function (cb) {
        cb.checked = checked;
        var id = parseInt(cb.dataset.id);
        if (checked) selectedIds.add(id);
        else         selectedIds.delete(id);
      });
      updateBulkBar();
    });

    // Individual checkbox — event delegation
    document.getElementById('songTable').addEventListener('change', function (e) {
      if (!e.target.classList.contains('song-check')) return;
      var id = parseInt(e.target.dataset.id);
      if (e.target.checked) selectedIds.add(id);
      else                  selectedIds.delete(id);
      updateBulkBar();
    });

    // Bulk bar buttons
    document.getElementById('btnBulkTrash').addEventListener('click', bulkTrash);
    document.getElementById('btnBulkRestore').addEventListener('click', bulkRestore);
    document.getElementById('btnBulkPurge').addEventListener('click', bulkPurge);
    document.getElementById('btnClearSel').addEventListener('click', function () {
      selectedIds.clear();
      document.querySelectorAll('.song-check').forEach(function (cb) { cb.checked = false; });
      updateBulkBar();
    });

    // Upload modal
    initDropZone();
    document.getElementById('uploadFiles').addEventListener('change', onFilesSelected);
    document.getElementById('btnBrowseFolder').addEventListener('click', function () {
      document.getElementById('uploadFolder').click();
    });
    document.getElementById('uploadFolder').addEventListener('change', onFolderSelected);
    document.getElementById('uploadForm').addEventListener('submit', handleUpload);
    document.getElementById('btnCancelUpload').addEventListener('click', closeUpload);
    document.getElementById('btnNewArtist').addEventListener('click', openNewArtist);
    document.getElementById('btnNewGenre').addEventListener('click', openNewGenre);
    document.getElementById('btnNewGenreEdit').addEventListener('click', openNewGenre);

    // Edit modal
    document.getElementById('editForm').addEventListener('submit', handleEdit);
    document.getElementById('btnCancelEdit').addEventListener('click', function () {
      document.getElementById('editModal').classList.add('hidden');
    });

    // New Genre modal
    document.getElementById('genreForm').addEventListener('submit', handleNewGenre);
    document.getElementById('btnCancelGenre').addEventListener('click', function () {
      document.getElementById('genreModal').classList.add('hidden');
    });

    // New Artist modal
    document.getElementById('artistForm').addEventListener('submit', handleNewArtist);
    document.getElementById('btnCancelArtist').addEventListener('click', function () {
      document.getElementById('artistModal').classList.add('hidden');
    });

    // Initial load
    loadSongs();
    loadPendingCount();
    loadTrashCount();
  }

  // ── View switching ──────────────────────────────────────

  function switchView(view) {
    if (view === currentView) return;
    currentView = view;
    currentPage = 1;
    selectedIds.clear();
    updateBulkBar();

    var tabLib       = document.getElementById('tabLibrary');
    var tabTrash     = document.getElementById('tabTrash');
    var tabDup       = document.getElementById('tabDuplicates');
    var filterBar    = document.getElementById('filterBar');
    var searchBar    = document.querySelector('.songs-filter');
    var trashToolbar = document.getElementById('trashToolbar');
    var btnUpload    = document.getElementById('btnUpload');
    var dupView      = document.getElementById('duplicatesView');
    var songSection  = document.getElementById('songSection');
    var bulkBar      = document.getElementById('bulkBar');
    var pagBar       = document.getElementById('paginationBar');

    tabLib.classList.remove('active');
    tabTrash.classList.remove('active');
    tabDup.classList.remove('active');
    filterBar.classList.add('hidden');
    searchBar.classList.add('hidden');
    trashToolbar.classList.add('hidden');
    dupView.classList.add('hidden');
    btnUpload.classList.add('hidden');
    songSection.classList.remove('hidden');
    bulkBar.classList.add('hidden');

    if (view === 'trash') {
      tabTrash.classList.add('active');
      trashToolbar.classList.remove('hidden');
      loadSongs();
    } else if (view === 'duplicates') {
      tabDup.classList.add('active');
      dupView.classList.remove('hidden');
      songSection.classList.add('hidden');
      pagBar.style.display = 'none';
      if (dupGroups.length === 0) dupScan();
    } else {
      tabLib.classList.add('active');
      filterBar.classList.remove('hidden');
      searchBar.classList.remove('hidden');
      btnUpload.classList.remove('hidden');
      loadSongs();
    }
  }

  // ── Drop zone ─────────────────────────────────────────

  function initDropZone() {
    var dz    = document.getElementById('dropZone');
    var input = document.getElementById('uploadFiles');

    dz.addEventListener('click', function (e) {
      if (e.target !== input && !e.target.classList.contains('btn')) input.click();
    });

    dz.addEventListener('dragover', function (e) {
      e.preventDefault();
      dz.classList.add('drag-over');
    });
    dz.addEventListener('dragleave', function (e) {
      if (!dz.contains(e.relatedTarget)) dz.classList.remove('drag-over');
    });
    dz.addEventListener('drop', function (e) {
      e.preventDefault();
      dz.classList.remove('drag-over');

      // Synchronously capture entries before DataTransfer expires
      var items = e.dataTransfer.items;
      var entries = [];
      var hasDirectories = false;

      if (items && items.length) {
        for (var i = 0; i < items.length; i++) {
          if (items[i].kind === 'file') {
            var entry = items[i].webkitGetAsEntry && items[i].webkitGetAsEntry();
            if (entry) {
              entries.push(entry);
              if (entry.isDirectory) hasDirectories = true;
            }
          }
        }
      }

      if (hasDirectories) {
        showToast('Reading folder contents...');
        collectFilesFromEntries(entries).then(function (files) {
          var dt = new DataTransfer();
          files.forEach(function (f) {
            var ext = f.name.split('.').pop().toLowerCase();
            if (AUDIO_EXTS.includes(ext)) dt.items.add(f);
          });
          if (dt.files.length === 0) {
            showToast('No supported audio files found in folder', 'error');
            return;
          }
          input.files = dt.files;
          showFileList(dt.files);
          showToast(dt.files.length + ' audio file(s) ready to upload');
        }).catch(function (err) {
          showToast('Error reading folder: ' + (err.message || err), 'error');
        });
        return;
      }

      // Plain files (no directories)
      var dt = new DataTransfer();
      var files = e.dataTransfer.files;
      for (var i = 0; i < files.length; i++) {
        var ext = files[i].name.split('.').pop().toLowerCase();
        if (AUDIO_EXTS.includes(ext)) dt.items.add(files[i]);
      }
      if (dt.files.length === 0) {
        showToast('No supported audio files dropped', 'error');
        return;
      }
      input.files = dt.files;
      showFileList(dt.files);
    });
  }

  // Recursively collect File objects from FileSystemEntry items (for folder drops)
  function collectFilesFromEntries(entries) {
    var files = [];

    function processEntry(entry) {
      return new Promise(function (resolve) {
        if (entry.isFile) {
          entry.file(function (f) {
            files.push(f);
            resolve();
          }, function () { resolve(); });
        } else if (entry.isDirectory) {
          readAllEntries(entry.createReader()).then(function (subEntries) {
            return Promise.all(subEntries.map(processEntry));
          }).then(resolve).catch(resolve);
        } else {
          resolve();
        }
      });
    }

    function readAllEntries(reader) {
      return new Promise(function (resolve) {
        var all = [];
        function readBatch() {
          reader.readEntries(function (batch) {
            if (batch.length === 0) {
              resolve(all);
            } else {
              all = all.concat(Array.from(batch));
              readBatch(); // Chrome returns max 100 per batch
            }
          }, function () { resolve(all); });
        }
        readBatch();
      });
    }

    return Promise.all(entries.map(processEntry)).then(function () {
      return files;
    });
  }

  // ── Reference data ────────────────────────────────────

  function loadArtists() {
    iRadioAPI.get('/admin/artists').then(function (data) {
      artists = data.artists || [];
      populateArtistSelects();
    });
  }

  function loadCategories() {
    iRadioAPI.get('/admin/categories').then(function (data) {
      categories = data.categories || [];
      populateGenreSelects();
    });
  }

  function populateArtistSelects() {
    var filterHtml = '<option value="">All Artists</option>';
    var selectHtml = '<option value="">Select artist...</option>';
    artists.forEach(function (a) {
      var opt = '<option value="' + a.id + '">' + escHtml(a.name) + '</option>';
      filterHtml += opt;
      selectHtml += opt;
    });

    var filterEl = document.getElementById('filterArtist');
    var curFilter = filterEl.value;
    filterEl.innerHTML = filterHtml;
    filterEl.value = curFilter;

    ['uploadArtist', 'editArtist'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.innerHTML = selectHtml;
    });
  }

  function populateGenreSelects() {
    var filterHtml = '<option value="">All Genres</option>';
    var selectHtml = '<option value="">Select genre...</option>';
    categories.forEach(function (c) {
      var opt = '<option value="' + c.id + '">' + escHtml(c.name) + '</option>';
      filterHtml += opt;
      selectHtml += opt;
    });

    var filterEl = document.getElementById('filterGenre');
    var curFilter = filterEl.value;
    filterEl.innerHTML = filterHtml;
    filterEl.value = curFilter;

    ['uploadGenreSel', 'editGenre'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.innerHTML = selectHtml;
    });
  }

  // ── Songs (server-side pagination) ────────────────────

  function loadSongs() {
    var params = [];

    if (currentView === 'trash') {
      params.push('trashed=true');
    } else {
      var genreId  = document.getElementById('filterGenre').value;
      var artistId = document.getElementById('filterArtist').value;
      var search   = (document.getElementById('songSearch').value || '').trim();

      if (genreId)  params.push('category_id=' + encodeURIComponent(genreId));
      if (artistId) params.push('artist_id=' + encodeURIComponent(artistId));
      if (search)   params.push('search=' + encodeURIComponent(search));

      // Default to active-only unless "Show inactive" is checked
      var showInactive = document.getElementById('showInactive');
      if (!showInactive || !showInactive.checked) {
        params.push('active=true');
      }
    }

    params.push('page=' + (perPage === 'all' ? 1 : currentPage));
    params.push('per_page=' + numericPerPage());

    var url = '/admin/songs?' + params.join('&');

    document.getElementById('songTable').innerHTML =
      '<tr><td colspan="7" class="empty">Loading...</td></tr>';

    iRadioAPI.get(url)
      .then(function (data) {
        totalSongs = data.total || 0;
        totalPages = data.pages || 1;

        renderSongsTable(data.songs || [], data.page, data.per_page);
        renderPagination();

        var countEl = document.getElementById('songCount');
        countEl.textContent = totalSongs + ' song' + (totalSongs !== 1 ? 's' : '');

        // Update trash info when in trash view
        if (currentView === 'trash') {
          document.getElementById('trashInfo').textContent =
            totalSongs + ' song' + (totalSongs !== 1 ? 's' : '') + ' in trash';
          document.getElementById('btnEmptyTrash').style.display =
            totalSongs > 0 ? '' : 'none';
        }
      })
      .catch(function (err) {
        document.getElementById('songTable').innerHTML =
          '<tr><td colspan="7" class="empty">Failed to load songs</td></tr>';
        showToast((err && err.error) || 'Failed to load', 'error');
      });
  }

  function renderSongsTable(songs, page, pageSize) {
    var tbody = document.getElementById('songTable');
    var startNum = ((page || 1) - 1) * (pageSize || numericPerPage());
    var isTrash = currentView === 'trash';

    if (!songs || songs.length === 0) {
      var emptyMsg = isTrash ? 'Trash is empty' : 'No songs found';
      tbody.innerHTML = '<tr><td colspan="7" class="empty">' + emptyMsg + '</td></tr>';
      updateCheckAllState();
      return;
    }

    var html = '';
    songs.forEach(function (s, i) {
      var isChecked = selectedIds.has(s.id) ? ' checked' : '';
      var title  = escHtml(s.title);
      var artist = escHtml(s.artist_name);
      var genre  = escHtml(s.category_name);

      var actions, dateCol, dateLabel;

      if (isTrash) {
        dateCol   = s.trashed_at ? formatDate(s.trashed_at) : '-';
        dateLabel = 'Trashed';
        actions =
          '<button class="icon-btn" title="Restore" onclick="iRadioMedia.restoreSong(' + s.id + ')">' + IC.restore + '</button>' +
          '<button class="icon-btn danger" title="Delete forever" onclick="iRadioMedia.purgeSong(' + s.id + ')">' + IC.del + '</button>';
      } else {
        dateCol   = s.created_at ? formatDate(s.created_at) : '-';
        dateLabel = 'Added';
        actions =
          '<button class="icon-btn" title="Edit metadata" onclick="iRadioMedia.editSong(' + s.id + ')">' + IC.edit + '</button>' +
          '<button class="icon-btn danger" title="Move to trash" onclick="iRadioMedia.trashSong(' + s.id + ')">' + IC.del + '</button>';
      }

      html +=
        '<tr>' +
          '<td style="width:32px;text-align:center;padding-right:4px">' +
            '<input type="checkbox" class="song-check" data-id="' + s.id + '"' + isChecked + '>' +
          '</td>' +
          '<td style="text-align:center;color:var(--text-dim,rgba(255,255,255,0.35));font-size:0.78rem">' + (startNum + i + 1) + '</td>' +
          '<td>' + title + (!s.is_active && !isTrash ? '<span class="badge-inactive">(off)</span>' : '') + '</td>' +
          '<td>' + artist + '</td>' +
          '<td>' + genre  + '</td>' +
          '<td style="white-space:nowrap;font-size:0.82rem">' + dateCol + '</td>' +
          '<td style="white-space:nowrap;padding-left:4px;padding-right:4px">' + actions + '</td>' +
        '</tr>';
    });

    tbody.innerHTML = html;
    updateCheckAllState();
  }

  // ── Pagination ────────────────────────────────────────

  function renderPagination() {
    var bar  = document.getElementById('paginationBar');
    var info = document.getElementById('paginationInfo');
    var nav  = document.getElementById('paginationNav');

    if (totalSongs === 0) {
      bar.style.display = 'none';
      return;
    }
    bar.style.display = 'flex';

    var pp    = numericPerPage();
    var start = (currentPage - 1) * pp + 1;
    var end   = Math.min(currentPage * pp, totalSongs);
    info.textContent = start + '-' + end + ' of ' + totalSongs;

    if (totalPages <= 1) {
      nav.innerHTML = '';
      return;
    }

    var html = '';
    html += '<button' + (currentPage <= 1 ? ' disabled' : '') +
            ' onclick="iRadioMedia.goToPage(' + (currentPage - 1) + ')">&lsaquo;</button>';

    var pages = buildPageNumbers(currentPage, totalPages);
    pages.forEach(function (p) {
      if (p === '...') {
        html += '<span class="page-ellipsis">...</span>';
      } else {
        html += '<button class="' + (p === currentPage ? 'active' : '') + '"' +
                ' onclick="iRadioMedia.goToPage(' + p + ')">' + p + '</button>';
      }
    });

    html += '<button' + (currentPage >= totalPages ? ' disabled' : '') +
            ' onclick="iRadioMedia.goToPage(' + (currentPage + 1) + ')">&rsaquo;</button>';

    nav.innerHTML = html;
  }

  function buildPageNumbers(current, total) {
    if (total <= 7) {
      var arr = [];
      for (var i = 1; i <= total; i++) arr.push(i);
      return arr;
    }
    var pages = [1];
    if (current > 3) pages.push('...');
    for (var j = Math.max(2, current - 1); j <= Math.min(total - 1, current + 1); j++) {
      pages.push(j);
    }
    if (current < total - 2) pages.push('...');
    pages.push(total);
    return pages;
  }

  function goToPage(page) {
    if (page < 1 || page > totalPages) return;
    currentPage = page;
    loadSongs();
    var tableWrap = document.querySelector('.table-wrap');
    if (tableWrap) tableWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // ── Pending count + auto-refresh ──────────────────────

  var lastPendingCount = -1;  // -1 = not yet loaded

  function loadPendingCount() {
    iRadioAPI.get('/admin/media/pending-count')
      .then(function (data) {
        var el = document.getElementById('noticeBanner');
        var count = data.pending_count || 0;

        if (count > 0) {
          el.innerHTML = '<span class="notice-icon">&#9202;</span> ' +
            count + ' file' + (count !== 1 ? 's' : '') + ' pending in upload queue';
          el.classList.remove('hidden');
        } else {
          el.classList.add('hidden');
          el.innerHTML = '';
        }

        // Auto-refresh table when organizer processes files
        if (lastPendingCount > 0 && count < lastPendingCount) {
          loadSongs();
          loadArtists();
          loadCategories();
          if (count === 0) loadTrashCount();
        }

        lastPendingCount = count;
      })
      .catch(function () {});

    // Poll faster while files are pending, slower when idle
    clearTimeout(pendingTimer);
    pendingTimer = setTimeout(loadPendingCount, lastPendingCount > 0 ? 5000 : 15000);
  }

  // ── Trash count (badge) ────────────────────────────────

  function loadTrashCount() {
    iRadioAPI.get('/admin/songs?trashed=true&per_page=1')
      .then(function (data) {
        var count = data.total || 0;
        var badge = document.getElementById('trashBadge');
        if (count > 0) {
          badge.textContent = count > 99 ? '99+' : count;
          badge.classList.remove('hidden');
        } else {
          badge.classList.add('hidden');
        }
      })
      .catch(function () {});
  }

  // ── Bulk selection ────────────────────────────────────

  function updateBulkBar() {
    var bar   = document.getElementById('bulkBar');
    var count = selectedIds.size;

    // Toggle which bulk actions are shown based on view
    var libActions   = document.getElementById('bulkLibraryActions');
    var trashActions = document.getElementById('bulkTrashActions');
    if (currentView === 'trash') {
      libActions.classList.add('hidden');
      trashActions.classList.remove('hidden');
    } else {
      libActions.classList.remove('hidden');
      trashActions.classList.add('hidden');
    }

    if (count > 0) {
      bar.classList.remove('hidden');
      document.getElementById('bulkCount').textContent =
        count + ' song' + (count !== 1 ? 's' : '') + ' selected';
    } else {
      bar.classList.add('hidden');
    }
    updateCheckAllState();
  }

  function updateCheckAllState() {
    var checkAll = document.getElementById('checkAll');
    var checkboxes = document.querySelectorAll('.song-check');
    if (!checkAll || checkboxes.length === 0) {
      if (checkAll) { checkAll.indeterminate = false; checkAll.checked = false; }
      return;
    }
    var checkedCount = 0;
    checkboxes.forEach(function (cb) { if (cb.checked) checkedCount++; });
    checkAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
    checkAll.checked       = checkedCount === checkboxes.length;
  }

  // ── Trash operations ──────────────────────────────────

  function trashSong(id) {
    iRadioAPI.post('/admin/songs/trash', { ids: [id] })
      .then(function () {
        showToast('Moved to trash');
        loadSongs();
        loadTrashCount();
      })
      .catch(function (err) {
        showToast((err && err.error) || 'Failed to trash song', 'error');
      });
  }

  function bulkTrash() {
    var count = selectedIds.size;
    if (!confirm('Move ' + count + ' selected song(s) to trash?')) return;

    iRadioAPI.post('/admin/songs/trash', { ids: Array.from(selectedIds) })
      .then(function () {
        selectedIds.clear();
        showToast(count + ' song(s) moved to trash');
        loadSongs();
        loadTrashCount();
      })
      .catch(function (err) {
        showToast((err && err.error) || 'Failed to trash songs', 'error');
      });
  }

  function restoreSong(id) {
    iRadioAPI.post('/admin/songs/restore', { ids: [id] })
      .then(function () {
        showToast('Song restored');
        loadSongs();
        loadTrashCount();
      })
      .catch(function (err) {
        showToast((err && err.error) || 'Failed to restore song', 'error');
      });
  }

  function bulkRestore() {
    var count = selectedIds.size;
    iRadioAPI.post('/admin/songs/restore', { ids: Array.from(selectedIds) })
      .then(function () {
        selectedIds.clear();
        showToast(count + ' song(s) restored');
        loadSongs();
        loadTrashCount();
      })
      .catch(function (err) {
        showToast((err && err.error) || 'Failed to restore songs', 'error');
      });
  }

  function purgeSong(id) {
    if (!confirm('Permanently delete this song?\nThis cannot be undone. The file will be removed from disk.')) return;

    iRadioAPI.del('/admin/songs/purge', { ids: [id] })
      .then(function () {
        showToast('Song permanently deleted');
        loadSongs();
        loadTrashCount();
        loadArtists();
      })
      .catch(function (err) {
        showToast((err && err.error) || 'Failed to delete song', 'error');
      });
  }

  function bulkPurge() {
    var count = selectedIds.size;
    if (!confirm('Permanently delete ' + count + ' selected song(s)?\nThis cannot be undone. Files will be removed from disk.')) return;

    iRadioAPI.del('/admin/songs/purge', { ids: Array.from(selectedIds) })
      .then(function () {
        selectedIds.clear();
        showToast(count + ' song(s) permanently deleted');
        loadSongs();
        loadTrashCount();
        loadArtists();
      })
      .catch(function (err) {
        showToast((err && err.error) || 'Failed to delete songs', 'error');
      });
  }

  function emptyTrash() {
    if (!confirm('Permanently delete ALL songs in trash?\nThis cannot be undone. All files will be removed from disk.')) return;

    iRadioAPI.del('/admin/songs/purge-all')
      .then(function (data) {
        var n = (data && data.purged) || 0;
        showToast(n + ' song(s) permanently deleted');
        loadSongs();
        loadTrashCount();
        loadArtists();
      })
      .catch(function (err) {
        showToast((err && err.error) || 'Failed to empty trash', 'error');
      });
  }

  // ── Upload ────────────────────────────────────────────

  function openUpload() {
    document.getElementById('uploadForm').reset();
    document.getElementById('dropFileList').textContent = '';
    document.getElementById('uploadResults').classList.add('hidden');
    document.getElementById('btnSubmitUpload').disabled    = false;
    document.getElementById('btnSubmitUpload').textContent = 'Upload';
    hideUploadProgress();
    populateArtistSelects();
    populateGenreSelects();
    document.getElementById('uploadModal').classList.remove('hidden');
  }

  function closeUpload() {
    document.getElementById('uploadModal').classList.add('hidden');
  }

  var AUDIO_EXTS = ['mp3','flac','ogg','wav','aac','m4a'];

  function onFilesSelected() {
    var files = document.getElementById('uploadFiles').files;
    showFileList(files);
  }

  function onFolderSelected() {
    var allFiles = document.getElementById('uploadFolder').files;
    if (!allFiles || allFiles.length === 0) return;
    showToast('Scanning ' + allFiles.length + ' files from folder...');
    // Filter to audio files only
    var dt = new DataTransfer();
    for (var i = 0; i < allFiles.length; i++) {
      var ext = allFiles[i].name.split('.').pop().toLowerCase();
      if (AUDIO_EXTS.includes(ext)) dt.items.add(allFiles[i]);
    }
    // Move filtered files into the main uploadFiles input
    document.getElementById('uploadFiles').files = dt.files;
    showFileList(dt.files);
    if (dt.files.length === 0) {
      showToast('No supported audio files found in folder', 'error');
    } else {
      showToast(dt.files.length + ' audio file(s) ready to upload');
    }
  }

  function showFileList(files) {
    var list = document.getElementById('dropFileList');
    if (!files || files.length === 0) { list.textContent = ''; return; }
    var names = Array.from(files).map(function (f) { return '- ' + f.name; }).join('\n');
    list.textContent = files.length + ' audio file(s):\n' + names;
  }

  function formatBytes(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
  }

  function updateProgressUI(label, pct, sizeText) {
    var wrap = document.getElementById('uploadProgress');
    wrap.classList.remove('hidden');
    document.getElementById('uploadProgressLabel').textContent = label;
    document.getElementById('uploadProgressPct').textContent = pct + '%';
    document.getElementById('uploadProgressBar').style.width = pct + '%';
    document.getElementById('uploadProgressSize').textContent = sizeText || '';
  }

  function hideUploadProgress() {
    var wrap = document.getElementById('uploadProgress');
    wrap.classList.add('hidden');
    document.getElementById('uploadProgressBar').style.width = '0';
    document.getElementById('uploadProgressLabel').textContent = 'Uploading...';
    document.getElementById('uploadProgressPct').textContent = '0%';
    document.getElementById('uploadProgressSize').textContent = '';
  }

  function setUploadFormDisabled(disabled) {
    var ids = ['uploadFiles', 'uploadFolder', 'uploadGenreSel', 'uploadArtist', 'btnBrowseFolder', 'btnNewArtist', 'btnNewGenre', 'btnCancelUpload'];
    ids.forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.disabled = disabled;
    });
    var dz = document.getElementById('dropZone');
    if (dz) dz.style.pointerEvents = disabled ? 'none' : '';
    if (dz) dz.style.opacity = disabled ? '0.5' : '';
  }

  function handleUpload(e) {
    e.preventDefault();

    var files    = document.getElementById('uploadFiles').files;
    var genreId  = document.getElementById('uploadGenreSel').value;
    var artistId = document.getElementById('uploadArtist').value;

    if (!files || files.length === 0) { showToast('Please select at least one audio file', 'error'); return; }
    if (!genreId) { showToast('Please select a genre', 'error'); return; }

    var btn = document.getElementById('btnSubmitUpload');
    btn.disabled    = true;
    btn.textContent = files.length > 1 ? 'Uploading ' + files.length + ' files...' : 'Uploading...';
    setUploadFormDisabled(true);

    hideUploadProgress();
    document.getElementById('uploadResults').classList.add('hidden');

    var totalFiles = files.length;

    // Single file
    if (totalFiles === 1) {
      var formData = new FormData();
      formData.append('file', files[0]);
      formData.append('category_id', genreId);
      if (artistId) formData.append('artist_id', artistId);

      iRadioAPI.upload('/admin/songs', formData, function(p) {
        var label = p.pct < 100 ? 'Uploading...' : 'Processing...';
        updateProgressUI(label, p.pct, formatBytes(p.loaded) + ' / ' + formatBytes(p.total));
      })
        .then(function () {
          hideUploadProgress();
          showToast('File queued for processing');
          closeUpload();
          loadSongs();
          loadPendingCount();
        })
        .catch(function (err) {
          hideUploadProgress();
          showToast((err && err.error) || 'Upload failed', 'error');
        })
        .finally(function () {
          btn.disabled    = false;
          btn.textContent = 'Upload';
          setUploadFormDisabled(false);
        });
      return;
    }

    // Multiple files: parallel upload (3 concurrent)
    var PARALLEL = 3;
    var results  = [];
    var imported = 0;
    var errors   = 0;
    var finished = 0;
    var fileArr  = Array.from(files);
    var nextIndex = 0;

    function onFileComplete() {
      finished++;
      var overallPct = Math.round((finished / totalFiles) * 100);
      btn.textContent = 'Uploading ' + finished + ' / ' + totalFiles + '...';
      updateProgressUI(
        finished + ' of ' + totalFiles + ' complete',
        overallPct,
        imported + ' queued' + (errors > 0 ? ', ' + errors + ' failed' : '')
      );

      if (finished >= totalFiles) {
        hideUploadProgress();
        showToast(imported + ' file(s) queued' + (errors > 0 ? ', ' + errors + ' skipped' : ''),
                  errors > 0 ? 'error' : 'success');

        var errs = results.filter(function (r) { return r.status !== 'queued'; });
        if (errs.length > 0) {
          var rHtml = '';
          results.forEach(function (r) {
            rHtml +=
              '<div class="upload-result-item">' +
                '<span>' + (r.status === 'queued' ? '&#10003;' : '&#10007;') + '</span>' +
                '<span class="res-name">' + escHtml(r.filename) + '</span>' +
                (r.status !== 'queued' ? '<span class="res-err">' + escHtml(r.error) + '</span>' : '') +
              '</div>';
          });
          document.getElementById('uploadResultList').innerHTML = rHtml;
          document.getElementById('uploadSummary').textContent =
            imported + ' of ' + totalFiles + ' queued for processing';
          document.getElementById('uploadResults').classList.remove('hidden');
        } else {
          closeUpload();
        }
        loadSongs();
        loadPendingCount();
        btn.disabled    = false;
        btn.textContent = 'Upload';
        setUploadFormDisabled(false);
        return;
      }

      // Start next file if any remain
      startNext();
    }

    function startNext() {
      if (nextIndex >= totalFiles) return;
      var idx  = nextIndex++;
      var file = fileArr[idx];
      var fd   = new FormData();
      fd.append('file', file);
      fd.append('category_id', genreId);
      if (artistId) fd.append('artist_id', artistId);

      iRadioAPI.upload('/admin/songs', fd, function() {})
        .then(function () {
          results.push({ status: 'queued', filename: file.name });
          imported++;
        })
        .catch(function (err) {
          results.push({ status: 'error', filename: file.name, error: (err && err.error) || 'Upload failed' });
          errors++;
        })
        .then(onFileComplete);
    }

    updateProgressUI('Starting upload...', 0, '');
    for (var p = 0; p < Math.min(PARALLEL, totalFiles); p++) {
      startNext();
    }
  }

  // ── Edit Song ─────────────────────────────────────────

  function editSong(id) {
    Promise.all([
      iRadioAPI.get('/admin/artists').then(function (data) {
        artists = data.artists || [];
        populateArtistSelects();
      }).catch(function () {}),
      iRadioAPI.get('/admin/categories').then(function (data) {
        categories = data.categories || [];
        populateGenreSelects();
      }).catch(function () {}),
      iRadioAPI.get('/admin/songs/' + id)
    ]).then(function (results) {
      var s = results[2].song;

      document.getElementById('editId').value            = s.id;
      document.getElementById('editTitle').value          = s.title || '';
      document.getElementById('editYear').value           = s.year || '';
      document.getElementById('editWeight').value         = s.rotation_weight;
      document.getElementById('editActive').checked       = s.is_active;
      document.getElementById('editRequestable').checked  = s.is_requestable;
      document.getElementById('editDuration').textContent = formatDuration(s.duration_ms);
      document.getElementById('editPlayCount').textContent = (s.play_count || 0) + ' plays';
      document.getElementById('editYearInfo').textContent = s.year || '-';

      document.getElementById('editArtist').value = s.artist_id;
      document.getElementById('editGenre').value  = s.category_id;

      document.getElementById('editModal').classList.remove('hidden');
    }).catch(function (err) {
      showToast((err && err.error) || 'Failed to load song', 'error');
    });
  }

  function handleEdit(e) {
    e.preventDefault();

    var id       = document.getElementById('editId').value;
    var artistId = parseInt(document.getElementById('editArtist').value);
    var genreId  = parseInt(document.getElementById('editGenre').value);

    if (!artistId) { showToast('Please select an artist', 'error'); return; }
    if (!genreId)  { showToast('Please select a genre', 'error'); return; }

    var body = {
      title:           document.getElementById('editTitle').value.trim(),
      artist_id:       artistId,
      category_id:     genreId,
      year:            parseInt(document.getElementById('editYear').value) || null,
      rotation_weight: parseFloat(document.getElementById('editWeight').value) || 1.0,
      is_active:       document.getElementById('editActive').checked,
      is_requestable:  document.getElementById('editRequestable').checked,
    };

    iRadioAPI.put('/admin/songs/' + id, body)
      .then(function () {
        showToast('Metadata saved');
        document.getElementById('editModal').classList.add('hidden');
        loadSongs();
      })
      .catch(function (err) {
        showToast((err && err.error) || 'Update failed', 'error');
      });
  }

  // ── New Artist ────────────────────────────────────────

  function openNewArtist() {
    document.getElementById('newArtistName').value = '';
    document.getElementById('artistModal').classList.remove('hidden');
  }

  function handleNewArtist(e) {
    e.preventDefault();
    var name = document.getElementById('newArtistName').value.trim();
    if (!name) return;

    iRadioAPI.post('/admin/artists', { name: name })
      .then(function (data) {
        var msg = data.message === 'Artist already exists' ? 'Artist already exists: ' + name : 'Artist created: ' + name;
        showToast(msg);
        document.getElementById('artistModal').classList.add('hidden');
        var newId = data.id;
        // Reload artists and auto-select the new one
        iRadioAPI.get('/admin/artists').then(function (result) {
          artists = result.artists || [];
          populateArtistSelects();
          if (newId) {
            var uploadSel = document.getElementById('uploadArtist');
            var editSel   = document.getElementById('editArtist');
            if (uploadSel) uploadSel.value = newId;
            if (editSel)   editSel.value = newId;
          }
        });
      })
      .catch(function (err) {
        showToast((err && err.error) || 'Failed to create artist', 'error');
      });
  }

  // ── New Genre ────────────────────────────────────────

  function openNewGenre() {
    document.getElementById('newGenreName').value = '';
    document.getElementById('genreModal').classList.remove('hidden');
  }

  function handleNewGenre(e) {
    e.preventDefault();
    var name = document.getElementById('newGenreName').value.trim();
    if (!name) return;

    iRadioAPI.post('/admin/categories', { name: name, type: 'music' })
      .then(function (data) {
        var msg = data.message === 'Category already exists' ? 'Genre already exists: ' + name : 'Genre created: ' + name;
        showToast(msg);
        document.getElementById('genreModal').classList.add('hidden');
        var newId = data.id;
        // Reload categories and auto-select the new one
        iRadioAPI.get('/admin/categories').then(function (result) {
          categories = result.categories || [];
          populateGenreSelects();
          if (newId) {
            var uploadSel = document.getElementById('uploadGenreSel');
            var editSel   = document.getElementById('editGenre');
            if (uploadSel) uploadSel.value = newId;
            if (editSel)   editSel.value = newId;
          }
        });
      })
      .catch(function (err) {
        showToast((err && err.error) || 'Failed to create genre', 'error');
      });
  }

  // ── Duplicates ──────────────────────────────────────────

  function dupScan() {
    var btn = document.getElementById('btnDupScan');
    btn.disabled = true;
    btn.textContent = 'Scanning\u2026';
    document.getElementById('btnDupDeleteAll').style.display = 'none';
    document.getElementById('dupSummary').textContent = '';
    document.getElementById('dupResults').innerHTML =
      '<p class="text-dim" style="padding:20px 0;text-align:center">Scanning library for duplicates\u2026</p>';

    iRadioAPI.get('/admin/duplicates/scan')
      .then(function (data) {
        dupGroups = data.groups || [];
        dupRenderGroups();
        var dupes = data.total_duplicates || 0;
        var gLen  = data.total_groups || 0;
        if (gLen === 0) {
          document.getElementById('dupSummary').textContent = 'No duplicates found.';
          document.getElementById('dupResults').innerHTML =
            '<p class="text-dim" style="padding:30px 0;text-align:center;opacity:.5">Your library is clean!</p>';
        } else {
          document.getElementById('dupSummary').textContent =
            gLen + ' group' + (gLen !== 1 ? 's' : '') + ', ' +
            dupes + ' duplicate' + (dupes !== 1 ? 's' : '') + ' found.';
          document.getElementById('btnDupDeleteAll').style.display = '';
        }
        dupUpdateBadge();
      })
      .catch(function (err) {
        document.getElementById('dupResults').innerHTML = '';
        showToast((err && err.error) || 'Scan failed', 'error');
      })
      .finally(function () {
        btn.disabled = false;
        btn.textContent = 'Scan for Duplicates';
      });
  }

  function dupRenderGroups() {
    var container = document.getElementById('dupResults');
    if (dupGroups.length === 0) {
      container.innerHTML = '';
      return;
    }

    var html = '';
    dupGroups.forEach(function (g, idx) {
      var badge = g.type === 'exact'
        ? '<span class="dup-badge-exact">Exact Match</span>'
        : '<span class="dup-badge-likely">Likely Match</span>';

      html += '<div class="dup-group" data-group="' + idx + '">';
      html += '<div class="flex items-center justify-between" style="margin-bottom:10px">';
      html += '<strong style="font-size:.9rem">Group ' + (idx + 1) + ' ' + badge + '</strong>';
      html += '<button class="btn btn-ghost btn-sm" style="color:var(--danger)" onclick="iRadioMedia.dupResolveGroup(' + idx + ')">Delete Unselected</button>';
      html += '</div>';

      html += '<table><thead><tr>';
      html += '<th style="width:36px">Keep</th>';
      html += '<th>Title</th>';
      html += '<th>Artist</th>';
      html += '<th style="font-size:.78rem">File Path</th>';
      html += '<th>Size</th>';
      html += '<th>Duration</th>';
      html += '<th>Plays</th>';
      html += '</tr></thead><tbody>';

      g.songs.forEach(function (s) {
        var checked = s.id === g.recommended_keep_id ? ' checked' : '';
        html += '<tr>';
        html += '<td style="text-align:center"><input type="radio" class="dup-keep-radio" name="dup_group_' + idx + '" value="' + s.id + '"' + checked + '></td>';
        html += '<td>' + escHtml(s.title) + '</td>';
        html += '<td>' + escHtml(s.artist_name) + '</td>';
        html += '<td style="font-size:.78rem;max-width:200px;word-break:break-all;opacity:.6">' + escHtml(s.file_path) + '</td>';
        html += '<td style="white-space:nowrap">' + formatBytes(s.file_size) + '</td>';
        html += '<td style="white-space:nowrap">' + formatDuration(s.duration_ms) + '</td>';
        html += '<td style="text-align:center">' + s.play_count + '</td>';
        html += '</tr>';
      });

      html += '</tbody></table></div>';
    });

    container.innerHTML = html;
  }

  function dupGetKeepId(idx) {
    var radios = document.querySelectorAll('input[name="dup_group_' + idx + '"]');
    for (var i = 0; i < radios.length; i++) {
      if (radios[i].checked) return parseInt(radios[i].value, 10);
    }
    return null;
  }

  function dupResolveGroup(idx) {
    var g = dupGroups[idx];
    if (!g) return;

    var keepId = dupGetKeepId(idx);
    if (keepId === null) {
      showToast('Please select a song to keep', 'error');
      return;
    }

    var deleteIds = [];
    g.songs.forEach(function (s) {
      if (s.id !== keepId) deleteIds.push(s.id);
    });

    if (deleteIds.length === 0) return;
    if (!confirm('Delete ' + deleteIds.length + ' duplicate(s) from this group? The selected song will be kept.')) return;

    iRadioAPI.post('/admin/duplicates/resolve', { keep_ids: [keepId], delete_ids: deleteIds })
      .then(function (data) {
        showToast('Deleted ' + data.deleted + ' song(s), freed ' + formatBytes(data.freed_bytes));
        dupGroups.splice(idx, 1);
        dupRenderGroups();
        dupUpdateSummary();
        dupUpdateBadge();
        loadArtists();
      })
      .catch(function (err) {
        showToast((err && err.error) || 'Delete failed', 'error');
      });
  }

  function dupResolveAll() {
    if (dupGroups.length === 0) return;

    var keepIds = [];
    var deleteIds = [];

    for (var i = 0; i < dupGroups.length; i++) {
      var keepId = dupGetKeepId(i);
      if (keepId === null) {
        showToast('Please select a song to keep in Group ' + (i + 1), 'error');
        return;
      }
      keepIds.push(keepId);
      dupGroups[i].songs.forEach(function (s) {
        if (s.id !== keepId) deleteIds.push(s.id);
      });
    }

    if (deleteIds.length === 0) return;
    if (!confirm('Delete ' + deleteIds.length + ' duplicate(s) across all groups? One song per group will be kept.')) return;

    iRadioAPI.post('/admin/duplicates/resolve', { keep_ids: keepIds, delete_ids: deleteIds })
      .then(function (data) {
        showToast('Deleted ' + data.deleted + ' song(s), freed ' + formatBytes(data.freed_bytes));
        dupGroups = [];
        dupRenderGroups();
        dupUpdateSummary();
        dupUpdateBadge();
        loadArtists();
        document.getElementById('btnDupDeleteAll').style.display = 'none';
        document.getElementById('dupResults').innerHTML =
          '<p class="text-dim" style="padding:30px 0;text-align:center;opacity:.5">Your library is clean!</p>';
      })
      .catch(function (err) {
        showToast((err && err.error) || 'Delete failed', 'error');
      });
  }

  function dupUpdateSummary() {
    var totalDupes = 0;
    dupGroups.forEach(function (g) { totalDupes += g.songs.length - 1; });
    var gLen = dupGroups.length;
    if (gLen === 0) {
      document.getElementById('dupSummary').textContent = 'No duplicates remaining.';
      document.getElementById('btnDupDeleteAll').style.display = 'none';
    } else {
      document.getElementById('dupSummary').textContent =
        gLen + ' group' + (gLen !== 1 ? 's' : '') + ', ' +
        totalDupes + ' duplicate' + (totalDupes !== 1 ? 's' : '') + ' remaining.';
    }
  }

  function dupUpdateBadge() {
    var badge = document.getElementById('dupBadge');
    var totalDupes = 0;
    dupGroups.forEach(function (g) { totalDupes += g.songs.length - 1; });
    if (totalDupes > 0) {
      badge.textContent = totalDupes > 99 ? '99+' : totalDupes;
      badge.classList.remove('hidden');
    } else {
      badge.classList.add('hidden');
    }
  }

  // ── Helpers ───────────────────────────────────────────

  function formatDate(iso) {
    if (!iso) return '-';
    var d = new Date(iso);
    return d.toLocaleDateString('en-US', Object.assign({ day: 'numeric', month: 'short', year: 'numeric' }, iRadioAPI.tzOpts()));
  }

  function formatDuration(ms) {
    if (!ms) return '-';
    var sec = Math.floor(ms / 1000);
    var min = Math.floor(sec / 60);
    var s   = sec % 60;
    return min + ':' + (s < 10 ? '0' : '') + s;
  }

  function escHtml(str) {
    if (str === null || str === undefined) return '';
    var div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
  }

  function escAttr(str) {
    return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  }

  function showToast(msg, type) {
    var container = document.getElementById('toasts');
    var toast     = document.createElement('div');
    toast.className   = 'toast toast-' + (type || 'success');
    toast.textContent = msg;
    container.appendChild(toast);
    setTimeout(function () { toast.remove(); }, 4500);
  }

  // ── Public API ────────────────────────────────────────

  return {
    init:            init,
    editSong:        editSong,
    trashSong:       trashSong,
    restoreSong:     restoreSong,
    purgeSong:       purgeSong,
    goToPage:        goToPage,
    dupResolveGroup: dupResolveGroup,
  };
})();
