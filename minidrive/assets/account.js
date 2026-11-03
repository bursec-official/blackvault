/* =====================================================================
   Black Vault — account menu (Drive and Admin pages)
   Change password, sign out other devices, links for administrators.
   ===================================================================== */
(() => {
  'use strict';

  const btn = document.getElementById('account-btn');
  if (!btn) return;
  const CSRF = (document.querySelector('[data-csrf]') || {}).dataset?.csrf || '';
  const isAdmin = btn.dataset.role === 'admin';
  const page = btn.dataset.page;
  const svg = (id) => `<svg class="ic" aria-hidden="true"><use href="#i-${id}"/></svg>`;

  // Monitor lives on another port of the same server
  document.querySelectorAll('a[data-port]').forEach((a) => {
    a.href = location.protocol + '//' + location.hostname + ':' + a.dataset.port + '/';
  });

  async function post(body) {
    const res = await fetch('api/account.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    if (res.status === 401) {
      if (data.locked) { setTimeout(() => { location.href = 'login.php'; }, 3500); throw new Error(data.error); }
      location.href = 'login.php'; throw new Error('Session expired');
    }
    if (!res.ok) throw new Error(data.error || 'Server error (' + res.status + ')');
    return data;
  }

  function notify(msg, type = 'ok') {
    let box = document.getElementById('toast-container');
    if (!box) { box = document.createElement('div'); box.id = 'toast-container'; box.className = 'toast-container'; document.body.appendChild(box); }
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML = svg(type === 'error' ? 'circle-alert' : 'circle-check') + '<div class="toast-body"></div>';
    el.querySelector('.toast-body').textContent = msg;
    box.appendChild(el);
    setTimeout(() => { el.classList.add('leaving'); setTimeout(() => el.remove(), 250); }, type === 'error' ? 6000 : 3500);
  }

  // ---------------- Menu ----------------
  let menu = null;
  function closeMenu() {
    if (!menu) return;
    menu.remove(); menu = null;
    btn.setAttribute('aria-expanded', 'false');
    document.removeEventListener('mousedown', outside, true);
  }
  function outside(e) { if (menu && !menu.contains(e.target) && !btn.contains(e.target)) closeMenu(); }

  function openMenu() {
    closeMenu();
    menu = document.createElement('div');
    menu.className = 'menu account-menu';
    menu.setAttribute('role', 'menu');
    const head = document.createElement('div');
    head.className = 'account-head';
    head.innerHTML = '<b></b><span></span>';
    head.querySelector('b').textContent = btn.dataset.name;
    head.querySelector('span').textContent = isAdmin ? 'Administrator' : 'Member';
    menu.appendChild(head);
    const add = (icon, label, fn, cls = '') => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'menu-item ' + cls;
      b.setAttribute('role', 'menuitem');
      b.innerHTML = svg(icon) + '<span></span>';
      b.querySelector('span').textContent = label;
      b.addEventListener('click', () => { closeMenu(); fn(); });
      menu.appendChild(b);
    };
    const sep = () => { const s = document.createElement('div'); s.className = 'menu-sep'; menu.appendChild(s); };
    if (isAdmin && page !== 'admin') add('shield-check', 'Admin panel', () => { location.href = 'admin.php'; });
    if (!isAdmin && page !== 'drive') add('hard-drive', 'My drive', () => { location.href = 'index.php'; });
    if (menu.children.length > 1) sep();
    add('key-round', 'Change password', openPassword);
    add('log-out', 'Sign out other devices', signOutOthers);
    sep();
    add('log-out', 'Sign out', () => document.getElementById('logout-form').submit(), 'danger');

    document.body.appendChild(menu);
    const r = btn.getBoundingClientRect();
    menu.style.top = (r.bottom + 8) + 'px';
    menu.style.left = Math.max(8, Math.min(r.right - menu.offsetWidth, innerWidth - menu.offsetWidth - 8)) + 'px';
    btn.setAttribute('aria-expanded', 'true');
    document.addEventListener('mousedown', outside, true);
    const first = menu.querySelector('.menu-item');
    if (first) first.focus();
    menu.addEventListener('keydown', (e) => {
      const items = [...menu.querySelectorAll('.menu-item')];
      const i = items.indexOf(document.activeElement);
      if (e.key === 'Escape') { closeMenu(); btn.focus(); }
      else if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
      else if (e.key === 'Tab') closeMenu();
    });
  }
  btn.addEventListener('click', (e) => { e.stopPropagation(); menu ? closeMenu() : openMenu(); });
  window.addEventListener('resize', closeMenu);

  async function signOutOthers() {
    try { await post({ action: 'signout_others' }); notify('Other devices have been signed out'); }
    catch (err) { notify(err.message, 'error'); }
  }

  // ---------------- Change password ----------------
  function openPassword() {
    const ov = document.createElement('div');
    ov.className = 'modal-overlay';
    ov.innerHTML = `
      <form class="modal-card" autocomplete="off" role="dialog" aria-modal="true" aria-labelledby="pw-title">
        <div class="modal-head"><h3 id="pw-title">Change password</h3>
          <button type="button" class="icon-btn" data-close aria-label="Close">${svg('x')}</button></div>
        <p class="modal-hint">Other devices signed in with your account will be signed out.</p>
        <div class="form-stack">
          <label class="field"><span>Current password</span>
            <input class="text-input" type="password" name="current" autocomplete="current-password" required></label>
          <label class="field"><span>New password</span>
            <input class="text-input" type="password" name="password" autocomplete="new-password" minlength="10" required>
            <small class="field-hint">At least 10 characters, mixing letters with numbers or symbols.</small></label>
          <label class="field"><span>Repeat new password</span>
            <input class="text-input" type="password" name="repeat" autocomplete="new-password" required></label>
        </div>
        <p class="dialog-error" hidden></p>
        <div class="modal-actions">
          <button type="button" class="btn btn-quiet" data-close>Cancel</button>
          <button type="submit" class="btn btn-primary">Change password</button>
        </div>
      </form>`;
    document.body.appendChild(ov);
    const form = ov.querySelector('form');
    const err = ov.querySelector('.dialog-error');
    const close = () => { ov.remove(); btn.focus(); };
    ov.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', close));
    ov.addEventListener('mousedown', (e) => { if (e.target === ov) close(); });
    ov.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
    form.current.focus();
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      err.hidden = true;
      if (form.password.value !== form.repeat.value) { err.textContent = 'The new passwords do not match.'; err.hidden = false; return; }
      const submit = form.querySelector('[type=submit]');
      submit.disabled = true;
      try {
        await post({ action: 'password', current: form.current.value, password: form.password.value });
        close();
        notify('Password changed');
      } catch (ex) {
        err.textContent = ex.message; err.hidden = false;
      } finally { submit.disabled = false; }
    });
  }
})();
