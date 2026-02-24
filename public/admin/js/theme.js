/* ============================================================
   iRadio Admin — Theme Engine
   Applies CSS custom-property overrides on <html>.
   Loads synchronously before page paint to prevent FOUC.
   ============================================================ */
var iRadioTheme = (function () {

  var THEMES = {
    dark:        { label: 'Dark',            group: 'Dark',  vars: { '--bg':'#0a0a0c','--bg-card':'#141416','--bg-input':'#0e0e10','--bg-sidebar':'#101012','--border':'#232326','--text':'#d4d4d8','--text-dim':'#71717a','--text-heading':'#fafafa','--accent':'#00c8a0','--accent-hover':'#00e6b8','--hover-overlay':'rgba(255,255,255,0.08)' }},
    midnight:    { label: 'Midnight Blue',   group: 'Dark',  vars: { '--bg':'#0b0e1a','--bg-card':'#111827','--bg-input':'#0d1017','--bg-sidebar':'#0f1322','--border':'#1e293b','--text':'#cbd5e1','--text-dim':'#64748b','--text-heading':'#f1f5f9','--accent':'#3b82f6','--accent-hover':'#60a5fa','--hover-overlay':'rgba(255,255,255,0.08)' }},
    earth:       { label: 'Warm Earth',      group: 'Dark',  vars: { '--bg':'#1a1410','--bg-card':'#231c15','--bg-input':'#171210','--bg-sidebar':'#1e1712','--border':'#3d3228','--text':'#d4c8b8','--text-dim':'#8b7d6b','--text-heading':'#f5ebe0','--accent':'#d97706','--accent-hover':'#f59e0b','--hover-overlay':'rgba(255,255,255,0.08)' }},
    sunset:      { label: 'Sunset',          group: 'Dark',  vars: { '--bg':'#1a0e0e','--bg-card':'#231414','--bg-input':'#170d0d','--bg-sidebar':'#1e1010','--border':'#3d2828','--text':'#e0c8c8','--text-dim':'#8b6b6b','--text-heading':'#fae8e8','--accent':'#f97316','--accent-hover':'#fb923c','--hover-overlay':'rgba(255,255,255,0.08)' }},
    nord:        { label: 'Nord',            group: 'Dark',  vars: { '--bg':'#2e3440','--bg-card':'#3b4252','--bg-input':'#2e3440','--bg-sidebar':'#2e3440','--border':'#434c5e','--text':'#d8dee9','--text-dim':'#7b88a1','--text-heading':'#eceff4','--accent':'#88c0d0','--accent-hover':'#8fbcbb','--hover-overlay':'rgba(255,255,255,0.08)' }},
    dracula:     { label: 'Dracula',         group: 'Dark',  vars: { '--bg':'#282a36','--bg-card':'#2d2f3d','--bg-input':'#21222c','--bg-sidebar':'#21222c','--border':'#44475a','--text':'#f8f8f2','--text-dim':'#6272a4','--text-heading':'#f8f8f2','--accent':'#bd93f9','--accent-hover':'#caa6fc','--hover-overlay':'rgba(255,255,255,0.08)' }},
    monokai:     { label: 'Monokai',         group: 'Dark',  vars: { '--bg':'#272822','--bg-card':'#2e2f2a','--bg-input':'#22231e','--bg-sidebar':'#1e1f1a','--border':'#3e3d32','--text':'#f8f8f2','--text-dim':'#75715e','--text-heading':'#f8f8f2','--accent':'#a6e22e','--accent-hover':'#b8f340','--hover-overlay':'rgba(255,255,255,0.08)' }},
    tokyonight:  { label: 'Tokyo Night',     group: 'Dark',  vars: { '--bg':'#1a1b26','--bg-card':'#1f2335','--bg-input':'#16161e','--bg-sidebar':'#16161e','--border':'#292e42','--text':'#a9b1d6','--text-dim':'#565f89','--text-heading':'#c0caf5','--accent':'#7aa2f7','--accent-hover':'#89b4fa','--hover-overlay':'rgba(255,255,255,0.08)' }},
    onedark:     { label: 'One Dark',        group: 'Dark',  vars: { '--bg':'#282c34','--bg-card':'#2c313a','--bg-input':'#21252b','--bg-sidebar':'#21252b','--border':'#3e4451','--text':'#abb2bf','--text-dim':'#5c6370','--text-heading':'#e6e8ed','--accent':'#61afef','--accent-hover':'#74baff','--hover-overlay':'rgba(255,255,255,0.08)' }},
    rosepine:    { label: 'Rosé Pine',       group: 'Dark',  vars: { '--bg':'#191724','--bg-card':'#1f1d2e','--bg-input':'#15131f','--bg-sidebar':'#1f1d2e','--border':'#26233a','--text':'#e0def4','--text-dim':'#6e6a86','--text-heading':'#e0def4','--accent':'#c4a7e7','--accent-hover':'#d4bbf0','--hover-overlay':'rgba(255,255,255,0.08)' }},
    catmocha:    { label: 'Catppuccin Mocha', group: 'Dark', vars: { '--bg':'#1e1e2e','--bg-card':'#24243b','--bg-input':'#181825','--bg-sidebar':'#181825','--border':'#313244','--text':'#cdd6f4','--text-dim':'#6c7086','--text-heading':'#cdd6f4','--accent':'#cba6f7','--accent-hover':'#d7b8fb','--hover-overlay':'rgba(255,255,255,0.08)' }},
    gruvdark:    { label: 'Gruvbox Dark',    group: 'Dark',  vars: { '--bg':'#282828','--bg-card':'#3c3836','--bg-input':'#1d2021','--bg-sidebar':'#1d2021','--border':'#504945','--text':'#ebdbb2','--text-dim':'#928374','--text-heading':'#fbf1c7','--accent':'#fabd2f','--accent-hover':'#fbd54a','--hover-overlay':'rgba(255,255,255,0.08)' }},
    solarized:   { label: 'Solarized Dark',  group: 'Dark',  vars: { '--bg':'#002b36','--bg-card':'#073642','--bg-input':'#002028','--bg-sidebar':'#073642','--border':'#094e5a','--text':'#93a1a1','--text-dim':'#586e75','--text-heading':'#eee8d5','--accent':'#2aa198','--accent-hover':'#35bdb4','--hover-overlay':'rgba(255,255,255,0.08)' }},
    cyberpunk:   { label: 'Cyberpunk',       group: 'Dark',  vars: { '--bg':'#0a0a12','--bg-card':'#12121e','--bg-input':'#08080f','--bg-sidebar':'#0e0e18','--border':'#2a1a3e','--text':'#e0d0f0','--text-dim':'#7a6a8a','--text-heading':'#f0e0ff','--accent':'#ff00ff','--accent-hover':'#ff44ff','--hover-overlay':'rgba(255,255,255,0.08)' }},
    forest:      { label: 'Forest',          group: 'Dark',  vars: { '--bg':'#0e1a0e','--bg-card':'#142014','--bg-input':'#0b150b','--bg-sidebar':'#101c10','--border':'#1e3a1e','--text':'#b8d4b8','--text-dim':'#6b8a6b','--text-heading':'#e0f0e0','--accent':'#4caf50','--accent-hover':'#66bb6a','--hover-overlay':'rgba(255,255,255,0.08)' }},
    ocean:       { label: 'Ocean',           group: 'Dark',  vars: { '--bg':'#0a1628','--bg-card':'#0f1e35','--bg-input':'#081220','--bg-sidebar':'#0c1a30','--border':'#1a3050','--text':'#b0c8e0','--text-dim':'#607890','--text-heading':'#e0f0ff','--accent':'#00b4d8','--accent-hover':'#22c8e8','--hover-overlay':'rgba(255,255,255,0.08)' }},
    cherry:      { label: 'Cherry Blossom',  group: 'Dark',  vars: { '--bg':'#1a0e14','--bg-card':'#22141c','--bg-input':'#160c10','--bg-sidebar':'#1c1018','--border':'#3a2030','--text':'#e0c0d0','--text-dim':'#8a6078','--text-heading':'#f8e0f0','--accent':'#e91e63','--accent-hover':'#f06292','--hover-overlay':'rgba(255,255,255,0.08)' }},
    light:       { label: 'Light',           group: 'Light', vars: { '--bg':'#f4f4f5','--bg-card':'#ffffff','--bg-input':'#e8e8eb','--bg-sidebar':'#ffffff','--border':'#d4d4d8','--text':'#3f3f46','--text-dim':'#71717a','--text-heading':'#18181b','--accent':'#0d9488','--accent-hover':'#0f766e','--hover-overlay':'rgba(0,0,0,0.06)' }},
    catlatte:    { label: 'Catppuccin Latte', group: 'Light', vars: { '--bg':'#eff1f5','--bg-card':'#ffffff','--bg-input':'#e6e9ef','--bg-sidebar':'#e6e9ef','--border':'#ccd0da','--text':'#4c4f69','--text-dim':'#7c7f93','--text-heading':'#1e1e2e','--accent':'#8839ef','--accent-hover':'#9a4eff','--hover-overlay':'rgba(0,0,0,0.06)' }},
    gruvlight:   { label: 'Gruvbox Light',   group: 'Light', vars: { '--bg':'#fbf1c7','--bg-card':'#ffffff','--bg-input':'#f2e5bc','--bg-sidebar':'#f2e5bc','--border':'#d5c4a1','--text':'#3c3836','--text-dim':'#7c6f64','--text-heading':'#282828','--accent':'#d65d0e','--accent-hover':'#e8700a','--hover-overlay':'rgba(0,0,0,0.06)' }},
    sollight:    { label: 'Solarized Light',  group: 'Light', vars: { '--bg':'#fdf6e3','--bg-card':'#ffffff','--bg-input':'#eee8d5','--bg-sidebar':'#eee8d5','--border':'#d1cbb8','--text':'#586e75','--text-dim':'#839496','--text-heading':'#073642','--accent':'#268bd2','--accent-hover':'#3a9de0','--hover-overlay':'rgba(0,0,0,0.06)' }},
    rosedawn:    { label: 'Rosé Pine Dawn',  group: 'Light', vars: { '--bg':'#faf4ed','--bg-card':'#fffaf3','--bg-input':'#f2e9e1','--bg-sidebar':'#f2e9e1','--border':'#dfdad9','--text':'#575279','--text-dim':'#9893a5','--text-heading':'#26233a','--accent':'#d7827e','--accent-hover':'#e09490','--hover-overlay':'rgba(0,0,0,0.06)' }}
  };

  var VAR_KEYS = Object.keys(THEMES.dark.vars);
  var root = document.documentElement;

  function apply(name) {
    var theme = THEMES[name];
    if (!theme) name = 'dark', theme = THEMES.dark;

    // Clear all overrides first
    VAR_KEYS.forEach(function (k) { root.style.removeProperty(k); });

    // Apply theme variables (skip for dark — it's the CSS default)
    if (name !== 'dark') {
      Object.keys(theme.vars).forEach(function (k) {
        root.style.setProperty(k, theme.vars[k]);
      });
    }

    // Re-apply custom accent if set
    var accent = localStorage.getItem('iradio_accent');
    if (accent) applyAccent(accent, theme);

    localStorage.setItem('iradio_theme', name);
  }

  function applyAccent(hex, theme) {
    if (!hex || !/^#[0-9a-fA-F]{6}$/.test(hex)) return;
    root.style.setProperty('--accent', hex);
    root.style.setProperty('--accent-hover', lighten(hex, 18));
    if (!theme) {
      var name = localStorage.getItem('iradio_theme') || 'dark';
      theme = THEMES[name] || THEMES.dark;
    }
  }

  function setAccent(hex) {
    if (!hex || !/^#[0-9a-fA-F]{6}$/.test(hex)) return;
    localStorage.setItem('iradio_accent', hex);
    applyAccent(hex);
  }

  function clearAccent() {
    localStorage.removeItem('iradio_accent');
    apply(localStorage.getItem('iradio_theme') || 'dark');
  }

  function current() {
    return localStorage.getItem('iradio_theme') || 'dark';
  }

  function accent() {
    return localStorage.getItem('iradio_accent') || null;
  }

  function list() {
    return THEMES;
  }

  // Lighten a hex color by a percentage
  function lighten(hex, pct) {
    var r = parseInt(hex.slice(1, 3), 16);
    var g = parseInt(hex.slice(3, 5), 16);
    var b = parseInt(hex.slice(5, 7), 16);
    r = Math.min(255, Math.round(r + (255 - r) * pct / 100));
    g = Math.min(255, Math.round(g + (255 - g) * pct / 100));
    b = Math.min(255, Math.round(b + (255 - b) * pct / 100));
    return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
  }

  // Auto-apply on load (synchronous, before paint)
  apply(localStorage.getItem('iradio_theme') || 'dark');

  return {
    apply:       apply,
    setAccent:   setAccent,
    clearAccent: clearAccent,
    current:     current,
    accent:      accent,
    list:        list
  };

})();
