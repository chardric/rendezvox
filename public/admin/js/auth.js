/* ============================================================
   RendezVox Admin — Auth Helper
   ============================================================ */
var RendezVoxAuth = (function() {

  // Clean up old localStorage tokens (migrated to sessionStorage)
  localStorage.removeItem('rendezvox_token');
  localStorage.removeItem('rendezvox_user');

  var IDLE_TIMEOUT_MS = 30 * 60 * 1000; // 30 minutes
  var lastActivity = Date.now();

  function getToken() {
    return sessionStorage.getItem('rendezvox_token');
  }

  function getUser() {
    try { return JSON.parse(sessionStorage.getItem('rendezvox_user')); }
    catch (e) { return null; }
  }

  function isLoggedIn() {
    return !!getToken();
  }

  function login(token, user) {
    sessionStorage.setItem('rendezvox_token', token);
    sessionStorage.setItem('rendezvox_user', JSON.stringify(user));
  }

  function logout() {
    sessionStorage.removeItem('rendezvox_token');
    sessionStorage.removeItem('rendezvox_user');
    window.location.href = '/admin/';
  }

  function requireLogin() {
    if (!isLoggedIn()) {
      window.location.href = '/admin/';
      return false;
    }
    return true;
  }

  function requireRole(role) {
    if (!requireLogin()) return false;
    var user = getUser();
    if (!user || user.role !== role) {
      window.location.href = '/admin/dashboard';
      return false;
    }
    return true;
  }

  // ── Idle session timeout ──────────────────────────────
  function resetIdleTimer() {
    lastActivity = Date.now();
  }

  function startIdleWatcher() {
    if (!isLoggedIn()) return;

    ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function(evt) {
      document.addEventListener(evt, resetIdleTimer, { passive: true });
    });

    setInterval(function() {
      if (!isLoggedIn()) return;
      if (Date.now() - lastActivity > IDLE_TIMEOUT_MS) {
        logout();
      }
    }, 60000);
  }

  startIdleWatcher();

  return {
    getToken: getToken,
    getUser: getUser,
    isLoggedIn: isLoggedIn,
    login: login,
    logout: logout,
    requireLogin: requireLogin,
    requireRole: requireRole
  };
})();
