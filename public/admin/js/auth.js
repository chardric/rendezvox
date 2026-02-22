/* ============================================================
   iRadio Admin — Auth Helper
   ============================================================ */
var iRadioAuth = (function() {

  function getToken() {
    return localStorage.getItem('iradio_token');
  }

  function getUser() {
    try { return JSON.parse(localStorage.getItem('iradio_user')); }
    catch (e) { return null; }
  }

  function isLoggedIn() {
    return !!getToken();
  }

  function login(token, user) {
    localStorage.setItem('iradio_token', token);
    localStorage.setItem('iradio_user', JSON.stringify(user));
  }

  function logout() {
    localStorage.removeItem('iradio_token');
    localStorage.removeItem('iradio_user');
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
