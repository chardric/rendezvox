/* ============================================================
   RendezVox Admin — Station IDs (TTS + File Management)
   ============================================================ */
var RendezVoxTts = (function() {

  var escHtml        = RendezVoxUtils.escHtml;
  var escAttr        = RendezVoxUtils.escAttr;
  var showToast      = RendezVoxUtils.showToast;
  var formatDuration = RendezVoxUtils.formatDuration;

  var settingMap = {
    'tts_voice':                    'ttsVoice',
    'tts_speed':                    'ttsSpeed',
    'tts_song_announce_enabled':    'ttsSongEnabled',
    'tts_song_announce_template':   'ttsSongTemplate',
    'tts_time_enabled':             'ttsTimeEnabled',
    'tts_time_interval_minutes':    'ttsTimeInterval',
    'tts_time_template':            'ttsTimeTemplate',
  };

  var currentlyPlaying = null;
  var renameTarget = null;

  function init() {
    loadSettings();
    loadStationIds();
    bindEvents();
  }

  // ── Settings ──────────────────────────────────────────

  function loadSettings() {
    RendezVoxAPI.get('/admin/settings').then(function(data) {
      if (!data.settings) return;
      data.settings.forEach(function(s) {
        var elId = settingMap[s.key];
        if (!elId) return;
        var el = document.getElementById(elId);
        if (!el) return;
        if (el.type === 'checkbox') {
          el.checked = s.value === 'true';
        } else if (el.type === 'range') {
          el.value = s.value;
          var valSpan = document.getElementById(elId + 'Val');
          if (valSpan) valSpan.textContent = s.value;
        } else {
          el.value = s.value;
        }
      });
    }).catch(function(err) {
      showToast((err && err.error) || 'Failed to load settings', 'error');
    });
  }

  function saveSetting(key, value) {
    RendezVoxAPI.put('/admin/settings/' + key, { value: String(value) })
    .then(function() {
      showToast('Setting saved');
    })
    .catch(function(err) {
      showToast((err && err.error) || 'Save failed', 'error');
    });
  }

  // ── Event bindings ────────────────────────────────────

  function bindEvents() {
    // Speed range slider
    var speedEl = document.getElementById('ttsSpeed');
    var speedVal = document.getElementById('ttsSpeedVal');
    if (speedEl) {
      speedEl.addEventListener('input', function() {
        if (speedVal) speedVal.textContent = speedEl.value;
      });
      speedEl.addEventListener('change', function() {
        saveSetting('tts_speed', speedEl.value);
      });
    }

    // Voice and interval dropdowns
    ['ttsVoice', 'ttsTimeInterval'].forEach(function(id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('change', function() {
        var key = Object.keys(settingMap).find(function(k) { return settingMap[k] === id; });
        if (key) saveSetting(key, el.value);
      });
    });

    // Toggle switches
    ['ttsSongEnabled', 'ttsTimeEnabled'].forEach(function(id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('change', function() {
        var key = Object.keys(settingMap).find(function(k) { return settingMap[k] === id; });
        if (key) saveSetting(key, el.checked ? 'true' : 'false');
      });
    });

    // Template inputs — save on blur
    ['ttsSongTemplate', 'ttsTimeTemplate'].forEach(function(id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('blur', function() {
        var key = Object.keys(settingMap).find(function(k) { return settingMap[k] === id; });
        if (key) saveSetting(key, el.value);
      });
    });

    // TTS preview + generate
    var btnPreview = document.getElementById('btnPreview');
    if (btnPreview) btnPreview.addEventListener('click', previewTts);

    var btnGen = document.getElementById('btnGenerateStationId');
    if (btnGen) btnGen.addEventListener('click', generateStationId);

    // File upload
    var fileInput = document.getElementById('fileInput');
    var btnUpload = document.getElementById('btnUpload');
    if (btnUpload && fileInput) {
      btnUpload.addEventListener('click', function() { fileInput.click(); });
      fileInput.addEventListener('change', function() {
        if (fileInput.files.length > 0) {
          uploadFiles(fileInput.files);
          fileInput.value = '';
        }
      });
    }

    // Audio element events
    var audio = document.getElementById('stationIdAudio');
    if (audio) {
      audio.addEventListener('ended', stopPlayback);
      audio.addEventListener('error', function() {
        showToast('Failed to play station ID', 'error');
        stopPlayback();
      });
    }

    // Rename modal
    var renameClose = document.getElementById('renameClose');
    var renameCancel = document.getElementById('renameCancel');
    var renameSave = document.getElementById('renameSave');
    var renameInput = document.getElementById('renameInput');
    if (renameClose) renameClose.addEventListener('click', closeRename);
    if (renameCancel) renameCancel.addEventListener('click', closeRename);
    if (renameSave) renameSave.addEventListener('click', submitRename);
    if (renameInput) {
      renameInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); submitRename(); }
        if (e.key === 'Escape') closeRename();
      });
    }
  }

  // ── TTS voice params ──────────────────────────────────

  function getVoiceParams() {
    return {
      voice: (document.getElementById('ttsVoice') || {}).value || 'male',
      speed: parseInt((document.getElementById('ttsSpeed') || {}).value || '160', 10)
    };
  }

  // ── TTS preview ───────────────────────────────────────

  function previewTts() {
    var textEl = document.getElementById('ttsStationText');
    var text = (textEl ? textEl.value : '').trim();
    if (!text) {
      showToast('Enter some text to preview', 'error');
      return;
    }

    var params = getVoiceParams();
    var btn = document.getElementById('btnPreview');
    if (btn) btn.disabled = true;

    var token = sessionStorage.getItem('rendezvox_token');
    fetch('/api/admin/tts/preview', {
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        text: text,
        voice: params.voice,
        speed: params.speed
      })
    })
    .then(function(res) {
      if (!res.ok) throw new Error('TTS generation failed');
      return res.blob();
    })
    .then(function(blob) {
      var audio = document.getElementById('ttsPreviewAudio');
      if (!audio) return;
      if (audio._blobUrl) URL.revokeObjectURL(audio._blobUrl);
      var url = URL.createObjectURL(blob);
      audio._blobUrl = url;
      audio.src = url;
      audio.play();
    })
    .catch(function(err) {
      showToast(err.message || 'Preview failed', 'error');
    })
    .finally(function() {
      if (btn) btn.disabled = false;
    });
  }

  // ── Generate station ID from TTS ──────────────────────

  function generateStationId() {
    var textEl = document.getElementById('ttsStationText');
    var text = (textEl ? textEl.value : '').trim();
    if (!text) {
      showToast('Enter text for the station ID', 'error');
      return;
    }

    var params = getVoiceParams();
    var btn = document.getElementById('btnGenerateStationId');
    if (btn) btn.disabled = true;

    RendezVoxAPI.post('/admin/tts/station-id', {
      text: text,
      voice: params.voice,
      speed: params.speed
    })
    .then(function(data) {
      showToast('Station ID saved: ' + data.filename);
      if (textEl) textEl.value = '';
      loadStationIds();
    })
    .catch(function(err) {
      showToast((err && err.error) || 'Generation failed', 'error');
    })
    .finally(function() {
      if (btn) btn.disabled = false;
    });
  }

  // ── Station ID list ───────────────────────────────────

  function loadStationIds() {
    RendezVoxAPI.get('/admin/station-ids').then(function(data) {
      renderTable(data.station_ids);
      renderSummary(data.station_ids);
    }).catch(function() {
      showToast('Failed to load station IDs', 'error');
    });
  }

  function renderSummary(files) {
    var el = document.getElementById('stationIdSummary');
    if (!el) return;
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
    if (!tbody) return;
    if (!files || files.length === 0) {
      tbody.innerHTML = '<tr><td colspan="5" class="empty">No station IDs yet</td></tr>';
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
          '<button type="button" class="btn-play' + playClass + '" title="' + (isPlaying ? 'Stop' : 'Play') + '" onclick="RendezVoxTts.togglePlay(\'' + escAttr(j.filename) + '\')">' + playIcon + '</button> ' +
          '<button type="button" class="icon-btn" title="Rename" onclick="RendezVoxTts.renameFile(\'' + escAttr(j.filename) + '\')">' + RendezVoxIcons.edit + '</button> ' +
          '<button type="button" class="icon-btn danger" title="Delete" onclick="RendezVoxTts.deleteFile(\'' + escAttr(j.filename) + '\')">' + RendezVoxIcons.del + '</button>' +
        '</td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
  }

  // ── Audio playback ────────────────────────────────────

  function togglePlay(filename) {
    if (currentlyPlaying === filename) {
      stopPlayback();
    } else {
      startPlayback(filename);
    }
  }

  function startPlayback(filename) {
    var audio = document.getElementById('stationIdAudio');
    if (!audio) return;
    if (currentlyPlaying) {
      audio.pause();
      if (audio.src && audio.src.startsWith('blob:')) {
        URL.revokeObjectURL(audio.src);
      }
    }
    currentlyPlaying = filename;
    refreshPlayButtons();

    fetch('/api/admin/station-ids/' + encodeURIComponent(filename) + '/stream', {
      headers: { 'Authorization': 'Bearer ' + sessionStorage.getItem('rendezvox_token') }
    })
    .then(function(res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.blob();
    })
    .then(function(blob) {
      if (currentlyPlaying !== filename) return;
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
    if (!audio) return;
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
      var fn = btn.getAttribute('onclick');
      if (!fn) return;
      var m = fn.match(/togglePlay\('(.+?)'\)/);
      if (!m) return;
      var filename = m[1].replace(/\\'/g, "'");
      var isPlaying = currentlyPlaying === filename;
      btn.className = 'btn-play' + (isPlaying ? ' playing' : '');
      btn.title = isPlaying ? 'Stop' : 'Play';
      btn.innerHTML = isPlaying
        ? '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" stroke="none"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>'
        : '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" stroke="none"><polygon points="5,3 19,12 5,21"/></svg>';
    });
  }

  // ── File upload ───────────────────────────────────────

  function uploadFiles(files) {
    var totalFiles = files.length;
    if (totalFiles === 1) {
      uploadSingle(files[0]);
      return;
    }

    var PARALLEL = 4;
    var finished = 0;
    var errors = 0;
    var fileArr = Array.from(files);
    var nextIndex = 0;

    function onFileComplete() {
      finished++;
      if (finished >= totalFiles) {
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
      var formData = new FormData();
      formData.append('file', fileArr[idx]);
      RendezVoxAPI.upload('/admin/station-ids', formData, function() {})
        .then(function() { onFileComplete(); })
        .catch(function(err) {
          errors++;
          showToast((err && err.error) || 'Upload failed: ' + fileArr[idx].name, 'error');
          onFileComplete();
        });
    }

    for (var p = 0; p < Math.min(PARALLEL, totalFiles); p++) {
      startNext();
    }
  }

  function uploadSingle(file) {
    var formData = new FormData();
    formData.append('file', file);
    var btn = document.getElementById('btnUpload');
    if (btn) { btn.disabled = true; btn.textContent = 'Uploading...'; }

    RendezVoxAPI.upload('/admin/station-ids', formData)
      .then(function() {
        showToast('Station ID uploaded');
        loadStationIds();
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Upload failed', 'error');
      })
      .finally(function() {
        if (btn) { btn.disabled = false; btn.textContent = 'Upload Audio File'; }
      });
  }

  // ── Rename modal ──────────────────────────────────────

  function renameFile(filename) {
    renameTarget = filename;
    var input = document.getElementById('renameInput');
    if (!input) return;
    input.value = filename;
    document.getElementById('renameModal').classList.remove('hidden');
    var dot = filename.lastIndexOf('.');
    input.focus();
    if (dot > 0) {
      input.setSelectionRange(0, dot);
    } else {
      input.select();
    }
  }

  function closeRename() {
    var modal = document.getElementById('renameModal');
    if (modal) modal.classList.add('hidden');
    renameTarget = null;
  }

  function submitRename() {
    if (!renameTarget) return;
    var newName = document.getElementById('renameInput').value.trim();
    if (newName === '' || newName === renameTarget) { closeRename(); return; }
    var filename = renameTarget;

    var btn = document.getElementById('renameSave');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }

    RendezVoxAPI.put('/admin/station-ids/' + encodeURIComponent(filename) + '/rename', { filename: newName })
      .then(function(data) {
        showToast('Renamed to ' + data.filename);
        if (currentlyPlaying === filename) currentlyPlaying = data.filename;
        closeRename();
        loadStationIds();
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Rename failed', 'error');
      })
      .finally(function() {
        if (btn) { btn.disabled = false; btn.textContent = 'Save'; }
      });
  }

  // ── Delete ────────────────────────────────────────────

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

  // ── Placeholder insertion ─────────────────────────────

  function insertPlaceholder(inputId, placeholder) {
    var el = document.getElementById(inputId);
    if (!el) return;
    var start = el.selectionStart;
    var end = el.selectionEnd;
    var val = el.value;
    el.value = val.substring(0, start) + placeholder + val.substring(end);
    el.selectionStart = el.selectionEnd = start + placeholder.length;
    el.focus();
  }

  // ── Helpers ───────────────────────────────────────────

  function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
  }

  return {
    init: init,
    insertPlaceholder: insertPlaceholder,
    togglePlay: togglePlay,
    renameFile: renameFile,
    deleteFile: deleteFile
  };
})();
