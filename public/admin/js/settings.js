/* ============================================================
   iRadio Admin — Settings
   ============================================================ */
var iRadioSettings = (function() {

  var groups = {
    'Station':   ['station_name'],
    'Playback':  ['artist_repeat_block', 'crossfade_ms'],
    'Requests':  ['request_expiry_minutes', 'request_rate_limit_seconds', 'request_auto_approve', 'profanity_filter_enabled'],
  };

  var defaults = {
    'station_name':               'iRadio',
    'artist_repeat_block':        '6',
    'crossfade_ms':               '3000',
    'request_expiry_minutes':     '120',
    'request_rate_limit_seconds': '60',
    'request_auto_approve':       'false',
    'profanity_filter_enabled':   'true',
    'weather_latitude':           '18.2644',
    'weather_longitude':          '121.9910',
    'normalize_target_lufs':      '-14.0',
  };

  var settings = {};
  var dirty = false;

  function init() {
    iRadioAPI.get('/admin/settings').then(function(result) {
      result.settings.forEach(function(s) { settings[s.key] = s; });
      render();
      loadTimezoneDisplay();
      loadApiKey();
      loadSmtp();
      loadAutoTag();
      loadBlockedWords();
      checkInitialScanStatus();
      checkInitialSyncStatus();
      checkInitialDedupStatus();
      checkInitialNormStatus();
      loadNormTarget();
      loadAutoNorm();
      initLocationPicker();
    });
  }

  function loadTimezoneDisplay() {
    iRadioAPI.getTimezone().then(function(tz) {
      var el = document.getElementById('stationTimezone');
      if (el) el.textContent = tz.replace(/_/g, ' ');
    });
  }

  // ── Blocked Words (Content Filter) ──────────────────
  function loadBlockedWords() {
    var s = settings['profanity_custom_words'];
    var el = document.getElementById('blockedWords');
    if (s && el) el.value = s.value || '';
  }

  function saveBlockedWords() {
    var el = document.getElementById('blockedWords');
    var val = el ? el.value.trim() : '';
    var btn = document.getElementById('btnSaveBlockedWords');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    iRadioAPI.put('/admin/settings/profanity_custom_words', { value: val })
      .then(function() {
        if (settings['profanity_custom_words']) settings['profanity_custom_words'].value = val;
        showToast('Custom blocked words saved');
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Save failed', 'error');
      })
      .then(function() {
        btn.disabled = false;
        btn.textContent = 'Save Blocked Words';
      });
  }

  function loadAutoTag() {
    var s = settings['auto_tag_enabled'];
    var el = document.getElementById('autoTagEnabled');
    if (s && el) el.checked = (s.value === 'true');
    if (el) el.addEventListener('change', saveAutoTag);
    loadAutoTagStatus();
  }

  function loadAutoTagStatus() {
    iRadioAPI.get('/admin/auto-tag-status').then(function(data) {
      var el = document.getElementById('autoTagStatus');
      if (!el) return;
      if (!data.has_run) {
        el.innerHTML = '<span style="opacity:.6">No auto-tag runs yet</span>';
      } else {
        var d = new Date(data.ran_at);
        var opts = iRadioAPI.tzOpts();
        var timeStr = d.toLocaleDateString('en-US', Object.assign({ month: 'short', day: 'numeric' }, opts)) + ' ' +
                      d.toLocaleTimeString('en-US', Object.assign({ hour: '2-digit', minute: '2-digit' }, opts));
        el.innerHTML = 'Last run: <strong>' + timeStr + '</strong> — ' +
          data.total + ' songs processed, ' + (data.updated || 0) + ' updated, ' + (data.skipped || 0) + ' skipped';
      }
    }).catch(function() {
      var el = document.getElementById('autoTagStatus');
      if (el) el.innerHTML = '<span style="opacity:.6">Could not load auto-tag status</span>';
    });
  }

  function saveAutoTag() {
    var el = document.getElementById('autoTagEnabled');
    var val = el.checked ? 'true' : 'false';
    iRadioAPI.put('/admin/settings/auto_tag_enabled', { value: val })
      .then(function() {
        if (settings['auto_tag_enabled']) settings['auto_tag_enabled'].value = val;
        showToast('Auto-tag ' + (el.checked ? 'enabled' : 'disabled'));
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Save failed', 'error');
        el.checked = !el.checked;
      });
  }

  function loadAutoNorm() {
    var s = settings['auto_normalize_enabled'];
    var el = document.getElementById('autoNormEnabled');
    if (s && el) el.checked = (s.value === 'true');
    if (el) el.addEventListener('change', saveAutoNorm);
    loadAutoNormStatus();
  }

  function loadAutoNormStatus() {
    iRadioAPI.get('/admin/auto-norm-status').then(function(data) {
      var el = document.getElementById('autoNormStatus');
      if (!el) return;
      if (!data.has_run) {
        el.innerHTML = '<span style="opacity:.6">No auto-normalize runs yet</span>';
      } else {
        var d = new Date(data.ran_at);
        var opts = iRadioAPI.tzOpts();
        var timeStr = d.toLocaleDateString('en-US', Object.assign({ month: 'short', day: 'numeric' }, opts)) + ' ' +
                      d.toLocaleTimeString('en-US', Object.assign({ hour: '2-digit', minute: '2-digit' }, opts));
        el.innerHTML = 'Last run: <strong>' + timeStr + '</strong> — ' +
          data.total + ' songs processed, ' + (data.normalized || 0) + ' normalized, ' + (data.skipped || 0) + ' skipped';
      }
    }).catch(function() {
      var el = document.getElementById('autoNormStatus');
      if (el) el.innerHTML = '<span style="opacity:.6">Could not load auto-normalize status</span>';
    });
  }

  function saveAutoNorm() {
    var el = document.getElementById('autoNormEnabled');
    var val = el.checked ? 'true' : 'false';
    iRadioAPI.put('/admin/settings/auto_normalize_enabled', { value: val })
      .then(function() {
        if (settings['auto_normalize_enabled']) settings['auto_normalize_enabled'].value = val;
        showToast('Auto-normalize ' + (el.checked ? 'enabled' : 'disabled'));
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Save failed', 'error');
        el.checked = !el.checked;
      });
  }

  function loadApiKey() {
    var s = settings['acoustid_api_key'];
    var el = document.getElementById('acoustidApiKey');
    if (s && el) el.value = s.value || '';
  }

  function saveApiKey() {
    var el = document.getElementById('acoustidApiKey');
    var val = el ? el.value.trim() : '';
    var btn = document.getElementById('btnSaveApiKey');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    iRadioAPI.put('/admin/settings/acoustid_api_key', { value: val })
      .then(function() {
        if (settings['acoustid_api_key']) settings['acoustid_api_key'].value = val;
        showToast('API key saved');
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Save failed', 'error');
      })
      .then(function() {
        btn.disabled = false;
        btn.textContent = 'Save Key';
      });
  }

  // ── SMTP Settings ──────────────────────────────────
  var smtpKeys = ['smtp_host','smtp_port','smtp_username','smtp_password','smtp_encryption','smtp_from_address','smtp_from_name'];
  var smtpFields = {
    'smtp_host':         'smtpHost',
    'smtp_port':         'smtpPort',
    'smtp_username':     'smtpUsername',
    'smtp_password':     'smtpPassword',
    'smtp_encryption':   'smtpEncryption',
    'smtp_from_address': 'smtpFromAddress',
    'smtp_from_name':    'smtpFromName'
  };

  function loadSmtp() {
    smtpKeys.forEach(function(key) {
      var s  = settings[key];
      var el = document.getElementById(smtpFields[key]);
      if (s && el) el.value = s.value || '';
    });
  }

  function saveSmtp() {
    var promises = [];
    smtpKeys.forEach(function(key) {
      var el  = document.getElementById(smtpFields[key]);
      var val = el ? el.value.trim() : '';
      promises.push(
        iRadioAPI.put('/admin/settings/' + encodeURIComponent(key), { value: val })
          .then(function() { if (settings[key]) settings[key].value = val; })
      );
    });

    Promise.all(promises)
      .then(function() { showToast('SMTP settings saved'); })
      .catch(function(err) { showToast((err && err.error) || 'Save failed', 'error'); });
  }

  function sendTestEmail() {
    var statusEl = document.getElementById('smtpTestStatus');
    statusEl.textContent = 'Sending test email…';
    statusEl.style.color = 'var(--text-dim)';

    iRadioAPI.post('/admin/test-email', {})
      .then(function(data) {
        statusEl.textContent = data.message || 'Test email sent!';
        statusEl.style.color = 'var(--success)';
      })
      .catch(function(err) {
        statusEl.textContent = (err && err.error) || 'Failed to send test email';
        statusEl.style.color = 'var(--danger)';
      });
  }

  function render() {
    var container = document.getElementById('settingsContainer');
    var html = '<div class="card" style="padding:24px">';

    var groupNames = Object.keys(groups);
    groupNames.forEach(function(groupName, gi) {
      var keys = groups[groupName];

      html += '<h3 class="settings-section-title">' + groupName + '</h3>';

      keys.forEach(function(key) {
        var s = settings[key];
        if (!s) return;

        html += '<div class="settings-field">';
        html += '<label>' + escHtml(s.description || key) + '</label>';
        html += renderInput(key, s);
        html += '</div>';
      });

      // Show auto-detected timezone in Station section
      if (groupName === 'Station') {
        html += '<div class="settings-field">';
        html += '<label>Station timezone</label>';
        html += '<span id="stationTimezone" style="font-size:.9rem;color:var(--text-dim)">Detecting…</span>';
        html += '<p style="font-size:.72rem;color:var(--text-dim);margin:4px 0 0">Auto-detected from server system clock</p>';
        html += '</div>';
      }

      if (gi < groupNames.length - 1) {
        html += '<hr class="settings-divider">';
      }
    });

    html += '<hr class="settings-divider">';
    html += '<div style="display:flex;justify-content:flex-end">';
    html += '<button class="btn btn-ghost btn-sm" id="btnResetDefaults" style="color:var(--danger)">Reset to Defaults</button>';
    html += '</div>';

    html += '</div>';
    container.innerHTML = html;

    document.getElementById('btnResetDefaults').addEventListener('click', resetToDefaults);

    // Listen for changes to show save bar
    container.addEventListener('input', onFieldChange);
    container.addEventListener('change', onFieldChange);
  }

  function renderInput(key, s) {
    var val = s.value;

    // Boolean → toggle
    if (s.type === 'boolean') {
      var checked = (val === 'true') ? ' checked' : '';
      return '<label class="toggle">' +
        '<input type="checkbox" id="setting_' + key + '"' + checked + ' data-key="' + key + '">' +
        '<span class="slider"></span>' +
        '</label>';
    }

    // Integer → number input
    if (s.type === 'integer') {
      return '<input type="number" id="setting_' + key + '" value="' + escAttr(val) + '" data-key="' + key + '" style="margin-bottom:0;max-width:200px">';
    }

    // String → text input
    return '<input type="text" id="setting_' + key + '" value="' + escAttr(val) + '" data-key="' + key + '" style="margin-bottom:0">';
  }

  // ── Dirty tracking ────────────────────────────────────
  function onFieldChange() {
    var hasChanges = false;
    Object.keys(groups).forEach(function(groupName) {
      groups[groupName].forEach(function(key) {
        var s = settings[key];
        if (!s) return;
        var el = document.getElementById('setting_' + key);
        if (!el) return;
        var newVal = s.type === 'boolean' ? (el.checked ? 'true' : 'false') : el.value;
        if (newVal !== s.value) hasChanges = true;
      });
    });
    dirty = hasChanges;
    var bar = document.getElementById('saveBar');
    if (hasChanges) {
      bar.classList.remove('hidden');
    } else {
      bar.classList.add('hidden');
    }
  }

  // ── Save all changed settings ─────────────────────────
  function saveAll() {
    var promises = [];
    var changedGroups = {};

    Object.keys(groups).forEach(function(groupName) {
      groups[groupName].forEach(function(key) {
        var s = settings[key];
        if (!s) return;
        var el = document.getElementById('setting_' + key);
        if (!el) return;

        var newVal = s.type === 'boolean' ? (el.checked ? 'true' : 'false') : el.value;
        if (newVal !== s.value) {
          changedGroups[groupName] = true;
          promises.push(
            iRadioAPI.put('/admin/settings/' + encodeURIComponent(key), { value: newVal })
              .then(function() { s.value = newVal; })
          );
        }
      });
    });

    if (promises.length === 0) {
      showToast('No changes to save');
      return;
    }

    var btn = document.getElementById('btnSaveAll');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    Promise.all(promises)
      .then(function() {
        showToast('Settings saved');
        dirty = false;
        document.getElementById('saveBar').classList.add('hidden');
        if (changedGroups['Station']) applyBranding();
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Save failed', 'error');
      })
      .then(function() {
        btn.disabled = false;
        btn.textContent = 'Save Changes';
      });
  }

  // ── Cancel / Revert ──────────────────────────────────
  function cancelChanges() {
    Object.keys(groups).forEach(function(groupName) {
      groups[groupName].forEach(function(key) {
        var s = settings[key];
        if (!s) return;
        var el = document.getElementById('setting_' + key);
        if (!el) return;
        if (s.type === 'boolean') {
          el.checked = (s.value === 'true');
        } else {
          el.value = s.value;
        }
      });
    });
    dirty = false;
    document.getElementById('saveBar').classList.add('hidden');
  }

  // ── Reset to Defaults ──────────────────────────────
  function resetToDefaults() {
    if (!confirm('Reset all settings to their default values? This will save immediately.')) return;

    var promises = [];
    var resetKeys = {};
    Object.keys(groups).forEach(function(groupName) {
      groups[groupName].forEach(function(key) { resetKeys[key] = true; });
    });
    // Include standalone defaults not in groups (e.g. normalize_target_lufs)
    Object.keys(defaults).forEach(function(key) { resetKeys[key] = true; });

    Object.keys(resetKeys).forEach(function(key) {
      var def = defaults[key];
      if (def === undefined) return;
      promises.push(
        iRadioAPI.put('/admin/settings/' + encodeURIComponent(key), { value: def })
          .then(function() {
            if (settings[key]) settings[key].value = def;
          })
      );
    });

    Promise.all(promises)
      .then(function() {
        showToast('Settings reset to defaults');
        dirty = false;
        document.getElementById('saveBar').classList.add('hidden');
        render();
        loadTimezoneDisplay();
        loadNormTarget();
        applyBranding();
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Reset failed', 'error');
      });
  }

  // ── Branding ───────────────────────────────────────
  function applyBranding() {
    var name = (settings['station_name'] && settings['station_name'].value) || 'iRadio';
    window.iRadioStationName = name;
    var brand = document.getElementById('brandName');
    if (brand) brand.textContent = name + ' Admin';
    document.title = name + ' Admin — Settings';
  }

  // ── Genre Scan ──────────────────────────────────────
  var scanPollTimer = null;

  function setScanButtons(scanning) {
    var btnStart = document.getElementById('btnGenreScan');
    var btnStop  = document.getElementById('btnStopScan');
    if (scanning) {
      btnStart.disabled = true;
      btnStart.textContent = 'Scanning…';
      btnStop.classList.remove('hidden');
    } else {
      btnStart.disabled = false;
      btnStart.textContent = 'Scan and Tag';
      btnStop.classList.add('hidden');
    }
  }

  function startGenreScan() {
    setScanButtons(true);

    iRadioAPI.post('/admin/genre-scan', {})
      .then(function(res) {
        var msg = res.message || 'Scan started';
        var isBlocked = msg.indexOf('already running') !== -1;
        showToast(msg, isBlocked ? 'error' : 'success');
        if (res.auto_tag_disabled) {
          var el = document.getElementById('autoTagEnabled');
          if (el) el.checked = false;
          if (settings['auto_tag_enabled']) settings['auto_tag_enabled'].value = 'false';
          showToast('Auto-tag disabled — manual scan takes over', 'success');
        }
        if (res.progress) {
          showScanProgress(res.progress);
          pollScanStatus();
        }
      })
      .catch(function(err) {
        setScanButtons(false);
        showToast((err && err.error) || 'Failed to start scan', 'error');
      });
  }

  function stopGenreScan() {
    iRadioAPI.del('/admin/genre-scan')
      .then(function(res) {
        showToast(res.message || 'Stopping scan…');
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Failed to stop scan', 'error');
      });
  }

  function pollScanStatus() {
    if (scanPollTimer) clearInterval(scanPollTimer);
    scanPollTimer = setInterval(function() {
      iRadioAPI.get('/admin/genre-scan')
        .then(function(data) {
          showScanProgress(data);
          if (data.status !== 'running') {
            clearInterval(scanPollTimer);
            scanPollTimer = null;
            setScanButtons(false);

            if (data.status === 'done') {
              showToast('Scan complete — ' + (data.updated || 0) + ' songs updated');
            } else if (data.status === 'stopped') {
              showToast('Scan stopped — ' + (data.updated || 0) + ' songs updated so far');
            }
          }
        });
    }, 2000);
  }

  function showScanProgress(p) {
    var wrap = document.getElementById('genreScanStatus');
    if (!p || p.status === 'idle') {
      wrap.style.display = 'none';
      return;
    }
    wrap.style.display = 'block';

    var total = p.total || 1;
    var processed = p.processed || 0;
    var pct = Math.round((processed / total) * 100);

    var label = 'Scanning songs…';
    if (p.status === 'done') label = 'Scan complete';
    else if (p.status === 'stopped') label = 'Scan stopped';

    document.getElementById('genreScanLabel').textContent = label;
    document.getElementById('genreScanPct').textContent = pct + '%';
    document.getElementById('genreScanBar').style.width = pct + '%';
    document.getElementById('genreScanDetails').textContent =
      processed + ' / ' + total + ' songs — ' +
      (p.updated || 0) + ' updated, ' + (p.skipped || 0) + ' skipped';
  }

  function checkInitialScanStatus() {
    iRadioAPI.get('/admin/genre-scan')
      .then(function(data) {
        if (data.status === 'running') {
          showScanProgress(data);
          setScanButtons(true);
          pollScanStatus();
        } else if (data.status === 'done' || data.status === 'stopped') {
          showScanProgress(data);
        }
      })
      .catch(function() { /* ignore */ });
  }

  // ── Library Sync ───────────────────────────────────
  var syncPollTimer = null;

  function setSyncButtons(syncing) {
    var btnStart = document.getElementById('btnLibrarySync');
    var btnStop  = document.getElementById('btnStopSync');
    if (syncing) {
      btnStart.disabled = true;
      btnStart.textContent = 'Syncing…';
      btnStop.classList.remove('hidden');
    } else {
      btnStart.disabled = false;
      btnStart.textContent = 'Sync Library';
      btnStop.classList.add('hidden');
    }
  }

  function startLibrarySync() {
    setSyncButtons(true);

    iRadioAPI.post('/admin/library-sync', {})
      .then(function(res) {
        var msg = res.message || 'Sync started';
        var isBlocked = msg.indexOf('already running') !== -1;
        showToast(msg, isBlocked ? 'error' : 'success');
        if (res.progress) {
          showSyncProgress(res.progress);
          pollSyncStatus();
        }
      })
      .catch(function(err) {
        setSyncButtons(false);
        showToast((err && err.error) || 'Failed to start sync', 'error');
      });
  }

  function stopLibrarySync() {
    iRadioAPI.del('/admin/library-sync')
      .then(function(res) {
        showToast(res.message || 'Stopping sync…');
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Failed to stop sync', 'error');
      });
  }

  function pollSyncStatus() {
    if (syncPollTimer) clearInterval(syncPollTimer);
    syncPollTimer = setInterval(function() {
      iRadioAPI.get('/admin/library-sync')
        .then(function(data) {
          showSyncProgress(data);
          if (data.status !== 'running') {
            clearInterval(syncPollTimer);
            syncPollTimer = null;
            setSyncButtons(false);

            if (data.status === 'done') {
              showToast('Sync complete — ' + (data.missing || 0) + ' missing, ' + (data.deactivated || 0) + ' deactivated');
            } else if (data.status === 'stopped') {
              showToast('Sync stopped — ' + (data.deactivated || 0) + ' deactivated so far');
            }
          }
        });
    }, 2000);
  }

  function showSyncProgress(p) {
    var wrap = document.getElementById('librarySyncStatus');
    if (!p || p.status === 'idle') {
      wrap.style.display = 'none';
      return;
    }
    wrap.style.display = 'block';

    var total = p.total || 1;
    var processed = p.processed || 0;
    var pct = Math.round((processed / total) * 100);

    var label = 'Checking files…';
    if (p.status === 'done') label = 'Sync complete';
    else if (p.status === 'stopped') label = 'Sync stopped';

    document.getElementById('librarySyncLabel').textContent = label;
    document.getElementById('librarySyncPct').textContent = pct + '%';
    document.getElementById('librarySyncBar').style.width = pct + '%';
    document.getElementById('librarySyncDetails').textContent =
      processed + ' / ' + total + ' songs — ' +
      (p.missing || 0) + ' missing, ' + (p.deactivated || 0) + ' deactivated';
  }

  function checkInitialSyncStatus() {
    iRadioAPI.get('/admin/library-sync')
      .then(function(data) {
        if (data.status === 'running') {
          showSyncProgress(data);
          setSyncButtons(true);
          pollSyncStatus();
        } else if (data.status === 'done' || data.status === 'stopped') {
          showSyncProgress(data);
        }
      })
      .catch(function() { /* ignore */ });
  }

  // ── Artist Dedup ───────────────────────────────────
  var dedupPollTimer = null;

  function setDedupButtons(running) {
    var btnStart = document.getElementById('btnArtistDedup');
    var btnStop  = document.getElementById('btnStopDedup');
    if (running) {
      btnStart.disabled = true;
      btnStart.textContent = 'Deduplicating…';
      btnStop.classList.remove('hidden');
    } else {
      btnStart.disabled = false;
      btnStart.textContent = 'Deduplicate Artists';
      btnStop.classList.add('hidden');
    }
  }

  function startArtistDedup() {
    setDedupButtons(true);

    iRadioAPI.post('/admin/artist-dedup', {})
      .then(function(res) {
        var msg = res.message || 'Dedup started';
        var isBlocked = msg.indexOf('already running') !== -1;
        showToast(msg, isBlocked ? 'error' : 'success');
        if (res.progress) {
          showDedupProgress(res.progress);
          pollDedupStatus();
        }
      })
      .catch(function(err) {
        setDedupButtons(false);
        showToast((err && err.error) || 'Failed to start dedup', 'error');
      });
  }

  function stopArtistDedup() {
    iRadioAPI.del('/admin/artist-dedup')
      .then(function(res) {
        showToast(res.message || 'Stopping dedup…');
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Failed to stop dedup', 'error');
      });
  }

  function pollDedupStatus() {
    if (dedupPollTimer) clearInterval(dedupPollTimer);
    dedupPollTimer = setInterval(function() {
      iRadioAPI.get('/admin/artist-dedup')
        .then(function(data) {
          showDedupProgress(data);
          if (data.status !== 'running') {
            clearInterval(dedupPollTimer);
            dedupPollTimer = null;
            setDedupButtons(false);

            if (data.status === 'done') {
              showToast('Dedup complete — ' + (data.merged || 0) + ' merged, ' + (data.renamed || 0) + ' renamed');
            } else if (data.status === 'stopped') {
              showToast('Dedup stopped — ' + (data.merged || 0) + ' merged so far');
            }
          }
        });
    }, 2000);
  }

  function showDedupProgress(p) {
    var wrap = document.getElementById('artistDedupStatus');
    if (!p || p.status === 'idle') {
      wrap.style.display = 'none';
      return;
    }
    wrap.style.display = 'block';

    var total = p.total || 1;
    var processed = p.processed || 0;
    var pct = Math.round((processed / total) * 100);

    var label = 'Deduplicating artists…';
    if (p.status === 'done') label = 'Dedup complete';
    else if (p.status === 'stopped') label = 'Dedup stopped';

    document.getElementById('artistDedupLabel').textContent = label;
    document.getElementById('artistDedupPct').textContent = pct + '%';
    document.getElementById('artistDedupBar').style.width = pct + '%';
    document.getElementById('artistDedupDetails').textContent =
      processed + ' / ' + total + ' artists — ' +
      (p.merged || 0) + ' merged, ' + (p.renamed || 0) + ' renamed';
  }

  function checkInitialDedupStatus() {
    iRadioAPI.get('/admin/artist-dedup')
      .then(function(data) {
        if (data.status === 'running') {
          showDedupProgress(data);
          setDedupButtons(true);
          pollDedupStatus();
        } else if (data.status === 'done' || data.status === 'stopped') {
          showDedupProgress(data);
        }
      })
      .catch(function() { /* ignore */ });
  }

  // ── Audio Normalization ────────────────────────────

  function loadNormTarget() {
    var s = settings['normalize_target_lufs'];
    var el = document.getElementById('normTargetLufs');
    if (s && el) el.value = s.value || '-14.0';
    if (el) el.addEventListener('change', saveNormTarget);
  }

  function saveNormTarget() {
    var el = document.getElementById('normTargetLufs');
    var val = el ? el.value : '-14.0';
    iRadioAPI.put('/admin/settings/normalize_target_lufs', { value: val })
      .then(function() {
        if (settings['normalize_target_lufs']) settings['normalize_target_lufs'].value = val;
        showToast('Target LUFS saved');
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Save failed', 'error');
      });
  }

  var normPollTimer = null;

  function setNormButtons(running) {
    var btnStart = document.getElementById('btnNormalize');
    var btnStop  = document.getElementById('btnStopNorm');
    if (running) {
      btnStart.disabled = true;
      btnStart.textContent = 'Normalizing…';
      btnStop.classList.remove('hidden');
    } else {
      btnStart.disabled = false;
      btnStart.textContent = 'Normalize';
      btnStop.classList.add('hidden');
    }
  }

  function startNormalize() {
    setNormButtons(true);

    iRadioAPI.post('/admin/normalize', {})
      .then(function(res) {
        var msg = res.message || 'Normalization started';
        var isBlocked = msg.indexOf('already running') !== -1;
        showToast(msg, isBlocked ? 'error' : 'success');
        if (res.auto_normalize_disabled) {
          var el = document.getElementById('autoNormEnabled');
          if (el) el.checked = false;
          if (settings['auto_normalize_enabled']) settings['auto_normalize_enabled'].value = 'false';
          showToast('Auto-normalize disabled — manual scan takes over', 'success');
        }
        if (res.progress) {
          showNormProgress(res.progress);
          pollNormStatus();
        }
      })
      .catch(function(err) {
        setNormButtons(false);
        showToast((err && err.error) || 'Failed to start normalization', 'error');
      });
  }

  function stopNormalize() {
    iRadioAPI.del('/admin/normalize')
      .then(function(res) {
        showToast(res.message || 'Stopping normalization…');
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Failed to stop normalization', 'error');
      });
  }

  function pollNormStatus() {
    if (normPollTimer) clearInterval(normPollTimer);
    normPollTimer = setInterval(function() {
      iRadioAPI.get('/admin/normalize')
        .then(function(data) {
          showNormProgress(data);
          if (data.status !== 'running') {
            clearInterval(normPollTimer);
            normPollTimer = null;
            setNormButtons(false);

            if (data.status === 'done') {
              showToast('Normalization complete — ' + (data.normalized || 0) + ' songs normalized');
            } else if (data.status === 'stopped') {
              showToast('Normalization stopped — ' + (data.normalized || 0) + ' songs normalized so far');
            }
          }
        });
    }, 2000);
  }

  function showNormProgress(p) {
    var wrap = document.getElementById('normStatus');
    if (!p || p.status === 'idle') {
      wrap.style.display = 'none';
      return;
    }
    wrap.style.display = 'block';

    var total = p.total || 1;
    var processed = p.processed || 0;
    var pct = Math.round((processed / total) * 100);

    var label = 'Analyzing loudness…';
    if (p.status === 'done') label = 'Normalization complete';
    else if (p.status === 'stopped') label = 'Normalization stopped';

    document.getElementById('normLabel').textContent = label;
    document.getElementById('normPct').textContent = pct + '%';
    document.getElementById('normBar').style.width = pct + '%';
    document.getElementById('normDetails').textContent =
      processed + ' / ' + total + ' songs — ' +
      (p.normalized || 0) + ' normalized, ' + (p.skipped || 0) + ' skipped, ' + (p.failed || 0) + ' failed';
  }

  function checkInitialNormStatus() {
    iRadioAPI.get('/admin/normalize')
      .then(function(data) {
        if (data.status === 'running') {
          showNormProgress(data);
          setNormButtons(true);
          pollNormStatus();
        } else if (data.status === 'done' || data.status === 'stopped') {
          showNormProgress(data);
        }
      })
      .catch(function() { /* ignore */ });
  }

  // ── Location Picker ─────────────────────────────────
  var locPickerCoords = null; // [lat, lon] resolved for current selection

  function initLocationPicker() {
    var container = document.getElementById('settingsContainer');
    if (!container) return;

    // Find the first card (the settings card) and inject after the Station section
    var card = container.querySelector('.card');
    if (!card) return;

    // Find the Station section divider (first <hr> in the card)
    var dividers = card.querySelectorAll('hr.settings-divider');
    var stationDivider = dividers.length > 0 ? dividers[0] : null;

    // Build location picker HTML
    var html = '<div class="loc-picker" id="locPicker">';
    html += '<h3 class="loc-picker-title">Weather Location</h3>';
    html += '<p class="loc-picker-desc">Select your station location for weather reports.</p>';
    html += '<div class="loc-picker-fields">';

    // Province
    html += '<div class="loc-picker-field">';
    html += '<label>Province</label>';
    html += '<select id="locProvince"><option value="">Loading…</option></select>';
    html += '</div>';

    // City
    html += '<div class="loc-picker-field">';
    html += '<label>City / Municipality</label>';
    html += '<select id="locCity" disabled><option value="">Select province first</option></select>';
    html += '</div>';

    // Barangay
    html += '<div class="loc-picker-field">';
    html += '<label>Barangay</label>';
    html += '<select id="locBarangay" disabled><option value="">Select city first</option></select>';
    html += '</div>';

    html += '</div>'; // .loc-picker-fields

    html += '<div class="loc-picker-coords" id="locCoords"></div>';
    html += '<div class="loc-picker-status dim" id="locStatus"></div>';

    html += '<button class="btn btn-primary btn-sm" id="btnSaveLocation" disabled>Save Location</button>';
    html += '</div>'; // .loc-picker

    // Insert before the first divider (after Station group)
    if (stationDivider) {
      var wrapper = document.createElement('div');
      wrapper.innerHTML = html;
      stationDivider.parentNode.insertBefore(wrapper.firstChild, stationDivider);
    }

    // Wire up events
    document.getElementById('locProvince').addEventListener('change', onProvinceChange);
    document.getElementById('locCity').addEventListener('change', onCityChange);
    document.getElementById('locBarangay').addEventListener('change', onBarangayChange);
    document.getElementById('btnSaveLocation').addEventListener('click', saveLocation);

    // Load provinces
    loadProvinces();
  }

  function loadProvinces() {
    var sel = document.getElementById('locProvince');
    if (!sel) return;

    iRadioAPI.get('/admin/geo/provinces')
      .then(function(data) {
        var html = '<option value="">— Select province —</option>';
        (data.provinces || []).forEach(function(p) {
          html += '<option value="' + escAttr(p) + '">' + escHtml(p) + '</option>';
        });
        sel.innerHTML = html;

        // Restore saved province
        var saved = settings['weather_province'];
        if (saved && saved.value) {
          sel.value = saved.value;
          if (sel.value === saved.value) {
            onProvinceChange();
          }
        }
      })
      .catch(function() {
        sel.innerHTML = '<option value="">Failed to load provinces</option>';
        setLocStatus('Could not load geographic data', 'error');
      });
  }

  function onProvinceChange() {
    var province = document.getElementById('locProvince').value;
    var citySel = document.getElementById('locCity');
    var brgySel = document.getElementById('locBarangay');

    // Reset downstream
    citySel.innerHTML = '<option value="">Loading…</option>';
    citySel.disabled = true;
    brgySel.innerHTML = '<option value="">Select city first</option>';
    brgySel.disabled = true;
    clearLocCoords();
    document.getElementById('btnSaveLocation').disabled = true;

    if (!province) {
      citySel.innerHTML = '<option value="">Select province first</option>';
      return;
    }

    iRadioAPI.get('/admin/geo/cities?province=' + encodeURIComponent(province))
      .then(function(data) {
        var html = '<option value="">— Select city —</option>';
        (data.cities || []).forEach(function(c) {
          html += '<option value="' + escAttr(c.name) + '" data-lat="' + c.coords[0] + '" data-lon="' + c.coords[1] + '">' + escHtml(c.name) + '</option>';
        });
        citySel.innerHTML = html;
        citySel.disabled = false;

        // Restore saved city
        var saved = settings['weather_city'];
        if (saved && saved.value) {
          citySel.value = saved.value;
          if (citySel.value === saved.value) {
            onCityChange();
          }
        }
      })
      .catch(function() {
        citySel.innerHTML = '<option value="">Failed to load cities</option>';
        setLocStatus('Could not load cities', 'error');
      });
  }

  function onCityChange() {
    var citySel = document.getElementById('locCity');
    var cityVal = citySel.value;
    var brgySel = document.getElementById('locBarangay');
    var province = document.getElementById('locProvince').value;

    // Reset barangay
    brgySel.innerHTML = '<option value="">Loading…</option>';
    brgySel.disabled = true;
    clearLocCoords();
    document.getElementById('btnSaveLocation').disabled = true;

    if (!cityVal) {
      brgySel.innerHTML = '<option value="">Select city first</option>';
      return;
    }

    // Get city-level coords from the option data attributes
    var opt = citySel.options[citySel.selectedIndex];
    var cityLat = parseFloat(opt.getAttribute('data-lat')) || 0;
    var cityLon = parseFloat(opt.getAttribute('data-lon')) || 0;

    iRadioAPI.get('/admin/geo/barangays?province=' + encodeURIComponent(province) + '&city=' + encodeURIComponent(cityVal))
      .then(function(data) {
        var coords = data.coords || [cityLat, cityLon];
        var html = '<option value="">— City center —</option>';
        (data.barangays || []).forEach(function(b) {
          html += '<option value="' + escAttr(b) + '">' + escHtml(b) + '</option>';
        });
        brgySel.innerHTML = html;
        brgySel.disabled = false;

        // Default to city center coords
        locPickerCoords = coords;
        showLocCoords(coords[0], coords[1], cityVal + ', ' + province);
        document.getElementById('btnSaveLocation').disabled = false;

        // Restore saved barangay
        var saved = settings['weather_barangay'];
        if (saved && saved.value) {
          brgySel.value = saved.value;
          if (brgySel.value === saved.value) {
            onBarangayChange();
          }
        }
      })
      .catch(function() {
        brgySel.innerHTML = '<option value="">Failed to load barangays</option>';
        // Fall back to city coords
        if (cityLat && cityLon) {
          locPickerCoords = [cityLat, cityLon];
          showLocCoords(cityLat, cityLon, cityVal + ', ' + province);
          document.getElementById('btnSaveLocation').disabled = false;
        }
        setLocStatus('Could not load barangays', 'warn');
      });
  }

  function onBarangayChange() {
    var brgy = document.getElementById('locBarangay').value;
    var city = document.getElementById('locCity').value;
    var province = document.getElementById('locProvince').value;

    if (!brgy) {
      // "City center" selected — use city coords
      var citySel = document.getElementById('locCity');
      var opt = citySel.options[citySel.selectedIndex];
      var lat = parseFloat(opt.getAttribute('data-lat')) || 0;
      var lon = parseFloat(opt.getAttribute('data-lon')) || 0;
      if (lat && lon) {
        locPickerCoords = [lat, lon];
        showLocCoords(lat, lon, city + ', ' + province);
      }
      document.getElementById('btnSaveLocation').disabled = false;
      return;
    }

    // Geocode the barangay
    var q = brgy + ', ' + city + ', ' + province + ', Philippines';
    setLocStatus('Geocoding…', 'dim');

    iRadioAPI.get('/admin/geo/geocode?q=' + encodeURIComponent(q))
      .then(function(data) {
        if (data.lat && data.lon) {
          locPickerCoords = [data.lat, data.lon];
          showLocCoords(data.lat, data.lon, brgy + ', ' + city);
          setLocStatus('', 'dim');
        } else {
          // Fall back to city coords
          var citySel = document.getElementById('locCity');
          var opt = citySel.options[citySel.selectedIndex];
          var lat = parseFloat(opt.getAttribute('data-lat')) || 0;
          var lon = parseFloat(opt.getAttribute('data-lon')) || 0;
          if (lat && lon) {
            locPickerCoords = [lat, lon];
            showLocCoords(lat, lon, city + ', ' + province);
          }
          setLocStatus('Geocoding unavailable — using city center', 'warn');
        }
        document.getElementById('btnSaveLocation').disabled = false;
      })
      .catch(function() {
        // Fall back to city coords
        var citySel = document.getElementById('locCity');
        var opt = citySel.options[citySel.selectedIndex];
        var lat = parseFloat(opt.getAttribute('data-lat')) || 0;
        var lon = parseFloat(opt.getAttribute('data-lon')) || 0;
        if (lat && lon) {
          locPickerCoords = [lat, lon];
          showLocCoords(lat, lon, city + ', ' + province);
        }
        setLocStatus('Geocoding failed — using city center', 'warn');
        document.getElementById('btnSaveLocation').disabled = false;
      });
  }

  function showLocCoords(lat, lon, label) {
    var el = document.getElementById('locCoords');
    if (!el) return;
    el.innerHTML = '<span class="pin">&#x1F4CD;</span> ' +
      '<span class="coord-text">' + lat.toFixed(4) + ', ' + lon.toFixed(4) + '</span>' +
      (label ? ' <span style="color:var(--text-dim)">—&nbsp; ' + escHtml(label) + '</span>' : '');
  }

  function clearLocCoords() {
    var el = document.getElementById('locCoords');
    if (el) el.innerHTML = '';
    locPickerCoords = null;
  }

  function setLocStatus(msg, cls) {
    var el = document.getElementById('locStatus');
    if (!el) return;
    el.textContent = msg;
    el.className = 'loc-picker-status ' + (cls || 'dim');
  }

  function saveLocation() {
    var province = document.getElementById('locProvince').value;
    var city = document.getElementById('locCity').value;
    var brgy = document.getElementById('locBarangay').value;
    var btn = document.getElementById('btnSaveLocation');

    if (!province || !city) {
      showToast('Select at least a province and city', 'error');
      return;
    }

    if (!locPickerCoords) {
      showToast('No coordinates resolved', 'error');
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Saving…';

    var lat = String(locPickerCoords[0]);
    var lon = String(locPickerCoords[1]);

    Promise.all([
      iRadioAPI.put('/admin/settings/weather_province',  { value: province }),
      iRadioAPI.put('/admin/settings/weather_city',      { value: city }),
      iRadioAPI.put('/admin/settings/weather_barangay',  { value: brgy }),
      iRadioAPI.put('/admin/settings/weather_latitude',  { value: lat }),
      iRadioAPI.put('/admin/settings/weather_longitude', { value: lon }),
    ])
    .then(function() {
      if (settings['weather_province'])  settings['weather_province'].value  = province;
      if (settings['weather_city'])      settings['weather_city'].value      = city;
      if (settings['weather_barangay'])  settings['weather_barangay'].value  = brgy;
      if (settings['weather_latitude'])  settings['weather_latitude'].value  = lat;
      if (settings['weather_longitude']) settings['weather_longitude'].value = lon;
      showToast('Weather location saved');
      setLocStatus('Location saved', 'ok');
    })
    .catch(function(err) {
      showToast((err && err.error) || 'Failed to save location', 'error');
      setLocStatus('Save failed', 'error');
    })
    .then(function() {
      btn.disabled = false;
      btn.textContent = 'Save Location';
    });
  }

  // ── Helpers ──────────────────────────────────────────

  function escHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  function escAttr(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
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
    saveAll: saveAll,
    cancelChanges: cancelChanges,
    saveApiKey: saveApiKey,
    saveSmtp: saveSmtp,
    sendTestEmail: sendTestEmail,
    startGenreScan: startGenreScan,
    stopGenreScan: stopGenreScan,
    startLibrarySync: startLibrarySync,
    stopLibrarySync: stopLibrarySync,
    startArtistDedup: startArtistDedup,
    stopArtistDedup: stopArtistDedup,
    startNormalize: startNormalize,
    stopNormalize: stopNormalize,
    saveBlockedWords: saveBlockedWords
  };
})();
