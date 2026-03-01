/* ============================================================
   RendezVox Admin — Station ID Manager
   ============================================================ */
var RendezVoxStationIds = (function() {

  var currentlyPlaying = null; // filename of currently playing station ID
  var renameTarget = null;     // filename being renamed

  function init() {
    loadStationIds();

    var fileInput = document.getElementById('fileInput');
    var btnBrowse = document.getElementById('btnBrowse');
    var uploadArea = document.getElementById('uploadArea');

    btnBrowse.addEventListener('click', function() {
      fileInput.click();
    });

    fileInput.addEventListener('change', function() {
      if (fileInput.files.length > 0) {
        uploadFiles(fileInput.files);
        fileInput.value = '';
      }
    });

    // Drag and drop
    uploadArea.addEventListener('dragover', function(e) {
      e.preventDefault();
      uploadArea.classList.add('dragover');
    });
    uploadArea.addEventListener('dragleave', function() {
      uploadArea.classList.remove('dragover');
    });
    uploadArea.addEventListener('drop', function(e) {
      e.preventDefault();
      uploadArea.classList.remove('dragover');
      if (e.dataTransfer.files.length > 0) {
        uploadFiles(e.dataTransfer.files);
      }
    });

    // Audio element events
    var audio = document.getElementById('stationIdAudio');
    audio.addEventListener('ended', function() {
      stopPlayback();
    });
    audio.addEventListener('error', function() {
      showToast('Failed to play station ID', 'error');
      stopPlayback();
    });

    // Rename modal
    document.getElementById('renameClose').addEventListener('click', closeRename);
    document.getElementById('renameCancel').addEventListener('click', closeRename);
    document.getElementById('renameSave').addEventListener('click', submitRename);
    document.getElementById('renameInput').addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); submitRename(); }
      if (e.key === 'Escape') closeRename();
    });

  }

  // ── Load & render ──────────────────────────────────────

  function loadStationIds() {
    RendezVoxAPI.get('/admin/station-ids').then(function(data) {
      renderTable(data.station_ids);
      renderSummary(data.station_ids);
    }).catch(function(err) {
      console.error('Station IDs load error:', err);
      showToast('Failed to load station IDs', 'error');
    });
  }

  function renderSummary(files) {
    var el = document.getElementById('stationIdSummary');
    if (!files || files.length === 0) {
      el.textContent = '';
      return;
    }
    var totalSize = 0;
    for (var i = 0; i < files.length; i++) {
      totalSize += files[i].size || 0;
    }
    el.textContent = files.length + ' station ID' + (files.length !== 1 ? 's' : '') +
      ' \u2014 ' + formatSize(totalSize);
  }

  function renderTable(files) {
    var tbody = document.getElementById('stationIdTable');
    if (!files || files.length === 0) {
      tbody.innerHTML = '<tr><td colspan="5" class="empty">No station IDs uploaded yet</td></tr>';
      return;
    }

    var html = '';
    files.forEach(function(j) {
      var size = formatSize(j.size);
      var dur = formatDuration(j.duration_ms);
      var date = new Date(j.created_at).toLocaleDateString('en-US', Object.assign({ day: 'numeric', month: 'short', year: 'numeric' }, RendezVoxAPI.tzOpts()));
      var isPlaying = currentlyPlaying === j.filename;
      var playIcon = isPlaying
        ? '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" stroke="none"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>'
        : '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" stroke="none"><polygon points="5,3 19,12 5,21"/></svg>';
      var playClass = isPlaying ? ' playing' : '';

      html += '<tr>' +
        '<td>' + escHtml(j.filename) + '</td>' +
        '<td class="duration">' + dur + '</td>' +
        '<td class="file-size">' + size + '</td>' +
        '<td>' + date + '</td>' +
        '<td style="white-space:nowrap">' +
          '<button type="button" class="btn-play' + playClass + '" title="' + (isPlaying ? 'Stop' : 'Play') + '" onclick="RendezVoxStationIds.togglePlay(\'' + escAttr(j.filename) + '\')">' + playIcon + '</button> ' +
          '<button type="button" class="icon-btn" title="Rename" onclick="RendezVoxStationIds.renameFile(\'' + escAttr(j.filename) + '\')">' + RendezVoxIcons.edit + '</button> ' +
          '<button type="button" class="icon-btn danger" title="Delete" onclick="RendezVoxStationIds.deleteFile(\'' + escAttr(j.filename) + '\')">' + RendezVoxIcons.del + '</button>' +
        '</td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
  }

  // ── Audio playback ─────────────────────────────────────

  function togglePlay(filename) {
    if (currentlyPlaying === filename) {
      stopPlayback();
    } else {
      startPlayback(filename);
    }
  }

  function startPlayback(filename) {
    var audio = document.getElementById('stationIdAudio');
    // Stop any current playback first
    if (currentlyPlaying) {
      audio.pause();
      if (audio.src && audio.src.startsWith('blob:')) {
        URL.revokeObjectURL(audio.src);
      }
    }
    currentlyPlaying = filename;
    refreshPlayButtons();

    // Fetch with auth header, create blob URL for audio element
    // (<audio> elements cannot send Authorization headers natively)
    fetch('/api/admin/station-ids/' + encodeURIComponent(filename) + '/stream', {
      headers: { 'Authorization': 'Bearer ' + sessionStorage.getItem('rendezvox_token') }
    })
    .then(function(res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.blob();
    })
    .then(function(blob) {
      if (currentlyPlaying !== filename) return; // user clicked stop/another while loading
      var url = URL.createObjectURL(blob);
      audio.src = url;
      return audio.play();
    })
    .catch(function() {
      showToast('Failed to play station ID', 'error');
      stopPlayback();
    });
  }

  function stopPlayback() {
    var audio = document.getElementById('stationIdAudio');
    audio.pause();
    if (audio.src && audio.src.startsWith('blob:')) {
      URL.revokeObjectURL(audio.src);
    }
    audio.removeAttribute('src');
    audio.load();
    currentlyPlaying = null;
    refreshPlayButtons();
  }

  function refreshPlayButtons() {
    var buttons = document.querySelectorAll('.btn-play');
    buttons.forEach(function(btn) {
      var fn = btn.getAttribute('onclick').match(/togglePlay\('(.+?)'\)/);
      if (!fn) return;
      var filename = fn[1].replace(/\\'/g, "'");
      var isPlaying = currentlyPlaying === filename;
      btn.className = 'btn-play' + (isPlaying ? ' playing' : '');
      btn.title = isPlaying ? 'Stop' : 'Play';
      btn.innerHTML = isPlaying
        ? '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" stroke="none"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>'
        : '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" stroke="none"><polygon points="5,3 19,12 5,21"/></svg>';
    });
  }

  // ── Upload with progress ───────────────────────────────

  function uploadFiles(files) {
    var totalFiles = files.length;

    if (totalFiles === 1) {
      uploadSingle(files[0]);
      return;
    }

    // Multiple files: parallel upload (4 concurrent)
    var PARALLEL = 4;
    var finished = 0;
    var errors = 0;
    var fileArr = Array.from(files);
    var nextIndex = 0;

    function onFileComplete() {
      finished++;
      var pct = Math.round((finished / totalFiles) * 100);
      showProgress(finished + ' of ' + totalFiles + ' complete', pct);

      if (finished >= totalFiles) {
        hideProgress();
        var ok = totalFiles - errors;
        showToast(ok + ' station ID(s) uploaded' + (errors > 0 ? ', ' + errors + ' failed' : ''));
        loadStationIds();
        return;
      }
      startNext();
    }

    function startNext() {
      if (nextIndex >= totalFiles) return;
      var idx = nextIndex++;
      var file = fileArr[idx];
      var formData = new FormData();
      formData.append('file', file);

      RendezVoxAPI.upload('/admin/station-ids', formData, function() {})
        .then(function() { onFileComplete(); })
        .catch(function(err) {
          errors++;
          showToast((err && err.error) || 'Upload failed: ' + file.name, 'error');
          onFileComplete();
        });
    }

    showProgress('Starting upload...', 0);
    for (var p = 0; p < Math.min(PARALLEL, totalFiles); p++) {
      startNext();
    }
  }

  function uploadSingle(file) {
    var formData = new FormData();
    formData.append('file', file);

    showProgress('Uploading...', 0);

    RendezVoxAPI.upload('/admin/station-ids', formData, function(p) {
      var label = p.pct < 100 ? 'Uploading...' : 'Processing...';
      showProgress(label, p.pct);
    }).then(function() {
      hideProgress();
      showToast('Station ID uploaded');
      loadStationIds();
    }).catch(function(err) {
      hideProgress();
      showToast((err && err.error) || 'Upload failed', 'error');
    });
  }

  // ── Progress UI ────────────────────────────────────────

  function showProgress(label, pct) {
    var wrap = document.getElementById('stationIdProgress');
    wrap.style.display = '';
    document.getElementById('stationIdProgressLabel').textContent = label;
    document.getElementById('stationIdProgressPct').textContent = pct + '%';
    document.getElementById('stationIdProgressBar').style.width = pct + '%';
  }

  function hideProgress() {
    var wrap = document.getElementById('stationIdProgress');
    wrap.style.display = 'none';
    document.getElementById('stationIdProgressBar').style.width = '0';
  }

  // ── Rename (modal) ─────────────────────────────────────

  function renameFile(filename) {
    renameTarget = filename;
    var input = document.getElementById('renameInput');
    input.value = filename;
    document.getElementById('renameModal').classList.remove('hidden');
    // Select just the basename (before extension) for easy editing
    var dot = filename.lastIndexOf('.');
    input.focus();
    if (dot > 0) {
      input.setSelectionRange(0, dot);
    } else {
      input.select();
    }
  }

  function closeRename() {
    document.getElementById('renameModal').classList.add('hidden');
    renameTarget = null;
  }

  function submitRename() {
    if (!renameTarget) return;
    var newName = document.getElementById('renameInput').value.trim();
    if (newName === '' || newName === renameTarget) { closeRename(); return; }
    var filename = renameTarget;

    var btn = document.getElementById('renameSave');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    RendezVoxAPI.put('/admin/station-ids/' + encodeURIComponent(filename) + '/rename', { filename: newName })
      .then(function(data) {
        showToast('Renamed to ' + data.filename);
        if (currentlyPlaying === filename) {
          currentlyPlaying = data.filename;
        }
        closeRename();
        loadStationIds();
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Rename failed', 'error');
      })
      .finally(function() {
        btn.disabled = false;
        btn.textContent = 'Save';
      });
  }

  // ── Delete ─────────────────────────────────────────────

  function deleteFile(filename) {
    RendezVoxConfirm('Delete station ID "' + filename + '"?', { title: 'Delete Station ID', okLabel: 'Delete' })
      .then(function(ok) {
        if (!ok) return;
        if (currentlyPlaying === filename) stopPlayback();
        RendezVoxAPI.del('/admin/station-ids/' + encodeURIComponent(filename))
          .then(function() { showToast('Station ID deleted'); loadStationIds(); })
          .catch(function(err) { showToast((err && err.error) || 'Delete failed', 'error'); });
      });
  }

  // ── Helpers ────────────────────────────────────────────

  function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
  }

  function formatDuration(ms) {
    if (!ms) return '-';
    var sec = Math.floor(ms / 1000);
    var min = Math.floor(sec / 60);
    var s = sec % 60;
    return min + ':' + (s < 10 ? '0' : '') + s;
  }

  function escHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  function escAttr(str) {
    return str.replace(/'/g, "\\'").replace(/"/g, '&quot;');
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
    togglePlay: togglePlay,
    renameFile: renameFile,
    deleteFile: deleteFile
  };
})();
