/* ============================================================
   RendezVox Admin — Songs Management
   ============================================================ */
var RendezVoxSongs = (function() {

  var currentPage = 1;
  var totalPages  = 1;
  var artists     = [];
  var categories  = [];
  var debounceTimer = null;

  function init() {
    loadArtists();
    loadCategories();

    // Apply URL query params (e.g. ?filter=missing from dashboard link)
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('filter') === 'missing') {
      document.getElementById('filterActive').value = 'missing';
    }

    loadSongs();

    // Filters
    document.getElementById('filterSearch').addEventListener('input', debounceLoad);
    document.getElementById('filterCategory').addEventListener('change', function() { currentPage = 1; loadSongs(); });
    document.getElementById('filterActive').addEventListener('change', function() { currentPage = 1; loadSongs(); });

    // Deactivate all missing files
    document.getElementById('btnDeactivateMissing').addEventListener('click', deactivateAllMissing);

    // Pagination
    document.getElementById('btnPrev').addEventListener('click', function() {
      if (currentPage > 1) { currentPage--; loadSongs(); }
    });
    document.getElementById('btnNext').addEventListener('click', function() {
      if (currentPage < totalPages) { currentPage++; loadSongs(); }
    });

    // Upload modal
    document.getElementById('btnUpload').addEventListener('click', function() {
      document.getElementById('uploadForm').reset();
      document.getElementById('uploadModal').classList.remove('hidden');
    });
    document.getElementById('btnCancelUpload').addEventListener('click', function() {
      document.getElementById('uploadModal').classList.add('hidden');
    });
    document.getElementById('uploadForm').addEventListener('submit', handleUpload);

    // Edit modal
    document.getElementById('btnCancelEdit').addEventListener('click', function() {
      document.getElementById('editModal').classList.add('hidden');
    });
    document.getElementById('editForm').addEventListener('submit', handleEdit);

    // New artist modal
    document.getElementById('btnNewArtist').addEventListener('click', function() {
      document.getElementById('artistForm').reset();
      document.getElementById('artistModal').classList.remove('hidden');
    });
    document.getElementById('btnCancelArtist').addEventListener('click', function() {
      document.getElementById('artistModal').classList.add('hidden');
    });
    document.getElementById('artistForm').addEventListener('submit', handleNewArtist);
  }

  function debounceLoad() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function() { currentPage = 1; loadSongs(); }, 300);
  }

  // ── Data loading ─────────────────────────────────────

  function loadSongs() {
    var search   = document.getElementById('filterSearch').value.trim();
    var category = document.getElementById('filterCategory').value;
    var active   = document.getElementById('filterActive').value;
    var btnDeact = document.getElementById('btnDeactivateMissing');

    var q = '?page=' + currentPage + '&per_page=50';
    if (search)   q += '&search='      + encodeURIComponent(search);
    if (category) q += '&category_id=' + category;

    if (active === 'missing') {
      q += '&missing=true';
      btnDeact.style.display = '';
    } else {
      if (active) q += '&active=' + active;
      btnDeact.style.display = 'none';
    }

    RendezVoxAPI.get('/admin/songs' + q)
      .then(function(data) {
        totalPages = data.pages || 1;
        renderTable(data.songs);
        renderPagination(data.total, data.page, data.pages);
      })
      .catch(function(err) {
        console.error('Song load error:', err);
      });
  }

  function loadArtists() {
    RendezVoxAPI.get('/admin/artists').then(function(data) {
      artists = data.artists;
      populateArtistSelects();
    });
  }

  function loadCategories() {
    RendezVoxAPI.get('/admin/categories').then(function(data) {
      categories = data.categories;
      populateCategorySelects();
      populateFilterCategory();
    });
  }

  // ── Rendering ────────────────────────────────────────

  function renderTable(songs) {
    var tbody = document.getElementById('songTable');

    if (!songs || songs.length === 0) {
      tbody.innerHTML = '<tr><td colspan="10" class="empty">No songs found</td></tr>';
      return;
    }

    var html = '';
    songs.forEach(function(s, idx) {
      html += '<tr>' +
        '<td>' + ((currentPage - 1) * 50 + idx + 1) + '</td>' +
        '<td>' + escHtml(s.title) + '</td>' +
        '<td>' + escHtml(s.artist_name) + '</td>' +
        '<td>' + escHtml(s.category_name) + '</td>' +
        '<td>' + formatDuration(s.duration_ms) + '</td>' +
        '<td>' + s.rotation_weight + '</td>' +
        '<td>' + s.play_count + '</td>' +
        '<td><label class="toggle toggle-sm"><input type="checkbox" onchange="RendezVoxSongs.toggleSong(' + s.id + ')"' + (s.is_active ? ' checked' : '') + '><span class="slider"></span></label></td>' +
        '<td>' + (s.is_requestable ? 'Yes' : 'No') + '</td>' +
        '<td>' +
          '<button type="button" class="icon-btn" title="Edit" onclick="RendezVoxSongs.editSong(' + s.id + ')">' + RendezVoxIcons.edit + '</button>' +
        '</td>' +
        '</tr>';
    });

    tbody.innerHTML = html;
  }

  function renderPagination(total, page, pages) {
    var pag = document.getElementById('pagination');
    if (total <= 50) {
      pag.style.display = 'none';
      return;
    }
    pag.style.display = '';
    document.getElementById('pageInfo').textContent = 'Page ' + page + ' of ' + pages + ' (' + total + ' songs)';
    document.getElementById('btnPrev').disabled = page <= 1;
    document.getElementById('btnNext').disabled = page >= pages;
  }

  function populateArtistSelects() {
    var html = '<option value="">Select artist…</option>';
    artists.forEach(function(a) {
      html += '<option value="' + a.id + '">' + escHtml(a.name) + '</option>';
    });
    document.getElementById('uploadArtist').innerHTML = html;
    document.getElementById('editArtist').innerHTML = html;
  }

  function populateCategorySelects() {
    var html = '<option value="">Select genre/category…</option>';
    categories.forEach(function(c) {
      html += '<option value="' + c.id + '">' + escHtml(c.name) + ' (' + c.type + ')</option>';
    });
    document.getElementById('uploadCategory').innerHTML = html;
    document.getElementById('editCategory').innerHTML = html;
  }

  function populateFilterCategory() {
    var sel = document.getElementById('filterCategory');
    var html = '<option value="">All Genres/Categories</option>';
    categories.forEach(function(c) {
      html += '<option value="' + c.id + '">' + escHtml(c.name) + '</option>';
    });
    sel.innerHTML = html;
  }

  // ── Actions ──────────────────────────────────────────

  function handleUpload(e) {
    e.preventDefault();

    var fileInput = document.getElementById('uploadFile');
    var title     = document.getElementById('uploadTitle').value.trim();
    var artistId  = document.getElementById('uploadArtist').value;
    var categoryId = document.getElementById('uploadCategory').value;
    var weight    = document.getElementById('uploadWeight').value;

    if (!title || !artistId || !categoryId) {
      showToast('Please fill all required fields', 'error');
      return;
    }

    var formData = new FormData();
    if (fileInput.files[0]) {
      formData.append('file', fileInput.files[0]);
    }
    formData.append('title', title);
    formData.append('artist_id', artistId);
    formData.append('category_id', categoryId);
    formData.append('rotation_weight', weight || '1.0');

    var btn = document.getElementById('btnSubmitUpload');
    btn.disabled = true;
    btn.textContent = 'Uploading…';

    RendezVoxAPI.upload('/admin/songs', formData)
      .then(function(data) {
        showToast('Song uploaded: #' + data.id);
        document.getElementById('uploadModal').classList.add('hidden');
        loadSongs();
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Upload failed', 'error');
      })
      .finally(function() {
        btn.disabled = false;
        btn.textContent = 'Upload';
      });
  }

  function editSong(id) {
    RendezVoxAPI.get('/admin/songs/' + id).then(function(data) {
      var s = data.song;
      document.getElementById('editId').value           = s.id;
      document.getElementById('editTitle').value         = s.title;
      document.getElementById('editArtist').value        = s.artist_id;
      document.getElementById('editCategory').value      = s.category_id;
      document.getElementById('editYear').value          = s.year || '';
      document.getElementById('editWeight').value        = s.rotation_weight;
      document.getElementById('editActive').checked      = s.is_active;
      document.getElementById('editRequestable').checked = s.is_requestable;
      document.getElementById('editModal').classList.remove('hidden');
    });
  }

  function handleEdit(e) {
    e.preventDefault();

    var id = document.getElementById('editId').value;
    var body = {
      title:           document.getElementById('editTitle').value.trim(),
      artist_id:       parseInt(document.getElementById('editArtist').value),
      category_id:     parseInt(document.getElementById('editCategory').value),
      year:            parseInt(document.getElementById('editYear').value) || null,
      rotation_weight: parseFloat(document.getElementById('editWeight').value),
      is_active:       document.getElementById('editActive').checked,
      is_requestable:  document.getElementById('editRequestable').checked,
    };

    RendezVoxAPI.put('/admin/songs/' + id, body)
      .then(function() {
        showToast('Song updated');
        document.getElementById('editModal').classList.add('hidden');
        loadSongs();
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Update failed', 'error');
      });
  }

  function toggleSong(id) {
    RendezVoxAPI.patch('/admin/songs/' + id + '/toggle', {})
      .then(function(data) {
        showToast(data.message);
        loadSongs();
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Toggle failed', 'error');
      });
  }

  function handleNewArtist(e) {
    e.preventDefault();
    var name = document.getElementById('newArtistName').value.trim();
    if (!name) return;

    RendezVoxAPI.post('/admin/artists', { name: name })
      .then(function(data) {
        showToast('Artist created: #' + data.id);
        document.getElementById('artistModal').classList.add('hidden');
        loadArtists();
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Failed to create artist', 'error');
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

  function deactivateAllMissing() {
    if (!confirm('Deactivate all songs with missing files?')) return;

    var btn = document.getElementById('btnDeactivateMissing');
    btn.disabled = true;
    btn.textContent = 'Working...';

    // Fetch ALL missing songs (up to 10000) to get their IDs
    RendezVoxAPI.get('/admin/songs?missing=true&per_page=10000')
      .then(function(data) {
        if (!data.songs || data.songs.length === 0) {
          showToast('No missing files found');
          btn.disabled = false;
          btn.textContent = 'Deactivate All Missing';
          return;
        }
        var ids = data.songs.map(function(s) { return s.id; });
        return RendezVoxAPI.post('/admin/songs/deactivate-missing', { ids: ids });
      })
      .then(function(result) {
        if (!result) return;
        showToast('Deactivated ' + result.count + ' songs');
        loadSongs();
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Failed to deactivate', 'error');
      })
      .finally(function() {
        btn.disabled = false;
        btn.textContent = 'Deactivate All Missing';
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
    editSong: editSong,
    toggleSong: toggleSong
  };
})();
