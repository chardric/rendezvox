/* ============================================================
   RendezVox — Shared Utility Functions
   Loaded before all other admin JS files.
   ============================================================ */
var RendezVoxUtils = (function() {
  'use strict';

  /** Escape HTML to prevent XSS. */
  function escHtml(str) {
    if (str === null || str === undefined) return '';
    var div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
  }

  /** Escape string for safe use in HTML attributes. */
  function escAttr(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  /** Format milliseconds as M:SS. */
  function formatDuration(ms) {
    if (!ms) return '-';
    var sec = Math.floor(ms / 1000);
    var min = Math.floor(sec / 60);
    var s = sec % 60;
    return min + ':' + (s < 10 ? '0' : '') + s;
  }

  /** Format ISO date string as "Mar 12, 2026". */
  function formatDate(iso) {
    if (!iso) return '-';
    var d = new Date(iso);
    return d.toLocaleDateString('en-US', Object.assign({ day: 'numeric', month: 'short', year: 'numeric' }, RendezVoxAPI.tzOpts()));
  }

  /** Format ISO timestamp as "HH:MM:SS". */
  function formatTime(iso) {
    if (!iso) return '-';
    var d = new Date(iso);
    return d.toLocaleTimeString([], Object.assign({ hour: '2-digit', minute: '2-digit', second: '2-digit' }, RendezVoxAPI.tzOpts()));
  }

  /** Format bytes as human-readable (e.g. "1.5 GB"). */
  function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
  }

  /** Format large numbers as K/M (e.g. 1500 -> "1.5K"). */
  function formatNumber(n) {
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
    return String(n);
  }

  /** Show a dismissible toast notification. */
  function showToast(msg, type) {
    var container = document.getElementById('toasts');
    if (!container) return;
    var toast = document.createElement('div');
    toast.className = 'toast toast-' + (type || 'success');
    toast.textContent = msg;
    container.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 4000);
  }

  return {
    escHtml: escHtml,
    escAttr: escAttr,
    formatDuration: formatDuration,
    formatDate: formatDate,
    formatTime: formatTime,
    formatBytes: formatBytes,
    formatNumber: formatNumber,
    showToast: showToast
  };
})();
