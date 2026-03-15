/* ============================================================
   RendezVox Admin — Shared Confirm Modal
   Replaces native confirm()/prompt() which can be blocked
   by browsers ("Prevent this page from creating dialogs").
   ============================================================ */
var RendezVoxConfirm = (function() {

  var modal, msgEl, btnCancel, btnOk, resolveFn;

  function ensureDOM() {
    if (modal) return;
    modal = document.createElement('div');
    modal.className = 'modal-backdrop hidden';
    modal.id = 'confirmModal';
    modal.innerHTML =
      '<div class="modal" style="max-width:400px">' +
        '<h3 id="confirmTitle">Confirm</h3>' +
        '<p id="confirmMsg" style="margin:12px 0;color:var(--text-dim);white-space:pre-line"></p>' +
        '<div class="modal-actions">' +
          '<button type="button" class="btn btn-ghost" id="confirmCancel">Cancel</button>' +
          '<button type="button" class="btn btn-danger" id="confirmOk">OK</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modal);

    msgEl     = document.getElementById('confirmMsg');
    btnCancel = document.getElementById('confirmCancel');
    btnOk     = document.getElementById('confirmOk');

    btnCancel.addEventListener('click', function() { close(false); });
    btnOk.addEventListener('click', function() { close(true); });
    document.addEventListener('keydown', function(e) {
      if (modal.classList.contains('hidden')) return;
      if (e.key === 'Escape') close(false);
      if (e.key === 'Enter')  close(true);
    });
  }

  function close(result) {
    modal.classList.add('hidden');
    if (resolveFn) { resolveFn(result); resolveFn = null; }
  }

  /**
   * Show a confirm dialog. Returns a Promise<boolean>.
   * Options: { title, okLabel, okClass, cancelLabel }
   */
  function show(message, options) {
    ensureDOM();
    var opts = options || {};
    document.getElementById('confirmTitle').textContent = opts.title || 'Confirm';
    msgEl.textContent = message;
    btnOk.textContent = opts.okLabel || 'OK';
    btnOk.className = 'btn ' + (opts.okClass || 'btn-danger');
    btnCancel.textContent = opts.cancelLabel || 'Cancel';
    var inputEl = document.getElementById('confirmInput');
    if (inputEl) inputEl.style.display = 'none';
    modal.classList.remove('hidden');
    btnOk.focus();
    return new Promise(function(resolve) { resolveFn = resolve; });
  }

  /**
   * Show a prompt dialog with text input. Returns Promise<string|null>.
   * Options: { title, okLabel, okClass, cancelLabel, placeholder, value }
   */
  function showPrompt(message, options) {
    ensureDOM();
    var opts = options || {};

    // Inject input if not present
    var inputEl = document.getElementById('confirmInput');
    if (!inputEl) {
      inputEl = document.createElement('input');
      inputEl.type = 'text';
      inputEl.id = 'confirmInput';
      inputEl.style.cssText = 'width:100%;margin-top:8px';
      msgEl.parentNode.insertBefore(inputEl, msgEl.nextSibling);
    }
    inputEl.style.display = '';
    inputEl.placeholder = opts.placeholder || '';
    inputEl.value = opts.value || '';

    document.getElementById('confirmTitle').textContent = opts.title || 'Input';
    msgEl.textContent = message;
    btnOk.textContent = opts.okLabel || 'OK';
    btnOk.className = 'btn ' + (opts.okClass || 'btn-primary');
    btnCancel.textContent = opts.cancelLabel || 'Cancel';
    modal.classList.remove('hidden');
    inputEl.focus();
    inputEl.select();

    return new Promise(function(resolve) {
      resolveFn = function(ok) {
        var val = inputEl.value.trim();
        inputEl.style.display = 'none';
        resolve(ok && val ? val : null);
      };
    });
  }

  show.prompt = showPrompt;

  return show;
})();
