/* ============================================================
   RendezVox Admin — Navigation
   ============================================================ */
var RendezVoxNav = (function() {

  var pages = [
    { href: '/admin/dashboard', label: 'Dashboard',  icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>' },
    { href: '/admin/media',     label: 'Media',      icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>' },
    { href: '/admin/files',     label: 'Files',      icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>' },
    { href: '/admin/playlists', label: 'Playlists',  icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>' },
    { href: '/admin/schedules', label: 'Schedules',  icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>' },
    { href: '/admin/station-ids', label: 'Station IDs', icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14"/><path d="M15.54 8.46a5 5 0 010 7.07"/></svg>' },
    { href: '/admin/requests',  label: 'Requests',   icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>' },
    { href: '/admin/users',     label: 'Users',      icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4-4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>', role: 'super_admin' },
    { href: '/admin/settings',  label: 'Settings',   icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 01-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>', role: 'super_admin' },
    { href: '/admin/analytics', label: 'Analytics',  icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>' },
  ];

  // Shared file input for avatar uploads (sidebar + modal)
  var avatarInput;

  function init() {
    var user = RendezVoxAuth.getUser();
    var userRole = user ? user.role : '';
    var current = window.location.pathname;

    // Build sidebar
    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    var navHtml = '';
    pages.forEach(function(p) {
      if (p.role && p.role !== userRole) return;
      var cls = (current === p.href) ? ' active' : '';
      navHtml += '<a href="' + p.href + '" class="' + cls + '" data-label="' + p.label + '">' + p.icon + '<span class="nav-label">' + p.label + '</span></a>';
    });

    // Build compact sidebar avatar (32px for inline use)
    var sidebarAvatarHtml = '';
    if (user) {
      if (user.avatar_path) {
        sidebarAvatarHtml = '<div class="sidebar-avatar" id="sidebarAvatar" title="Change avatar"><img src="/api/avatar/' + user.id + '?v=' + Date.now() + '" alt="Avatar"></div>';
      } else {
        var sidebarInitial = user.username ? user.username.charAt(0).toUpperCase() : '?';
        sidebarAvatarHtml = '<div class="sidebar-avatar" id="sidebarAvatar" title="Change avatar"><div class="initials">' + sidebarInitial + '</div></div>';
      }
    }

    var collapseIcon = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>';

    sidebar.innerHTML =
      '<div class="sidebar-brand">' +
        '<div class="sidebar-logo-wrap"><img class="sidebar-logo" src="/api/logo?v=' + Date.now() + '" alt="RendezVox"></div>' +
      '</div>' +
      '<button type="button" class="sidebar-collapse-btn" id="btnCollapseSidebar" title="Collapse sidebar">' + collapseIcon + '</button>' +
      '<nav class="sidebar-nav">' + navHtml + '</nav>' +
      '<div class="sidebar-footer">' +
        '<div class="sidebar-footer-row">' +
          sidebarAvatarHtml +
          '<a href="#" class="username" id="btnOpenProfile" title="Account settings">' + (user ? (user.display_name || user.username) : '') + '</a>' +
          '<button type="button" class="btn-logout" id="btnLogout" title="Logout"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></button>' +
        '</div>' +
      '</div>';

    // Restore collapsed state from localStorage
    if (localStorage.getItem('rendezvox_sidebar_collapsed') === 'true') {
      sidebar.classList.add('collapsed');
    }

    document.getElementById('btnLogout').addEventListener('click', RendezVoxAuth.logout);
    document.getElementById('btnOpenProfile').addEventListener('click', function(e) {
      e.preventDefault();
      openProfileModal();
    });

    // Sidebar collapse toggle
    document.getElementById('btnCollapseSidebar').addEventListener('click', function() {
      var isCollapsed = sidebar.classList.toggle('collapsed');
      localStorage.setItem('rendezvox_sidebar_collapsed', isCollapsed ? 'true' : 'false');
    });

    // Avatar upload (shared input)
    avatarInput = document.createElement('input');
    avatarInput.type = 'file';
    avatarInput.accept = 'image/jpeg,image/png,image/gif,image/webp';
    avatarInput.style.display = 'none';
    document.body.appendChild(avatarInput);

    avatarInput.addEventListener('change', function() {
      if (!avatarInput.files || !avatarInput.files[0]) return;
      var formData = new FormData();
      formData.append('file', avatarInput.files[0]);
      RendezVoxAPI.upload('/admin/avatar', formData)
        .then(function(res) {
          var u = RendezVoxAuth.getUser();
          if (u) {
            u.avatar_path = res.avatar_path;
            localStorage.setItem('rendezvox_user', JSON.stringify(u));
          }
          // Update sidebar avatar
          var sidebarAv = document.getElementById('sidebarAvatar');
          if (sidebarAv) {
            sidebarAv.innerHTML = '<img src="/api/avatar/' + u.id + '?v=' + Date.now() + '" alt="Avatar">';
          }
          // Update modal avatar if open
          var modalAv = document.getElementById('profileModalAvatar');
          if (modalAv) {
            modalAv.innerHTML = '<img src="/api/avatar/' + u.id + '?v=' + Date.now() + '" alt="Avatar">';
          }
          showToast('Avatar updated', 'success');
        })
        .catch(function(err) {
          showToast((err && err.error) ? err.error : 'Failed to upload avatar', 'error');
        });
      avatarInput.value = '';
    });

    var avatarEl = document.getElementById('sidebarAvatar');
    if (avatarEl) {
      avatarEl.addEventListener('click', function() {
        avatarInput.click();
      });
    }

    // Set page title
    var pageLabel = '';
    pages.forEach(function(p) {
      if (current === p.href) pageLabel = p.label;
    });
    document.title = 'RendezVox Admin' + (pageLabel ? ' — ' + pageLabel : '');

    // Mobile top bar — hamburger + page title inline
    var hamburger = document.getElementById('hamburger');
    var overlay = document.getElementById('overlay');
    if (hamburger) {
      var topbar = document.createElement('div');
      topbar.className = 'mobile-topbar';
      hamburger.parentNode.insertBefore(topbar, hamburger);
      topbar.appendChild(hamburger);
      var mobileTitle = document.createElement('span');
      mobileTitle.className = 'mobile-page-title';
      mobileTitle.textContent = pageLabel || 'Admin';
      topbar.appendChild(mobileTitle);

      hamburger.addEventListener('click', function() {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('open');
      });
    }
    if (overlay) {
      overlay.addEventListener('click', function() {
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
      });
    }

    // Inject profile modal
    injectProfileModal();

    // Quick theme switcher in sidebar footer
    initThemeSwitcher();

    // Inject copyright footer into .main
    var mainEl = document.querySelector('.main');
    if (mainEl) {
      var footer = document.createElement('footer');
      footer.className = 'site-footer';
      footer.innerHTML = '&copy; 2026 <a href="https://downstreamtech.net" target="_blank" rel="noopener">DownStreamTech</a>. All rights reserved.';
      mainEl.appendChild(footer);
    }

    // Load persistent mini-player (streams audio across all pages)
    var mpScript = document.createElement('script');
    mpScript.src = '/admin/js/miniplayer.js?v=20260226';
    mpScript.onload = function() {
      if (window.RendezVoxMiniPlayer) {
        RendezVoxMiniPlayer.init();
        document.dispatchEvent(new Event('miniplayer:ready'));
      }
    };
    document.body.appendChild(mpScript);
  }

  /* ── Role display labels ──────────────────────────────── */
  function roleLabel(role) {
    var map = { super_admin: 'Super Admin', admin: 'Admin', editor: 'Editor', viewer: 'Viewer' };
    return map[role] || role;
  }

  /* ── Date formatting helpers ──────────────────────────── */
  function fmtDate(iso) {
    if (!iso) return '—';
    var d = new Date(iso);
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[d.getMonth()] + ' ' + d.getFullYear();
  }

  function fmtDateTime(iso) {
    if (!iso) return '—';
    var d = new Date(iso);
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var h = d.getHours();
    var m = d.getMinutes();
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return months[d.getMonth()] + ' ' + d.getDate() + ', ' + (m < 10 ? '0' : '') + m + ' ' + ampm;
  }

  /* ── Profile modal injection ──────────────────────────── */
  function injectProfileModal() {
    if (document.getElementById('profileModal')) return;
    var modal = document.createElement('div');
    modal.id = 'profileModal';
    modal.className = 'modal-backdrop';
    modal.style.display = 'none';
    modal.innerHTML =
      '<div class="modal" style="max-width:420px">' +
        '<h3>Account</h3>' +
        '<button type="button" class="modal-close" id="profileModalClose">&times;</button>' +

        /* ── Profile header ── */
        '<div class="profile-header">' +
          '<div class="profile-avatar" id="profileModalAvatar" title="Change avatar"></div>' +
          '<div class="profile-info">' +
            '<div class="profile-display-name" id="profileDisplayNameLabel"></div>' +
            '<div class="profile-username" id="profileUsername"></div>' +
            '<div class="profile-email" id="profileEmail"></div>' +
            '<span class="badge badge-active" id="profileRole"></span>' +
          '</div>' +
        '</div>' +

        /* ── Display name (editable) ── */
        '<div class="profile-name-row">' +
          '<label for="profileDisplayName">Display Name</label>' +
          '<div class="profile-name-input-wrap">' +
            '<input type="text" id="profileDisplayName" placeholder="Enter your name" maxlength="255">' +
            '<button type="button" class="btn btn-sm btn-ghost" id="profileNameSave" style="display:none">Save</button>' +
          '</div>' +
        '</div>' +

        /* ── Email (editable) ── */
        '<div class="profile-name-row">' +
          '<label for="profileEmailInput">Email</label>' +
          '<div class="profile-name-input-wrap">' +
            '<input type="email" id="profileEmailInput" placeholder="Enter your email" maxlength="255">' +
            '<button type="button" class="btn btn-sm btn-ghost" id="profileEmailSave" style="display:none">Save</button>' +
          '</div>' +
        '</div>' +

        /* ── Meta card ── */
        '<div class="profile-meta">' +
          '<div class="profile-meta-row">' +
            '<span>Member since</span><span id="profileCreatedAt"></span>' +
          '</div>' +
          '<div class="profile-meta-row">' +
            '<span>Last login</span><span id="profileLastLogin"></span>' +
          '</div>' +
          '<div class="profile-meta-row">' +
            '<span>Login IP</span><span class="profile-mono" id="profileLoginIp"></span>' +
          '</div>' +
        '</div>' +

        /* ── Divider ── */
        '<hr class="profile-divider">' +

        /* ── Password change ── */
        '<div class="compact-form">' +
          '<h4 style="color:var(--text-heading);margin-bottom:10px;font-size:.95rem">Change Password</h4>' +
          '<label>Current Password</label>' +
          '<div class="eye-wrap">' +
            '<input type="password" id="pwCurrent" style="padding-right:36px">' +
            '<button type="button" class="eye-toggle" onclick="toggleVis(\'pwCurrent\',this)" aria-label="Toggle password visibility">' + EYE_OPEN + '</button>' +
          '</div>' +
          '<label>New Password</label>' +
          '<div class="eye-wrap">' +
            '<input type="password" id="pwNew" style="padding-right:36px">' +
            '<button type="button" class="eye-toggle" onclick="toggleVis(\'pwNew\',this)" aria-label="Toggle password visibility">' + EYE_OPEN + '</button>' +
          '</div>' +
          '<div id="pwNewStrength" style="margin-top:-4px;display:none">' +
            '<div style="display:flex;gap:3px;margin-bottom:4px">' +
              '<div class="pw-bar" id="pwNewBar1"></div>' +
              '<div class="pw-bar" id="pwNewBar2"></div>' +
              '<div class="pw-bar" id="pwNewBar3"></div>' +
              '<div class="pw-bar" id="pwNewBar4"></div>' +
            '</div>' +
            '<span id="pwNewStrengthLabel" style="font-size:.78rem"></span>' +
          '</div>' +
          '<label>Confirm New Password</label>' +
          '<div class="eye-wrap">' +
            '<input type="password" id="pwConfirm" style="padding-right:36px">' +
            '<button type="button" class="eye-toggle" onclick="toggleVis(\'pwConfirm\',this)" aria-label="Toggle password visibility">' + EYE_OPEN + '</button>' +
          '</div>' +
          '<div id="pwError" style="color:var(--danger);font-size:.82rem;display:none"></div>' +
          '<div class="modal-actions">' +
            '<button type="button" class="btn btn-ghost" id="profileModalCancel">Cancel</button>' +
            '<button type="button" class="btn btn-primary" id="profileModalSave">Change Password</button>' +
          '</div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modal);

    // Event listeners
    modal.addEventListener('click', function(e) {
      if (e.target === modal) closeProfileModal();
    });
    document.getElementById('profileModalClose').addEventListener('click', closeProfileModal);
    document.getElementById('profileModalCancel').addEventListener('click', closeProfileModal);
    document.getElementById('profileModalSave').addEventListener('click', submitPasswordChange);
    document.getElementById('pwNew').addEventListener('input', updatePwNewStrength);

    // Avatar click in modal
    document.getElementById('profileModalAvatar').addEventListener('click', function() {
      avatarInput.click();
    });

    // Display name inline edit
    var nameInput = document.getElementById('profileDisplayName');
    var nameSaveBtn = document.getElementById('profileNameSave');
    var savedName = '';

    nameInput.addEventListener('input', function() {
      var changed = nameInput.value.trim() !== savedName;
      nameSaveBtn.style.display = changed ? '' : 'none';
    });

    nameSaveBtn.addEventListener('click', function() {
      var newName = nameInput.value.trim() || null;
      nameSaveBtn.disabled = true;
      nameSaveBtn.textContent = 'Saving...';
      RendezVoxAPI.put('/admin/profile', { display_name: newName })
        .then(function(res) {
          savedName = res.display_name || '';
          nameInput.value = savedName;
          nameSaveBtn.style.display = 'none';
          // Update localStorage, sidebar, and modal header
          var u = RendezVoxAuth.getUser();
          if (u) {
            u.display_name = res.display_name;
            localStorage.setItem('rendezvox_user', JSON.stringify(u));
            var sidebarName = document.getElementById('btnOpenProfile');
            if (sidebarName) sidebarName.textContent = res.display_name || u.username;
            var dnLabel = document.getElementById('profileDisplayNameLabel');
            if (dnLabel) {
              if (res.display_name) { dnLabel.textContent = res.display_name; dnLabel.style.display = ''; }
              else { dnLabel.textContent = ''; dnLabel.style.display = 'none'; }
            }
          }
          showToast('Name updated', 'success');
        })
        .catch(function(err) {
          showToast((err && err.error) ? err.error : 'Failed to update name', 'error');
        })
        .finally(function() {
          nameSaveBtn.disabled = false;
          nameSaveBtn.textContent = 'Save';
        });
    });

    // Store savedName reference for closure
    nameInput._getSavedName = function() { return savedName; };
    nameInput._setSavedName = function(v) { savedName = v; };

    // Email inline edit
    var emailInput = document.getElementById('profileEmailInput');
    var emailSaveBtn = document.getElementById('profileEmailSave');
    var savedEmail = '';

    emailInput.addEventListener('input', function() {
      var changed = emailInput.value.trim() !== savedEmail;
      emailSaveBtn.style.display = changed ? '' : 'none';
    });

    emailSaveBtn.addEventListener('click', function() {
      var newEmail = emailInput.value.trim();
      if (!newEmail) {
        showToast('Email cannot be empty', 'error');
        return;
      }
      emailSaveBtn.disabled = true;
      emailSaveBtn.textContent = 'Saving...';
      RendezVoxAPI.put('/admin/profile', { email: newEmail })
        .then(function(res) {
          savedEmail = res.email || '';
          emailInput.value = savedEmail;
          emailSaveBtn.style.display = 'none';
          // Update localStorage, modal header
          var u = RendezVoxAuth.getUser();
          if (u) {
            u.email = res.email;
            localStorage.setItem('rendezvox_user', JSON.stringify(u));
            var emailLabel = document.getElementById('profileEmail');
            if (emailLabel) emailLabel.textContent = res.email || '';
          }
          showToast('Email updated', 'success');
        })
        .catch(function(err) {
          showToast((err && err.error) ? err.error : 'Failed to update email', 'error');
        })
        .finally(function() {
          emailSaveBtn.disabled = false;
          emailSaveBtn.textContent = 'Save';
        });
    });

    emailInput._getSavedEmail = function() { return savedEmail; };
    emailInput._setSavedEmail = function(v) { savedEmail = v; };
  }

  /* ── Open profile modal & fetch fresh data ────────────── */
  function openProfileModal() {
    var modal = document.getElementById('profileModal');
    if (!modal) return;

    // Reset password fields
    document.getElementById('pwCurrent').value = '';
    document.getElementById('pwNew').value = '';
    document.getElementById('pwConfirm').value = '';
    document.getElementById('pwError').style.display = 'none';
    document.getElementById('pwNewStrength').style.display = 'none';
    document.getElementById('profileNameSave').style.display = 'none';
    document.getElementById('profileEmailSave').style.display = 'none';

    // Show modal with loading state
    var user = RendezVoxAuth.getUser();
    populateProfile(user, null);
    modal.style.display = 'flex';

    // Fetch fresh data from /me
    RendezVoxAPI.get('/admin/me').then(function(res) {
      populateProfile(res.user, res.user);
    }).catch(function() {});
  }

  function populateProfile(user, fullData) {
    if (!user) return;

    // Avatar
    var avatarEl = document.getElementById('profileModalAvatar');
    if (user.avatar_path) {
      avatarEl.innerHTML = '<img src="/api/avatar/' + user.id + '?v=' + Date.now() + '" alt="Avatar">';
    } else {
      var initial = user.username ? user.username.charAt(0).toUpperCase() : '?';
      avatarEl.innerHTML = '<div class="initials">' + initial + '</div>';
    }

    // Info
    var displayName = (fullData && fullData.display_name) || user.display_name || '';
    var displayNameEl = document.getElementById('profileDisplayNameLabel');
    if (displayName) {
      displayNameEl.textContent = displayName;
      displayNameEl.style.display = '';
    } else {
      displayNameEl.textContent = '';
      displayNameEl.style.display = 'none';
    }
    document.getElementById('profileUsername').textContent = user.username || '';
    document.getElementById('profileEmail').textContent = user.email || '';
    document.getElementById('profileRole').textContent = roleLabel(user.role);

    // Display name
    var nameInput = document.getElementById('profileDisplayName');
    var name = (fullData && fullData.display_name) || (user.display_name) || '';
    nameInput.value = name;
    nameInput._setSavedName(name);

    // Email
    var emailInput = document.getElementById('profileEmailInput');
    var email = (fullData && fullData.email) || (user.email) || '';
    emailInput.value = email;
    emailInput._setSavedEmail(email);

    // Meta (only available from full /me response)
    if (fullData) {
      document.getElementById('profileCreatedAt').textContent = fmtDate(fullData.created_at);
      document.getElementById('profileLastLogin').textContent = fmtDateTime(fullData.last_login_at);
      document.getElementById('profileLoginIp').textContent = fullData.last_login_ip || '—';
    } else {
      document.getElementById('profileCreatedAt').textContent = '...';
      document.getElementById('profileLastLogin').textContent = '...';
      document.getElementById('profileLoginIp').textContent = '...';
    }
  }

  function closeProfileModal() {
    var modal = document.getElementById('profileModal');
    if (modal) modal.style.display = 'none';
  }

  /* ── Password strength ────────────────────────────────── */
  function pwStrengthCalc(pw) {
    if (!pw) return { score: 0, label: '', color: '' };
    var score = 0;
    if (pw.length >= 6)  score++;
    if (pw.length >= 10) score++;
    if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^a-zA-Z0-9]/.test(pw)) score++;
    score = Math.min(4, Math.max(1, score));
    var levels = [
      { label: 'Weak',   color: '#ef4444' },
      { label: 'Fair',   color: '#f59e0b' },
      { label: 'Good',   color: '#3b82f6' },
      { label: 'Strong', color: '#10b981' },
    ];
    return { score: score, label: levels[score - 1].label, color: levels[score - 1].color };
  }

  function updatePwNewStrength() {
    var pw = document.getElementById('pwNew').value;
    var wrap = document.getElementById('pwNewStrength');
    if (!pw) { wrap.style.display = 'none'; return; }
    wrap.style.display = '';
    var s = pwStrengthCalc(pw);
    for (var i = 1; i <= 4; i++) {
      document.getElementById('pwNewBar' + i).style.background =
        i <= s.score ? s.color : 'var(--bg-input)';
    }
    var lbl = document.getElementById('pwNewStrengthLabel');
    lbl.textContent = s.label;
    lbl.style.color = s.color;
  }

  /* ── Password change submit ───────────────────────────── */
  function submitPasswordChange() {
    var current = document.getElementById('pwCurrent').value;
    var newPw   = document.getElementById('pwNew').value;
    var confirm = document.getElementById('pwConfirm').value;
    var errEl   = document.getElementById('pwError');

    if (!current || !newPw) {
      errEl.textContent = 'All fields are required';
      errEl.style.display = 'block';
      return;
    }
    if (pwStrengthCalc(newPw).score < 3) {
      errEl.textContent = 'Password is too weak — use 8+ characters with mixed case, numbers, or symbols';
      errEl.style.display = 'block';
      return;
    }
    if (newPw !== confirm) {
      errEl.textContent = 'New passwords do not match';
      errEl.style.display = 'block';
      return;
    }

    var btn = document.getElementById('profileModalSave');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    RendezVoxAPI.put('/admin/password', { current_password: current, new_password: newPw })
      .then(function() {
        closeProfileModal();
        showToast('Password changed successfully', 'success');
      })
      .catch(function(err) {
        errEl.textContent = (err && err.error) ? err.error : 'Failed to change password';
        errEl.style.display = 'block';
      })
      .finally(function() {
        btn.disabled = false;
        btn.textContent = 'Change Password';
      });
  }

  function showToast(msg, type) {
    var container = document.getElementById('toasts');
    if (!container) return;
    var t = document.createElement('div');
    t.className = 'toast toast-' + (type || 'success');
    t.textContent = msg;
    container.appendChild(t);
    setTimeout(function() { t.remove(); }, 3000);
  }

  /* ── Quick theme switcher (sidebar footer) ──────────── */
  function initThemeSwitcher() {
    var footer = document.querySelector('.sidebar-footer');
    if (!footer || !window.RendezVoxTheme) return;

    var themes = RendezVoxTheme.list();
    var current = RendezVoxTheme.current();

    var row = document.createElement('div');
    row.className = 'sidebar-theme-row';

    var icon = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;opacity:.5"><circle cx="12" cy="12" r="10"/><path d="M12 2a10 10 0 000 20 5 5 0 005-5 3 3 0 00-3-3h-1.5a1.5 1.5 0 01-1.5-1.5 1.5 1.5 0 011.5-1.5H14a2 2 0 002-2 10 10 0 00-4-7z"/></svg>';

    var sel = document.createElement('select');
    sel.className = 'sidebar-theme-select';
    sel.title = 'Switch theme';

    var lastGroup = '';
    var html = '';
    Object.keys(themes).forEach(function(key) {
      var t = themes[key];
      if (t.group && t.group !== lastGroup) {
        if (lastGroup) html += '</optgroup>';
        html += '<optgroup label="' + t.group + '">';
        lastGroup = t.group;
      }
      var selected = (key === current) ? ' selected' : '';
      html += '<option value="' + key + '"' + selected + '>' + t.label + '</option>';
    });
    if (lastGroup) html += '</optgroup>';
    sel.innerHTML = html;

    row.innerHTML = icon;
    row.appendChild(sel);
    footer.appendChild(row);

    sel.addEventListener('change', function() {
      RendezVoxTheme.apply(sel.value);
      showToast('Theme: ' + (themes[sel.value] || {}).label);
    });
  }

  return { init: init };
})();
