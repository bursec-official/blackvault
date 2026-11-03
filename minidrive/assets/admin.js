/* =====================================================================
   Black Vault — administration (accounts, storage & disks, security, activity)
   Every check is repeated on the server (and disk actions again by the
   root disk service); this file only drives the interface.
   Every value coming from the server is inserted with textContent.
   ===================================================================== */
(() => {
  'use strict';

  const root = document.getElementById('admin');
  const CSRF = root.dataset.csrf;
  const ME = root.dataset.me;
  const $ = (id) => document.getElementById(id);
  const svg = (id) => `<svg class="ic" aria-hidden="true"><use href="#i-${id}"/></svg>`;
  const store = {
    get(k) { try { return localStorage.getItem(k); } catch { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch { /* private mode */ } },
  };
  let state = null;      // accounts, quotas, settings, activity
  let disks = null;      // disks as the root disk service sees them

  // ---------------- Formatting ----------------
  const nf1 = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 1 });
  const nf0 = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 0 });
  function fmtSize(b) {
    if (b == null) return '—';
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0, s = Number(b);
    while (s >= 1024 && i < u.length - 1) { s /= 1024; i++; }
    return (i > 0 && s < 10 ? nf1 : nf0).format(s) + ' ' + u[i];
  }
  const GB = 1024 ** 3;
  function ago(ts) {
    if (!ts) return 'Never';
    const s = Math.max(0, Date.now() / 1000 - ts);
    if (s < 60) return 'Just now';
    if (s < 3600) return Math.round(s / 60) + ' min ago';
    if (s < 86400) return Math.round(s / 3600) + ' h ago';
    if (s < 86400 * 7) return Math.round(s / 86400) + ' d ago';
    return new Date(ts * 1000).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  }
  const FRACTIONS = [[50, '½'], [33.33, '⅓'], [25, '¼'], [20, '⅕']];
  function quotaLabel(q) {
    if (q.mode === 'auto') return 'Equal share';
    if (q.mode === 'none') return 'No limit';
    if (q.mode === 'fixed') return 'Fixed size';
    const f = FRACTIONS.find(([v]) => Math.abs(v - q.value) < 0.05);
    return (f ? f[1] : nf1.format(q.value) + '%') + ' of storage';
  }
  const initials = (n) => n.replace(/[^A-Za-z0-9]/g, '').slice(0, 2).toUpperCase() || '?';
  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };

  // ---------------- Server ----------------
  async function api(body) {
    const opt = body
      ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body) }
      : { method: 'GET' };
    opt.credentials = 'same-origin';
    let res;
    try { res = await fetch('api/admin.php', opt); }
    catch { throw new Error('Cannot reach the server.'); }
    if (res.status === 401) { location.href = 'login.php'; throw new Error('Session expired'); }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Server error (' + res.status + ')');
    return data;
  }
  /** Calls an action that answers with the accounts state, and redraws. */
  async function act(body, okMsg, sub = '') {
    try { state = await api(body); render(); if (okMsg) toast(okMsg, 'ok', sub); return true; }
    catch (err) { toast(err.message, 'error'); return false; }
  }
  /** Calls a disk action (answers with the disks state). */
  async function diskAct(body) {
    const d = await api(body);
    disks = d; renderDisks(); renderAlerts(); renderStorageStats();
    return d;
  }

  function toast(msg, type = 'ok', sub = '') {
    const t = el('div', 'toast ' + type);
    t.innerHTML = svg(type === 'error' ? 'circle-alert' : 'circle-check') + '<div class="toast-body"><div></div><small></small></div>';
    t.querySelector('.toast-body div').textContent = msg;
    t.querySelector('small').textContent = sub;
    $('toast-container').appendChild(t);
    setTimeout(() => { t.classList.add('leaving'); setTimeout(() => t.remove(), 250); }, type === 'error' ? 7000 : 4500);
  }

  // ---------------- Tabs ----------------
  const TABS = ['accounts', 'storage', 'security', 'activity'];
  let tab = TABS.includes(location.hash.slice(1)) ? location.hash.slice(1) : (store.get('bv-admin-tab') || 'accounts');
  if (!TABS.includes(tab)) tab = 'accounts';
  function showTab(name, focus = false) {
    tab = name;
    store.set('bv-admin-tab', name);
    if (location.hash !== '#' + name) history.replaceState(null, '', '#' + name);
    document.querySelectorAll('.tabs [role=tab]').forEach((b) => {
      const on = b.dataset.tab === name;
      b.setAttribute('aria-selected', on);
      b.tabIndex = on ? 0 : -1;
      if (on && focus) b.focus();
    });
    TABS.forEach((t) => { $('panel-' + t).hidden = t !== name; });
    if (name === 'storage' && !disks && state) loadDisks();
  }
  document.querySelectorAll('.tabs [role=tab]').forEach((b, i, all) => {
    b.addEventListener('click', () => showTab(b.dataset.tab));
    b.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
        e.preventDefault();
        const n = all[(i + (e.key === 'ArrowRight' ? 1 : all.length - 1)) % all.length];
        showTab(n.dataset.tab, true);
      }
    });
  });
  window.addEventListener('hashchange', () => { const h = location.hash.slice(1); if (TABS.includes(h)) showTab(h); });

  // ---------------- Quota maths (mirrors includes/users.php) ----------------
  function limitsFor(users, pool) {
    const out = {};
    let fixed = 0;
    const auto = [];
    users.forEach((u) => {
      if (u.role === 'admin') return;              // administrators have no drive
      const q = u.quota;
      if (q.mode === 'share') { out[u.id] = Math.floor(pool * q.value / 100); fixed += out[u.id]; }
      else if (q.mode === 'fixed') { out[u.id] = q.value; fixed += q.value; }
      else if (q.mode === 'none') out[u.id] = null;
      else auto.push(u.id);
    });
    const each = auto.length ? Math.floor(Math.max(0, pool - fixed) / auto.length) : 0;
    auto.forEach((id) => { out[id] = each; });
    return out;
  }

  // =====================================================================
  //  Render
  // =====================================================================
  function render() {
    renderAlerts();
    renderUsers();
    renderStorageStats();
    renderSettings();
    renderSecurity();
    renderActivity();
  }

  // ---------------- Alerts (top of the page) ----------------
  function renderAlerts() {
    const box = $('alerts');
    box.textContent = '';
    if (!state) return;
    const add = (kind, icon, title, text, action) => {
      const a = el('div', 'alert ' + kind);
      a.innerHTML = svg(icon) + '<div class="alert-body"><b></b><span></span></div>';
      a.querySelector('b').textContent = title;
      a.querySelector('span').textContent = text;
      if (action) {
        const b = el('button', 'btn btn-sm', action.label);
        b.type = 'button';
        b.addEventListener('click', action.fn);
        a.appendChild(b);
      }
      box.appendChild(a);
      return a;
    };
    const st = state.storage || {};
    const move = (disks && disks.move) || null;
    if (st.state === 'offline') {
      add('bad', 'triangle-alert', 'The drives are offline', 'The disk that holds them is not connected. Plug it back in; the files are safe on it.', { label: 'Look for it', fn: () => { showTab('storage'); loadDisks(); } });
    }
    if (st.state === 'moving' || (move && ['running', 'verifying'].includes(move.state))) {
      const a = add('info', 'arrow-right-left', move && move.state === 'verifying' ? 'Checking the copy…' : 'Moving the drives…',
        'Files are read-only until it finishes. You can close this page; it carries on by itself.');
      const m = el('div', 'meter alert-meter');
      const f = el('div', 'meter-fill');
      f.style.width = Math.max(2, (move ? move.progress : st.progress) || 0) + '%';
      m.appendChild(f);
      a.querySelector('.alert-body').appendChild(m);
      startProgressPolling();
    }
    if (move && move.state === 'failed' && !store.get('bv-move-seen-' + (move.finished || ''))) {
      add('bad', 'circle-alert', 'The move did not finish', move.error || 'The drives stayed where they were.', { label: 'OK', fn: () => { store.set('bv-move-seen-' + (move.finished || ''), '1'); renderAlerts(); } });
    }
    if (move && move.state === 'done' && move.old) {
      add('info', 'hard-drive', 'An old copy of the drives is still on ' + locName(move.old),
        'Everything now lives on ' + locName(move.to) + '. Remove the old copy when you are happy, to free ' + fmtSize(move.total) + '.',
        { label: 'Remove old copy…', fn: () => removeOldModal(move.old, move.total) });
    }
    const failing = ((disks && disks.disks) || []).filter((d) => d.health && d.health.status === 'failing');
    failing.forEach((d) => add('bad', 'triangle-alert', d.model + ' is failing', (d.health.notes || []).join('. ') || 'Copy your data off it now.', { label: 'See disk', fn: () => showTab('storage') }));
    const locked = state.users.filter((u) => u.locked && u.locked_by === 'fails');
    if (locked.length) {
      add('warn', 'lock', locked.length === 1 ? locked[0].username + ' is locked' : locked.length + ' accounts are locked',
        'Too many wrong passwords. Unlock them if it was really them.', { label: 'Review', fn: () => showTab('accounts') });
    }
    // Dots on the tabs
    document.querySelector('#tab-accounts .tab-dot').hidden = !locked.length;
    document.querySelector('#tab-storage .tab-dot').hidden = !(st.state !== 'ok' || failing.length || (move && move.state === 'done' && move.old));
  }

  function locName(loc) {
    if (loc === 'internal') return 'the internal disk';
    const d = ((disks && disks.disks) || []).find((x) => x.bv && x.bv.uuid === loc);
    if (d) return d.model + ' (' + fmtSize(d.size) + ')';
    const m = ((disks && disks.missing) || []).find((x) => x.uuid === loc);
    return m ? m.model : 'the other disk';
  }

  // ---------------- Accounts ----------------
  function renderUsers() {
    const members = state.users.filter((u) => u.role !== 'admin');
    const admins = state.users.length - members.length;
    const locked = state.users.filter((u) => u.locked).length;
    $('users-meta').textContent = [
      members.length + (members.length === 1 ? ' member' : ' members'),
      admins + (admins === 1 ? ' administrator' : ' administrators'),
      locked ? locked + ' locked' : null,
      fmtSize(members.reduce((a, u) => a + u.used, 0)) + ' stored',
    ].filter(Boolean).join(' · ');

    const box = $('users');
    box.textContent = '';
    state.users.forEach((u) => {
      const isAdmin = u.role === 'admin';
      const row = el('div', 'urow' + (u.locked ? ' is-locked' : ''));
      row.setAttribute('role', 'row');

      const who = el('div', 'u-who');
      who.innerHTML = '<span class="u-avatar"></span><div><b></b><small></small></div>';
      who.querySelector('.u-avatar').textContent = initials(u.username);
      who.querySelector('b').textContent = u.username;
      who.querySelector('small').textContent = isAdmin ? 'Administrator' : 'Member';
      if (u.id === ME) { const y = el('span', 'badge', 'You'); who.querySelector('b').after(y); }

      const quota = el('div', 'u-quota');
      quota.innerHTML = '<span></span><small></small>';
      quota.querySelector('span').textContent = isAdmin ? 'No drive' : quotaLabel(u.quota);
      quota.querySelector('small').textContent = isAdmin ? 'Manages accounts and the Monitor' : u.limit == null ? 'Up to the free disk space' : fmtSize(u.limit);

      const usage = el('div', 'u-usage');
      if (isAdmin) { usage.textContent = '—'; usage.classList.add('u-none'); }
      else {
        const pct = u.limit ? Math.min(100, u.used / u.limit * 100) : 0;
        usage.innerHTML = '<div class="meter"><div class="meter-fill"></div></div><small></small>';
        const fill = usage.querySelector('.meter-fill');
        fill.style.width = (u.limit == null ? 0 : pct) + '%';
        if (pct >= 95) fill.classList.add('crit'); else if (pct >= 80) fill.classList.add('warn');
        usage.querySelector('small').textContent = fmtSize(u.used) + (u.limit != null ? ' of ' + fmtSize(u.limit) + ' · ' + nf0.format(pct) + '%' : ' used');
      }

      const last = el('div', 'u-last', ago(u.last_login));

      const status = el('div');
      const pill = el('span', 'status ' + (u.locked ? 'lock' : 'on'), u.locked ? 'Locked' : 'Active');
      if (u.locked) pill.title = (u.locked_by === 'fails' ? 'Locked after ' + state.max_fails + ' wrong passwords' : 'Locked by an administrator') + ' · ' + ago(u.locked_at).toLowerCase();
      status.appendChild(pill);
      if (u.locked) status.appendChild(el('small', 'status-why', u.locked_by === 'fails' ? 'Wrong passwords' : 'By an admin'));

      const acts = el('div', 'u-acts');
      // Lock / unlock, right on the row
      const canLock = u.id !== ME;
      const lockBtn = el('button', 'btn btn-sm lock-btn' + (u.locked ? ' btn-primary' : ''));
      lockBtn.type = 'button';
      lockBtn.innerHTML = svg(u.locked ? 'lock-open' : 'lock') + '<span></span>';
      lockBtn.querySelector('span').textContent = u.locked ? 'Unlock' : 'Lock';
      lockBtn.setAttribute('aria-label', (u.locked ? 'Unlock ' : 'Lock ') + u.username);
      if (!canLock && !u.locked) { lockBtn.disabled = true; lockBtn.title = 'You cannot lock your own account'; }
      lockBtn.addEventListener('click', () => (u.locked ? unlock(u) : lockModal(u)));
      const edit = el('button', 'btn btn-sm btn-quiet', 'Edit');
      edit.type = 'button';
      edit.addEventListener('click', () => userModal(u));
      const more = el('button', 'icon-btn');
      more.type = 'button';
      more.innerHTML = svg('ellipsis');
      more.setAttribute('aria-label', 'More actions for ' + u.username);
      more.addEventListener('click', (e) => { e.stopPropagation(); rowMenu(u, more); });
      acts.append(lockBtn, edit, more);

      row.append(who, quota, usage, last, status, acts);
      box.appendChild(row);
    });
  }

  function unlock(u) {
    act({ action: 'unlock', id: u.id }, u.username + ' is unlocked', 'They can sign in again with their password.');
  }
  function lockModal(u) {
    modal(`
      <form class="modal-card" role="dialog" aria-modal="true">
        <div class="modal-head"><h3></h3><button type="button" class="icon-btn" data-close aria-label="Close">${svg('x')}</button></div>
        <p class="dialog-text"></p>
        <p class="dialog-error" hidden></p>
        <div class="modal-actions">
          <button type="button" class="btn btn-quiet" data-close>Cancel</button>
          <button type="submit" class="btn btn-primary">${svg('lock')}<span>Lock account</span></button>
        </div>
      </form>`, (ov, close) => {
      ov.querySelector('h3').textContent = 'Lock ' + u.username + '?';
      ov.querySelector('.dialog-text').textContent = u.username + ' will be signed out of every device and cannot sign in until you unlock the account. '
        + (u.role === 'admin' ? 'Their administrator rights stay as they are.' : 'Their files stay safe and untouched.');
      ov.querySelector('form').addEventListener('submit', async (e) => {
        e.preventDefault();
        try { state = await api({ action: 'lock', id: u.id }); render(); close(); toast(u.username + ' is locked', 'ok', 'Signed out everywhere.'); }
        catch (err) { showErr(ov, err.message); }
      });
      ov.querySelector('[type=submit]').focus();
    });
  }

  // ---------------- Storage ----------------
  function renderStorageStats() {
    const p = state.pool;
    const st = state.storage || {};
    const onDisk = st.disk ? locName(st.disk) : 'Internal disk';
    $('st-disk').textContent = st.state === 'offline' ? 'Offline' : onDisk.replace(/^the /, '').replace(/^\w/, (c) => c.toUpperCase());
    $('st-disk-sub').textContent = st.state === 'offline' ? 'Plug the disk back in' : fmtSize(p.disk_total) + ' disk · ' + fmtSize(p.disk_free) + ' free';
    $('st-pool').textContent = fmtSize(p.pool);
    $('st-pool-sub').textContent = nf0.format(p.reserve_pct) + '% kept free for the system';
    const members = state.users.filter((u) => u.role !== 'admin');
    const limited = members.filter((u) => u.limit != null);
    const assigned = limited.reduce((a, u) => a + u.limit, 0);
    const pct = p.pool ? Math.min(100, assigned / p.pool * 100) : 0;
    $('st-assigned').textContent = fmtSize(assigned);
    $('st-assigned-bar').style.width = pct + '%';
    const unlimited = members.length - limited.length;
    $('st-assigned-sub').textContent = nf0.format(pct) + '% of the space for drives' + (unlimited ? ' · ' + unlimited + ' without limit' : '');
  }

  const reserve = $('reserve');
  function renderSettings() {
    if (document.activeElement !== reserve) reserve.value = state.pool.reserve_pct;
    updateReserveHint();
  }
  function updateReserveHint() {
    const r = Number(reserve.value);
    $('reserve-val').textContent = r + '%';
    const pool = state.pool.disk_total * (1 - r / 100);
    const lim = limitsFor(state.users, pool);
    const autoUsers = state.users.filter((u) => u.role !== 'admin' && u.quota.mode === 'auto');
    $('reserve-hint').textContent = fmtSize(state.pool.disk_total * r / 100) + ' kept free · ' + fmtSize(pool) + ' for drives'
      + (autoUsers.length ? ' · each equal share ≈ ' + fmtSize(lim[autoUsers[0].id]) : '');
    $('settings-save').disabled = r === Number(state.pool.reserve_pct);
  }
  reserve.addEventListener('input', updateReserveHint);
  $('settings-form').addEventListener('submit', (e) => {
    e.preventDefault();
    act({ action: 'settings', reserve_pct: Number(reserve.value) }, 'Storage settings saved');
  });

  // ---------------- Disks ----------------
  const ROLE = {
    system: ['System disk', 'neutral'],
    storage: ['Drives live here', 'acc'],
    blackvault: ['Ready for Black Vault', 'ok'],
    empty: ['New disk', 'neutral'],
    other: ['Has other data', 'warn'],
  };
  const HEALTH = {
    healthy: ['Healthy', 'ok'], warning: ['Needs attention', 'warn'], failing: ['Failing', 'bad'],
    asleep: ['Asleep', 'neutral'], unknown: ['Health unknown', 'neutral'],
  };
  function chip(text, kind) { return el('span', 'chip-s ' + kind, text); }

  async function loadDisks() {
    const box = $('disks');
    try { disks = await api({ action: 'disks' }); }
    catch (err) { box.textContent = ''; box.appendChild(el('div', 'urow-empty', err.message)); return; }
    renderDisks();
    renderAlerts();
    renderStorageStats();
  }

  function renderDisks() {
    const box = $('disks');
    box.textContent = '';
    if (!disks || !state) return;
    if (!disks.available) {
      const e = el('div', 'disks-empty');
      e.innerHTML = svg('triangle-alert') + '<div><b>Disk management is not installed</b><span></span></div>';
      e.querySelector('span').textContent = disks.message + ' Run the installer again on the server.';
      box.appendChild(e);
      $('btn-health').disabled = true;
      return;
    }
    $('btn-health').disabled = !disks.smart_tool;
    $('btn-health').title = disks.smart_tool ? 'Ask every disk how it is doing' : 'Install smartmontools on the server to see disk health';
    const busy = disks.move && ['running', 'verifying'].includes(disks.move.state);
    const list = [...disks.disks].sort((a, b) => (a.role === 'system') - (b.role === 'system'));
    const external = list.filter((d) => d.role !== 'system');
    $('disks-sub').textContent = external.length
      ? 'Prepare a disk to use it, then move the drives onto it.'
      : 'Plug a USB disk into the server and press “Look for disks”.';
    list.forEach((d) => box.appendChild(diskCard(d, busy)));
    (disks.missing || []).forEach((m) => box.appendChild(missingCard(m)));
  }

  function diskCard(d, busy) {
    const c = el('article', 'disk' + (d.role === 'storage' ? ' is-active' : '') + (d.role === 'system' ? ' is-system' : ''));
    const head = el('div', 'disk-head');
    const icon = el('span', 'disk-ic');
    icon.innerHTML = svg(d.transport === 'usb' ? 'usb' : 'hard-drive');
    const title = el('div', 'disk-title');
    title.appendChild(el('b', null, d.model));
    title.appendChild(el('small', null, [fmtSize(d.size), d.transport ? d.transport.toUpperCase() : null, d.rotational ? 'Hard disk' : 'SSD', d.path].filter(Boolean).join(' · ')));
    head.append(icon, title);
    c.appendChild(head);

    const chips = el('div', 'disk-chips');
    const role = ROLE[d.role] || ROLE.other;
    chips.appendChild(chip(role[0], role[1]));
    if (d.role === 'system' && d.holds_drives) chips.appendChild(chip('Drives live here', 'acc'));
    if (d.role === 'blackvault' && d.bv && !d.bv.connected) chips.appendChild(chip('Not connected', 'neutral'));
    const h = d.health ? (HEALTH[d.health.status] || HEALTH.unknown) : HEALTH.unknown;
    const hc = chip(h[0], h[1]);
    hc.insertAdjacentHTML('afterbegin', svg('activity'));
    chips.appendChild(hc);
    c.appendChild(chips);

    // What it holds
    const info = el('p', 'disk-info');
    if (d.role === 'system') info.textContent = d.reason ? 'Used by the system (' + d.reason.replace(/^Used by the system \(|\)$/g, '') + '). It can never be erased from here.' : 'The system lives here.';
    else if (d.role === 'storage') info.textContent = 'All drives are stored on this disk. It cannot be ejected or erased while they live here.';
    else if (d.role === 'blackvault') info.textContent = d.bv && d.bv.connected
      ? (d.bv.has_drives ? 'Prepared, with an older copy of the drives. You can move the drives here or remove that copy.' : 'Prepared and connected. You can move the drives here.')
      : 'Prepared for Black Vault, but not connected yet.';
    else if (d.role === 'empty') info.textContent = 'This disk is empty. Prepare it to use it with Black Vault.';
    else info.textContent = 'This disk has data on it (' + ((d.formats || []).join(', ') || 'unknown format') + '). Preparing it erases everything.';
    c.appendChild(info);

    if (d.bv && d.bv.connected && d.bv.total) {
      const used = d.bv.total - d.bv.free;
      const m = el('div', 'meter');
      const f = el('div', 'meter-fill');
      f.style.width = Math.min(100, used / d.bv.total * 100) + '%';
      m.appendChild(f);
      c.append(m, el('small', 'disk-space', fmtSize(used) + ' used · ' + fmtSize(d.bv.free) + ' free'));
    }
    if (d.health && (d.health.temp != null || d.health.hours != null || (d.health.notes || []).length)) {
      const hl = el('button', 'disk-health-link');
      hl.type = 'button';
      hl.textContent = [d.health.temp != null ? d.health.temp + ' °C' : null, d.health.hours != null ? nf0.format(d.health.hours) + ' h on' : null, d.health.wear != null ? d.health.wear + '% worn' : null].filter(Boolean).join(' · ') || 'Health details';
      hl.addEventListener('click', () => healthModal(d));
      c.appendChild(hl);
    }

    // Guided actions
    const acts = el('div', 'disk-acts');
    const btn = (label, icon, cls, fn, disabled = false, title = '') => {
      const b = el('button', 'btn btn-sm ' + cls);
      b.type = 'button';
      b.innerHTML = svg(icon) + '<span></span>';
      b.querySelector('span').textContent = label;
      b.disabled = disabled;
      if (title) b.title = title;
      b.addEventListener('click', fn);
      acts.appendChild(b);
      return b;
    };
    const offline = state.storage && state.storage.state === 'offline';
    if (d.role === 'empty' || d.role === 'other') {
      btn('Prepare for Black Vault…', 'eraser', 'btn-primary', () => formatModal(d), busy, busy ? 'Wait until the move finishes' : '');
    } else if (d.role === 'blackvault' && d.bv && !d.bv.connected) {
      btn('Connect', 'plug', 'btn-primary', () => connectDisk(d.bv.uuid, d.model), busy);
    } else if (d.role === 'blackvault' && d.bv && d.bv.connected) {
      btn('Move the drives here…', 'arrow-right-left', 'btn-primary', () => moveModal(d.bv.uuid, d.model + ' (' + fmtSize(d.size) + ')', d.bv.free), busy || offline, offline ? 'The drives are offline' : '');
      if (d.bv.has_drives) btn('Remove old copy…', 'trash-2', 'btn-quiet', () => removeOldModal(d.bv.uuid, null), busy);
      btn('Eject', 'log-out', 'btn-quiet', () => ejectModal(d), busy);
      btn('Erase…', 'eraser', 'btn-quiet danger-quiet', () => formatModal(d), busy);
    } else if (d.role === 'system' && d.internal && !d.holds_drives && disks.storage && disks.storage.disk) {
      const free = disks.internal ? disks.internal.free : null;
      btn('Move the drives back here…', 'arrow-right-left', 'btn-quiet', () => moveModal('internal', 'the internal disk', free), busy || offline);
    }
    if (acts.children.length) c.appendChild(acts);
    return c;
  }

  function missingCard(m) {
    const c = el('article', 'disk is-missing' + (m.active ? ' is-active' : ''));
    const head = el('div', 'disk-head');
    const icon = el('span', 'disk-ic');
    icon.innerHTML = svg('plug');
    const title = el('div', 'disk-title');
    title.append(el('b', null, m.model || 'Disk'), el('small', null, (m.size ? fmtSize(m.size) + ' · ' : '') + 'Not plugged in'));
    head.append(icon, title);
    const chips = el('div', 'disk-chips');
    chips.appendChild(chip(m.active ? 'Drives live here' : 'Black Vault disk', m.active ? 'bad' : 'neutral'));
    c.append(head, chips, el('p', 'disk-info', m.active
      ? 'The drives are offline until this disk is plugged back in. Plug it in and press “Look for disks”.'
      : 'Plug it in to use it again.'));
    return c;
  }

  async function connectDisk(uuid, model) {
    try { await diskAct({ action: 'disk_connect', uuid }); toast(model + ' is connected'); refreshState(); }
    catch (err) { toast(err.message, 'error'); }
  }

  // ---------------- Disk dialogs ----------------
  const pwField = `
    <label class="field"><span>Your password</span>
      <input class="text-input" type="password" name="password" autocomplete="current-password" required>
      <small class="field-hint">Needed for anything that erases or moves data.</small>
    </label>`;

  function busyButton(b, text) { b.disabled = true; b.dataset.label = b.innerHTML; b.innerHTML = '<span class="spinner">' + svg('loader-circle') + '</span><span></span>'; b.querySelector('span:last-child').textContent = text; }
  function idleButton(b) { b.disabled = false; if (b.dataset.label) b.innerHTML = b.dataset.label; }

  function formatModal(d) {
    modal(`
      <form class="modal-card" autocomplete="off" role="dialog" aria-modal="true">
        <div class="modal-head"><h3></h3><button type="button" class="icon-btn" data-close aria-label="Close">${svg('x')}</button></div>
        <div class="warn-box">${svg('triangle-alert')}<p></p></div>
        <div class="form-stack">
          <label class="field"><span>Type ERASE to confirm</span>
            <input class="text-input" name="confirm" autocomplete="off" spellcheck="false" autocapitalize="characters"></label>
          ${pwField}
        </div>
        <p class="dialog-error" hidden></p>
        <div class="modal-actions">
          <button type="button" class="btn btn-quiet" data-close>Cancel</button>
          <button type="submit" class="btn btn-danger-solid" disabled>Erase and prepare</button>
        </div>
      </form>`, (ov, close) => {
      ov.querySelector('h3').textContent = 'Prepare ' + d.model + ' for Black Vault';
      ov.querySelector('.warn-box p').textContent = 'Everything on this disk (' + fmtSize(d.size) + (d.formats && d.formats.length ? ', ' + d.formats.join(', ') : '') + ') will be erased. This cannot be undone. Afterwards it is ready to hold the drives.';
      const form = ov.querySelector('form');
      const ok = form.querySelector('[type=submit]');
      const check = () => { ok.disabled = form.confirm.value.trim().toUpperCase() !== 'ERASE' || !form.password.value; };
      form.confirm.addEventListener('input', check);
      form.password.addEventListener('input', check);
      form.confirm.focus();
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        showErr(ov, '');
        busyButton(ok, 'Preparing… this can take a minute');
        ov.querySelectorAll('[data-close]').forEach((b) => { b.disabled = true; });
        try {
          await diskAct({ action: 'disk_format', path: d.path, id: d.id, password: form.password.value });
          close();
          toast(d.model + ' is ready', 'ok', 'Now you can move the drives onto it.');
        } catch (err) {
          showErr(ov, err.message); idleButton(ok); check();
          ov.querySelectorAll('[data-close]').forEach((b) => { b.disabled = false; });
        }
      });
    });
  }

  function moveModal(to, label, free) {
    const size = disks ? disks.drives_size : 0;
    modal(`
      <form class="modal-card" autocomplete="off" role="dialog" aria-modal="true">
        <div class="modal-head"><h3></h3><button type="button" class="icon-btn" data-close aria-label="Close">${svg('x')}</button></div>
        <ol class="steps compact">
          <li><b>Copy</b> every drive and the trash (<span data-size></span>).</li>
          <li><b>Check</b> that the copy is identical.</li>
          <li><b>Switch</b> over. Nobody has to do anything.</li>
        </ol>
        <p class="dialog-text">While it copies, files can be opened and downloaded but not changed. The old copy stays where it is until you remove it.</p>
        <div class="form-stack">${pwField}</div>
        <p class="dialog-error" hidden></p>
        <div class="modal-actions">
          <button type="button" class="btn btn-quiet" data-close>Cancel</button>
          <button type="submit" class="btn btn-primary">${svg('arrow-right-left')}<span>Move the drives</span></button>
        </div>
      </form>`, (ov, close) => {
      ov.querySelector('h3').textContent = 'Move the drives to ' + label + '?';
      ov.querySelector('[data-size]').textContent = '≈ ' + fmtSize(size) + (free != null ? ', ' + fmtSize(free) + ' free there' : '');
      const form = ov.querySelector('form');
      form.password.focus();
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const ok = form.querySelector('[type=submit]');
        busyButton(ok, 'Starting…');
        try {
          await diskAct({ action: 'disk_move', to, password: form.password.value });
          close();
          toast('Moving the drives', 'ok', 'You can follow it at the top of the page.');
          refreshState();
          startProgressPolling();
        } catch (err) { showErr(ov, err.message); idleButton(ok); }
      });
    });
  }

  function removeOldModal(loc, size) {
    modal(`
      <form class="modal-card" autocomplete="off" role="dialog" aria-modal="true">
        <div class="modal-head"><h3>Remove the old copy?</h3><button type="button" class="icon-btn" data-close aria-label="Close">${svg('x')}</button></div>
        <p class="dialog-text danger-text"></p>
        <div class="form-stack">${pwField}</div>
        <p class="dialog-error" hidden></p>
        <div class="modal-actions">
          <button type="button" class="btn btn-quiet" data-close>Cancel</button>
          <button type="submit" class="btn btn-danger-solid">Remove old copy</button>
        </div>
      </form>`, (ov, close) => {
      ov.querySelector('.danger-text').textContent = 'This deletes the old copy of the drives on ' + locName(loc) + (size ? ' (' + fmtSize(size) + ')' : '')
        + '. The drives in use (on ' + locName(state.storage.disk || 'internal') + ') are not touched.';
      const form = ov.querySelector('form');
      form.password.focus();
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const ok = form.querySelector('[type=submit]');
        busyButton(ok, 'Removing…');
        try { await diskAct({ action: 'disk_remove_old', location: loc, password: form.password.value }); close(); toast('Old copy removed'); refreshState(); }
        catch (err) { showErr(ov, err.message); idleButton(ok); }
      });
    });
  }

  function ejectModal(d) {
    modal(`
      <form class="modal-card" role="dialog" aria-modal="true">
        <div class="modal-head"><h3></h3><button type="button" class="icon-btn" data-close aria-label="Close">${svg('x')}</button></div>
        <p class="dialog-text">Black Vault stops using it so it can be unplugged safely. Nothing on it is deleted.</p>
        <p class="dialog-error" hidden></p>
        <div class="modal-actions">
          <button type="button" class="btn btn-quiet" data-close>Cancel</button>
          <button type="submit" class="btn btn-primary">${svg('log-out')}<span>Eject</span></button>
        </div>
      </form>`, (ov, close) => {
      ov.querySelector('h3').textContent = 'Eject ' + d.model + '?';
      const form = ov.querySelector('form');
      form.querySelector('[type=submit]').focus();
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const ok = form.querySelector('[type=submit]');
        busyButton(ok, 'Ejecting…');
        try { await diskAct({ action: 'disk_eject', uuid: d.bv.uuid }); close(); toast('You can unplug ' + d.model + ' now'); }
        catch (err) { showErr(ov, err.message); idleButton(ok); }
      });
    });
  }

  function healthModal(d) {
    const h = d.health || {};
    modal(`
      <div class="modal-card" role="dialog" aria-modal="true">
        <div class="modal-head"><h3></h3><button type="button" class="icon-btn" data-close aria-label="Close">${svg('x')}</button></div>
        <dl class="health-list"></dl>
        <ul class="health-notes"></ul>
        <p class="field-hint" data-when></p>
        <div class="modal-actions"><button type="button" class="btn btn-primary" data-close>Close</button></div>
      </div>`, (ov) => {
      ov.querySelector('h3').textContent = d.model;
      const dl = ov.querySelector('.health-list');
      const row = (k, v) => { if (v == null) return; dl.append(el('dt', null, k), el('dd', null, v)); };
      row('Status', (HEALTH[h.status] || HEALTH.unknown)[0]);
      row('Temperature', h.temp != null ? h.temp + ' °C' : null);
      row('Time switched on', h.hours != null ? nf0.format(h.hours) + ' hours (' + nf1.format(h.hours / 24 / 365) + ' years)' : null);
      row('Wear', h.wear != null ? h.wear + '%' : null);
      row('Size', fmtSize(d.size));
      const ul = ov.querySelector('.health-notes');
      (h.notes || []).forEach((n) => ul.appendChild(el('li', null, n)));
      if (!(h.notes || []).length) ul.remove();
      ov.querySelector('[data-when]').textContent = h.checked ? 'Checked ' + ago(h.checked).toLowerCase() + (h.asleep ? ' (the disk was asleep and was not woken up)' : '') : '';
    });
  }

  // ---------------- Move progress ----------------
  let polling = null;
  function startProgressPolling() {
    if (polling) return;
    polling = setInterval(async () => {
      try {
        const p = await api({ action: 'disk_progress' });
        if (disks) disks.move = p.move;
        state.storage = p.storage;
        const running = p.move && ['running', 'verifying'].includes(p.move.state);
        if (!running) {
          clearInterval(polling); polling = null;
          if (p.move && p.move.state === 'done') toast('The drives were moved', 'ok', 'Everything now lives on ' + locName(p.move.to) + '.');
          else if (p.move && p.move.state === 'failed') toast(p.move.error || 'The move did not finish', 'error');
          await refreshState();
          loadDisks();
        } else {
          renderAlerts();
        }
      } catch { /* keep trying */ }
    }, 2000);
  }

  $('btn-disks-refresh').addEventListener('click', async () => {
    const b = $('btn-disks-refresh');
    b.disabled = true;
    await loadDisks();
    b.disabled = false;
  });
  $('btn-health').addEventListener('click', async () => {
    const b = $('btn-health');
    busyButton(b, 'Checking…');
    try { await diskAct({ action: 'disk_health' }); toast('Disk health updated'); }
    catch (err) { toast(err.message, 'error'); }
    idleButton(b);
    b.disabled = disks && !disks.smart_tool;
  });

  // ---------------- Security ----------------
  let failsDraft = null;
  function renderSecurity() {
    if (failsDraft === null) failsDraft = state.max_fails;
    const [lo, hi] = state.max_fails_range;
    $('fails-val').textContent = failsDraft;
    document.querySelector('[data-step="-1"]').disabled = failsDraft <= lo;
    document.querySelector('[data-step="1"]').disabled = failsDraft >= hi;
    $('fails-hint').textContent = 'Between ' + lo + ' and ' + hi + '. Fewer is safer; more forgives typing mistakes. '
      + (failsDraft !== state.max_fails ? 'Now: ' + state.max_fails + '.' : '');
    $('security-save').disabled = failsDraft === state.max_fails;
  }
  document.querySelectorAll('[data-step]').forEach((b) => b.addEventListener('click', () => {
    const [lo, hi] = state.max_fails_range;
    failsDraft = Math.max(lo, Math.min(hi, failsDraft + Number(b.dataset.step)));
    renderSecurity();
  }));
  $('security-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const n = failsDraft;
    if (await act({ action: 'security', max_fails: n }, 'Saved', 'Accounts now lock after ' + n + ' wrong passwords in a row.')) failsDraft = state.max_fails;
    renderSecurity();
  });

  // ---------------- Activity ----------------
  const EVENTS = {
    setup: 'Accounts set up', login: 'Signed in', login_failed: 'Failed sign-in', user_created: 'Account created',
    user_updated: 'Account changed', user_deleted: 'Account deleted', password_reset: 'Password reset by an administrator',
    password_changed: 'Password changed', password_change_failed: 'Wrong current password', signed_out: 'Sessions closed', settings: 'Settings changed',
    account_locked: 'Account locked', account_unlocked: 'Account unlocked', login_blocked: 'Sign-in to a locked account',
    upgrade: 'Moved to member', admin_created: 'Administrator created', monitor_login: 'Opened the Monitor', monitor_login_failed: 'Failed Monitor sign-in',
    disk_formatted: 'Disk prepared', disk_connected: 'Disk connected', disk_ejected: 'Disk ejected', drives_moving: 'Moving the drives',
    old_copy_removed: 'Old copy removed', disk_auth_failed: 'Wrong password for a disk action',
  };
  const BAD = /failed$|^account_locked$|^login_blocked$/;
  function renderActivity() {
    const box = $('activity');
    box.textContent = '';
    if (!state.activity.length) { box.appendChild(el('p', 'card-text', 'Nothing yet.')); return; }
    state.activity.forEach((e) => {
      const row = el('div', 'act' + (BAD.test(e.event) ? ' bad' : ''));
      row.innerHTML = '<span class="act-dot"></span><div><b></b><small></small></div><time></time>';
      row.querySelector('b').textContent = (EVENTS[e.event] || e.event) + (e.user ? ' · ' + e.user : '');
      const bits = [];
      if (e.detail) bits.push(e.detail);
      if (e.by && e.by !== e.user) bits.push('by ' + e.by);
      if (e.ip) bits.push(e.ip);
      row.querySelector('small').textContent = bits.join(' · ');
      row.querySelector('time').textContent = ago(e.t);
      row.querySelector('time').title = new Date(e.t * 1000).toLocaleString('en-GB');
      box.appendChild(row);
    });
  }

  // ---------------- Row menu ----------------
  let menu = null;
  function closeMenu() { if (menu) { menu.remove(); menu = null; } }
  document.addEventListener('mousedown', (e) => { if (menu && !menu.contains(e.target)) closeMenu(); });
  window.addEventListener('resize', closeMenu);
  function rowMenu(u, anchor) {
    closeMenu();
    menu = el('div', 'menu');
    menu.setAttribute('role', 'menu');
    const add = (icon, label, fn, cls = '') => {
      const b = el('button', 'menu-item ' + cls);
      b.type = 'button'; b.setAttribute('role', 'menuitem');
      b.innerHTML = svg(icon) + '<span></span>';
      b.querySelector('span').textContent = label;
      b.addEventListener('click', () => { closeMenu(); fn(); });
      menu.appendChild(b);
    };
    add('pencil', 'Edit account', () => userModal(u));
    add('key-round', 'Reset password', () => passwordModal(u));
    add('log-out', 'Sign out everywhere', () => act({ action: 'signout', id: u.id }, 'All sessions of ' + u.username + ' were closed'));
    if (u.id !== ME) {
      add(u.locked ? 'lock-open' : 'lock', u.locked ? 'Unlock account' : 'Lock account…', () => (u.locked ? unlock(u) : lockModal(u)));
      menu.appendChild(el('div', 'menu-sep'));
      add('trash-2', 'Delete account…', () => deleteModal(u), 'danger');
    }
    document.body.appendChild(menu);
    const r = anchor.getBoundingClientRect();
    const top = r.bottom + 6 + menu.offsetHeight > innerHeight ? r.top - menu.offsetHeight - 6 : r.bottom + 6;
    menu.style.top = top + 'px';
    menu.style.left = Math.max(8, r.right - menu.offsetWidth) + 'px';
    menu.querySelector('.menu-item').focus();
    menu.addEventListener('keydown', (e) => {
      const items = [...menu.querySelectorAll('.menu-item')];
      const i = items.indexOf(document.activeElement);
      if (e.key === 'Escape') { closeMenu(); anchor.focus(); }
      else if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
    });
  }

  // ---------------- Modals ----------------
  function modal(html, onReady) {
    const ov = el('div', 'modal-overlay');
    ov.innerHTML = html;
    document.body.appendChild(ov);
    const last = document.activeElement;
    const busy = () => !!ov.querySelector('[data-close]:disabled');
    const onKey = (e) => { if (e.key === 'Escape' && !busy()) { e.preventDefault(); close(); } };
    const close = () => { document.removeEventListener('keydown', onKey); ov.remove(); if (last && last.focus) last.focus(); };
    ov.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => { if (!b.disabled) close(); }));
    ov.addEventListener('mousedown', (e) => { if (e.target === ov && !busy()) close(); });
    document.addEventListener('keydown', onKey);
    onReady(ov, close);
    // Keyboard users land inside the dialog
    if (!ov.contains(document.activeElement)) {
      const f = ov.querySelector('input, button[type=submit], .modal-actions button, [data-close]');
      if (f) f.focus();
    }
    return ov;
  }
  function showErr(ov, msg) { const e = ov.querySelector('.dialog-error'); if (!e) return; e.textContent = msg; e.hidden = !msg; }

  function genPassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    const sym = '-_.!?';
    const r = new Uint32Array(16);
    crypto.getRandomValues(r);
    let out = '';
    for (let i = 0; i < 16; i++) {
      if (i === 5 || i === 11) out += sym[r[i] % sym.length];
      else out += chars[r[i] % chars.length];
    }
    return out;
  }

  const passwordField = (label) => `
    <label class="field"><span>${label}</span>
      <div class="pw-wrap">
        <input class="text-input" type="password" name="password" autocomplete="new-password" spellcheck="false" required>
        <button type="button" class="icon-btn" data-show title="Show password" aria-label="Show password">${svg('eye')}</button>
        <button type="button" class="btn btn-sm" data-gen>Generate</button>
      </div>
      <small class="field-hint">At least ${state.min_password} characters. Share it in person, not by e-mail.</small>
    </label>`;
  function wirePassword(ov) {
    const input = ov.querySelector('[name=password]');
    ov.querySelector('[data-show]').addEventListener('click', () => { input.type = input.type === 'password' ? 'text' : 'password'; });
    ov.querySelector('[data-gen]').addEventListener('click', () => {
      input.value = genPassword(); input.type = 'text'; input.focus(); input.select();
      if (navigator.clipboard) navigator.clipboard.writeText(input.value).then(() => toast('Password generated and copied'), () => {}); // clipboard needs https; otherwise copy it by hand
    });
  }

  function userModal(u) {
    const isNew = !u;
    const q = u ? { ...u.quota } : { mode: 'auto', value: 0 };
    const html = `
      <form class="modal-card user-card" autocomplete="off" role="dialog" aria-modal="true" aria-labelledby="um-title">
        <div class="modal-head"><h3 id="um-title"></h3>
          <button type="button" class="icon-btn" data-close aria-label="Close">${svg('x')}</button></div>
        <p class="modal-hint" data-hint></p>
        <div class="form-stack">
          <label class="field"><span>Username</span>
            <input class="text-input" name="username" required maxlength="32" autocapitalize="none" spellcheck="false" placeholder="e.g. alex"></label>
          ${isNew ? passwordField('Password') : ''}
          ${isNew ? `<div class="field"><span>Role</span>
            <div class="seg-choice" role="radiogroup" aria-label="Role">
              <button type="button" data-role="user">Member<small>Their own private drive</small></button>
              <button type="button" data-role="admin">Administrator<small>Accounts + Monitor, no drive</small></button>
            </div>
          </div>` : ''}
          <div class="field" data-storage><span>Storage</span>
            <div class="quota-modes" role="radiogroup" aria-label="Storage quota">
              <button type="button" data-mode="auto"><b>Equal share</b><small>Splits the free space evenly</small></button>
              <button type="button" data-mode="share"><b>Part of the storage</b><small>½, ⅓, ¼… of the space</small></button>
              <button type="button" data-mode="fixed"><b>Fixed size</b><small>An exact amount in GB</small></button>
              <button type="button" data-mode="none"><b>No limit</b><small>Up to the free disk space</small></button>
            </div>
            <div class="quota-detail" data-for="share">
              <div class="chips">${FRACTIONS.map(([v, l]) => `<button type="button" class="chip" data-share="${v}">${l}</button>`).join('')}</div>
              <label class="inline-num"><input class="text-input" type="number" name="share" min="1" max="100" step="any"><span>% of the storage</span></label>
            </div>
            <div class="quota-detail" data-for="fixed">
              <label class="inline-num"><input class="text-input" type="number" name="gb" min="1" step="any"><span>GB</span></label>
            </div>
            <p class="quota-preview" aria-live="polite"></p>
          </div>
        </div>
        <p class="dialog-error" hidden></p>
        <div class="modal-actions">
          <button type="button" class="btn btn-quiet" data-close>Cancel</button>
          <button type="submit" class="btn btn-primary"></button>
        </div>
      </form>`;
    modal(html, (ov, close) => {
      const form = ov.querySelector('form');
      ov.querySelector('#um-title').textContent = isNew ? 'Add user' : 'Edit ' + u.username;
      form.querySelector('[type=submit]').textContent = isNew ? 'Create account' : 'Save changes';
      form.username.value = u ? u.username : '';
      if (isNew) wirePassword(ov);
      let role = u ? u.role : 'user';
      const HINTS = {
        newuser: 'They get an empty private drive. Nobody else, administrators included, can open their files.',
        newadmin: 'Administrators manage accounts and open the Monitor. They have no drive, and wrong passwords never lock them out.',
        user: 'Member with a private drive. The role is fixed: to give someone admin rights, create a separate administrator account.',
        admin: 'Administrator account: manages accounts and the Monitor, with no drive. The role is fixed.',
      };
      const syncRole = () => {
        ov.querySelectorAll('[data-role]').forEach((b) => { b.classList.toggle('on', b.dataset.role === role); b.setAttribute('aria-checked', b.dataset.role === role); });
        ov.querySelector('[data-storage]').hidden = role === 'admin';
        ov.querySelector('[data-hint]').textContent = HINTS[(isNew ? 'new' : '') + (role === 'admin' ? 'admin' : 'user')];
      };
      ov.querySelectorAll('[data-role]').forEach((b) => b.addEventListener('click', () => { role = b.dataset.role; syncRole(); }));
      syncRole();

      if (q.mode === 'share') form.share.value = q.value;
      if (q.mode === 'fixed') form.gb.value = Math.round(q.value / GB);
      if (!form.share.value) form.share.value = 50;
      if (!form.gb.value) form.gb.value = 100;

      const current = () => {
        if (q.mode === 'share') return { mode: 'share', value: Number(form.share.value) || 0 };
        if (q.mode === 'fixed') return { mode: 'fixed', value: Math.round((Number(form.gb.value) || 0) * GB) };
        return { mode: q.mode, value: 0 };
      };
      const preview = () => {
        ov.querySelectorAll('[data-mode]').forEach((b) => { b.classList.toggle('on', b.dataset.mode === q.mode); b.setAttribute('aria-checked', b.dataset.mode === q.mode); });
        ov.querySelectorAll('.quota-detail').forEach((d) => { d.hidden = d.dataset.for !== q.mode; });
        ov.querySelectorAll('[data-share]').forEach((c) => c.classList.toggle('on', Math.abs(Number(c.dataset.share) - Number(form.share.value)) < 0.05));
        const id = u ? u.id : '__new__';
        const others = state.users.filter((x) => x.id !== id);
        const me = { id, role: 'user', quota: current() };
        const lim = limitsFor([...others, me], state.pool.pool)[id];
        const used = u ? u.used : 0;
        const out = ov.querySelector('.quota-preview');
        if (lim == null) out.textContent = 'This drive can grow until the disk is full (' + fmtSize(state.pool.disk_free) + ' free now).';
        else {
          let t = 'This drive gets ' + fmtSize(lim) + '.';
          if (q.mode === 'auto') t += ' It changes by itself when you add or remove people.';
          if (used > lim) t += ' Warning: ' + fmtSize(used) + ' is already stored, so uploads will be blocked until space is freed.';
          out.textContent = t;
        }
      };
      ov.querySelectorAll('[data-mode]').forEach((b) => b.addEventListener('click', () => { q.mode = b.dataset.mode; preview(); }));
      ov.querySelectorAll('[data-share]').forEach((c) => c.addEventListener('click', () => { form.share.value = c.dataset.share; preview(); }));
      form.share.addEventListener('input', preview);
      form.gb.addEventListener('input', preview);
      preview();
      form.username.focus();

      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        showErr(ov, '');
        const body = isNew
          ? { action: 'create', username: form.username.value.trim(), password: form.password.value, role, quota: current() }
          : { action: 'update', id: u.id, username: form.username.value.trim() };
        if (!isNew && role === 'user') body.quota = current();
        const btn = form.querySelector('[type=submit]');
        btn.disabled = true;
        try {
          state = await api(body);
          render();
          close();
          toast(isNew ? 'Account ' + body.username + ' created' : 'Changes saved', 'ok', isNew ? (role === 'admin' ? 'They can open Admin and the Monitor.' : 'Their private drive is ready.') : '');
        } catch (err) { showErr(ov, err.message); }
        finally { btn.disabled = false; }
      });
    });
  }

  function passwordModal(u) {
    modal(`
      <form class="modal-card" autocomplete="off" role="dialog" aria-modal="true">
        <div class="modal-head"><h3></h3>
          <button type="button" class="icon-btn" data-close aria-label="Close">${svg('x')}</button></div>
        <p class="modal-hint">They will be signed out of every device and must use the new password. If the account is locked, this also unlocks it.</p>
        <div class="form-stack">${passwordField('New password')}</div>
        <p class="dialog-error" hidden></p>
        <div class="modal-actions">
          <button type="button" class="btn btn-quiet" data-close>Cancel</button>
          <button type="submit" class="btn btn-primary">Set password</button>
        </div>
      </form>`, (ov, close) => {
      ov.querySelector('h3').textContent = 'Reset password for ' + u.username;
      wirePassword(ov);
      const form = ov.querySelector('form');
      form.password.focus();
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        try { state = await api({ action: 'password', id: u.id, password: form.password.value }); render(); close(); toast('Password updated for ' + u.username); }
        catch (err) { showErr(ov, err.message); }
      });
    });
  }

  function deleteModal(u) {
    modal(`
      <form class="modal-card" autocomplete="off" role="dialog" aria-modal="true">
        <div class="modal-head"><h3></h3>
          <button type="button" class="icon-btn" data-close aria-label="Close">${svg('x')}</button></div>
        <p class="dialog-text danger-text"></p>
        <label class="field"><span></span><input class="text-input" name="confirm" autocomplete="off" spellcheck="false"></label>
        <p class="dialog-error" hidden></p>
        <div class="modal-actions">
          <button type="button" class="btn btn-quiet" data-close>Cancel</button>
          <button type="submit" class="btn btn-danger-solid" disabled>Delete account</button>
        </div>
      </form>`, (ov, close) => {
      ov.querySelector('h3').textContent = 'Delete ' + u.username + '?';
      ov.querySelector('.danger-text').textContent = u.role === 'admin'
        ? 'This deletes the administrator account. It has no files. It cannot be undone.'
        : 'This deletes the account, every file in their drive and their trash (' + fmtSize(u.used) + '). It cannot be undone. To keep the files, lock the account instead.';
      ov.querySelector('.field span').textContent = 'Type “' + u.username + '” to confirm';
      const form = ov.querySelector('form');
      const btn = form.querySelector('[type=submit]');
      form.confirm.addEventListener('input', () => { btn.disabled = form.confirm.value.trim().toLowerCase() !== u.username.toLowerCase(); });
      form.confirm.focus();
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        try { state = await api({ action: 'delete', id: u.id, confirm: form.confirm.value }); render(); close(); toast(u.username + ' was deleted'); }
        catch (err) { showErr(ov, err.message); }
      });
    });
  }

  // ---------------- Start ----------------
  async function refreshState() {
    try { state = await api(); render(); } catch { /* ignore */ }
  }
  $('btn-add').addEventListener('click', () => userModal(null));

  showTab(tab);
  (async () => {
    try { state = await api(); render(); }
    catch (err) { $('users').textContent = err.message; return; }
    loadDisks();            // also feeds the alerts (failing disks, moves…)
  })();
  // Keep "last sign-in", locks and activity fresh while the page is open
  setInterval(() => { if (!document.hidden && !document.querySelector('.modal-overlay')) refreshState(); }, 60000);
})();
