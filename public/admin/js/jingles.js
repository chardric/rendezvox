/* ============================================================
   iRadio Admin — Jingle Manager
   ============================================================ */
var iRadioJingles = (function() {

  var currentlyPlaying = null; // filename of currently playing jingle
  var renameTarget = null;     // filename being renamed

  function init() {
    loadJingles();

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
    var audio = document.getElementById('jingleAudio');
    audio.addEventListener('ended', function() {
      stopJingle();
    });
    audio.addEventListener('error', function() {
      showToast('Failed to play jingle', 'error');
      stopJingle();
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

  function loadJingles() {
    iRadioAPI.get('/admin/jingles').then(function(data) {
      renderTable(data.jingles);
      renderSummary(data.jingles);
    }).catch(function() {
      showToast('Failed to load jingles', 'error');
    });
  }

  function renderSummary(jingles) {
    var el = document.getElementById('jingleSummary');
    if (!jingles || jingles.length === 0) {
      el.textContent = '';
      return;
    }
    var totalSize = 0;
    for (var i = 0; i < jingles.length; i++) {
      totalSize += jingles[i].size || 0;
    }
    el.textContent = jingles.length + ' jingle' + (jingles.length !== 1 ? 's' : '') +
      ' \u2014 ' + formatSize(totalSize);
  }

  function renderTable(jingles) {
    var tbody = document.getElementById('jingleTable');
    if (!jingles || jingles.length === 0) {
      tbody.innerHTML = '<tr><td colspan="5" class="empty">No jingles uploaded yet</td></tr>';
      return;
    }

    var html = '';
    jingles.forEach(function(j) {
      var size = formatSize(j.size);
      var dur = formatDuration(j.duration_ms);
      var date = new Date(j.created_at).toLocaleDateString('en-US', Object.assign({ day: 'numeric', month: 'short', year: 'numeric' }, iRadioAPI.tzOpts()));
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
          '<button type="button" class="btn-play' + playClass + '" title="' + (isPlaying ? 'Stop' : 'Play') + '" onclick="iRadioJingles.togglePlay(\'' + escAttr(j.filename) + '\')">' + playIcon + '</button> ' +
          '<button type="button" class="icon-btn" title="Rename" onclick="iRadioJingles.renameJingle(\'' + escAttr(j.filename) + '\')">' + iRadioIcons.edit + '</button> ' +
          '<button type="button" class="icon-btn danger" title="Delete" onclick="iRadioJingles.deleteJingle(\'' + escAttr(j.filename) + '\')">' + iRadioIcons.del + '</button>' +
        '</td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
  }

  // ── Audio playback ─────────────────────────────────────

  function togglePlay(filename) {
    if (currentlyPlaying === filename) {
      stopJingle();
    } else {
      playJingle(filename);
    }
  }

  function playJingle(filename) {
    var audio = document.getElementById('jingleAudio');
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
    fetch('/api/admin/jingles/' + encodeURIComponent(filename) + '/stream', {
      headers: { 'Authorization': 'Bearer ' + localStorage.getItem('iradio_token') }
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
      showToast('Failed to play jingle', 'error');
      stopJingle();
    });
  }

  function stopJingle() {
    var audio = document.getElementById('jingleAudio');
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
        showToast(ok + ' jingle(s) uploaded' + (errors > 0 ? ', ' + errors + ' failed' : ''));
        loadJingles();
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

      iRadioAPI.upload('/admin/jingles', formData, function() {})
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

    iRadioAPI.upload('/admin/jingles', formData, function(p) {
      var label = p.pct < 100 ? 'Uploading...' : 'Processing...';
      showProgress(label, p.pct);
    }).then(function() {
      hideProgress();
      showToast('Jingle uploaded');
      loadJingles();
    }).catch(function(err) {
      hideProgress();
      showToast((err && err.error) || 'Upload failed', 'error');
    });
  }

  // ── Progress UI ────────────────────────────────────────

  function showProgress(label, pct) {
    var wrap = document.getElementById('jingleProgress');
    wrap.style.display = '';
    document.getElementById('jingleProgressLabel').textContent = label;
    document.getElementById('jingleProgressPct').textContent = pct + '%';
    document.getElementById('jingleProgressBar').style.width = pct + '%';
  }

  function hideProgress() {
    var wrap = document.getElementById('jingleProgress');
    wrap.style.display = 'none';
    document.getElementById('jingleProgressBar').style.width = '0';
  }

  // ── Rename (modal) ─────────────────────────────────────

  function renameJingle(filename) {
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

    iRadioAPI.put('/admin/jingles/' + encodeURIComponent(filename) + '/rename', { filename: newName })
      .then(function(data) {
        showToast('Renamed to ' + data.filename);
        if (currentlyPlaying === filename) {
          currentlyPlaying = data.filename;
        }
        closeRename();
        loadJingles();
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

  function deleteJingle(filename) {
    iRadioConfirm('Delete jingle "' + filename + '"?', { title: 'Delete Jingle', okLabel: 'Delete' })
      .then(function(ok) {
        if (!ok) return;
        if (currentlyPlaying === filename) stopJingle();
        iRadioAPI.del('/admin/jingles/' + encodeURIComponent(filename))
          .then(function() { showToast('Jingle deleted'); loadJingles(); })
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
    renameJingle: renameJingle,
    deleteJingle: deleteJingle
  };
})();
