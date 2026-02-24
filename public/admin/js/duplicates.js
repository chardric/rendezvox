/* ============================================================
   iRadio Admin — Duplicate Detection & Removal
   ============================================================ */
var iRadioDuplicates = (function() {

  var groups = [];

  function init() {
    document.getElementById('btnScan').addEventListener('click', scan);
    document.getElementById('btnDeleteAll').addEventListener('click', resolveAll);
  }

  // ── Scan API ──────────────────────────────────────────────

  function scan() {
    var btn = document.getElementById('btnScan');
    btn.disabled = true;
    btn.textContent = 'Scanning\u2026';
    document.getElementById('btnDeleteAll').style.display = 'none';
    document.getElementById('summary').textContent = '';
    document.getElementById('results').innerHTML = '<p class="text-dim">Scanning library for duplicates\u2026</p>';

    iRadioAPI.get('/admin/duplicates/scan')
      .then(function(data) {
        groups = data.groups || [];
        renderGroups();
        var dupes = data.total_duplicates || 0;
        var gLen = data.total_groups || 0;
        if (gLen === 0) {
          document.getElementById('summary').textContent = 'No duplicates found.';
        } else {
          document.getElementById('summary').textContent =
            gLen + ' group' + (gLen !== 1 ? 's' : '') + ', ' +
            dupes + ' duplicate' + (dupes !== 1 ? 's' : '') + ' found.';
          document.getElementById('btnDeleteAll').style.display = '';
        }
      })
      .catch(function(err) {
        document.getElementById('results').innerHTML = '';
        showToast((err && err.error) || 'Scan failed', 'error');
      })
      .finally(function() {
        btn.disabled = false;
        btn.textContent = 'Scan for Duplicates';
      });
  }

  // ── Render ────────────────────────────────────────────────

  function renderGroups() {
    var container = document.getElementById('results');
    if (groups.length === 0) {
      container.innerHTML = '';
      return;
    }

    var html = '';
    groups.forEach(function(g, idx) {
      var badge = g.type === 'exact'
        ? '<span style="background:var(--danger);color:#fff;padding:2px 8px;border-radius:4px;font-size:0.75rem;margin-left:8px">Exact</span>'
        : '<span style="background:var(--warning, #e6a817);color:#000;padding:2px 8px;border-radius:4px;font-size:0.75rem;margin-left:8px">Likely</span>';

      html += '<div class="card" style="margin-bottom:16px;padding:16px" data-group="' + idx + '">';
      html += '<div class="flex items-center justify-between" style="margin-bottom:12px">';
      html += '<strong>Group ' + (idx + 1) + badge + '</strong>';
      html += '<button type="button" class="btn btn-danger btn-sm" onclick="iRadioDuplicates.resolveGroup(' + idx + ')">Delete Duplicates</button>';
      html += '</div>';

      html += '<table style="width:100%"><thead><tr>';
      html += '<th style="width:40px">Keep</th>';
      html += '<th>Title</th>';
      html += '<th>Artist</th>';
      html += '<th>File</th>';
      html += '<th>Size</th>';
      html += '<th>Duration</th>';
      html += '<th>Plays</th>';
      html += '<th>Added</th>';
      html += '</tr></thead><tbody>';

      g.songs.forEach(function(s) {
        var checked = s.id === g.recommended_keep_id ? ' checked' : '';
        var isBad = s.file_size === 0 || s.file_missing;
        var rowStyle = isBad ? ' style="background:rgba(248,113,113,0.08)"' : '';
        html += '<tr' + rowStyle + '>';
        html += '<td><input type="radio" name="group_' + idx + '" value="' + s.id + '"' + checked + '></td>';
        html += '<td>' + escHtml(s.title) + '</td>';
        html += '<td>' + escHtml(s.artist_name) + '</td>';
        html += '<td style="font-size:0.8rem;word-break:break-all;max-width:240px">' + escHtml(s.file_path) + '</td>';
        html += '<td>' + formatSize(s) + '</td>';
        html += '<td>' + formatDuration(s.duration_ms) + '</td>';
        html += '<td>' + s.play_count + '</td>';
        html += '<td style="font-size:0.8rem">' + (s.created_at ? s.created_at.substring(0, 10) : '') + '</td>';
        html += '</tr>';
      });

      html += '</tbody></table></div>';
    });

    container.innerHTML = html;
  }

  // ── Resolve single group ──────────────────────────────────

  function resolveGroup(idx) {
    var g = groups[idx];
    if (!g) return;

    var keepId = getSelectedKeepId(idx);
    if (keepId === null) {
      showToast('Please select a song to keep', 'error');
      return;
    }

    var deleteIds = [];
    g.songs.forEach(function(s) {
      if (s.id !== keepId) deleteIds.push(s.id);
    });

    if (deleteIds.length === 0) {
      showToast('Nothing to delete', 'error');
      return;
    }

    iRadioConfirm('Delete ' + deleteIds.length + ' duplicate(s) from this group? The selected song will be kept.', {
      title: 'Delete Duplicates',
      okLabel: 'Delete'
    }).then(function(ok) {
      if (!ok) return;

      iRadioAPI.post('/admin/duplicates/resolve', { keep_ids: [keepId], delete_ids: deleteIds })
        .then(function(data) {
          showToast('Deleted ' + data.deleted + ' song(s), freed ' + formatBytes(data.freed_bytes));
          groups.splice(idx, 1);
          renderGroups();
          updateSummary();
        })
        .catch(function(err) {
          showToast((err && err.error) || 'Delete failed', 'error');
        });
    });
  }

  // ── Resolve all groups ────────────────────────────────────

  function resolveAll() {
    if (groups.length === 0) return;

    var keepIds = [];
    var deleteIds = [];

    for (var i = 0; i < groups.length; i++) {
      var keepId = getSelectedKeepId(i);
      if (keepId === null) {
        showToast('Please select a song to keep in Group ' + (i + 1), 'error');
        return;
      }

      keepIds.push(keepId);
      groups[i].songs.forEach(function(s) {
        if (s.id !== keepId) deleteIds.push(s.id);
      });
    }

    if (deleteIds.length === 0) {
      showToast('Nothing to delete', 'error');
      return;
    }

    iRadioConfirm('Delete ' + deleteIds.length + ' duplicate(s) across all groups? One song per group will be kept.', {
      title: 'Delete All Duplicates',
      okLabel: 'Delete All'
    }).then(function(ok) {
      if (!ok) return;

      iRadioAPI.post('/admin/duplicates/resolve', { keep_ids: keepIds, delete_ids: deleteIds })
        .then(function(data) {
          showToast('Deleted ' + data.deleted + ' song(s), freed ' + formatBytes(data.freed_bytes));
          groups = [];
          renderGroups();
          updateSummary();
          document.getElementById('btnDeleteAll').style.display = 'none';
        })
        .catch(function(err) {
          showToast((err && err.error) || 'Delete failed', 'error');
        });
    });
  }

  // ── Helpers ───────────────────────────────────────────────

  function getSelectedKeepId(idx) {
    var radios = document.querySelectorAll('input[name="group_' + idx + '"]');
    for (var i = 0; i < radios.length; i++) {
      if (radios[i].checked) return parseInt(radios[i].value, 10);
    }
    return null;
  }

  function updateSummary() {
    var totalDupes = 0;
    groups.forEach(function(g) { totalDupes += g.songs.length - 1; });
    var gLen = groups.length;
    if (gLen === 0) {
      document.getElementById('summary').textContent = 'No duplicates remaining.';
    } else {
      document.getElementById('summary').textContent =
        gLen + ' group' + (gLen !== 1 ? 's' : '') + ', ' +
        totalDupes + ' duplicate' + (totalDupes !== 1 ? 's' : '') + ' remaining.';
    }
  }

  function formatSize(song) {
    if (song.file_missing) {
      return '<span style="color:var(--danger);font-weight:600">Missing</span>';
    }
    if (song.file_size === 0) {
      return '<span style="color:var(--danger);font-weight:600">0 B</span>';
    }
    return formatBytes(song.file_size);
  }

  function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB'];
    var i = Math.floor(Math.log(bytes) / Math.log(1024));
    if (i >= units.length) i = units.length - 1;
    return (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
  }

  function formatDuration(ms) {
    if (!ms) return '—';
    var totalSec = Math.round(ms / 1000);
    var m = Math.floor(totalSec / 60);
    var s = totalSec % 60;
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
    resolveGroup: resolveGroup,
    resolveAll: resolveAll
  };
})();
