/* ============================================================
   RendezVox Admin — Settings
   ============================================================ */
var RendezVoxSettings = (function() {

  var groups = {
    'Station':   [],
    'Playback':  ['artist_repeat_block', 'crossfade_ms'],
    'Requests':  ['request_expiry_minutes', 'request_rate_limit_seconds', 'request_auto_approve', 'profanity_filter_enabled'],
  };

  var defaults = {
    'station_name':               'RendezVox',
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

  function restoreAuto(settingKey, checkboxId) {
    RendezVoxAPI.put('/admin/settings/' + settingKey, { value: 'true' })
      .then(function() {
        if (settings[settingKey]) settings[settingKey].value = 'true';
        var el = document.getElementById(checkboxId);
        if (el) el.checked = true;
      })
      .catch(function() { /* silent — not critical */ });
  }

  function init() {
    RendezVoxAPI.get('/admin/settings').then(function(result) {
      result.settings.forEach(function(s) { settings[s.key] = s; });
      render();
      loadTimezoneDisplay();
      loadApiKey();
      loadTheAudioDbKey();
      loadSmtp();
      loadAutoTag();
      loadAutoSync();
      loadAutoDedup();
      loadAutoDedupSongs();
      loadBlockedWords();
      checkInitialScanStatus();
      checkInitialCoverScanStatus();
      checkInitialSyncStatus();
      checkInitialDedupStatus();
      checkInitialDedupSongsStatus();
      checkInitialNormStatus();
      checkInitialSilenceStatus();
      checkInitialRenameStatus();
      loadNormTarget();
      loadAutoNorm();
      loadAutoSilence();
      loadAutoRename();
      initEq();
      initLocationPicker();
      initAppearanceTab();
      initSystemTab();
      initTabs();
    });
  }

  // ── Tab switching ──────────────────────────────────────
  function initTabs() {
    var tabs = document.querySelectorAll('#settingsTabs .tab');
    tabs.forEach(function(tab) {
      tab.addEventListener('click', function() {
        switchTab(tab.getAttribute('data-tab'));
      });
    });
    // Restore tab from URL hash
    var hash = location.hash.replace('#', '');
    if (hash && document.getElementById('tab-' + hash)) {
      switchTab(hash);
    }
  }

  function switchTab(name) {
    var panels = document.querySelectorAll('.tab-panel');
    var tabs = document.querySelectorAll('#settingsTabs .tab');
    panels.forEach(function(p) { p.classList.remove('active'); });
    tabs.forEach(function(t) { t.classList.remove('active'); });
    var panel = document.getElementById('tab-' + name);
    if (panel) panel.classList.add('active');
    var tab = document.querySelector('#settingsTabs .tab[data-tab="' + name + '"]');
    if (tab) tab.classList.add('active');
    history.replaceState(null, '', '#' + name);
  }

  // toggleVis is now a global function defined in theme.js

  function loadTimezoneDisplay() {
    RendezVoxAPI.getTimezone().then(function(tz) {
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

    RendezVoxAPI.put('/admin/settings/profanity_custom_words', { value: val })
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

  // ── Auto Sync ──────────────────────────────────────────
  function loadAutoSync() {
    var s = settings['auto_library_sync_enabled'];
    var el = document.getElementById('autoSyncEnabled');
    if (s && el) el.checked = (s.value === 'true');
    if (el) el.addEventListener('change', saveAutoSync);
    loadAutoSyncStatus();
  }

  function loadAutoSyncStatus() {
    RendezVoxAPI.get('/admin/auto-sync-status').then(function(data) {
      var el = document.getElementById('autoSyncStatus');
      if (!el) return;
      if (!data.has_run) {
        el.innerHTML = '<span style="opacity:.6">No auto-sync runs yet</span>';
      } else {
        var d = new Date(data.ran_at);
        var opts = RendezVoxAPI.tzOpts();
        var timeStr = d.toLocaleDateString('en-US', Object.assign({ month: 'short', day: 'numeric' }, opts)) + ' ' +
                      d.toLocaleTimeString('en-US', Object.assign({ hour: '2-digit', minute: '2-digit' }, opts));
        el.innerHTML = 'Last run: <strong>' + timeStr + '</strong> — ' +
          data.total + ' songs checked, ' + (data.missing || 0) + ' missing, ' + (data.purged || 0) + ' purged';
      }
    }).catch(function() {
      var el = document.getElementById('autoSyncStatus');
      if (el) el.innerHTML = '<span style="opacity:.6">Could not load auto-sync status</span>';
    });
  }

  function saveAutoSync() {
    var el = document.getElementById('autoSyncEnabled');
    var val = el.checked ? 'true' : 'false';
    RendezVoxAPI.put('/admin/settings/auto_library_sync_enabled', { value: val })
      .then(function() {
        if (settings['auto_library_sync_enabled']) settings['auto_library_sync_enabled'].value = val;
        showToast('Auto-sync ' + (el.checked ? 'enabled' : 'disabled'));
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Save failed', 'error');
        el.checked = !el.checked;
      });
  }

  // ── Auto Dedup ─────────────────────────────────────────
  function loadAutoDedup() {
    var s = settings['auto_artist_dedup_enabled'];
    var el = document.getElementById('autoDedupEnabled');
    if (s && el) el.checked = (s.value === 'true');
    if (el) el.addEventListener('change', saveAutoDedup);
    loadAutoDedupStatus();
  }

  function loadAutoDedupStatus() {
    RendezVoxAPI.get('/admin/auto-dedup-status').then(function(data) {
      var el = document.getElementById('autoDedupStatus');
      if (!el) return;
      if (!data.has_run) {
        el.innerHTML = '<span style="opacity:.6">No auto-dedup runs yet</span>';
      } else {
        var d = new Date(data.ran_at);
        var opts = RendezVoxAPI.tzOpts();
        var timeStr = d.toLocaleDateString('en-US', Object.assign({ month: 'short', day: 'numeric' }, opts)) + ' ' +
                      d.toLocaleTimeString('en-US', Object.assign({ hour: '2-digit', minute: '2-digit' }, opts));
        el.innerHTML = 'Last run: <strong>' + timeStr + '</strong> — ' +
          data.total + ' artists checked, ' + (data.merged || 0) + ' merged, ' + (data.renamed || 0) + ' renamed';
      }
    }).catch(function() {
      var el = document.getElementById('autoDedupStatus');
      if (el) el.innerHTML = '<span style="opacity:.6">Could not load auto-dedup status</span>';
    });
  }

  function saveAutoDedup() {
    var el = document.getElementById('autoDedupEnabled');
    var val = el.checked ? 'true' : 'false';
    RendezVoxAPI.put('/admin/settings/auto_artist_dedup_enabled', { value: val })
      .then(function() {
        if (settings['auto_artist_dedup_enabled']) settings['auto_artist_dedup_enabled'].value = val;
        showToast('Auto-dedup ' + (el.checked ? 'enabled' : 'disabled'));
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Save failed', 'error');
        el.checked = !el.checked;
      });
  }

  // ── Auto Dedup Songs ────────────────────────────────────
  function loadAutoDedupSongs() {
    var s = settings['auto_dedup_songs_enabled'];
    var el = document.getElementById('autoDedupSongsEnabled');
    if (s && el) el.checked = (s.value === 'true');
    if (el) el.addEventListener('change', saveAutoDedupSongs);
    loadAutoDedupSongsStatus();
  }

  function loadAutoDedupSongsStatus() {
    RendezVoxAPI.get('/admin/auto-dedup-songs-status').then(function(data) {
      var el = document.getElementById('autoDedupSongsStatus');
      if (!el) return;
      if (!data.has_run) {
        el.innerHTML = '<span style="opacity:.6">No auto-dedup runs yet</span>';
      } else {
        var d = new Date(data.ran_at);
        var opts = RendezVoxAPI.tzOpts();
        var timeStr = d.toLocaleDateString('en-US', Object.assign({ month: 'short', day: 'numeric' }, opts)) + ' ' +
                      d.toLocaleTimeString('en-US', Object.assign({ hour: '2-digit', minute: '2-digit' }, opts));
        el.innerHTML = 'Last run: <strong>' + timeStr + '</strong> — ' + (data.message || 'No duplicates');
      }
    }).catch(function() {
      var el = document.getElementById('autoDedupSongsStatus');
      if (el) el.innerHTML = '<span style="opacity:.6">Could not load status</span>';
    });
  }

  function saveAutoDedupSongs() {
    var el = document.getElementById('autoDedupSongsEnabled');
    var val = el.checked ? 'true' : 'false';
    RendezVoxAPI.put('/admin/settings/auto_dedup_songs_enabled', { value: val })
      .then(function() {
        if (settings['auto_dedup_songs_enabled']) settings['auto_dedup_songs_enabled'].value = val;
        showToast('Auto-dedup songs ' + (el.checked ? 'enabled' : 'disabled'));
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Save failed', 'error');
        el.checked = !el.checked;
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
    RendezVoxAPI.get('/admin/auto-tag-status').then(function(data) {
      var el = document.getElementById('autoTagStatus');
      if (!el) return;
      if (!data.has_run) {
        el.innerHTML = '<span style="opacity:.6">No auto-tag runs yet</span>';
      } else {
        var d = new Date(data.ran_at);
        var opts = RendezVoxAPI.tzOpts();
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
    RendezVoxAPI.put('/admin/settings/auto_tag_enabled', { value: val })
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
    RendezVoxAPI.get('/admin/auto-norm-status').then(function(data) {
      var el = document.getElementById('autoNormStatus');
      if (!el) return;
      if (!data.has_run) {
        el.innerHTML = '<span style="opacity:.6">No auto-normalize runs yet</span>';
      } else {
        var d = new Date(data.ran_at);
        var opts = RendezVoxAPI.tzOpts();
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
    RendezVoxAPI.put('/admin/settings/auto_normalize_enabled', { value: val })
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

    RendezVoxAPI.put('/admin/settings/acoustid_api_key', { value: val })
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

  function loadTheAudioDbKey() {
    var s = settings['theaudiodb_api_key'];
    var el = document.getElementById('theAudioDbApiKey');
    if (s && el) el.value = s.value || '';
  }

  function saveTheAudioDbKey() {
    var el = document.getElementById('theAudioDbApiKey');
    var val = el ? el.value.trim() : '';
    var btn = document.getElementById('btnSaveTheAudioDbKey');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    RendezVoxAPI.put('/admin/settings/theaudiodb_api_key', { value: val })
      .then(function() {
        if (settings['theaudiodb_api_key']) settings['theaudiodb_api_key'].value = val;
        showToast('TheAudioDB key saved');
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Save failed', 'error');
      })
      .then(function() {
        btn.disabled = false;
        btn.textContent = 'Save Key';
      });
  }

  // ── Audio Equalizer ─────────────────────────────────

  var eqFreqs = [32, 64, 125, 250, 500, 1000, 2000, 4000, 8000, 16000];
  var eqLabels = ['32', '64', '125', '250', '500', '1K', '2K', '4K', '8K', '16K'];
  var eqBands = {};
  var eqPresets = {
    flat:         [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
    bass_boost:   [6, 5, 4, 2, 0, 0, 0, 0, 0, 0],
    treble_boost: [0, 0, 0, 0, 0, 2, 3, 4, 5, 6],
    vocal:        [-2, -1, 0, 2, 4, 4, 3, 1, 0, -1],
    rock:         [4, 3, 1, -1, -2, 1, 3, 4, 4, 3],
    pop:          [-1, 1, 3, 4, 3, 0, -1, -1, 1, 2],
    jazz:         [3, 2, 0, 2, -2, -2, 0, 2, 3, 4],
    classical:    [4, 3, 2, 1, 0, 0, 0, 1, 2, 3],
    loudness:     [6, 4, 0, 0, -2, 0, -1, -4, 4, 2]
  };

  function initEq() {
    var container = document.getElementById('eqSliders');
    if (!container) return;

    // Build slider columns
    var html = '';
    for (var i = 0; i < eqFreqs.length; i++) {
      var freq = eqFreqs[i];
      html += '<div style="display:flex;flex-direction:column;align-items:center;flex:1;min-width:0">' +
        '<span id="eqVal_' + freq + '" style="font-size:.7rem;color:var(--text-dim);margin-bottom:4px;font-variant-numeric:tabular-nums">0</span>' +
        '<div style="height:120px;display:flex;align-items:center;justify-content:center">' +
        '<input type="range" id="eqBand_' + freq + '" min="-12" max="12" step="1" value="0" ' +
        'orient="vertical" data-freq="' + freq + '" ' +
        'style="writing-mode:vertical-lr;direction:rtl;-webkit-appearance:slider-vertical;' +
        'width:28px;height:110px;cursor:pointer;accent-color:var(--accent)">' +
        '</div>' +
        '<span style="font-size:.65rem;color:var(--text-dim);margin-top:4px">' + eqLabels[i] + '</span>' +
        '</div>';
    }
    container.innerHTML = html;

    // Attach change listeners
    for (var j = 0; j < eqFreqs.length; j++) {
      (function(freq) {
        var slider = document.getElementById('eqBand_' + freq);
        if (slider) {
          slider.addEventListener('input', function() {
            document.getElementById('eqVal_' + freq).textContent = slider.value;
            document.getElementById('eqPreset').value = 'custom';
          });
        }
      })(eqFreqs[j]);
    }

    // Preset change
    var presetEl = document.getElementById('eqPreset');
    if (presetEl) {
      presetEl.addEventListener('change', function() {
        var p = presetEl.value;
        if (p !== 'custom' && eqPresets[p]) {
          setEqSliders(eqPresets[p]);
        }
      });
    }

    // Load saved EQ from API
    loadEq();
  }

  function loadEq() {
    RendezVoxAPI.get('/admin/eq').then(function(data) {
      var presetEl = document.getElementById('eqPreset');
      if (presetEl && data.preset) presetEl.value = data.preset;
      if (data.bands) {
        var vals = [];
        for (var i = 0; i < eqFreqs.length; i++) {
          vals.push(data.bands[String(eqFreqs[i])] || 0);
        }
        setEqSliders(vals);
      }
    }).catch(function() { /* ignore */ });
  }

  function setEqSliders(values) {
    for (var i = 0; i < eqFreqs.length; i++) {
      var freq = eqFreqs[i];
      var val = values[i] || 0;
      var slider = document.getElementById('eqBand_' + freq);
      var label = document.getElementById('eqVal_' + freq);
      if (slider) slider.value = val;
      if (label) label.textContent = val;
    }
  }

  function getEqBands() {
    var bands = {};
    for (var i = 0; i < eqFreqs.length; i++) {
      var slider = document.getElementById('eqBand_' + eqFreqs[i]);
      bands[String(eqFreqs[i])] = slider ? parseInt(slider.value, 10) : 0;
    }
    return bands;
  }

  function applyEq() {
    var preset = document.getElementById('eqPreset').value;
    var bands = getEqBands();
    var statusEl = document.getElementById('eqStatus');
    if (statusEl) statusEl.textContent = 'Applying…';

    RendezVoxAPI.put('/admin/eq', { preset: preset, bands: bands })
      .then(function(res) {
        if (res.bands) {
          var vals = [];
          for (var i = 0; i < eqFreqs.length; i++) {
            vals.push(res.bands[String(eqFreqs[i])] || 0);
          }
          setEqSliders(vals);
        }
        if (res.preset && res.preset !== 'custom') {
          document.getElementById('eqPreset').value = res.preset;
        }
        showToast('EQ applied: ' + preset.replace(/_/g, ' '));
        if (statusEl) statusEl.textContent = '';
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Failed to apply EQ', 'error');
        if (statusEl) statusEl.textContent = '';
      });
  }

  function resetEq() {
    document.getElementById('eqPreset').value = 'flat';
    setEqSliders(eqPresets.flat);
    applyEq();
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
        RendezVoxAPI.put('/admin/settings/' + encodeURIComponent(key), { value: val })
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

    RendezVoxAPI.post('/admin/test-email', {})
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
            RendezVoxAPI.put('/admin/settings/' + encodeURIComponent(key), { value: newVal })
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
        RendezVoxAPI.put('/admin/settings/' + encodeURIComponent(key), { value: def })
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
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Reset failed', 'error');
      });
  }

  // ── Genre Scan ──────────────────────────────────────
  var scanPollTimer = null;
  var scanWasAutoEnabled = false;

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

    RendezVoxAPI.post('/admin/genre-scan', {})
      .then(function(res) {
        var msg = res.message || 'Scan started';
        var isBlocked = msg.indexOf('already running') !== -1;
        showToast(msg, isBlocked ? 'error' : 'success');
        if (res.auto_tag_disabled) {
          scanWasAutoEnabled = true;
          var el = document.getElementById('autoTagEnabled');
          if (el) el.checked = false;
          if (settings['auto_tag_enabled']) settings['auto_tag_enabled'].value = 'false';
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
    RendezVoxAPI.del('/admin/genre-scan')
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
      RendezVoxAPI.get('/admin/genre-scan')
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
            if (scanWasAutoEnabled) restoreAuto('auto_tag_enabled', 'autoTagEnabled');
            scanWasAutoEnabled = false;
            setTimeout(function() { document.getElementById('genreScanStatus').style.display = 'none'; }, 3000);
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
    var detail = processed + ' / ' + total + ' songs — ' +
      (p.updated || 0) + ' updated, ' + (p.skipped || 0) + ' skipped';
    if (p.covers) detail += ', ' + p.covers + ' covers';
    if (p.relocated) detail += ', ' + p.relocated + ' relocated';
    document.getElementById('genreScanDetails').textContent = detail;
  }

  function checkInitialScanStatus() {
    RendezVoxAPI.get('/admin/genre-scan')
      .then(function(data) {
        if (data.status === 'running') {
          showScanProgress(data);
          setScanButtons(true);
          pollScanStatus();
        }
      })
      .catch(function() { /* ignore */ });
  }

  // ── Cover Art Re-fetch ────────────────────────────
  var coverPollTimer = null;

  function setCoverScanButtons(scanning) {
    var btnAll     = document.getElementById('btnCoverScanAll');
    var btnMissing = document.getElementById('btnCoverScanMissing');
    var btnStop    = document.getElementById('btnStopCoverScan');
    if (scanning) {
      btnAll.disabled = true;
      btnAll.textContent = 'Scanning…';
      btnMissing.disabled = true;
      btnStop.classList.remove('hidden');
    } else {
      btnAll.disabled = false;
      btnAll.textContent = 'Re-fetch All';
      btnMissing.disabled = false;
      btnStop.classList.add('hidden');
    }
  }

  function startCoverScan(mode) {
    setCoverScanButtons(true);

    RendezVoxAPI.post('/admin/cover-scan?mode=' + encodeURIComponent(mode), {})
      .then(function(res) {
        var msg = res.message || 'Cover scan started';
        var isBlocked = msg.indexOf('already running') !== -1;
        showToast(msg, isBlocked ? 'error' : 'success');
        if (res.progress) {
          showCoverScanProgress(res.progress);
          pollCoverScanStatus();
        }
      })
      .catch(function(err) {
        setCoverScanButtons(false);
        showToast((err && err.error) || 'Failed to start cover scan', 'error');
      });
  }

  function stopCoverScan() {
    RendezVoxAPI.del('/admin/cover-scan')
      .then(function(res) {
        showToast(res.message || 'Stopping cover scan…');
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Failed to stop cover scan', 'error');
      });
  }

  function pollCoverScanStatus() {
    if (coverPollTimer) clearInterval(coverPollTimer);
    coverPollTimer = setInterval(function() {
      RendezVoxAPI.get('/admin/cover-scan')
        .then(function(data) {
          showCoverScanProgress(data);
          if (data.status !== 'running') {
            clearInterval(coverPollTimer);
            coverPollTimer = null;
            setCoverScanButtons(false);

            if (data.status === 'done') {
              showToast('Cover scan complete — ' + (data.updated || 0) + ' covers updated');
            } else if (data.status === 'stopped') {
              showToast('Cover scan stopped — ' + (data.updated || 0) + ' covers updated so far');
            }
            setTimeout(function() { document.getElementById('coverScanStatus').style.display = 'none'; }, 3000);
          }
        });
    }, 2000);
  }

  function showCoverScanProgress(p) {
    var wrap = document.getElementById('coverScanStatus');
    if (!p || p.status === 'idle') {
      wrap.style.display = 'none';
      return;
    }
    wrap.style.display = 'block';

    var total = p.total || 1;
    var processed = p.processed || 0;
    var pct = Math.round((processed / total) * 100);

    var label = 'Fetching covers…';
    if (p.status === 'done') label = 'Cover scan complete';
    else if (p.status === 'stopped') label = 'Cover scan stopped';

    document.getElementById('coverScanLabel').textContent = label;
    document.getElementById('coverScanPct').textContent = pct + '%';
    document.getElementById('coverScanBar').style.width = pct + '%';
    var detail = processed + ' / ' + total + ' songs — ' +
      (p.updated || 0) + ' updated, ' + (p.skipped || 0) + ' skipped';
    document.getElementById('coverScanDetails').textContent = detail;
  }

  function checkInitialCoverScanStatus() {
    RendezVoxAPI.get('/admin/cover-scan')
      .then(function(data) {
        if (data.status === 'running') {
          showCoverScanProgress(data);
          setCoverScanButtons(true);
          pollCoverScanStatus();
        }
      })
      .catch(function() { /* ignore */ });
  }

  // ── Library Sync ───────────────────────────────────
  var syncPollTimer = null;
  var syncWasAutoEnabled = false;

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

    RendezVoxAPI.post('/admin/library-sync', {})
      .then(function(res) {
        var msg = res.message || 'Sync started';
        var isBlocked = msg.indexOf('already running') !== -1;
        showToast(msg, isBlocked ? 'error' : 'success');
        if (res.auto_sync_disabled) {
          syncWasAutoEnabled = true;
          var el = document.getElementById('autoSyncEnabled');
          if (el) el.checked = false;
          if (settings['auto_library_sync_enabled']) settings['auto_library_sync_enabled'].value = 'false';
        }
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
    RendezVoxAPI.del('/admin/library-sync')
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
      RendezVoxAPI.get('/admin/library-sync')
        .then(function(data) {
          showSyncProgress(data);
          if (data.status !== 'running') {
            clearInterval(syncPollTimer);
            syncPollTimer = null;
            setSyncButtons(false);

            if (data.status === 'done') {
              showToast('Sync complete — ' + (data.missing || 0) + ' missing, ' + (data.purged || 0) + ' purged');
            } else if (data.status === 'stopped') {
              showToast('Sync stopped — ' + (data.deactivated || 0) + ' deactivated so far');
            }
            if (syncWasAutoEnabled) restoreAuto('auto_library_sync_enabled', 'autoSyncEnabled');
            syncWasAutoEnabled = false;
            setTimeout(function() { document.getElementById('librarySyncStatus').style.display = 'none'; }, 3000);
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
      (p.missing || 0) + ' missing, ' + (p.purged || 0) + ' purged';
  }

  function checkInitialSyncStatus() {
    RendezVoxAPI.get('/admin/library-sync')
      .then(function(data) {
        if (data.status === 'running') {
          showSyncProgress(data);
          setSyncButtons(true);
          pollSyncStatus();
        }
      })
      .catch(function() { /* ignore */ });
  }

  // ── Artist Dedup ───────────────────────────────────
  var dedupPollTimer = null;
  var dedupWasAutoEnabled = false;

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

    RendezVoxAPI.post('/admin/artist-dedup', {})
      .then(function(res) {
        var msg = res.message || 'Dedup started';
        var isBlocked = msg.indexOf('already running') !== -1;
        showToast(msg, isBlocked ? 'error' : 'success');
        if (res.auto_dedup_disabled) {
          dedupWasAutoEnabled = true;
          var el = document.getElementById('autoDedupEnabled');
          if (el) el.checked = false;
          if (settings['auto_artist_dedup_enabled']) settings['auto_artist_dedup_enabled'].value = 'false';
        }
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
    RendezVoxAPI.del('/admin/artist-dedup')
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
      RendezVoxAPI.get('/admin/artist-dedup')
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
            if (dedupWasAutoEnabled) restoreAuto('auto_artist_dedup_enabled', 'autoDedupEnabled');
            dedupWasAutoEnabled = false;
            setTimeout(function() { document.getElementById('artistDedupStatus').style.display = 'none'; }, 3000);
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
    RendezVoxAPI.get('/admin/artist-dedup')
      .then(function(data) {
        if (data.status === 'running') {
          showDedupProgress(data);
          setDedupButtons(true);
          pollDedupStatus();
        }
      })
      .catch(function() { /* ignore */ });
  }

  // ── Song Dedup ──────────────────────────────────────
  var dedupSongsPollTimer = null;
  var dedupSongsWasAutoEnabled = false;

  function setDedupSongsButtons(running) {
    var btnStart = document.getElementById('btnDedupSongs');
    var btnStop  = document.getElementById('btnStopDedupSongs');
    if (running) {
      btnStart.disabled = true;
      btnStart.textContent = 'Deduplicating…';
      btnStop.classList.remove('hidden');
    } else {
      btnStart.disabled = false;
      btnStart.textContent = 'Deduplicate Songs';
      btnStop.classList.add('hidden');
    }
  }

  function startDedupSongs() {
    setDedupSongsButtons(true);

    RendezVoxAPI.post('/admin/dedup-songs', {})
      .then(function(res) {
        var msg = res.message || 'Dedup started';
        var isBlocked = msg.indexOf('already running') !== -1;
        showToast(msg, isBlocked ? 'error' : 'success');
        if (res.auto_dedup_songs_disabled) {
          dedupSongsWasAutoEnabled = true;
          var el = document.getElementById('autoDedupSongsEnabled');
          if (el) el.checked = false;
          if (settings['auto_dedup_songs_enabled']) settings['auto_dedup_songs_enabled'].value = 'false';
        }
        if (res.progress) {
          showDedupSongsProgress(res.progress);
          pollDedupSongsStatus();
        }
      })
      .catch(function(err) {
        setDedupSongsButtons(false);
        showToast((err && err.error) || 'Failed to start dedup', 'error');
      });
  }

  function stopDedupSongs() {
    RendezVoxAPI.del('/admin/dedup-songs')
      .then(function(res) {
        showToast(res.message || 'Stopping…');
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Failed to stop', 'error');
      });
  }

  function pollDedupSongsStatus() {
    if (dedupSongsPollTimer) clearInterval(dedupSongsPollTimer);
    dedupSongsPollTimer = setInterval(function() {
      RendezVoxAPI.get('/admin/dedup-songs')
        .then(function(data) {
          showDedupSongsProgress(data);
          if (data.status !== 'running') {
            clearInterval(dedupSongsPollTimer);
            dedupSongsPollTimer = null;
            setDedupSongsButtons(false);

            if (data.status === 'done') {
              showToast('Dedup complete — ' + (data.deleted || 0) + ' duplicates deleted');
            } else if (data.status === 'stopped') {
              showToast('Dedup stopped — ' + (data.deleted || 0) + ' deleted so far');
            }
            if (dedupSongsWasAutoEnabled) restoreAuto('auto_dedup_songs_enabled', 'autoDedupSongsEnabled');
            dedupSongsWasAutoEnabled = false;
            loadAutoDedupSongsStatus();
            setTimeout(function() { document.getElementById('dedupSongsStatus').style.display = 'none'; }, 3000);
          }
        });
    }, 2000);
  }

  function showDedupSongsProgress(p) {
    var wrap = document.getElementById('dedupSongsStatus');
    if (!p || p.status === 'idle') {
      wrap.style.display = 'none';
      return;
    }
    wrap.style.display = 'block';

    var total = p.total_groups || 1;
    var processed = p.processed || 0;
    var pct = total > 0 ? Math.round((processed / total) * 100) : 0;

    var label = p.phase === 'scanning' ? 'Scanning for duplicates…' : 'Deleting duplicates…';
    if (p.status === 'done') label = 'Dedup complete';
    else if (p.status === 'stopped') label = 'Dedup stopped';

    document.getElementById('dedupSongsLabel').textContent = label;
    document.getElementById('dedupSongsPct').textContent = pct + '%';
    document.getElementById('dedupSongsBar').style.width = pct + '%';

    var freedMB = ((p.freed_bytes || 0) / 1048576).toFixed(1);
    document.getElementById('dedupSongsDetails').textContent =
      processed + ' / ' + total + ' groups — ' +
      (p.deleted || 0) + ' deleted, ' + freedMB + ' MB freed';
  }

  function checkInitialDedupSongsStatus() {
    RendezVoxAPI.get('/admin/dedup-songs')
      .then(function(data) {
        if (data.status === 'running') {
          showDedupSongsProgress(data);
          setDedupSongsButtons(true);
          pollDedupSongsStatus();
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
    RendezVoxAPI.put('/admin/settings/normalize_target_lufs', { value: val })
      .then(function() {
        if (settings['normalize_target_lufs']) settings['normalize_target_lufs'].value = val;
        showToast('Target LUFS saved');
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Save failed', 'error');
      });
  }

  var normPollTimer = null;
  var normWasAutoEnabled = false;

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

    RendezVoxAPI.post('/admin/normalize', {})
      .then(function(res) {
        var msg = res.message || 'Normalization started';
        var isBlocked = msg.indexOf('already running') !== -1;
        showToast(msg, isBlocked ? 'error' : 'success');
        if (res.auto_normalize_disabled) {
          normWasAutoEnabled = true;
          var el = document.getElementById('autoNormEnabled');
          if (el) el.checked = false;
          if (settings['auto_normalize_enabled']) settings['auto_normalize_enabled'].value = 'false';
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
    RendezVoxAPI.del('/admin/normalize')
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
      RendezVoxAPI.get('/admin/normalize')
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
            if (normWasAutoEnabled) restoreAuto('auto_normalize_enabled', 'autoNormEnabled');
            normWasAutoEnabled = false;
            setTimeout(function() { document.getElementById('normStatus').style.display = 'none'; }, 3000);
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
    RendezVoxAPI.get('/admin/normalize')
      .then(function(data) {
        if (data.status === 'running') {
          showNormProgress(data);
          setNormButtons(true);
          pollNormStatus();
        }
      })
      .catch(function() { /* ignore */ });
  }

  // ── Silence Detection ────────────────────────────────

  function loadAutoSilence() {
    var s = settings['auto_silence_detect_enabled'];
    var el = document.getElementById('autoSilenceEnabled');
    if (s && el) el.checked = (s.value === 'true');
    if (el) el.addEventListener('change', saveAutoSilence);
    loadAutoSilenceStatus();
  }

  function loadAutoSilenceStatus() {
    RendezVoxAPI.get('/admin/auto-silence-status').then(function(data) {
      var el = document.getElementById('autoSilenceStatus');
      if (!el) return;
      if (!data.has_run) {
        el.innerHTML = '<span style="opacity:.6">No auto-silence-detect runs yet</span>';
      } else {
        var d = new Date(data.ran_at);
        var opts = RendezVoxAPI.tzOpts();
        var timeStr = d.toLocaleDateString('en-US', Object.assign({ month: 'short', day: 'numeric' }, opts)) + ' ' +
                      d.toLocaleTimeString('en-US', Object.assign({ hour: '2-digit', minute: '2-digit' }, opts));
        el.innerHTML = 'Last run: <strong>' + timeStr + '</strong> — ' +
          data.total + ' songs processed, ' + (data.analyzed || 0) + ' analyzed, ' + (data.skipped || 0) + ' skipped';
      }
    }).catch(function() {
      var el = document.getElementById('autoSilenceStatus');
      if (el) el.innerHTML = '<span style="opacity:.6">Could not load auto-silence-detect status</span>';
    });
  }

  function saveAutoSilence() {
    var el = document.getElementById('autoSilenceEnabled');
    var val = el.checked ? 'true' : 'false';
    RendezVoxAPI.put('/admin/settings/auto_silence_detect_enabled', { value: val })
      .then(function() {
        if (settings['auto_silence_detect_enabled']) settings['auto_silence_detect_enabled'].value = val;
        showToast('Auto-silence-detect ' + (el.checked ? 'enabled' : 'disabled'));
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Save failed', 'error');
        el.checked = !el.checked;
      });
  }

  var silencePollTimer = null;
  var silenceWasAutoEnabled = false;

  function setSilenceButtons(running) {
    var btnStart = document.getElementById('btnSilenceDetect');
    var btnStop  = document.getElementById('btnStopSilence');
    if (running) {
      btnStart.disabled = true;
      btnStart.textContent = 'Detecting…';
      btnStop.classList.remove('hidden');
    } else {
      btnStart.disabled = false;
      btnStart.textContent = 'Detect Silence';
      btnStop.classList.add('hidden');
    }
  }

  function startSilenceDetect() {
    setSilenceButtons(true);

    RendezVoxAPI.post('/admin/silence-detect', {})
      .then(function(res) {
        var msg = res.message || 'Silence detection started';
        var isBlocked = msg.indexOf('already running') !== -1;
        showToast(msg, isBlocked ? 'error' : 'success');
        if (res.auto_silence_disabled) {
          silenceWasAutoEnabled = true;
          var el = document.getElementById('autoSilenceEnabled');
          if (el) el.checked = false;
          if (settings['auto_silence_detect_enabled']) settings['auto_silence_detect_enabled'].value = 'false';
        }
        if (res.progress) {
          showSilenceProgress(res.progress);
          pollSilenceStatus();
        }
      })
      .catch(function(err) {
        setSilenceButtons(false);
        showToast((err && err.error) || 'Failed to start silence detection', 'error');
      });
  }

  function stopSilenceDetect() {
    RendezVoxAPI.del('/admin/silence-detect')
      .then(function(res) {
        showToast(res.message || 'Stopping silence detection…');
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Failed to stop silence detection', 'error');
      });
  }

  function pollSilenceStatus() {
    if (silencePollTimer) clearInterval(silencePollTimer);
    silencePollTimer = setInterval(function() {
      RendezVoxAPI.get('/admin/silence-detect')
        .then(function(data) {
          showSilenceProgress(data);
          if (data.status !== 'running') {
            clearInterval(silencePollTimer);
            silencePollTimer = null;
            setSilenceButtons(false);

            if (data.status === 'done') {
              showToast('Silence detection complete — ' + (data.analyzed || 0) + ' songs analyzed');
            } else if (data.status === 'stopped') {
              showToast('Silence detection stopped — ' + (data.analyzed || 0) + ' songs analyzed so far');
            }
            if (silenceWasAutoEnabled) restoreAuto('auto_silence_detect_enabled', 'autoSilenceEnabled');
            silenceWasAutoEnabled = false;
            setTimeout(function() { document.getElementById('silenceStatus').style.display = 'none'; }, 3000);
          }
        });
    }, 2000);
  }

  function showSilenceProgress(p) {
    var wrap = document.getElementById('silenceStatus');
    if (!p || p.status === 'idle') {
      wrap.style.display = 'none';
      return;
    }
    wrap.style.display = 'block';

    var total = p.total || 1;
    var processed = p.processed || 0;
    var pct = Math.round((processed / total) * 100);

    var label = 'Detecting silence…';
    if (p.status === 'done') label = 'Silence detection complete';
    else if (p.status === 'stopped') label = 'Silence detection stopped';

    document.getElementById('silenceLabel').textContent = label;
    document.getElementById('silencePct').textContent = pct + '%';
    document.getElementById('silenceBar').style.width = pct + '%';
    document.getElementById('silenceDetails').textContent =
      processed + ' / ' + total + ' songs — ' +
      (p.analyzed || 0) + ' analyzed, ' + (p.skipped || 0) + ' skipped, ' + (p.failed || 0) + ' failed';
  }

  function checkInitialSilenceStatus() {
    RendezVoxAPI.get('/admin/silence-detect')
      .then(function(data) {
        if (data.status === 'running') {
          showSilenceProgress(data);
          setSilenceButtons(true);
          pollSilenceStatus();
        }
      })
      .catch(function() { /* ignore */ });
  }

  // ── Auto Path Rename ─────────────────────────────────
  function loadAutoRename() {
    var s = settings['auto_rename_paths_enabled'];
    var el = document.getElementById('autoRenameEnabled');
    if (s && el) el.checked = (s.value === 'true');
    if (el) el.addEventListener('change', saveAutoRename);
    loadAutoRenameStatus();
  }

  function loadAutoRenameStatus() {
    RendezVoxAPI.get('/admin/auto-rename-status').then(function(data) {
      var el = document.getElementById('autoRenameStatus');
      if (!el) return;
      if (!data.has_run) {
        el.innerHTML = '<span style="opacity:.6">No auto-rename runs yet</span>';
      } else {
        var d = new Date(data.ran_at);
        var opts = RendezVoxAPI.tzOpts();
        var timeStr = d.toLocaleDateString('en-US', Object.assign({ month: 'short', day: 'numeric' }, opts)) + ' ' +
                      d.toLocaleTimeString('en-US', Object.assign({ hour: '2-digit', minute: '2-digit' }, opts));
        el.innerHTML = 'Last run: <strong>' + timeStr + '</strong> — ' +
          (data.dirs_renamed || 0) + ' dirs, ' + (data.files_renamed || 0) + ' files renamed';
      }
    }).catch(function() {
      var el = document.getElementById('autoRenameStatus');
      if (el) el.innerHTML = '<span style="opacity:.6">Could not load auto-rename status</span>';
    });
  }

  function saveAutoRename() {
    var el = document.getElementById('autoRenameEnabled');
    var val = el.checked ? 'true' : 'false';
    RendezVoxAPI.put('/admin/settings/auto_rename_paths_enabled', { value: val })
      .then(function() {
        if (settings['auto_rename_paths_enabled']) settings['auto_rename_paths_enabled'].value = val;
        showToast('Auto-rename ' + (el.checked ? 'enabled' : 'disabled'));
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Save failed', 'error');
        el.checked = !el.checked;
      });
  }

  // ── Path Rename ──────────────────────────────────────
  var renamePollTimer = null;
  var renameWasAutoEnabled = false;

  function setRenameButtons(running) {
    var btnStart = document.getElementById('btnRenamePaths');
    var btnStop  = document.getElementById('btnStopRename');
    if (running) {
      btnStart.disabled = true;
      btnStart.textContent = 'Renaming…';
      btnStop.classList.remove('hidden');
    } else {
      btnStart.disabled = false;
      btnStart.textContent = 'Rename Paths';
      btnStop.classList.add('hidden');
    }
  }

  function startRenamePaths() {
    setRenameButtons(true);

    RendezVoxAPI.post('/admin/rename-paths', {})
      .then(function(res) {
        var msg = res.message || 'Rename started';
        var isBlocked = msg.indexOf('already running') !== -1;
        showToast(msg, isBlocked ? 'error' : 'success');
        if (isBlocked) { setRenameButtons(false); return; }
        if (res.auto_rename_disabled) {
          renameWasAutoEnabled = true;
          var el = document.getElementById('autoRenameEnabled');
          if (el) el.checked = false;
          if (settings['auto_rename_paths_enabled']) settings['auto_rename_paths_enabled'].value = 'false';
        }
        if (res.progress) showRenameProgress(res.progress);
        pollRenameStatus();
      })
      .catch(function(err) {
        setRenameButtons(false);
        showToast((err && err.error) || 'Failed to start rename', 'error');
      });
  }

  function stopRenamePaths() {
    RendezVoxAPI.del('/admin/rename-paths')
      .then(function(res) {
        showToast(res.message || 'Stopping rename…');
        if (res.message && res.message.indexOf('No') !== -1) {
          if (renamePollTimer) { clearInterval(renamePollTimer); renamePollTimer = null; }
          setRenameButtons(false);
        }
      })
      .catch(function(err) {
        showToast((err && err.error) || 'Failed to stop rename', 'error');
      });
  }

  function pollRenameStatus() {
    if (renamePollTimer) clearInterval(renamePollTimer);
    var idleCount = 0;
    renamePollTimer = setInterval(function() {
      RendezVoxAPI.get('/admin/rename-paths')
        .then(function(data) {
          if (!data || data.status === 'idle') {
            if (++idleCount >= 3) {
              clearInterval(renamePollTimer); renamePollTimer = null;
              setRenameButtons(false);
            }
            return;
          }
          idleCount = 0;
          showRenameProgress(data);
          if (data.status !== 'running') {
            clearInterval(renamePollTimer);
            renamePollTimer = null;
            setRenameButtons(false);

            if (data.status === 'done') {
              showToast('Rename complete — ' + (data.dirs_renamed || 0) + ' dirs, ' + (data.files_renamed || 0) + ' files');
            } else if (data.status === 'stopped') {
              showToast('Rename stopped — ' + (data.dirs_renamed || 0) + ' dirs, ' + (data.files_renamed || 0) + ' files so far');
            }
            if (renameWasAutoEnabled) restoreAuto('auto_rename_paths_enabled', 'autoRenameEnabled');
            renameWasAutoEnabled = false;
            setTimeout(function() { document.getElementById('renameStatus').style.display = 'none'; }, 3000);
          }
        });
    }, 2000);
  }

  function showRenameProgress(p) {
    var wrap = document.getElementById('renameStatus');
    if (!p || p.status === 'idle') {
      wrap.style.display = 'none';
      return;
    }
    wrap.style.display = 'block';

    var total = p.total || 1;
    var processed = p.processed || 0;
    var pct = Math.round((processed / total) * 100);

    var label = 'Renaming ' + (p.phase === 'files' ? 'files' : 'directories') + '…';
    if (p.status === 'done') label = 'Rename complete';
    else if (p.status === 'stopped') label = 'Rename stopped';

    document.getElementById('renameLabel').textContent = label;
    document.getElementById('renamePct').textContent = pct + '%';
    document.getElementById('renameBar').style.width = pct + '%';
    document.getElementById('renameDetails').textContent =
      processed + ' / ' + total + ' — ' +
      (p.dirs_renamed || 0) + ' dirs, ' + (p.files_renamed || 0) + ' files renamed';
  }

  function checkInitialRenameStatus() {
    RendezVoxAPI.get('/admin/rename-paths')
      .then(function(data) {
        if (data.status === 'running') {
          showRenameProgress(data);
          setRenameButtons(true);
          pollRenameStatus();
        }
      })
      .catch(function() { /* ignore */ });
  }

  // ── Location Picker ─────────────────────────────────
  var locPickerCoords = null; // [lat, lon] resolved for current selection

  function initLocationPicker() {
    var container = document.getElementById('tab-general');
    if (!container) return;

    // Find the first card (the settings card rendered into #settingsContainer)
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

    RendezVoxAPI.get('/admin/geo/provinces')
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

    RendezVoxAPI.get('/admin/geo/cities?province=' + encodeURIComponent(province))
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

    RendezVoxAPI.get('/admin/geo/barangays?province=' + encodeURIComponent(province) + '&city=' + encodeURIComponent(cityVal))
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

    RendezVoxAPI.get('/admin/geo/geocode?q=' + encodeURIComponent(q))
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
      RendezVoxAPI.put('/admin/settings/weather_province',  { value: province }),
      RendezVoxAPI.put('/admin/settings/weather_city',      { value: city }),
      RendezVoxAPI.put('/admin/settings/weather_barangay',  { value: brgy }),
      RendezVoxAPI.put('/admin/settings/weather_latitude',  { value: lat }),
      RendezVoxAPI.put('/admin/settings/weather_longitude', { value: lon }),
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

  // ── Appearance Tab ──────────────────────────────────

  function initAppearanceTab() {
    renderThemeDropdown();
    initAccentPicker();
  }

  function renderThemeDropdown() {
    var sel = document.getElementById('themeSelect');
    var dot = document.getElementById('themePreviewDot');
    if (!sel) return;
    var themes = RendezVoxTheme.list();
    var current = RendezVoxTheme.current();
    var html = '';
    var lastGroup = '';

    Object.keys(themes).forEach(function(key) {
      var t = themes[key];
      if (t.group && t.group !== lastGroup) {
        if (lastGroup) html += '</optgroup>';
        html += '<optgroup label="' + escAttr(t.group) + '">';
        lastGroup = t.group;
      }
      var selected = (key === current) ? ' selected' : '';
      html += '<option value="' + key + '"' + selected + '>' + escHtml(t.label) + '</option>';
    });
    if (lastGroup) html += '</optgroup>';

    sel.innerHTML = html;
    updatePreviewDot(dot, themes, current);

    sel.addEventListener('change', function() {
      var name = sel.value;
      RendezVoxTheme.apply(name);
      updatePreviewDot(dot, themes, name);
      syncAccentPicker();
      showToast('Theme changed to ' + (themes[name] || {}).label);
    });
  }

  function updatePreviewDot(dot, themes, name) {
    if (!dot || !themes[name]) return;
    dot.style.background = themes[name].vars['--accent'];
  }

  function initAccentPicker() {
    var picker = document.getElementById('accentColorPicker');
    var hex = document.getElementById('accentColorHex');
    var reset = document.getElementById('btnResetAccent');
    if (!picker || !hex || !reset) return;

    // Load server accent into local state on init
    var serverAccent = settings.accent_color ? settings.accent_color.value : '';
    if (serverAccent && /^#[0-9a-fA-F]{6}$/.test(serverAccent)) {
      RendezVoxTheme.setAccent(serverAccent);
    }

    // Initialize with current accent or theme default
    syncAccentPicker();

    // Check role — only super_admin can modify accent color
    var user = RendezVoxAuth.getUser();
    var isSuperAdmin = user && user.role === 'super_admin';

    if (!isSuperAdmin) {
      picker.disabled = true;
      hex.disabled = true;
      reset.disabled = true;
      reset.style.opacity = '0.4';
      reset.style.cursor = 'default';
      var note = document.createElement('div');
      note.style.cssText = 'font-size:12px;color:var(--text-dim);margin-top:8px;font-style:italic';
      note.textContent = 'Only super admins can change the station accent color';
      reset.parentNode.appendChild(note);
      return;
    }

    // Color picker → hex field (debounced save, immediate on change)
    var accentSaveTimer = null;
    picker.addEventListener('input', function() {
      hex.value = picker.value;
      RendezVoxTheme.setAccent(picker.value);
      clearTimeout(accentSaveTimer);
      accentSaveTimer = setTimeout(function() { saveAccentToServer(picker.value); }, 500);
    });
    picker.addEventListener('change', function() {
      clearTimeout(accentSaveTimer);
      saveAccentToServer(picker.value);
    });

    // Hex field → color picker + save to server
    hex.addEventListener('input', function() {
      var val = hex.value.trim();
      if (/^#[0-9a-fA-F]{6}$/.test(val)) {
        picker.value = val;
        RendezVoxTheme.setAccent(val);
        clearTimeout(accentSaveTimer);
        accentSaveTimer = setTimeout(function() { saveAccentToServer(val); }, 500);
      }
    });

    // Reset accent
    reset.addEventListener('click', function() {
      RendezVoxTheme.clearAccent();
      syncAccentPicker();
      saveAccentToServer('');
      showToast('Accent color reset to theme default');
    });
  }

  function saveAccentToServer(hex) {
    RendezVoxAPI.put('/admin/settings/accent_color', { value: hex })
      .then(function() { showToast('Accent color saved'); })
      .catch(function(err) { showToast((err && err.error) || 'Failed to save accent color', 'error'); });
  }

  function syncAccentPicker() {
    var picker = document.getElementById('accentColorPicker');
    var hex = document.getElementById('accentColorHex');
    if (!picker || !hex) return;

    var customAccent = RendezVoxTheme.accent();
    if (customAccent) {
      picker.value = customAccent;
      hex.value = customAccent;
    } else {
      var current = RendezVoxTheme.current();
      var themes = RendezVoxTheme.list();
      var defaultAccent = themes[current] ? themes[current].vars['--accent'] : '#ff7800';
      picker.value = defaultAccent;
      hex.value = defaultAccent;
    }
  }



  // ── System Info tab ─────────────────────────────────

  function initSystemTab() {
    var btn = document.getElementById('btnRefreshSystem');
    if (btn) btn.addEventListener('click', loadSystemInfo);
    loadSystemInfo();
  }

  function loadSystemInfo() {
    var container = document.getElementById('systemInfoContainer');
    if (!container) return;
    container.innerHTML = '<div class="spinner"></div>';

    RendezVoxAPI.get('/admin/system-info').then(function(data) {
      renderSystemInfo(data);
    }).catch(function() {
      container.innerHTML = '<span style="color:var(--text-dim)">Could not load system information</span>';
    });
  }

  function renderSystemInfo(d) {
    var container = document.getElementById('systemInfoContainer');
    if (!container) return;

    var cpuPct = d.cpu_cores > 0 ? Math.min(100, Math.round(d.cpu_load[0] / d.cpu_cores * 100)) : 0;
    var memPct = Math.round(d.memory_percent);
    var diskFreeMb = Math.round(d.disk_free_bytes / (1024 * 1024));
    var diskTotalMb = Math.round(d.disk_total_bytes / (1024 * 1024));
    var diskUsedMb = diskTotalMb - diskFreeMb;
    var diskPct = diskTotalMb > 0 ? Math.round(diskUsedMb / diskTotalMb * 100) : 0;

    var html = '';

    // Services
    html += '<h4 class="si-heading">Services</h4>';
    html += '<div class="si-services">';
    var svcOrder = ['nginx', 'php', 'icecast', 'liquidsoap'];
    var svcLabels = { nginx: 'Nginx', php: 'PHP-FPM', icecast: 'Icecast', liquidsoap: 'Liquidsoap' };
    for (var i = 0; i < svcOrder.length; i++) {
      var key = svcOrder[i];
      var status = d.services[key] || 'unknown';
      var running = status === 'running';
      html += '<div class="si-svc">' +
        '<span class="si-svc-dot" style="background:' + (running ? '#4ade80' : '#f87171') + '"></span>' +
        '<span class="si-svc-name">' + escHtml(svcLabels[key] || key) + '</span>' +
        '<span class="si-svc-status" style="color:' + (running ? '#4ade80' : '#f87171') + '">' + escHtml(status) + '</span>' +
        '</div>';
    }
    html += '</div>';

    // Resource meters
    html += '<h4 class="si-heading">Resources</h4>';
    html += '<div class="si-meters">';
    html += siMeter('CPU', cpuPct, d.cpu_load[0].toFixed(2) + ' / ' + d.cpu_cores + ' cores');
    html += siMeter('RAM', memPct, fmtMb(d.memory_used_mb) + ' / ' + fmtMb(d.memory_total_mb));
    html += siMeter('Disk', diskPct, fmtMb(diskUsedMb) + ' / ' + fmtMb(diskTotalMb));
    html += '</div>';

    // Software versions
    html += '<h4 class="si-heading">Software</h4>';
    html += '<div class="si-table">';
    html += siRow('PHP', d.php_version);
    html += siRow('PostgreSQL', d.pg_version);
    html += '</div>';

    // Host info
    html += '<h4 class="si-heading">Host</h4>';
    html += '<div class="si-table">';
    html += siRow('Hostname', d.hostname);
    html += siRow('OS', d.os);
    html += siRow('Architecture', d.arch);
    html += siRow('Uptime', d.uptime);
    html += '</div>';

    container.innerHTML = html;
  }

  function siMeter(label, pct, detail) {
    var color = pct < 60 ? '#4ade80' : (pct < 85 ? '#f59e0b' : '#f87171');
    return '<div class="si-meter">' +
      '<div class="si-meter-head">' +
        '<span class="si-meter-label">' + escHtml(label) + '</span>' +
        '<span class="si-meter-pct">' + pct + '%</span>' +
      '</div>' +
      '<div class="si-meter-track">' +
        '<div class="si-meter-fill" style="width:' + pct + '%;background:' + color + '"></div>' +
      '</div>' +
      '<div class="si-meter-detail">' + escHtml(detail) + '</div>' +
      '</div>';
  }

  function siRow(label, value) {
    return '<div class="si-row">' +
      '<span class="si-row-label">' + escHtml(label) + '</span>' +
      '<span class="si-row-value">' + escHtml(value || '—') + '</span>' +
      '</div>';
  }

  function fmtMb(mb) {
    if (mb >= 1024) return (mb / 1024).toFixed(1) + ' GB';
    return Math.round(mb) + ' MB';
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
    saveTheAudioDbKey: saveTheAudioDbKey,
    saveSmtp: saveSmtp,
    sendTestEmail: sendTestEmail,
    startGenreScan: startGenreScan,
    stopGenreScan: stopGenreScan,
    startCoverScan: startCoverScan,
    stopCoverScan: stopCoverScan,
    startLibrarySync: startLibrarySync,
    stopLibrarySync: stopLibrarySync,
    startArtistDedup: startArtistDedup,
    stopArtistDedup: stopArtistDedup,
    startDedupSongs: startDedupSongs,
    stopDedupSongs: stopDedupSongs,
    startNormalize: startNormalize,
    stopNormalize: stopNormalize,
    startSilenceDetect: startSilenceDetect,
    stopSilenceDetect: stopSilenceDetect,
    startRenamePaths: startRenamePaths,
    stopRenamePaths: stopRenamePaths,
    saveBlockedWords: saveBlockedWords,
    applyEq: applyEq,
    resetEq: resetEq,
    toggleVis: toggleVis
  };
})();
