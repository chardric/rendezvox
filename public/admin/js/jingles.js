/* ============================================================
   iRadio Admin — Jingle Manager
   ============================================================ */
var iRadioJingles = (function() {

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
  }

  function loadJingles() {
    iRadioAPI.get('/admin/jingles').then(function(data) {
      renderTable(data.jingles);
    }).catch(function() {
      showToast('Failed to load jingles', 'error');
    });
  }

  function renderTable(jingles) {
    var tbody = document.getElementById('jingleTable');
    if (!jingles || jingles.length === 0) {
      tbody.innerHTML = '<tr><td colspan="4" class="empty">No jingles uploaded yet</td></tr>';
      return;
    }

    var html = '';
    jingles.forEach(function(j) {
      var size = formatSize(j.size);
      var date = new Date(j.created_at).toLocaleDateString('en-US', Object.assign({ day: 'numeric', month: 'short', year: 'numeric' }, iRadioAPI.tzOpts()));
      html += '<tr>' +
        '<td>' + escHtml(j.filename) + '</td>' +
        '<td class="file-size">' + size + '</td>' +
        '<td>' + date + '</td>' +
        '<td>' +
          '<button class="icon-btn danger" title="Delete" onclick="iRadioJingles.deleteJingle(\'' + escAttr(j.filename) + '\')">' + iRadioIcons.del + '</button>' +
        '</td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
  }

  function uploadFiles(files) {
    var pending = files.length;
    var errors = 0;

    for (var i = 0; i < files.length; i++) {
      var formData = new FormData();
      formData.append('file', files[i]);

      iRadioAPI.upload('/admin/jingles', formData).then(function(data) {
        pending--;
        if (pending === 0) {
          showToast((files.length - errors) + ' jingle(s) uploaded');
          loadJingles();
        }
      }).catch(function(err) {
        errors++;
        pending--;
        showToast((err && err.error) || 'Upload failed', 'error');
        if (pending === 0) {
          loadJingles();
        }
      });
    }
  }

  function deleteJingle(filename) {
    if (!confirm('Delete jingle "' + filename + '"?')) return;

    iRadioAPI.del('/admin/jingles/' + encodeURIComponent(filename)).then(function() {
      showToast('Jingle deleted');
      loadJingles();
    }).catch(function(err) {
      showToast((err && err.error) || 'Delete failed', 'error');
    });
  }

  function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
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
    deleteJingle: deleteJingle
  };
})();
