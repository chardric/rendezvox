/* ============================================================
   iRadio Admin — File Manager
   ============================================================ */
(function () {
  'use strict';

  // ── State ──────────────────────────────────────────────────
  var currentPath  = '/';
  var currentItems = [];       // [{type, name, path, size, file_count}]
  var selSet       = new Set();
  var lastClickIdx = -1;
  var clipboard    = null;     // {mode:'copy'|'cut', items:[{path,name,type}]}
  var ctxMenu      = null;
  var treeExpanded    = new Set(['/']);
  var treeData        = [];
  var SYSTEM_PATHS     = ['/tagged', '/_untagged', '/imports', '/upload'];
  var newFolderTarget  = null;  // overrides currentPath when creating from tree ctx menu
  var dragItems        = null;  // [{path,name,type}] — items being dragged
  var lasso            = { active: false, moved: false, startX: 0, startY: 0, el: null, origSel: null };
  var lassoWasDragged  = false;

  // ── DOM refs ────────────────────────────────────────────────
  var elContent, elBreadcrumb, elTree, elStatus;
  var elBtnNewFolder, elBtnCut, elBtnCopy, elBtnPaste, elBtnRename, elBtnDelete;

  // ── Bootstrap ───────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {
    if (!iRadioAuth.requireLogin()) return;
    iRadioNav.init();

    elContent      = document.getElementById('fm-content');
    elBreadcrumb   = document.getElementById('fm-breadcrumb');
    elTree         = document.getElementById('fm-tree');
    elStatus       = document.getElementById('fm-status');
    elBtnNewFolder = document.getElementById('btnNewFolder');
    elBtnCut       = document.getElementById('btnCut');
    elBtnCopy      = document.getElementById('btnCopy');
    elBtnPaste     = document.getElementById('btnPaste');
    elBtnRename    = document.getElementById('btnRename');
    elBtnDelete    = document.getElementById('btnDelete');

    elBtnNewFolder.addEventListener('click', cmdNewFolder);
    elBtnCut.addEventListener('click', cmdCut);
    elBtnCopy.addEventListener('click', cmdCopy);
    elBtnPaste.addEventListener('click', cmdPaste);
    elBtnRename.addEventListener('click', function () {
      var paths = Array.from(selSet);
      if (paths.length === 1) cmdRename(paths[0]);
    });
    elBtnDelete.addEventListener('click', cmdDelete);

    document.addEventListener('keydown', handleKeydown);
    document.addEventListener('click', function (e) {
      closeCtxMenu();
      if (e.target === elContent) {
        if (lassoWasDragged) { lassoWasDragged = false; return; }
        selSet.clear();
        lastClickIdx = -1;
        updateSelectionClasses();
        updateToolbar();
      }
    });

    document.addEventListener('mousemove', onLassoMove);
    document.addEventListener('mouseup',   onLassoUp);

    elContent.addEventListener('mousedown', startLasso);

    // Drop into current folder (background of content pane)
    elContent.addEventListener('dragover', function (e) {
      if (!dragItems || e.target.closest('.fm-item')) return;
      var valid = dragItems.some(function (di) {
        return parentPath(di.path) !== currentPath &&
          !(di.type === 'folder' && (currentPath === di.path || currentPath.startsWith(di.path + '/')));
      });
      if (!valid) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
    });
    elContent.addEventListener('drop', function (e) {
      if (e.target.closest('.fm-item') || !dragItems) return;
      e.preventDefault();
      doMove(dragItems, currentPath);
    });
    initNewFolderModal();
    loadTree();
    browse('/');
  });

  // ── Browse ──────────────────────────────────────────────────
  function browse(path) {
    currentPath = path || '/';
    selSet.clear();
    lastClickIdx = -1;
    elContent.style.opacity = '0.5';

    iRadioAPI.get('/admin/files/browse?path=' + encodeURIComponent(currentPath))
      .then(function (data) {
        currentItems = [];
        (data.folders || []).forEach(function (f) {
          currentItems.push({ type: 'folder', name: f.name, path: f.path, child_count: f.child_count || 0 });
        });
        (data.files || []).forEach(function (f) {
          currentItems.push({ type: 'file', name: f.name, path: f.path, size: f.size || 0 });
        });
        renderBreadcrumb(data.breadcrumbs || []);
        renderList();
        updateToolbar();
        highlightTreeNode(currentPath);
      })
      .catch(function (err) {
        elContent.innerHTML = '<div class="fm-empty" style="color:var(--danger)">Failed to load directory</div>';
        showToast((err && err.error) ? err.error : 'Failed to load directory', 'error');
      })
      .finally(function () { elContent.style.opacity = '1'; });
  }

  // ── Breadcrumb ──────────────────────────────────────────────
  function renderBreadcrumb(crumbs) {
    var html = '';
    crumbs.forEach(function (c, i) {
      if (i > 0) html += '<span class="bc-sep">/</span>';
      if (i === crumbs.length - 1) {
        html += '<span class="bc-current">' + esc(c.name) + '</span>';
      } else {
        html += '<a class="bc-link" data-path="' + esc(c.path) + '">' + esc(c.name) + '</a>';
      }
    });
    elBreadcrumb.innerHTML = html;
    elBreadcrumb.querySelectorAll('.bc-link').forEach(function (a) {
      a.addEventListener('click', function () { browse(this.dataset.path); });
    });
  }

  // ── File list ───────────────────────────────────────────────
  function renderList() {
    if (currentItems.length === 0) {
      elContent.innerHTML = '<div class="fm-empty">This folder is empty</div>';
      return;
    }

    var html = '<div class="fm-items">';
    currentItems.forEach(function (item, i) {
      var isSel = selSet.has(item.path);
      var isCut = clipboard && clipboard.mode === 'cut' &&
        clipboard.items.some(function (ci) { return ci.path === item.path; });
      var cls = 'fm-item' + (isSel ? ' selected' : '') + (isCut ? ' cut-mode' : '');
      var icon = item.type === 'folder' ? iconFolder() : iconFile();
      var meta = item.type === 'folder'
        ? (item.child_count + ' item' + (item.child_count !== 1 ? 's' : ''))
        : fmtSize(item.size);

      html += '<div class="' + cls + '" data-index="' + i + '" data-path="' + esc(item.path) + '">'
        + '<div class="item-icon' + (item.type === 'folder' ? ' folder-icon' : '') + '">' + icon + '</div>'
        + '<div class="item-name">' + esc(item.name) + '</div>'
        + '<div class="item-meta">' + esc(meta) + '</div>'
        + '<div class="item-actions">'
        + (isSystemPath(item.path) ? '' : '<button class="btn btn-ghost btn-sm" data-act="rename" title="Rename">' + iconEdit() + '</button>')
        + (isSystemPath(item.path) ? '' : '<button class="btn btn-sm" data-act="delete" title="Delete" style="color:var(--danger);border-color:transparent;background:transparent;">' + iconTrash() + '</button>')
        + '</div></div>';
    });
    html += '</div>';
    elContent.innerHTML = html;

    elContent.querySelectorAll('.fm-item').forEach(function (el) {
      var idx  = parseInt(el.dataset.index, 10);
      var item = currentItems[idx];

      el.addEventListener('click', function (e) {
        if (e.target.closest('[data-act]')) return;
        handleItemClick(e, idx);
      });

      el.addEventListener('dblclick', function (e) {
        if (e.target.closest('[data-act]')) return;
        if (item.type === 'folder') {
          treeExpanded.add(item.path);
          browse(item.path);
        }
      });

      el.addEventListener('contextmenu', function (e) {
        e.preventDefault();
        if (!selSet.has(item.path)) {
          selSet.clear();
          selSet.add(item.path);
          lastClickIdx = idx;
          updateSelectionClasses();
          updateToolbar();
        }
        showCtxMenu(e.clientX, e.clientY, item);
      });

      // ── Drag source ──
      el.draggable = true;
      el.addEventListener('dragstart', function (e) {
        if (!selSet.has(item.path)) {
          selSet.clear();
          selSet.add(item.path);
          lastClickIdx = idx;
          updateSelectionClasses();
          updateToolbar();
        }
        dragItems = getSelectedItems();
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', 'fm-drag');
        // Apply .dragging after ghost image is captured
        requestAnimationFrame(function () {
          elContent.querySelectorAll('.fm-item').forEach(function (it) {
            if (dragItems.some(function (di) { return di.path === it.dataset.path; })) {
              it.classList.add('dragging');
            }
          });
        });
      });

      el.addEventListener('dragend', function () {
        dragItems = null;
        elContent.querySelectorAll('.fm-item').forEach(function (it) {
          it.classList.remove('dragging', 'drag-over');
        });
      });

      // ── Drop target (folders only) ──
      if (item.type === 'folder') {
        el.addEventListener('dragover', function (e) {
          if (!dragItems) return;
          var invalid = dragItems.some(function (di) {
            return di.path === item.path || item.path.startsWith(di.path + '/');
          });
          if (invalid) return;
          e.preventDefault();
          e.dataTransfer.dropEffect = 'move';
          el.classList.add('drag-over');
        });
        el.addEventListener('dragleave', function (e) {
          if (!el.contains(e.relatedTarget)) el.classList.remove('drag-over');
        });
        el.addEventListener('drop', function (e) {
          e.preventDefault();
          el.classList.remove('drag-over');
          if (!dragItems) return;
          doMove(dragItems, item.path);
        });
      }

      var renameBtn = el.querySelector('[data-act="rename"]');
      if (renameBtn) {
        renameBtn.addEventListener('click', function (e) {
          e.stopPropagation();
          cmdRename(item.path);
        });
      }

      var deleteBtn = el.querySelector('[data-act="delete"]');
      if (deleteBtn) {
        deleteBtn.addEventListener('click', function (e) {
          e.stopPropagation();
          selSet.clear();
          selSet.add(item.path);
          updateToolbar();
          cmdDelete();
        });
      }
    });
  }

  // ── Selection ───────────────────────────────────────────────
  function handleItemClick(e, idx) {
    var path = currentItems[idx].path;
    if (e.ctrlKey || e.metaKey) {
      selSet.has(path) ? selSet.delete(path) : selSet.add(path);
      lastClickIdx = idx;
    } else if (e.shiftKey && lastClickIdx >= 0) {
      var lo = Math.min(lastClickIdx, idx);
      var hi = Math.max(lastClickIdx, idx);
      selSet.clear();
      for (var i = lo; i <= hi; i++) selSet.add(currentItems[i].path);
    } else {
      selSet.clear();
      selSet.add(path);
      lastClickIdx = idx;
    }
    updateSelectionClasses();
    updateToolbar();
  }

  // Update selected/cut-mode classes in-place — no DOM rebuild, preserves dblclick targets
  function updateSelectionClasses() {
    elContent.querySelectorAll('.fm-item').forEach(function (el) {
      var idx = parseInt(el.dataset.index, 10);
      if (isNaN(idx) || !currentItems[idx]) return;
      var path  = currentItems[idx].path;
      var isCut = clipboard && clipboard.mode === 'cut' &&
        clipboard.items.some(function (ci) { return ci.path === path; });
      el.classList.toggle('selected',  selSet.has(path));
      el.classList.toggle('cut-mode',  isCut);
    });
  }

  // ── Toolbar state ────────────────────────────────────────────
  function updateToolbar() {
    var n = selSet.size;
    elBtnCut.disabled    = n === 0;
    elBtnCopy.disabled   = n === 0;
    elBtnRename.disabled = n !== 1;
    elBtnDelete.disabled = n === 0;
    elBtnPaste.disabled  = !clipboard;

    var parts = [];
    if (n > 0) parts.push(n + ' selected');
    if (clipboard) {
      var cl = clipboard.items.length;
      parts.push((clipboard.mode === 'cut' ? 'Cut' : 'Copied') + ': ' + cl + ' item' + (cl !== 1 ? 's' : ''));
    }
    elStatus.textContent = parts.join(' · ');
  }

  // ── Commands ────────────────────────────────────────────────
  function cmdCut() {
    if (!selSet.size) return;
    clipboard = { mode: 'cut', items: getSelectedItems() };
    updateSelectionClasses();
    updateToolbar();
    showToast(clipboard.items.length + ' item(s) cut to clipboard');
  }

  function cmdCopy() {
    if (!selSet.size) return;
    clipboard = { mode: 'copy', items: getSelectedItems() };
    updateToolbar();
    showToast(clipboard.items.length + ' item(s) copied to clipboard');
  }

  function cmdSelectAll() {
    currentItems.forEach(function (item) { selSet.add(item.path); });
    lastClickIdx = currentItems.length - 1;
    updateSelectionClasses();
    updateToolbar();
  }

  function getSelectedItems() {
    return Array.from(selSet).map(function (p) {
      var item = currentItems.find(function (it) { return it.path === p; });
      return { path: p, name: item ? item.name : basename(p), type: item ? item.type : 'file' };
    });
  }

  function cmdPaste() {
    if (!clipboard) return;
    var items = clipboard.items;
    var mode  = clipboard.mode;
    var dest  = currentPath;

    // Filter out invalid items (pasting cut folder into itself or a descendant)
    var toPaste = items.filter(function (it) {
      if (mode === 'cut') {
        if (it.type === 'folder' && (dest === it.path || dest.startsWith(it.path + '/'))) return false;
        if (parentPath(it.path) === dest) return false;
      }
      return true;
    });

    if (toPaste.length === 0) {
      showToast('Nothing to paste here', 'error');
      return;
    }

    var endpoint = mode === 'copy' ? '/admin/media/copy' : '/admin/files/move';
    var promises = toPaste.map(function (it) {
      return iRadioAPI.post(endpoint, { path: it.path, destination: dest });
    });

    Promise.allSettled(promises).then(function (results) {
      var ok = 0, fail = 0, errMsg = '';
      results.forEach(function (r) {
        if (r.status === 'fulfilled') ok++;
        else { fail++; if (!errMsg && r.reason && r.reason.error) errMsg = r.reason.error; }
      });
      if (mode === 'cut' && ok > 0) clipboard = null;
      if (ok > 0)   showToast(ok + ' item(s) ' + (mode === 'cut' ? 'moved' : 'copied') + ' successfully', 'success');
      if (fail > 0) showToast(fail + ' item(s) failed' + (errMsg ? ': ' + errMsg : ''), 'error');
      loadTree();
      browse(currentPath);
    });
  }

  function cmdPasteInto(destPath) {
    if (!clipboard) return;
    var items = clipboard.items;
    var mode  = clipboard.mode;

    var toPaste = items.filter(function (it) {
      if (mode === 'cut') {
        if (it.type === 'folder' && (destPath === it.path || destPath.startsWith(it.path + '/'))) return false;
        if (parentPath(it.path) === destPath) return false;
      }
      return true;
    });

    if (toPaste.length === 0) { showToast('Nothing to paste here', 'error'); return; }

    var endpoint = mode === 'copy' ? '/admin/media/copy' : '/admin/files/move';
    var promises = toPaste.map(function (it) {
      return iRadioAPI.post(endpoint, { path: it.path, destination: destPath });
    });

    Promise.allSettled(promises).then(function (results) {
      var ok = 0, fail = 0, errMsg = '';
      results.forEach(function (r) {
        if (r.status === 'fulfilled') ok++;
        else { fail++; if (!errMsg && r.reason && r.reason.error) errMsg = r.reason.error; }
      });
      if (mode === 'cut' && ok > 0) clipboard = null;
      if (ok > 0)   showToast(ok + ' item(s) ' + (mode === 'cut' ? 'moved' : 'copied') + ' successfully', 'success');
      if (fail > 0) showToast(fail + ' item(s) failed' + (errMsg ? ': ' + errMsg : ''), 'error');
      updateToolbar();
      loadTree();
      browse(currentPath);
    });
  }

  function doMove(items, destPath) {
    var toPaste = items.filter(function (it) {
      if (it.type === 'folder' && (destPath === it.path || destPath.startsWith(it.path + '/'))) return false;
      if (parentPath(it.path) === destPath) return false;  // already here
      return true;
    });
    if (!toPaste.length) { showToast('Already in this folder', 'error'); return; }

    var promises = toPaste.map(function (it) {
      return iRadioAPI.post('/admin/files/move', { path: it.path, destination: destPath });
    });
    Promise.allSettled(promises).then(function (results) {
      var ok = 0, fail = 0, errMsg = '';
      results.forEach(function (r) {
        if (r.status === 'fulfilled') ok++;
        else { fail++; if (!errMsg && r.reason && r.reason.error) errMsg = r.reason.error; }
      });
      if (ok > 0) showToast(ok + ' item(s) moved', 'success');
      if (fail > 0) showToast(fail + ' failed' + (errMsg ? ': ' + errMsg : ''), 'error');
      // Clear moved items from clipboard if they were cut
      if (clipboard && clipboard.mode === 'cut') {
        var moved = toPaste.map(function (it) { return it.path; });
        clipboard.items = clipboard.items.filter(function (ci) { return !moved.includes(ci.path); });
        if (!clipboard.items.length) clipboard = null;
      }
      loadTree();
      browse(currentPath);
      updateToolbar();
    });
  }

  function cmdDeletePath(path, name) {
    iRadioAPI.get('/admin/files/delete-check?path=' + encodeURIComponent(path))
      .then(function (data) {
        var affected = data.playlists || [];
        var msg = 'Delete "' + name + '"?\nThis cannot be undone.';
        if (affected.length > 0) {
          msg += '\n\n\u26a0\ufe0f Warning: This folder was used to create the following playlist(s):\n\u2022 ' +
            affected.join('\n\u2022 ') +
            '\n\nDeleting it will remove all songs from those playlists and may affect the stream.';
        }
        return iRadioConfirm(msg, { title: 'Delete', okLabel: 'Delete', okClass: 'btn-danger' });
      })
      .then(function (ok) {
        if (!ok) return;
        iRadioAPI.del('/admin/files/delete?path=' + encodeURIComponent(path))
          .then(function () {
            showToast('Deleted successfully', 'success');
            if (clipboard) {
              clipboard.items = clipboard.items.filter(function (ci) {
                return ci.path !== path && !ci.path.startsWith(path + '/');
              });
              if (!clipboard.items.length) clipboard = null;
            }
            var navTo = (currentPath === path || currentPath.startsWith(path + '/')) ? parentPath(path) : currentPath;
            updateToolbar();
            loadTree();
            browse(navTo);
          })
          .catch(function (err) {
            showToast((err && err.error) ? err.error : 'Delete failed', 'error');
          });
      })
      .catch(function () {
        // Check failed — proceed with standard confirm
        iRadioConfirm('Delete "' + name + '"?\nThis cannot be undone.', { title: 'Delete', okLabel: 'Delete', okClass: 'btn-danger' })
          .then(function (ok) {
            if (!ok) return;
            iRadioAPI.del('/admin/files/delete?path=' + encodeURIComponent(path))
              .then(function () {
                showToast('Deleted successfully', 'success');
                var navTo = (currentPath === path || currentPath.startsWith(path + '/')) ? parentPath(path) : currentPath;
                updateToolbar();
                loadTree();
                browse(navTo);
              })
              .catch(function (err) {
                showToast((err && err.error) ? err.error : 'Delete failed', 'error');
              });
          });
      });
  }

  function cmdDelete() {
    if (!selSet.size) return;
    var allPaths = Array.from(selSet);

    // Silently skip system folders and warn if any were in the selection
    var sysPaths = allPaths.filter(isSystemPath);
    var paths    = allPaths.filter(function (p) { return !isSystemPath(p); });

    if (sysPaths.length > 0 && paths.length === 0) {
      showToast('System folders cannot be deleted.', 'error');
      return;
    }
    if (sysPaths.length > 0) {
      showToast(sysPaths.length + ' system folder(s) skipped.', 'error');
    }

    var names = paths.map(function (p) {
      var it = currentItems.find(function (x) { return x.path === p; });
      return it ? it.name : basename(p);
    });

    var folderPaths = paths.filter(function (p) {
      var it = currentItems.find(function (x) { return x.path === p; });
      return it && it.type === 'folder';
    });

    function doConfirmDelete(playlistWarning) {
      var msg = names.length === 1
        ? 'Delete "' + names[0] + '"?\nThis cannot be undone.'
        : 'Delete ' + names.length + ' items?\nThis cannot be undone.';
      if (playlistWarning) msg += '\n\n' + playlistWarning;

      iRadioConfirm(msg, { title: 'Delete', okLabel: 'Delete', okClass: 'btn-danger' }).then(function (ok) {
        if (!ok) return;
        var promises = paths.map(function (p) {
          return iRadioAPI.del('/admin/files/delete?path=' + encodeURIComponent(p));
        });
        Promise.allSettled(promises).then(function (results) {
          var done = 0, fail = 0;
          results.forEach(function (r) { if (r.status === 'fulfilled') done++; else fail++; });
          if (done > 0) showToast(done + ' item(s) deleted', 'success');
          if (fail > 0) showToast(fail + ' item(s) failed to delete', 'error');
          if (clipboard) {
            clipboard.items = clipboard.items.filter(function (ci) { return !paths.includes(ci.path); });
            if (!clipboard.items.length) clipboard = null;
          }
          loadTree();
          browse(currentPath);
        });
      });
    }

    if (folderPaths.length === 0) {
      doConfirmDelete('');
      return;
    }

    Promise.all(folderPaths.map(function (p) {
      return iRadioAPI.get('/admin/files/delete-check?path=' + encodeURIComponent(p));
    })).then(function (results) {
      var affected = [];
      results.forEach(function (r) {
        (r.playlists || []).forEach(function (name) {
          if (!affected.includes(name)) affected.push(name);
        });
      });
      var warning = affected.length > 0
        ? '\u26a0\ufe0f Warning: This folder was used to create the following playlist(s):\n\u2022 ' +
          affected.join('\n\u2022 ') +
          '\n\nDeleting it will remove all songs from those playlists and may affect the stream.'
        : '';
      doConfirmDelete(warning);
    }).catch(function () {
      doConfirmDelete('');
    });
  }

  function cmdRename(path) {
    if (isSystemPath(path)) { showToast('System folders cannot be renamed.', 'error'); return; }
    var item = currentItems.find(function (it) { return it.path === path; });
    if (!item) return;
    var idx    = currentItems.indexOf(item);
    var el     = elContent.querySelector('[data-index="' + idx + '"]');
    if (!el) return;

    var nameEl  = el.querySelector('.item-name');
    var oldName = item.name;
    var input   = document.createElement('input');
    input.type      = 'text';
    input.className = 'rename-input';
    input.value     = oldName;
    nameEl.textContent = '';
    nameEl.appendChild(input);
    input.focus();

    if (item.type === 'file') {
      var dot = oldName.lastIndexOf('.');
      input.setSelectionRange(0, dot > 0 ? dot : oldName.length);
    } else {
      input.select();
    }

    var committed = false;
    function commit() {
      if (committed) return;
      committed = true;
      var newName = input.value.trim();
      if (!newName || newName === oldName) { renderList(); return; }
      iRadioAPI.post('/admin/files/rename', { path: path, new_name: newName })
        .then(function () {
          showToast('Renamed successfully', 'success');
          loadTree();
          browse(currentPath);
        })
        .catch(function (err) {
          showToast((err && err.error) ? err.error : 'Rename failed', 'error');
          renderList();
        });
    }

    input.addEventListener('blur', commit);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter')  { e.preventDefault(); input.blur(); }
      if (e.key === 'Escape') { e.preventDefault(); committed = true; renderList(); }
      e.stopPropagation();
    });
  }

  function cmdRenameTree(path, oldName, labelEl) {
    if (!labelEl) return;
    var input = document.createElement('input');
    input.type      = 'text';
    input.className = 'rename-input';
    input.value     = oldName;
    labelEl.textContent = '';
    labelEl.appendChild(input);
    input.focus();
    input.select();

    var committed = false;
    function commit() {
      if (committed) return;
      committed = true;
      var newName = input.value.trim();
      if (!newName || newName === oldName) { labelEl.textContent = oldName; return; }
      iRadioAPI.post('/admin/files/rename', { path: path, new_name: newName })
        .then(function () {
          showToast('Renamed successfully', 'success');
          var parent  = parentPath(path);
          var newPath = (parent === '/' ? '/' : parent + '/') + newName;
          if (currentPath === path) {
            loadTree();
            browse(newPath);
          } else if (currentPath.startsWith(path + '/')) {
            loadTree();
            browse(newPath + currentPath.slice(path.length));
          } else {
            loadTree();
            browse(currentPath);
          }
        })
        .catch(function (err) {
          showToast((err && err.error) ? err.error : 'Rename failed', 'error');
          labelEl.textContent = oldName;
        });
    }

    input.addEventListener('blur', commit);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter')  { e.preventDefault(); input.blur(); }
      if (e.key === 'Escape') { e.preventDefault(); committed = true; labelEl.textContent = oldName; }
      e.stopPropagation();
    });
  }

  function cmdNewFolder() {
    document.getElementById('newFolderModal').style.display = 'flex';
    var input = document.getElementById('newFolderName');
    input.value = '';
    setTimeout(function () { input.focus(); }, 50);
  }

  // ── Context menu ────────────────────────────────────────────
  function showCtxMenu(x, y, item) {
    closeCtxMenu();
    var menu = document.createElement('div');
    menu.className = 'ctx-menu';
    menu.id = 'ctxMenu';

    var entries = [];
    if (item.type === 'folder') {
      entries.push({ label: 'Open', icon: iconOpen(), action: function () { treeExpanded.add(item.path); browse(item.path); } });
      entries.push({ sep: true });
    }
    var sys = isSystemPath(item.path);
    if (!sys) entries.push({ label: 'Rename', icon: iconEdit(), action: function () { cmdRename(item.path); } });
    entries.push({ label: 'Cut',  icon: iconCut(),  action: cmdCut });
    entries.push({ label: 'Copy', icon: iconCopy(), action: cmdCopy });
    if (clipboard) {
      entries.push({ label: 'Paste here', icon: iconPaste(), action: cmdPaste });
    }
    if (!sys) {
      entries.push({ sep: true });
      entries.push({ label: 'Delete', icon: iconTrash(), action: cmdDelete, cls: 'ctx-danger' });
    }

    entries.forEach(function (entry) {
      if (entry.sep) {
        var s = document.createElement('div');
        s.className = 'ctx-sep';
        menu.appendChild(s);
      } else {
        var el = document.createElement('div');
        el.className = 'ctx-item' + (entry.cls ? ' ' + entry.cls : '');
        el.innerHTML = '<span class="ctx-icon">' + (entry.icon || '') + '</span>' + esc(entry.label);
        el.addEventListener('click', function (e) {
          e.stopPropagation();
          closeCtxMenu();
          entry.action();
        });
        menu.appendChild(el);
      }
    });

    document.body.appendChild(menu);
    ctxMenu = menu;

    menu.style.left = x + 'px';
    menu.style.top  = y + 'px';
    requestAnimationFrame(function () {
      var r = menu.getBoundingClientRect();
      if (r.right  > window.innerWidth)  menu.style.left = (x - r.width)  + 'px';
      if (r.bottom > window.innerHeight) menu.style.top  = (y - r.height) + 'px';
    });
  }

  function closeCtxMenu() {
    if (ctxMenu) { ctxMenu.remove(); ctxMenu = null; }
  }

  function showTreeCtxMenu(x, y, tnEl) {
    closeCtxMenu();
    var path   = tnEl.dataset.path;
    var isRoot = path === '/';
    var isSys  = isSystemPath(path);
    var name   = (tnEl.querySelector('.fn-label') || {}).textContent || basename(path);
    var menu   = document.createElement('div');
    menu.className = 'ctx-menu';
    menu.id = 'ctxMenu';

    var entries = [];
    entries.push({ label: 'Open', icon: iconOpen(), action: function () {
      treeExpanded.add(path);
      browse(path);
    }});
    entries.push({ sep: true });
    entries.push({ label: 'New Folder here', icon: iconNewFolder(), action: function () {
      newFolderTarget = path;
      cmdNewFolder();
    }});
    if (!isRoot && !isSys) {
      entries.push({ sep: true });
      entries.push({ label: 'Rename', icon: iconEdit(), action: function () {
        cmdRenameTree(path, name, tnEl.querySelector('.fn-label'));
      }});
    }
    if (!isRoot) {
      entries.push({ label: 'Cut', icon: iconCut(), action: function () {
        clipboard = { mode: 'cut', items: [{ path: path, name: name, type: 'folder' }] };
        updateToolbar();
        showToast('1 item(s) cut to clipboard');
      }});
      entries.push({ label: 'Copy', icon: iconCopy(), action: function () {
        clipboard = { mode: 'copy', items: [{ path: path, name: name, type: 'folder' }] };
        updateToolbar();
        showToast('1 item(s) copied to clipboard');
      }});
    }
    if (clipboard) {
      entries.push({ label: 'Paste here', icon: iconPaste(), action: function () {
        cmdPasteInto(path);
      }});
    }
    if (!isRoot && !isSys) {
      entries.push({ sep: true });
      entries.push({ label: 'Delete', icon: iconTrash(), action: function () {
        cmdDeletePath(path, name);
      }, cls: 'ctx-danger' });
    }

    entries.forEach(function (entry) {
      if (entry.sep) {
        var s = document.createElement('div');
        s.className = 'ctx-sep';
        menu.appendChild(s);
      } else {
        var el = document.createElement('div');
        el.className = 'ctx-item' + (entry.cls ? ' ' + entry.cls : '');
        el.innerHTML = '<span class="ctx-icon">' + (entry.icon || '') + '</span>' + esc(entry.label);
        el.addEventListener('click', function (e) {
          e.stopPropagation();
          closeCtxMenu();
          entry.action();
        });
        menu.appendChild(el);
      }
    });

    document.body.appendChild(menu);
    ctxMenu = menu;
    menu.style.left = x + 'px';
    menu.style.top  = y + 'px';
    requestAnimationFrame(function () {
      var r = menu.getBoundingClientRect();
      if (r.right  > window.innerWidth)  menu.style.left = (x - r.width)  + 'px';
      if (r.bottom > window.innerHeight) menu.style.top  = (y - r.height) + 'px';
    });
  }

  // ── Folder tree ─────────────────────────────────────────────
  function loadTree() {
    iRadioAPI.get('/admin/files/tree')
      .then(function (data) {
        treeData = data.folders || [];
        renderTree();
      })
      .catch(function () {
        elTree.innerHTML = '<div style="padding:12px;font-size:.8rem;color:var(--danger)">Tree load failed</div>';
      });
  }

  function renderTree() {
    // Build hierarchy from flat list (using path as key)
    var nodeMap = {};
    var root    = null;

    treeData.forEach(function (f) {
      nodeMap[f.path] = { name: f.name, path: f.path, depth: f.depth, children: [] };
    });
    treeData.forEach(function (f) {
      if (f.path === '/') { root = nodeMap[f.path]; return; }
      var pp     = parentPath(f.path);
      var parent = nodeMap[pp] || root;
      if (parent) parent.children.push(nodeMap[f.path]);
    });

    if (!root) root = { name: 'Music', path: '/', depth: -1, children: [] };

    elTree.innerHTML = buildTreeHtml(root, true);

    elTree.querySelectorAll('.fm-tn').forEach(function (el) {
      var path = el.dataset.path;

      // Arrow click: toggle expand/collapse only (don't navigate)
      var arrEl = el.querySelector('.fn-arr');
      if (arrEl) {
        arrEl.addEventListener('click', function (e) {
          e.stopPropagation();
          var wrap = el.nextElementSibling;
          if (wrap && wrap.classList.contains('fm-tc')) {
            var open = wrap.classList.toggle('open');
            arrEl.classList.toggle('open', open);
            if (open) treeExpanded.add(path); else treeExpanded.delete(path);
          }
        });
      }

      // Row click: navigate to folder + expand if collapsed (never collapse)
      el.addEventListener('click', function (e) {
        e.stopPropagation();
        var wrap = el.nextElementSibling;
        if (wrap && wrap.classList.contains('fm-tc') && !wrap.classList.contains('open')) {
          wrap.classList.add('open');
          if (arrEl) arrEl.classList.add('open');
          treeExpanded.add(path);
        }
        browse(path);
      });

      el.addEventListener('contextmenu', function (e) {
        e.preventDefault();
        e.stopPropagation();
        showTreeCtxMenu(e.clientX, e.clientY, el);
      });

      // ── Tree drop target ──
      el.addEventListener('dragover', function (e) {
        if (!dragItems) return;
        var invalid = dragItems.some(function (di) {
          return di.path === path || path.startsWith(di.path + '/');
        });
        if (invalid) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        el.classList.add('drag-over');
      });
      el.addEventListener('dragleave', function (e) {
        if (!el.contains(e.relatedTarget)) el.classList.remove('drag-over');
      });
      el.addEventListener('drop', function (e) {
        e.preventDefault();
        el.classList.remove('drag-over');
        if (!dragItems) return;
        doMove(dragItems, path);
      });
    });

    highlightTreeNode(currentPath);
  }

  function buildTreeHtml(node, isRoot) {
    var hasChildren = node.children && node.children.length > 0;
    var isOpen      = treeExpanded.has(node.path);
    var arrow = hasChildren
      ? '<svg class="fn-arr' + (isOpen ? ' open' : '') + '" viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>'
      : '<span class="fn-spacer"></span>';
    var icon = isRoot
      ? '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>'
      : '<svg viewBox="0 0 24 24" width="14" height="14" fill="#f59e0b" stroke="none" style="flex-shrink:0"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>';
    var depth  = Math.max(0, (node.depth || 0) + 1);
    var indent = depth * 14;

    var html = '<div class="fm-tn" data-path="' + esc(node.path) + '" style="padding-left:' + (8 + indent) + 'px">'
      + arrow + icon + '<span class="fn-label">' + esc(node.name) + '</span>'
      + '</div>';

    if (hasChildren) {
      html += '<div class="fm-tc' + (isOpen ? ' open' : '') + '">';
      node.children.forEach(function (child) { html += buildTreeHtml(child, false); });
      html += '</div>';
    }
    return html;
  }

  function highlightTreeNode(path) {
    elTree.querySelectorAll('.fm-tn').forEach(function (el) {
      el.classList.toggle('active', el.dataset.path === path);
    });
    // Auto-expand ancestors of current path
    var parts = path.split('/').filter(Boolean);
    var acc = '';
    parts.forEach(function (part) {
      acc += '/' + part;
      var tn = elTree.querySelector('.fm-tn[data-path="' + acc + '"]');
      if (tn) {
        var tc = tn.nextElementSibling;
        if (tc && tc.classList.contains('fm-tc') && !tc.classList.contains('open')) {
          tc.classList.add('open');
          var arr = tn.querySelector('.fn-arr');
          if (arr) arr.classList.add('open');
          treeExpanded.add(acc);
        }
      }
    });
  }

  // ── New folder modal ─────────────────────────────────────────
  function initNewFolderModal() {
    var modal  = document.getElementById('newFolderModal');
    var input  = document.getElementById('newFolderName');
    var btnOk  = document.getElementById('btnCreateFolder');
    var btnCan = document.getElementById('btnCancelFolder');
    var btnX   = document.getElementById('btnCancelFolderX');

    function submit() {
      var name   = input.value.trim();
      if (!name) return;
      var target = newFolderTarget !== null ? newFolderTarget : currentPath;
      var path   = (target === '/' ? '' : target) + '/' + name;
      btnOk.disabled = true;
      btnOk.textContent = 'Creating…';
      iRadioAPI.post('/admin/media/mkdir', { path: path })
        .then(function () {
          modal.style.display = 'none';
          newFolderTarget = null;
          showToast('Folder created', 'success');
          treeExpanded.add(target);
          loadTree();
          browse(target);
        })
        .catch(function (err) {
          showToast((err && err.error) ? err.error : 'Failed to create folder', 'error');
        })
        .finally(function () {
          btnOk.disabled = false;
          btnOk.textContent = 'Create';
        });
    }

    function close() { modal.style.display = 'none'; newFolderTarget = null; }

    btnOk.addEventListener('click', submit);
    btnCan.addEventListener('click', close);
    btnX.addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter')  submit();
      if (e.key === 'Escape') { e.preventDefault(); close(); }
    });
  }

  // ── Rubber-band / lasso selection ───────────────────────────
  function startLasso(e) {
    if (e.button !== 0) return;
    if (e.target.closest('.fm-item')) return;  // item clicks handled separately
    lasso.active  = true;
    lasso.moved   = false;
    lasso.startX  = e.clientX;
    lasso.startY  = e.clientY;
    lasso.origSel = (e.ctrlKey || e.metaKey) ? new Set(selSet) : new Set();
    if (!e.ctrlKey && !e.metaKey) {
      selSet.clear();
      lastClickIdx = -1;
      updateSelectionClasses();
      updateToolbar();
    }
    lasso.el = document.createElement('div');
    lasso.el.className = 'fm-lasso';
    lasso.el.style.cssText = 'left:' + e.clientX + 'px;top:' + e.clientY + 'px;width:0;height:0';
    document.body.appendChild(lasso.el);
    e.preventDefault();
  }

  function onLassoMove(e) {
    if (!lasso.active) return;
    lasso.moved = true;
    var x1 = Math.min(e.clientX, lasso.startX);
    var y1 = Math.min(e.clientY, lasso.startY);
    var x2 = Math.max(e.clientX, lasso.startX);
    var y2 = Math.max(e.clientY, lasso.startY);
    lasso.el.style.cssText = 'left:' + x1 + 'px;top:' + y1 + 'px;width:' + (x2 - x1) + 'px;height:' + (y2 - y1) + 'px';

    var newSel = new Set(lasso.origSel);
    elContent.querySelectorAll('.fm-item').forEach(function (el) {
      var r   = el.getBoundingClientRect();
      var hit = r.left < x2 && r.right > x1 && r.top < y2 && r.bottom > y1;
      var idx = parseInt(el.dataset.index, 10);
      if (hit && !isNaN(idx) && currentItems[idx]) newSel.add(currentItems[idx].path);
    });

    selSet = newSel;
    // Update item classes in-place — no full re-render
    elContent.querySelectorAll('.fm-item').forEach(function (el) {
      var idx = parseInt(el.dataset.index, 10);
      if (!isNaN(idx) && currentItems[idx]) {
        el.classList.toggle('selected', selSet.has(currentItems[idx].path));
      }
    });
    updateToolbar();
  }

  function onLassoUp(e) {
    if (!lasso.active) return;
    lasso.active = false;
    if (lasso.el) { lasso.el.remove(); lasso.el = null; }
    if (lasso.moved) lassoWasDragged = true;
    updateToolbar();
  }

  // ── Keyboard shortcuts ───────────────────────────────────────
  function handleKeydown(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    var ctrl = e.ctrlKey || e.metaKey;
    if      (ctrl && e.key === 'a') { e.preventDefault(); cmdSelectAll(); }
    else if (ctrl && e.key === 'c') { e.preventDefault(); cmdCopy(); }
    else if (ctrl && e.key === 'x') { e.preventDefault(); cmdCut(); }
    else if (ctrl && e.key === 'v') { e.preventDefault(); cmdPaste(); }
    else if (e.key === 'Delete')    { e.preventDefault(); cmdDelete(); }
    else if (e.key === 'F2')        { e.preventDefault(); var p = Array.from(selSet); if (p.length === 1) cmdRename(p[0]); }
    else if (e.key === 'Escape')    { selSet.clear(); lastClickIdx = -1; updateSelectionClasses(); updateToolbar(); }
    else if (e.key === 'Backspace' && ctrl) { e.preventDefault(); browse(parentPath(currentPath)); }
  }

  // ── Helpers ──────────────────────────────────────────────────
  function isSystemPath(p) {
    return SYSTEM_PATHS.indexOf(p) !== -1;
  }

  function parentPath(p) {
    if (!p || p === '/') return '/';
    var last = p.lastIndexOf('/');
    return last <= 0 ? '/' : p.substring(0, last);
  }

  function basename(p) {
    return (p || '').split('/').pop() || '';
  }

  function fmtSize(bytes) {
    if (!bytes) return '—';
    var u = ['B', 'KB', 'MB', 'GB'];
    var i = Math.floor(Math.log(Math.max(1, bytes)) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(i ? 1 : 0) + '\u00a0' + u[i];
  }

  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function showToast(msg, type) {
    var c = document.getElementById('toasts');
    if (!c) return;
    var t = document.createElement('div');
    t.className = 'toast toast-' + (type || 'info');
    t.textContent = msg;
    c.appendChild(t);
    setTimeout(function () { t.remove(); }, 3000);
  }

  // ── SVG icons ────────────────────────────────────────────────
  function iconFolder() { return '<svg viewBox="0 0 24 24" width="18" height="18" fill="#f59e0b" stroke="none"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>'; }
  function iconFile()   { return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>'; }
  function iconEdit()   { return '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4z"/></svg>'; }
  function iconTrash()  { return '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>'; }
  function iconCut()    { return '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><circle cx="6" cy="20" r="2"/><circle cx="6" cy="4" r="2"/><line x1="6" y1="2" x2="6" y2="22"/><line x1="21" y1="2" x2="6" y2="22"/><line x1="21" y1="22" x2="6" y2="2"/></svg>'; }
  function iconCopy()   { return '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>'; }
  function iconPaste()  { return '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>'; }
  function iconOpen()      { return '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>'; }
  function iconNewFolder() { return '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>'; }

})();
