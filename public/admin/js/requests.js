/* ============================================================
   RendezVox Admin — Request Management
   ============================================================ */
var RendezVoxRequests = (function() {

  var escHtml    = RendezVoxUtils.escHtml;
  var showToast  = RendezVoxUtils.showToast;
  var formatTime = RendezVoxUtils.formatTime;

  var currentStatus = 'pending';
  var pollTimer = null;

  function init() {
    RendezVoxAPI.getTimezone(); // pre-load station timezone
    loadRequests();
    pollTimer = setInterval(loadRequests, 15000);

    // Tab clicks
    var tabs = document.querySelectorAll('.tab[data-status]');
    tabs.forEach(function(tab) {
      tab.addEventListener('click', function() {
        tabs.forEach(function(t) { t.classList.remove('active'); });
        this.classList.add('active');
        currentStatus = this.getAttribute('data-status');
        loadRequests();
      });
    });
  }

  function loadRequests() {
    RendezVoxAPI.get('/admin/requests?status=' + currentStatus)
      .then(function(data) {
        renderTable(data.requests);
      })
      .catch(function(err) {
        console.error('Request load error:', err);
      });
  }

  function renderTable(requests) {
    var tbody = document.getElementById('requestTable');

    if (!requests || requests.length === 0) {
      tbody.innerHTML = '<tr><td colspan="9" class="empty">No ' + currentStatus + ' requests</td></tr>';
      return;
    }

    var html = '';
    requests.forEach(function(r, idx) {
      var statusCls = 'badge-' + (r.status === 'pending' ? 'pending' : r.status === 'approved' ? 'active' : r.status === 'played' ? 'request' : 'inactive');
      var time = formatTime(r.created_at);
      var actions = '';

      if (r.status === 'pending') {
        actions =
          '<button type="button" class="icon-btn success" title="Approve" onclick="RendezVoxRequests.approve(' + r.id + ')">' + RendezVoxIcons.approve + '</button> ' +
          '<button type="button" class="icon-btn danger" title="Reject" onclick="RendezVoxRequests.reject(' + r.id + ')">' + RendezVoxIcons.reject + '</button>';
      } else if (r.status === 'approved') {
        actions =
          '<button type="button" class="icon-btn danger" title="Reject" onclick="RendezVoxRequests.reject(' + r.id + ')">' + RendezVoxIcons.reject + '</button>';
      }

      html += '<tr>' +
        '<td>' + (idx + 1) + '</td>' +
        '<td>' + escHtml(r.title) + '</td>' +
        '<td>' + escHtml(r.artist) + '</td>' +
        '<td>' + escHtml(r.listener_name || '—') + '</td>' +
        '<td class="text-dim">' + (r.listener_ip || '') + '</td>' +
        '<td>' + escHtml(r.message || '') + '</td>' +
        '<td><span class="badge ' + statusCls + '">' + r.status + '</span></td>' +
        '<td>' + time + '</td>' +
        '<td>' + actions + '</td>' +
        '</tr>';
    });

    tbody.innerHTML = html;
  }

  function approve(id) {
    RendezVoxAPI.post('/admin/approve-request', { request_id: id })
      .then(function(data) {
        showToast('Request #' + id + ' approved (queue position: ' + data.queue_position + ')');
        loadRequests();
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Approve failed', 'error');
      });
  }

  function reject(id) {
    RendezVoxAPI.post('/admin/reject-request', { request_id: id })
      .then(function() {
        showToast('Request #' + id + ' rejected');
        loadRequests();
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Reject failed', 'error');
      });
  }

  return {
    init: init,
    approve: approve,
    reject: reject
  };
})();
