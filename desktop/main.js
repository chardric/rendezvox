'use strict';

const { app, BrowserWindow, Tray, Menu, shell, nativeTheme, nativeImage, globalShortcut, ipcMain } = require('electron');
const path = require('path');

nativeTheme.themeSource = 'dark';

let win = null;
let tray = null;
let isQuitting = false;

const iconPath = path.join(__dirname, 'src', 'icons', 'icon.png');

function showWindow() {
  if (!win) return;
  win.show();
  win.restore();
  win.focus();
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

  win.loadFile(path.join(__dirname, 'src', 'index.html'));

  win.webContents.setWindowOpenHandler(({ url }) => {
    shell.openExternal(url);
    return { action: 'deny' };
  });

  // Minimize to tray
  win.on('minimize', (e) => {
    e.preventDefault();
    win.hide();
  });

  // Close hides to tray (unless actually quitting)
  win.on('close', (e) => {
    if (!isQuitting) {
      e.preventDefault();
      win.hide();
    }
  });

  win.on('closed', () => {
    win = null;
  });

  // Media key bindings
  globalShortcut.register('MediaPlayPause', () => {
    if (win) win.webContents.send('media-key', 'toggle');
  });
  globalShortcut.register('MediaStop', () => {
    if (win) win.webContents.send('media-key', 'stop');
  });
}

function createTray() {
  const icon = nativeImage.createFromPath(iconPath).resize({ width: 22, height: 22 });
  tray = new Tray(icon);
  tray.setToolTip('RendezVox');

  updateTrayMenu();

  // Left-click: toggle window (may not work on all Linux DEs)
  tray.on('click', () => showWindow());
  tray.on('double-click', () => showWindow());
}

function updateTrayMenu() {
  if (!tray) return;
  const isVisible = win && win.isVisible();
  const menu = Menu.buildFromTemplate([
    {
      label: isVisible ? 'Hide RendezVox' : 'Show RendezVox',
      click: () => {
        if (win && win.isVisible()) {
          win.hide();
        } else {
          showWindow();
        }
        updateTrayMenu();
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

// ── Autostart IPC ────────────────────────────────────────
ipcMain.handle('get-autostart', () => {
  return app.getLoginItemSettings().openAtLogin;
});

ipcMain.handle('set-autostart', (_event, enabled) => {
  app.setLoginItemSettings({ openAtLogin: enabled });
  return app.getLoginItemSettings().openAtLogin;
});

app.whenReady().then(() => {
  createTray();
  createWindow();

  // Update tray menu label when window visibility changes
  if (win) {
    win.on('show', () => updateTrayMenu());
    win.on('hide', () => updateTrayMenu());
  }

  app.on('activate', () => {
    if (!win) createWindow();
    else showWindow();
  });
});

app.on('window-all-closed', () => {
  // Keep running in tray
});

app.on('before-quit', () => {
  isQuitting = true;
  globalShortcut.unregisterAll();
});
