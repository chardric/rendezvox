'use strict';

const { app, BrowserWindow, Tray, Menu, shell, nativeTheme, nativeImage, globalShortcut, ipcMain, net } = require('electron');
const path = require('path');
const fs = require('fs');

// Single instance lock — prevent duplicate windows
const gotLock = app.requestSingleInstanceLock();
if (!gotLock) {
  app.quit();
}

nativeTheme.themeSource = 'dark';

let win = null;
let tray = null;
let isQuitting = false;
const startHidden = process.argv.includes('--hidden');

const iconPath = path.join(__dirname, 'src', 'icons', 'icon.png');

function hideWindow() {
  if (!win) return;
  win.hide();
  updateTrayMenu();
}

function showWindow() {
  if (!win) {
    createWindow();
  } else {
    win.show();
    win.focus();
  }
  updateTrayMenu();
}

function createWindow() {
  win = new BrowserWindow({
    width: 420,
    height: 560,
    minWidth: 420,
    minHeight: 560,
    resizable: false,
    maximizable: false,
    backgroundColor: '#0F0F0F',
    autoHideMenuBar: true,
    icon: iconPath,
    skipTaskbar: false,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true
    }
  });

  win.setMenu(null);
  win.loadFile(path.join(__dirname, 'src', 'index.html'));



  win.webContents.setWindowOpenHandler(({ url }) => {
    shell.openExternal(url);
    return { action: 'deny' };
  });

  // Minimize to tray
  win.on('minimize', (e) => {
    e.preventDefault();
    setImmediate(() => hideWindow());
  });

  // Close hides to tray (unless actually quitting)
  win.on('close', (e) => {
    if (!isQuitting) {
      e.preventDefault();
      setImmediate(() => hideWindow());
    }
  });

  win.on('closed', () => {
    win = null;
  });
}

function createTray() {
  const icon = nativeImage.createFromPath(iconPath).resize({ width: 22, height: 22 });
  tray = new Tray(icon);
  tray.setToolTip('RendezVox');

  updateTrayMenu();

  tray.on('click', () => showWindow());
  tray.on('double-click', () => showWindow());
}

function updateTrayMenu() {
  if (!tray) return;
  const isVisible = win !== null && win.isVisible();
  const menu = Menu.buildFromTemplate([
    {
      label: isVisible ? 'Hide RendezVox' : 'Show RendezVox',
      click: () => {
        if (isVisible) {
          hideWindow();
        } else {
          showWindow();
        }
      }
    },
    { type: 'separator' },
    {
      label: 'Quit',
      click: () => {
        isQuitting = true;
        app.quit();
      }
    }
  ]);
  tray.setContextMenu(menu);
}

// ── Autostart IPC (platform-specific) ────────────────────
function getAutostartDesktopPath() {
  const dir = path.join(app.getPath('home'), '.config', 'autostart');
  return path.join(dir, 'rendezvox.desktop');
}

function getAutostartEnabled() {
  if (process.platform === 'linux') {
    return fs.existsSync(getAutostartDesktopPath());
  }
  // macOS + Windows: Electron's built-in API
  return app.getLoginItemSettings().openAtLogin;
}

function setAutostartEnabled(enabled) {
  if (process.platform === 'linux') {
    const desktopPath = getAutostartDesktopPath();
    if (enabled) {
      const dir = path.dirname(desktopPath);
      if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
      // Use process.execPath for both DEB and AppImage installs
      const execPath = process.execPath;
      const content = [
        '[Desktop Entry]',
        'Type=Application',
        'Name=RendezVox',
        'Comment=RendezVox — Online FM Radio Player',
        `Exec="${execPath}" --hidden`,
        'Icon=rendezvox',
        'Terminal=false',
        'Categories=AudioVideo;',
        'X-GNOME-Autostart-enabled=true',
        ''
      ].join('\n');
      fs.writeFileSync(desktopPath, content);
    } else {
      try { fs.unlinkSync(desktopPath); } catch (_) {}
    }
    return enabled;
  }
  // macOS + Windows: Electron's built-in API
  app.setLoginItemSettings({
    openAtLogin: enabled,
    args: enabled ? ['--hidden'] : []
  });
  return app.getLoginItemSettings().openAtLogin;
}

ipcMain.handle('get-autostart', () => {
  return getAutostartEnabled();
});

ipcMain.handle('set-autostart', (_event, enabled) => {
  return setAutostartEnabled(enabled);
});

ipcMain.handle('check-update', async (_event, baseUrl) => {
  try {
    const url = `${baseUrl}/api/version`;
    const response = await net.fetch(url, { signal: AbortSignal.timeout(5000) });
    if (!response.ok) return null;
    return await response.json();
  } catch (_) {
    return null;
  }
});

// ── Second instance handler — show window if user launches again ──
app.on('second-instance', () => showWindow());

app.whenReady().then(() => {
  createTray();

  if (startHidden) {
    // Launched at login — stay in tray, don't show window
  } else {
    createWindow();
  }

  // Media key bindings (silently skip if DE already owns them)
  try { globalShortcut.register('MediaPlayPause', () => { if (win) win.webContents.send('media-key', 'toggle'); }); } catch (_) {}
  try { globalShortcut.register('MediaStop', () => { if (win) win.webContents.send('media-key', 'stop'); }); } catch (_) {}

  app.on('activate', () => showWindow());
});

app.on('window-all-closed', () => {
  // Keep running in tray
});

app.on('before-quit', () => {
  isQuitting = true;
  globalShortcut.unregisterAll();
});
