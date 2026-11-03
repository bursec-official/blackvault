/* =====================================================================
   Black Vault — appearance settings (shared by MiniDrive and SysMonitor)
   Loaded in <head>: applies the saved theme and accent BEFORE the page
   paints, and builds the "Appearance" window when it is opened.
   No inline scripts, so the Content-Security-Policy can forbid them.
   ===================================================================== */
(() => {
  'use strict';

  const KEY = 'bv-prefs-v2';
  const SWATCHES = [
    { name: 'Champagne', hex: '#c9a96e' },
    { name: 'Platinum', hex: '#c7cdd6' },
    { name: 'Sapphire', hex: '#6f9de0' },
    { name: 'Emerald', hex: '#5fbf9a' },
    { name: 'Rose gold', hex: '#d49a8c' },
    { name: 'Amethyst', hex: '#a68ad9' },
    { name: 'Crimson', hex: '#d5665b' },
  ];
  const DEFAULTS = { theme: 'dark', color: '#c9a96e', glow: 45 };

  const root = document.documentElement;

  function load() {
    try {
      const p = JSON.parse(localStorage.getItem(KEY) || 'null');
      if (p && typeof p === 'object') {
        return {
          theme: p.theme === 'light' ? 'light' : 'dark',
          color: /^#[0-9a-f]{6}$/i.test(p.color) ? p.color.toLowerCase() : DEFAULTS.color,
          glow: Number.isFinite(p.glow) ? Math.max(0, Math.min(100, p.glow)) : DEFAULTS.glow,
        };
      }
    } catch { /* no storage available: defaults */ }
    return { ...DEFAULTS };
  }
  function save(p) { try { localStorage.setItem(KEY, JSON.stringify(p)); } catch { /* ignore */ } }

  // ---- One hex colour -> every accent token ----
  const toRgb = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
  const toHex = (r) => '#' + r.map((v) => Math.round(Math.max(0, Math.min(255, v))).toString(16).padStart(2, '0')).join('');
  const mix = (a, b, t) => a.map((v, i) => v + (b[i] - v) * t);
  const lum = ([r, g, b]) => {
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
  };

  function apply(p) {
    const rgb = toRgb(p.color);
    const light = p.theme === 'light';
    const k = p.glow / 100;
    const set = (n, v) => root.style.setProperty(n, v);
    root.dataset.theme = light ? 'light' : 'dark';
    set('--acc', p.color);
    set('--acc-strong', toHex(mix(rgb, [255, 255, 255], 0.32)));
    // Accent used as text/icons: lifted on dark, deepened on light so it stays readable
    set('--acc-text', light ? toHex(mix(rgb, [0, 0, 0], 0.38)) : toHex(mix(rgb, [255, 255, 255], 0.12)));
    // Text on top of the accent: dark when the accent is light, and vice versa
    set('--acc-ink', lum(rgb) > 0.3 ? '#15110a' : '#ffffff');
    set('--acc-soft', `rgba(${rgb}, ${light ? 0.12 : 0.10})`);
    set('--acc-line', `rgba(${rgb}, ${light ? 0.45 : 0.32})`);
    set('--acc-glow', `rgba(${rgb}, ${(0.16 * k).toFixed(3)})`);
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.content = light ? '#f5f3ef' : '#0c0f13';
  }

  let prefs = load();
  apply(prefs);

  // ---------------- Appearance window ----------------
  let modal = null;
  let lastFocus = null;

  const svg = (id) => `<svg class="ic" aria-hidden="true"><use href="#i-${id}"/></svg>`;

  function build() {
    const m = document.createElement('div');
    m.className = 'modal-overlay';
    m.hidden = true;
    m.innerHTML = `
      <div class="modal-card prefs-card" role="dialog" aria-modal="true" aria-labelledby="prefs-title">
        <div class="modal-head">
          <h3 id="prefs-title">Appearance</h3>
          <button type="button" class="icon-btn" data-close aria-label="Close">${svg('x')}</button>
        </div>
        <p class="prefs-hint">Saved on this browser. Applies to Drive and Monitor.</p>

        <div class="prefs-section">
          <div class="prefs-label">Theme</div>
          <div class="modes" role="radiogroup" aria-label="Theme">
            <button type="button" class="mode" data-theme-opt="dark" role="radio"><span class="mode-pv dark"><i></i><i></i></span>Midnight</button>
            <button type="button" class="mode" data-theme-opt="light" role="radio"><span class="mode-pv light"><i></i><i></i></span>Ivory</button>
          </div>
        </div>

        <div class="prefs-section">
          <div class="prefs-label">Accent</div>
          <div class="swatches" role="radiogroup" aria-label="Accent colour"></div>
          <label class="custom-color">
            <input type="color" id="prefs-color" aria-label="Pick a custom colour">
            <span class="custom-chip"></span>
            <span>Custom colour</span>
            <input type="text" id="prefs-hex" maxlength="7" spellcheck="false" autocomplete="off" aria-label="Colour hex code">
          </label>
        </div>

        <div class="prefs-section">
          <div class="prefs-label">Background glow <span class="prefs-val" id="prefs-glow-val"></span></div>
          <input type="range" id="prefs-glow" min="0" max="100" step="5" aria-label="Background glow intensity">
        </div>

        <div class="modal-actions">
          <button type="button" class="btn btn-quiet" data-reset>Reset</button>
          <button type="button" class="btn btn-primary" data-close>Done</button>
        </div>
      </div>`;
    document.body.appendChild(m);

    const sw = m.querySelector('.swatches');
    SWATCHES.forEach((s) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'swatch';
      b.dataset.hex = s.hex;
      b.title = s.name;
      b.setAttribute('role', 'radio');
      b.setAttribute('aria-label', s.name);
      b.style.setProperty('--sw', s.hex);
      b.innerHTML = svg('check');
      sw.appendChild(b);
    });

    const colorIn = m.querySelector('#prefs-color');
    const hexIn = m.querySelector('#prefs-hex');
    const glowIn = m.querySelector('#prefs-glow');
    const glowVal = m.querySelector('#prefs-glow-val');
    const chip = m.querySelector('.custom-chip');

    function sync() {
      m.querySelectorAll('.swatch').forEach((b) => {
        const on = b.dataset.hex === prefs.color;
        b.classList.toggle('on', on);
        b.setAttribute('aria-checked', on ? 'true' : 'false');
      });
      m.querySelectorAll('.mode').forEach((b) => {
        const on = b.dataset.themeOpt === prefs.theme;
        b.classList.toggle('on', on);
        b.setAttribute('aria-checked', on ? 'true' : 'false');
      });
      colorIn.value = prefs.color;
      if (document.activeElement !== hexIn) hexIn.value = prefs.color;
      chip.style.background = prefs.color;
      glowIn.value = prefs.glow;
      glowVal.textContent = prefs.glow === 0 ? 'Off' : prefs.glow + '%';
    }
    function change(patch) { prefs = { ...prefs, ...patch }; apply(prefs); save(prefs); sync(); }

    sw.addEventListener('click', (e) => { const b = e.target.closest('.swatch'); if (b) change({ color: b.dataset.hex }); });
    m.querySelector('.modes').addEventListener('click', (e) => { const b = e.target.closest('.mode'); if (b) change({ theme: b.dataset.themeOpt }); });
    colorIn.addEventListener('input', () => change({ color: colorIn.value.toLowerCase() }));
    hexIn.addEventListener('input', () => {
      let v = hexIn.value.trim();
      if (v && v[0] !== '#') v = '#' + v;
      if (/^#[0-9a-f]{6}$/i.test(v)) change({ color: v.toLowerCase() });
    });
    hexIn.addEventListener('blur', sync);
    glowIn.addEventListener('input', () => change({ glow: parseInt(glowIn.value, 10) }));
    m.querySelector('[data-reset]').addEventListener('click', () => change({ ...DEFAULTS }));
    m.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', close));
    m.addEventListener('mousedown', (e) => { if (e.target === m) close(); });
    m.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') { e.stopPropagation(); close(); return; }
      if (e.key !== 'Tab') return;
      // Keep focus inside the window while it is open
      const f = [...m.querySelectorAll('button, input')].filter((x) => !x.disabled && x.offsetParent !== null);
      if (!f.length) return;
      const first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    m._sync = sync;
    return m;
  }

  function open() {
    if (!modal) modal = build();
    lastFocus = document.activeElement;
    modal._sync();
    modal.hidden = false;
    const on = modal.querySelector('.mode.on') || modal.querySelector('.swatch');
    if (on) on.focus();
  }
  function close() {
    if (!modal) return;
    modal.hidden = true;
    if (lastFocus && lastFocus.focus) lastFocus.focus({ preventScroll: true });
  }

  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-open-prefs]')) { e.preventDefault(); open(); }
  });

  // Other open tabs follow along when the settings change
  window.addEventListener('storage', (e) => {
    if (e.key === KEY) { prefs = load(); apply(prefs); if (modal && !modal.hidden) modal._sync(); }
  });
})();
