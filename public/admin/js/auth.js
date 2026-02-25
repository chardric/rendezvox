/* ============================================================
   RendezVox Admin — Auth Helper
   ============================================================ */
var RendezVoxAuth = (function() {

  function getToken() {
    return localStorage.getItem('rendezvox_token');
  }

  function getUser() {
    try { return JSON.parse(localStorage.getItem('rendezvox_user')); }
    catch (e) { return null; }
  }

  function isLoggedIn() {
    return !!getToken();
  }

  function login(token, user) {
    localStorage.setItem('rendezvox_token', token);
    localStorage.setItem('rendezvox_user', JSON.stringify(user));
  }

  function logout() {
    localStorage.removeItem('rendezvox_token');
    localStorage.removeItem('rendezvox_user');
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
