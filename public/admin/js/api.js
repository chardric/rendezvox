/* ============================================================
   RendezVox Admin — API Helper
   ============================================================ */
var RendezVoxAPI = (function() {
  var BASE = '/api';

  function getToken() {
    return localStorage.getItem('rendezvox_token');
  }

  function headers(extra) {
    var h = { 'Accept': 'application/json' };
    var token = getToken();
    if (token) h['Authorization'] = 'Bearer ' + token;
    if (extra) {
      for (var k in extra) h[k] = extra[k];
    }
    return h;
  }

  function handleResponse(res) {
    if (res.status === 401) {
      localStorage.removeItem('rendezvox_token');
      localStorage.removeItem('rendezvox_user');
      window.location.href = '/admin/';
      return Promise.reject(new Error('Unauthorized'));
    }
    if (res.status === 413) {
      return Promise.reject({ error: 'Upload too large. Try fewer files or smaller files.' });
    }
    return res.json().then(function(data) {
      if (!res.ok) return Promise.reject(data);
      return data;
    }).catch(function(err) {
      if (err && err.error) return Promise.reject(err);
      return Promise.reject({ error: 'Server error (HTTP ' + res.status + ')' });
    });
  }

  function get(path) {
    return fetch(BASE + path, { headers: headers() }).then(handleResponse);
  }

  function post(path, body) {
    return fetch(BASE + path, {
      method: 'POST',
      headers: headers({ 'Content-Type': 'application/json' }),
      body: JSON.stringify(body)
    }).then(handleResponse);
  }

  function put(path, body) {
    return fetch(BASE + path, {
      method: 'PUT',
      headers: headers({ 'Content-Type': 'application/json' }),
      body: JSON.stringify(body)
    }).then(handleResponse);
  }

  function patch(path, body) {
    return fetch(BASE + path, {
      method: 'PATCH',
      headers: headers({ 'Content-Type': 'application/json' }),
      body: JSON.stringify(body)
    }).then(handleResponse);
  }

  function del(path, body) {
    var opts = {
      method: 'DELETE',
      headers: body ? headers({ 'Content-Type': 'application/json' }) : headers()
    };
    if (body) opts.body = JSON.stringify(body);
    return fetch(BASE + path, opts).then(handleResponse);
  }

  function upload(path, formData, onProgress) {
    if (!onProgress) {
      return fetch(BASE + path, {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + getToken() },
        body: formData
      }).then(handleResponse);
    }

    return new Promise(function(resolve, reject) {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', BASE + path);
      xhr.setRequestHeader('Authorization', 'Bearer ' + getToken());
      xhr.setRequestHeader('Accept', 'application/json');

      xhr.upload.addEventListener('progress', function(e) {
        if (e.lengthComputable) {
          onProgress({ loaded: e.loaded, total: e.total, pct: Math.round((e.loaded / e.total) * 100) });
        }
      });

      xhr.addEventListener('load', function() {
        if (xhr.status === 401) {
          localStorage.removeItem('rendezvox_token');
          localStorage.removeItem('rendezvox_user');
          window.location.href = '/admin/';
          return reject(new Error('Unauthorized'));
        }
        if (xhr.status === 413) {
          return reject({ error: 'Upload too large. Try fewer files or smaller files.' });
        }
        try {
          var data = JSON.parse(xhr.responseText);
          if (xhr.status >= 200 && xhr.status < 300) resolve(data);
          else reject(data);
        } catch (e) {
          reject({ error: 'Server error (HTTP ' + xhr.status + ')' });
        }
      });

      xhr.addEventListener('error', function() {
        reject({ error: 'Upload failed — network error' });
      });

      xhr.send(formData);
    });
  }

  // ── Station timezone cache ──────────────────────────
  var _tz = null;
  var _tzLoading = null;

  /**
   * Returns a promise that resolves to the station timezone string (e.g. "Asia/Manila").
   * Auto-detected from the server's system clock. Fetches once and caches.
   */
  function getTimezone() {
    if (_tz) return Promise.resolve(_tz);
    if (_tzLoading) return _tzLoading;
    _tzLoading = get('/config').then(function(cfg) {
      _tz = cfg.station_timezone || 'UTC';
      _tzLoading = null;
      return _tz;
    }).catch(function() {
      _tz = 'UTC';
      _tzLoading = null;
      return _tz;
    });
    return _tzLoading;
  }

  /**
   * Synchronous access to the cached timezone.
   * Returns null if not yet loaded — call getTimezone() first.
   */
  function tz() {
    return _tz;
  }

  /**
   * Returns { timeZone: tz } options object for toLocaleString calls.
   * Returns {} if timezone hasn't been loaded yet.
   */
  function tzOpts() {
    return _tz ? { timeZone: _tz } : {};
  }

  return {
    get: get, post: post, put: put, patch: patch, del: del, upload: upload,
    getTimezone: getTimezone, tz: tz, tzOpts: tzOpts
  };
})();
