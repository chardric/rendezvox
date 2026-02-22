/* ============================================================
   iRadio Admin — Users Management
   ============================================================ */
var iRadioUsers = (function() {

  var users = [];
  var editingId = null;
  var currentUserId = null;
  var smtpConfigured = null; // cached after first check

  function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }

  function showToast(msg, type) {
    var c = document.getElementById('toasts');
    if (!c) return;
    var t = document.createElement('div');
    t.className = 'toast toast-' + (type || 'success');
    t.textContent = msg;
    c.appendChild(t);
    setTimeout(function() { t.remove(); }, 3000);
  }

  function roleBadge(role) {
    if (role === 'super_admin') {
      return '<span class="badge" style="background:#7c3aed;color:#fff">Super Admin</span>';
    }
    return '<span class="badge" style="background:#10b981;color:#fff">DJ</span>';
  }

  function formatDate(dt) {
    if (!dt) return '<span style="color:var(--text-dim)">Never</span>';
    return new Date(dt).toLocaleString(undefined, iRadioAPI.tzOpts());
  }

  function checkSmtpConfigured() {
    if (smtpConfigured !== null) return Promise.resolve(smtpConfigured);
    return iRadioAPI.get('/admin/settings').then(function(data) {
      var settings = data.settings || [];
      for (var i = 0; i < settings.length; i++) {
        if (settings[i].key === 'smtp_host') {
          smtpConfigured = !!(settings[i].value && settings[i].value.trim());
          return smtpConfigured;
        }
      }
      smtpConfigured = false;
      return false;
    }).catch(function() {
      smtpConfigured = false;
      return false;
    });
  }

  function init() {
    var me = iRadioAuth.getUser();
    currentUserId = me ? me.id : null;

    document.getElementById('btnNewUser').addEventListener('click', openCreateModal);
    document.getElementById('userModalClose').addEventListener('click', closeModal);
    document.getElementById('userModalCancel').addEventListener('click', closeModal);
    document.getElementById('userModalSave').addEventListener('click', saveUser);
    document.getElementById('userModal').addEventListener('click', function(e) {
      if (e.target === this) closeModal();
    });

    // Live validation
    document.getElementById('userUsername').addEventListener('input', validateUsername);
    document.getElementById('userEmail').addEventListener('input', validateEmail);
    document.getElementById('userPassword').addEventListener('input', updatePasswordStrength);

    // Pre-fetch SMTP status
    checkSmtpConfigured();

    iRadioAPI.getTimezone().then(function() { loadUsers(); });
  }

  // ── Validation helpers ──────────────────────────────────

  function validateUsername() {
    var el = document.getElementById('userUsername');
    var hint = document.getElementById('userUsernameHint');
    var val = el.value.trim();
    if (!val) {
      hint.textContent = '';
      hint.className = 'field-hint';
      return false;
    }
    if (val.length < 3) {
      hint.textContent = 'At least 3 characters';
      hint.className = 'field-hint error';
      return false;
    }
    if (!/^[a-zA-Z0-9_.-]+$/.test(val)) {
      hint.textContent = 'Only letters, numbers, dots, hyphens, underscores';
      hint.className = 'field-hint error';
      return false;
    }
    hint.textContent = '';
    hint.className = 'field-hint';
    return true;
  }

  function validateEmail() {
    var el = document.getElementById('userEmail');
    var hint = document.getElementById('userEmailHint');
    var val = el.value.trim();
    if (!val) {
      hint.textContent = '';
      hint.className = 'field-hint';
      return false;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
      hint.textContent = 'Enter a valid email address';
      hint.className = 'field-hint error';
      return false;
    }
    hint.textContent = '';
    hint.className = 'field-hint';
    return true;
  }

  function getPasswordStrength(pw) {
    if (!pw) return { score: 0, label: '', color: '' };
    var score = 0;
    if (pw.length >= 6)  score++;
    if (pw.length >= 10) score++;
    if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^a-zA-Z0-9]/.test(pw)) score++;
    // Clamp to 1-4
    score = Math.min(4, Math.max(1, score));
    var levels = [
      { label: 'Weak',   color: '#ef4444' },
      { label: 'Fair',   color: '#f59e0b' },
      { label: 'Good',   color: '#3b82f6' },
      { label: 'Strong', color: '#10b981' },
    ];
    return { score: score, label: levels[score - 1].label, color: levels[score - 1].color };
  }

  function updatePasswordStrength() {
    var pw = document.getElementById('userPassword').value;
    var wrap = document.getElementById('pwStrength');
    if (!pw) {
      wrap.style.display = 'none';
      return;
    }
    wrap.style.display = '';
    var s = getPasswordStrength(pw);
    for (var i = 1; i <= 4; i++) {
      document.getElementById('pwBar' + i).style.background =
        i <= s.score ? s.color : 'var(--bg-input)';
    }
    var lbl = document.getElementById('pwStrengthLabel');
    lbl.textContent = s.label;
    lbl.style.color = s.color;
  }

  function loadUsers() {
    iRadioAPI.get('/admin/users').then(function(data) {
      users = data.users || [];
      renderTable();
    }).catch(function(err) {
      document.getElementById('userTable').innerHTML =
        '<tr><td colspan="7" class="empty">Failed to load users</td></tr>';
    });
  }

  function renderTable() {
    var tbody = document.getElementById('userTable');
    if (!users.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="empty">No users found</td></tr>';
      return;
    }

    var html = '';
    users.forEach(function(u, i) {
      var isSelf = u.id === currentUserId;
      var nameCell = escHtml(u.username);
      if (u.display_name) {
        nameCell += '<br><span style="color:var(--text-dim);font-size:.8rem">' + escHtml(u.display_name) + '</span>';
      }
      if (isSelf) {
        nameCell += ' <span style="color:var(--text-dim);font-size:.8rem">(you)</span>';
      }
      html += '<tr>' +
        '<td>' + (i + 1) + '</td>' +
        '<td>' + nameCell + '</td>' +
        '<td>' + escHtml(u.email) + '</td>' +
        '<td>' + roleBadge(u.role) + '</td>' +
        '<td>' + activeToggle(u) + '</td>' +
        '<td>' + formatDate(u.last_login_at) + '</td>' +
        '<td>' + actions(u) + '</td>' +
      '</tr>';
    });
    tbody.innerHTML = html;

    // Bind toggle listeners
    tbody.querySelectorAll('.user-toggle').forEach(function(el) {
      el.addEventListener('change', function() {
        toggleActive(parseInt(this.dataset.id), this.checked);
      });
    });

    // Bind edit listeners
    tbody.querySelectorAll('.btn-edit-user').forEach(function(el) {
      el.addEventListener('click', function() {
        openEditModal(parseInt(this.dataset.id));
      });
    });

    // Bind delete listeners
    tbody.querySelectorAll('.btn-delete-user').forEach(function(el) {
      el.addEventListener('click', function() {
        deleteUser(parseInt(this.dataset.id));
      });
    });
  }

  function activeToggle(u) {
    if (u.id === currentUserId) {
      return '<span style="color:var(--text-dim);font-size:.85rem">-</span>';
    }
    return '<label class="toggle"><input type="checkbox" class="user-toggle" data-id="' + u.id + '"' +
      (u.is_active ? ' checked' : '') + '><span class="slider"></span></label>';
  }

  function actions(u) {
    var btns = '<div style="display:flex;gap:6px;justify-content:flex-end">';
    btns += '<button class="btn btn-ghost btn-sm btn-edit-user" data-id="' + u.id + '">Edit</button>';
    if (u.id !== currentUserId) {
      btns += '<button class="btn btn-sm btn-delete-user" data-id="' + u.id + '" style="color:var(--danger)">Delete</button>';
    }
    btns += '</div>';
    return btns;
  }

  function toggleActive(id, active) {
    iRadioAPI.put('/admin/users/' + id, { is_active: active }).then(function() {
      showToast(active ? 'User activated' : 'User deactivated', 'success');
      loadUsers();
    }).catch(function(err) {
      showToast((err && err.error) || 'Failed to update user', 'error');
      loadUsers();
    });
  }

  function resetModalHints() {
    document.getElementById('userUsernameHint').textContent = '';
    document.getElementById('userUsernameHint').className = 'field-hint';
    document.getElementById('userEmailHint').textContent = '';
    document.getElementById('userEmailHint').className = 'field-hint';
    document.getElementById('pwStrength').style.display = 'none';
    document.getElementById('userError').style.display = 'none';
    document.getElementById('userInviteInfo').style.display = 'none';
  }

  function openCreateModal() {
    editingId = null;
    document.getElementById('userModalTitle').textContent = 'New User';
    document.getElementById('userUsername').value = '';
    document.getElementById('userEmail').value = '';
    document.getElementById('userDisplayName').value = '';
    document.getElementById('userPassword').value = '';
    document.getElementById('userRole').value = 'dj';
    document.getElementById('userActive').checked = true;
    document.getElementById('userActiveGroup').style.display = 'none';

    // Hide password field for create mode (invite-based)
    document.getElementById('userPasswordGroup').style.display = 'none';

    // Show invite info
    var infoEl = document.getElementById('userInviteInfo');
    checkSmtpConfigured().then(function(configured) {
      if (configured) {
        infoEl.style.display = 'block';
        infoEl.style.background = 'var(--bg-input)';
        infoEl.style.color = 'var(--text-body)';
        infoEl.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> An activation email will be sent to the user.';
      } else {
        infoEl.style.display = 'block';
        infoEl.style.background = '#fef3c7';
        infoEl.style.color = '#92400e';
        infoEl.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> SMTP not configured — a temporary password will be generated.';
      }
    });

    resetModalHints();
    document.getElementById('userModal').style.display = 'flex';
  }

  function openEditModal(id) {
    var u = users.find(function(x) { return x.id === id; });
    if (!u) return;
    editingId = id;
    document.getElementById('userModalTitle').textContent = 'Edit User';
    document.getElementById('userUsername').value = u.username;
    document.getElementById('userEmail').value = u.email;
    document.getElementById('userDisplayName').value = u.display_name || '';
    document.getElementById('userPassword').value = '';
    document.getElementById('userPasswordLabel').textContent = 'Password (leave blank to keep)';
    document.getElementById('userRole').value = u.role;
    document.getElementById('userActive').checked = u.is_active;
    document.getElementById('userActiveGroup').style.display = (u.id === currentUserId) ? 'none' : 'flex';

    // Show password field for edit mode
    document.getElementById('userPasswordGroup').style.display = '';

    // Hide invite info for edit mode
    document.getElementById('userInviteInfo').style.display = 'none';

    resetModalHints();
    document.getElementById('userModal').style.display = 'flex';
  }

  function closeModal() {
    document.getElementById('userModal').style.display = 'none';
  }

  function saveUser() {
    var errEl = document.getElementById('userError');
    var username    = document.getElementById('userUsername').value.trim();
    var email       = document.getElementById('userEmail').value.trim();
    var displayName = document.getElementById('userDisplayName').value.trim();
    var password    = document.getElementById('userPassword').value;
    var role        = document.getElementById('userRole').value;

    if (!username || !email) {
      errEl.textContent = 'Username and email are required';
      errEl.style.display = 'block';
      return;
    }

    if (!validateUsername() || !validateEmail()) {
      errEl.textContent = 'Please fix the errors above';
      errEl.style.display = 'block';
      return;
    }

    // Password validation only in edit mode when password is provided
    if (editingId && password && getPasswordStrength(password).score < 3) {
      errEl.textContent = 'Password is too weak — use 8+ characters with mixed case, numbers, or symbols';
      errEl.style.display = 'block';
      return;
    }

    var btn = document.getElementById('userModalSave');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    var body = { username: username, email: email, role: role, display_name: displayName || null };
    if (editingId && password) body.password = password;
    if (editingId) body.is_active = document.getElementById('userActive').checked;

    var promise = editingId
      ? iRadioAPI.put('/admin/users/' + editingId, body)
      : iRadioAPI.post('/admin/users', body);

    promise.then(function(data) {
      closeModal();

      if (!editingId && data) {
        // Create mode — show appropriate toast
        if (data.invite_sent) {
          showToast('User created — activation email sent', 'success');
        } else if (data.temp_password) {
          showTempPasswordToast(data.temp_password);
        } else {
          showToast('User created', 'success');
        }
      } else {
        showToast('User updated', 'success');
      }

      loadUsers();
    }).catch(function(err) {
      errEl.textContent = (err && err.error) || 'Failed to save user';
      errEl.style.display = 'block';
    }).finally(function() {
      btn.disabled = false;
      btn.textContent = 'Save';
    });
  }

  function showTempPasswordToast(tempPw) {
    var c = document.getElementById('toasts');
    if (!c) return;
    var t = document.createElement('div');
    t.className = 'toast toast-success';
    t.style.maxWidth = '420px';
    t.innerHTML =
      '<div style="margin-bottom:6px"><strong>User created with temporary password:</strong></div>' +
      '<div style="display:flex;align-items:center;gap:8px">' +
        '<code style="background:rgba(0,0,0,.1);padding:4px 8px;border-radius:4px;font-size:.85rem;user-select:all">' + escHtml(tempPw) + '</code>' +
        '<button onclick="navigator.clipboard.writeText(\'' + escHtml(tempPw).replace(/'/g, "\\'") + '\');this.textContent=\'Copied!\'" ' +
          'style="background:none;border:1px solid rgba(255,255,255,.3);color:inherit;padding:2px 8px;border-radius:4px;cursor:pointer;font-size:.78rem">Copy</button>' +
      '</div>' +
      '<div style="font-size:.78rem;margin-top:6px;opacity:.8">Share this password securely with the user.</div>';
    c.appendChild(t);
    setTimeout(function() { t.remove(); }, 15000);
  }

  function deleteUser(id) {
    var u = users.find(function(x) { return x.id === id; });
    if (!u) return;
    if (!confirm('Delete user "' + u.username + '"? This cannot be undone.')) return;

    iRadioAPI.del('/admin/users/' + id).then(function() {
      showToast('User deleted', 'success');
      loadUsers();
    }).catch(function(err) {
      showToast((err && err.error) || 'Failed to delete user', 'error');
    });
  }

  return { init: init };
})();
