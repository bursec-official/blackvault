/* =====================================================================
   SysMonitor — panel en vivo
   ===================================================================== */
(() => {
  'use strict';

  const POLL_MS = 1500;
  const HISTORY = 80;                     // 80 muestras x 1,5 s = 2 minutos
  const $ = (id) => document.getElementById(id);
  const dpr = Math.min(window.devicePixelRatio || 1, 2);
  const nf0 = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 0 });
  const nf1 = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 1 });

  // ---------------- Formato ----------------
  function bytes(n) {
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    n = Number(n) || 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i > 0 && n < 10 ? nf1 : nf0).format(n) + ' ' + u[i];
  }
  const rate = (n) => bytes(n) + '/s';
  function uptime(sec) {
    const d = Math.floor(sec / 86400), h = Math.floor((sec % 86400) / 3600), m = Math.floor((sec % 3600) / 60);
    return d > 0 ? `${d} d ${h} h` : h > 0 ? `${h} h ${m} min` : `${m} min`;
  }
  const level = (p, warn = 70, crit = 90) => (p >= crit ? 'crit' : p >= warn ? 'warn' : 'ok');
  function setNum(el, value, lvl) {
    el.firstChild.nodeValue = value;
    el.dataset.level = lvl;
  }
  const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

  // ---------------- Charts ----------------
  class Chart {
    /** series: [{ get: (arr) => ..., color: 'var' }] ; fixed max (100) or automatic */
    constructor(canvas, series, { max = null, unit = (v) => v } = {}) {
      this.canvas = canvas;
      this.ctx = canvas.getContext('2d');
      this.series = series.map((s) => ({ ...s, data: new Array(HISTORY).fill(0) }));
      this.max = max;
      this.unit = unit;
    }
    push(values) {
      values.forEach((v, i) => { const d = this.series[i].data; d.push(v); if (d.length > HISTORY) d.shift(); });
      this.draw();
    }
    draw() {
      const c = this.canvas;
      const w = c.clientWidth, h = c.clientHeight;
      if (!w || !h) return;
      if (c.width !== Math.round(w * dpr) || c.height !== Math.round(h * dpr)) { c.width = Math.round(w * dpr); c.height = Math.round(h * dpr); }
      const ctx = this.ctx;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, w, h);

      let max = this.max;
      if (max === null) {
        max = Math.max(...this.series.flatMap((s) => s.data), 1);
        max *= 1.15;
      }
      const padT = 6, padB = 4;
      const innerH = h - padT - padB;
      const step = w / (HISTORY - 1);

      // Guide lines
      ctx.strokeStyle = css('--line');
      ctx.lineWidth = 1;
      ctx.setLineDash([2, 5]);
      [0.25, 0.5, 0.75].forEach((f) => {
        const y = Math.round(padT + innerH * f) + 0.5;
        ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(w, y); ctx.stroke();
      });
      ctx.setLineDash([]);

      this.series.forEach((s, si) => {
        const color = css(s.color);
        const pts = s.data.map((v, i) => [i * step, padT + innerH - Math.min(v / max, 1) * innerH]);
        // Relleno
        ctx.beginPath();
        pts.forEach(([x, y], i) => (i ? ctx.lineTo(x, y) : ctx.moveTo(x, y)));
        ctx.lineTo(w, h); ctx.lineTo(0, h); ctx.closePath();
        const g = ctx.createLinearGradient(0, 0, 0, h);
        g.addColorStop(0, color); g.addColorStop(1, 'transparent');
        ctx.globalAlpha = si === 0 ? 0.22 : 0.12;
        ctx.fillStyle = g;
        ctx.fill();
        ctx.globalAlpha = 1;
        // Line
        ctx.beginPath();
        pts.forEach(([x, y], i) => (i ? ctx.lineTo(x, y) : ctx.moveTo(x, y)));
        ctx.strokeStyle = color;
        ctx.lineWidth = 1.6;
        ctx.lineJoin = 'round';
        ctx.shadowColor = color;
        ctx.shadowBlur = 0;
        ctx.stroke();
        ctx.shadowBlur = 0;
        // Punto actual
        const [lx, ly] = pts[pts.length - 1];
        ctx.fillStyle = color;
        ctx.beginPath(); ctx.arc(lx - 2, ly, 2.6, 0, Math.PI * 2); ctx.fill();
      });

      // Scale (only on charts with an automatic max)
      if (this.max === null) {
        ctx.fillStyle = css('--faint');
        ctx.font = '10px ' + css('--font');
        ctx.textAlign = 'left';
        ctx.fillText(this.unit(max), 2, 10);
      }
    }
  }

  const cpuChart = new Chart($('chart-cpu'), [{ color: '--acc' }], { max: 100 });
  const netChart = new Chart($('chart-net'), [{ color: '--acc' }, { color: '--info' }], { unit: rate });
  const ioChart = new Chart($('chart-io'), [{ color: '--acc' }, { color: '--info' }], { unit: rate });
  window.addEventListener('resize', () => [cpuChart, netChart, ioChart].forEach((c) => c.draw()));
  window.addEventListener('storage', () => [cpuChart, netChart, ioChart].forEach((c) => c.draw()));
  // Redraw when the colour changes in "Customise"
  new MutationObserver(() => [cpuChart, netChart, ioChart].forEach((c) => c.draw()))
    .observe(document.documentElement, { attributes: true, attributeFilter: ['style', 'data-theme'] });

  // ---------------- Registro ----------------
  const logEl = $('log');
  function log(text, lvl = 'ok') {
    const tags = { ok: 'OK', info: 'Info', warn: 'Warning', crit: 'Alert' };
    const row = document.createElement('div');
    row.className = 'log-line ' + lvl;
    const t = document.createElement('time');
    t.textContent = new Date().toLocaleTimeString('en-GB', { hour12: false });
    const tag = document.createElement('span');
    tag.className = 'tag';
    tag.textContent = tags[lvl] || 'Info';
    const msg = document.createElement('span');
    msg.textContent = text;
    row.append(t, tag, msg);
    logEl.prepend(row);
    while (logEl.children.length > 60) logEl.lastChild.remove();
  }
  // One alert per "episode": it does not repeat until the value is back to normal
  const active = {};
  function watch(key, bad, textOn, textOff, lvl = 'warn') {
    if (bad && !active[key]) { active[key] = true; log(textOn, lvl); }
    else if (!bad && active[key]) { active[key] = false; log(textOff, 'ok'); }
  }

  // ---------------- Pintado ----------------
  const coresEl = $('cores');
  let coreEls = [];
  function paintCores(cores) {
    if (coreEls.length !== cores.length) {
      coresEl.textContent = '';
      coreEls = cores.map((_, i) => {
        const w = document.createElement('div'); w.className = 'core';
        const bar = document.createElement('div'); bar.className = 'core-bar';
        const fill = document.createElement('i'); bar.appendChild(fill);
        const lbl = document.createElement('div'); lbl.className = 'core-lbl';
        const a = document.createElement('span'); a.textContent = 'Core ' + i;
        const b = document.createElement('b'); lbl.append(a, b);
        w.append(bar, lbl); coresEl.appendChild(w);
        return { fill, b };
      });
    }
    cores.forEach((p, i) => {
      coreEls[i].fill.style.setProperty('--h', Math.max(p, 2) + '%');
      coreEls[i].fill.className = level(p, 70, 90) === 'ok' ? '' : level(p, 70, 90);
      coreEls[i].b.textContent = nf0.format(p) + '%';
    });
  }

  function bar(el, pct) {
    el.style.width = Math.min(pct, 100) + '%';
    el.className = 'meter-fill' + (pct >= 90 ? ' crit' : pct >= 75 ? ' warn' : '');
  }

  function paintProcs(list) {
    const box = $('procs');
    box.textContent = '';
    const maxCpu = Math.max(100, ...list.map((p) => p.cpu));
    list.forEach((p) => {
      const row = document.createElement('div');
      row.className = 'prow';
      row.setAttribute('role', 'row');
      const cell = (cls, text) => { const s = document.createElement('span'); s.className = cls; s.textContent = text; return s; };
      const cpu = document.createElement('span'); cpu.className = 'cpu r';
      const track = document.createElement('i'); track.style.setProperty('--w', Math.min(p.cpu / maxCpu * 100, 100) + '%');
      const num = document.createElement('b'); num.textContent = nf1.format(p.cpu) + '%';
      cpu.append(track, num);
      row.append(cell('pid', p.pid), cell('user', p.user), cell('name', p.name), cpu, cell('mem r', bytes(p.mem)));
      box.appendChild(row);
    });
  }

  let first = true;
  function paint(s) {
    $('s-host').textContent = s.hostname;
    $('s-os').textContent = s.os;
    $('s-kernel').textContent = 'Kernel ' + s.kernel + ' ' + s.arch;
    $('s-ip').textContent = s.ip || location.hostname;
    $('s-uptime').textContent = uptime(s.uptime);
    $('s-procs').textContent = s.procs.total;

    // CPU
    setNum($('cpu-pct'), nf0.format(s.cpu.percent), level(s.cpu.percent));
    $('cpu-model').textContent = s.cpu.model + ' · ' + s.cpu.cores.length + ' cores';
    $('load-1').textContent = s.cpu.load[0].toFixed(2);
    $('load-5').textContent = s.cpu.load[1].toFixed(2);
    $('load-15').textContent = s.cpu.load[2].toFixed(2);
    $('iowait').textContent = nf1.format(s.cpu.iowait) + '%';
    cpuChart.push([s.cpu.percent]);
    paintCores(s.cpu.cores);

    // Memoria
    const m = s.mem;
    setNum($('mem-pct'), nf0.format(m.percent), level(m.percent, 80, 92));
    $('mem-total').textContent = bytes(m.total) + ' total';
    const used = m.total ? ((m.used - Math.min(m.cache, m.used)) / m.total) * 100 : 0;
    $('mem-bar-used').style.width = Math.max(used, 0) + '%';
    $('mem-bar-cache').style.width = (m.total ? Math.min(m.cache, m.total) / m.total * 100 : 0) + '%';
    $('mem-used').textContent = bytes(m.used);
    $('mem-cache').textContent = bytes(m.cache);
    $('mem-free').textContent = bytes(m.available);
    $('swap-text').textContent = m.swap_total ? bytes(m.swap_used) + ' / ' + bytes(m.swap_total) : 'no swap';
    bar($('swap-bar'), m.swap_percent);

    // Red
    $('net-rx').textContent = rate(s.net.rx_rate);
    $('net-tx').textContent = rate(s.net.tx_rate);
    $('net-iface').textContent = s.net.ifaces.length ? s.net.ifaces[0].name : 'no interfaces';
    netChart.push([s.net.rx_rate, s.net.tx_rate]);
    const ul = $('ifaces');
    ul.textContent = '';
    s.net.ifaces.forEach((i) => {
      const li = document.createElement('li');
      [i.name, '↓ ' + bytes(i.rx_total), '↑ ' + bytes(i.tx_total)].forEach((t) => { const sp = document.createElement('span'); sp.textContent = t; li.appendChild(sp); });
      ul.appendChild(li);
    });

    // Disco
    const d = s.disk;
    setNum($('disk-pct'), nf0.format(d.percent), level(d.percent, 80, 92));
    bar($('disk-bar'), d.percent);
    $('disk-used').textContent = bytes(d.used);
    $('disk-total').textContent = bytes(d.total);
    $('disk-free').textContent = bytes(d.free);
    $('disk-devs').textContent = d.devices.slice(0, 3).join(', ');
    $('io-read').textContent = rate(d.read_rate);
    $('io-write').textContent = rate(d.write_rate);
    ioChart.push([d.read_rate, d.write_rate]);

    // Temperatura
    const t = s.temp;
    $('temp-has').hidden = t.main === null;
    $('temp-none').hidden = t.main !== null;
    if (t.main !== null) {
      const lvl = level(t.main, 75, 90);
      setNum($('temp-main'), nf0.format(t.main), lvl);
      $('temp-source').textContent = t.source || '';
      const tb = $('temp-bar');
      tb.style.width = Math.max(0, Math.min((t.main - 30) / 70 * 100, 100)) + '%';
      tb.className = 'thermo-fill' + (lvl === 'ok' ? '' : ' ' + lvl);
      const sl = $('sensors');
      sl.textContent = '';
      t.sensors.slice(0, 5).forEach((x) => {
        const li = document.createElement('li');
        const n = document.createElement('span'); n.textContent = x.chip + ' · ' + x.label;
        const v = document.createElement('b'); v.textContent = nf0.format(x.value) + ' °C';
        li.append(n, v); sl.appendChild(li);
      });
    }

    // Procesos
    $('procs-meta').textContent = s.procs.total + ' total, ' + s.procs.running + ' running';
    paintProcs(s.procs.top);

    // Avisos
    if (first) { log('Connected to ' + s.hostname + ' (' + s.os + ')', 'info'); first = false; }
    watch('cpu', s.cpu.percent >= 90, 'CPU at ' + nf0.format(s.cpu.percent) + '%', 'CPU back to normal');
    watch('mem', m.percent >= 92, 'Memory almost full (' + nf0.format(m.percent) + '%)', 'Memory back to normal', 'crit');
    watch('disk', d.percent >= 92, 'Disk almost full (' + nf0.format(d.percent) + '%): ' + bytes(d.free) + ' left', 'Disk space recovered', 'crit');
    watch('swap', m.swap_total > 0 && m.swap_percent >= 50, 'Heavy swap usage (' + nf0.format(m.swap_percent) + '%)', 'Swap back to normal');
    watch('temp', t.main !== null && t.main >= 80, 'High temperature: ' + nf0.format(t.main) + ' °C', 'Temperature back to normal', 'crit');
  }

  // ---------------- Polling loop ----------------
  const liveEl = $('live'), liveText = $('live-text');
  let paused = false, timer = null, failures = 0, inflight = false;

  function setLive(state, text) {
    liveEl.className = 'live' + (state === 'ok' ? '' : ' ' + state);
    liveText.textContent = text;
    $('live-led').className = 'led' + (state === 'error' ? ' bad' : state === 'paused' ? ' off' : '');
  }

  async function poll() {
    if (paused || inflight || document.hidden) return schedule();
    inflight = true;
    try {
      const ctrl = new AbortController();
      const to = setTimeout(() => ctrl.abort(), 8000);
      const res = await fetch('api/stats.php', { cache: 'no-store', signal: ctrl.signal });
      clearTimeout(to);
      if (res.status === 401) { location.href = 'login.php'; return; }
      if (!res.ok) throw new Error('HTTP ' + res.status);
      paint(await res.json());
      if (failures) log('Connection restored', 'ok');
      failures = 0;
      setLive('ok', 'Live');
    } catch (e) {
      failures++;
      setLive('error', 'Offline');
      if (failures === 3) log('Cannot read from the server (' + (e.name === 'AbortError' ? 'timed out' : e.message) + ')', 'crit');
    } finally {
      inflight = false;
      schedule();
    }
  }
  function schedule() {
    clearTimeout(timer);
    // On failure, wait longer each time (up to 15 s) so the server is not flooded
    timer = setTimeout(poll, Math.min(POLL_MS * (1 + failures * 2), 15000));
  }

  // Manual pause, and automatic pause when the tab is hidden
  const pauseBtn = $('btn-pause');
  const PLAY = 'M7 4.5v15l12-7.5z', PAUSE = 'M9 5v14M15 5v14';
  pauseBtn.addEventListener('click', () => {
    paused = !paused;
    pauseBtn.setAttribute('aria-pressed', paused);
    pauseBtn.title = paused ? 'Resume updates' : 'Pause updates';
    $('pause-path').setAttribute('d', paused ? PLAY : PAUSE);
    setLive(paused ? 'paused' : 'ok', paused ? 'Paused' : 'Live');
    if (!paused) poll();
  });
  document.addEventListener('visibilitychange', () => { if (!document.hidden && !paused) poll(); });

  // Link to the Admin panel (same server, other port)
  const nav = $('nav-drive');
  const port = nav.dataset.port;
  nav.href = location.protocol + '//' + location.hostname + (port && port !== '80' && port !== '443' ? ':' + port : '') + '/admin.php';

  // ---------------- Disk health (every 5 minutes; never wakes a sleeping disk) ----------------
  const HEALTH = {
    healthy: ['Healthy', 'ok'], warning: ['Needs attention', 'warn'], failing: ['Failing', 'bad'],
    asleep: ['Asleep', 'off'], unknown: ['Health unknown', 'off'], missing: ['Not plugged in', 'off'],
  };
  const ROLE = { system: 'System disk', storage: 'Holds the drives', blackvault: 'Black Vault disk', empty: 'New disk', other: 'Other data', missing: 'Black Vault disk' };
  const ago = (t) => { const s = Date.now() / 1000 - t; return s < 90 ? 'just now' : s < 3600 ? Math.round(s / 60) + ' min ago' : Math.round(s / 3600) + ' h ago'; };
  const csrf = (document.querySelector('input[name=csrf]') || {}).value || '';
  let lastHealthLevel = null;

  async function loadHealth(refresh = false) {
    const box = $('hdisks');
    const btn = $('btn-health-now');
    btn.disabled = true;
    try {
      const res = await fetch('api/disks.php', refresh
        ? { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } }
        : { credentials: 'same-origin' });
      if (res.status === 401) { location.href = 'login.php'; return; }
      const d = await res.json();
      paintHealth(d);
    } catch {
      box.textContent = '';
      const p = document.createElement('p'); p.className = 'dim'; p.textContent = 'Could not read the disks.'; box.appendChild(p);
    } finally { btn.disabled = false; }
  }

  function paintHealth(d) {
    const box = $('hdisks');
    box.textContent = '';
    if (!d.available) {
      const p = document.createElement('p'); p.className = 'dim'; p.textContent = d.error || 'Not available.'; box.appendChild(p);
      $('health-meta').textContent = '';
      return;
    }
    let worst = 'ok';
    let newest = 0;
    d.disks.forEach((x) => {
      const h = HEALTH[x.status] || HEALTH.unknown;
      if (h[1] === 'bad') worst = 'bad'; else if (h[1] === 'warn' && worst !== 'bad') worst = 'warn';
      if (x.checked > newest) newest = x.checked;
      const c = document.createElement('article');
      c.className = 'hdisk lvl-' + h[1] + (x.holds_drives ? ' holds' : '');
      const top = document.createElement('div'); top.className = 'hdisk-top';
      const led = document.createElement('span'); led.className = 'led ' + (h[1] === 'ok' ? '' : h[1]);
      const name = document.createElement('b'); name.textContent = x.model;
      const st = document.createElement('span'); st.className = 'hdisk-status'; st.textContent = h[0];
      top.append(led, name, st);
      const sub = document.createElement('small');
      sub.textContent = [bytes(x.size), x.transport ? x.transport.toUpperCase() : null, ROLE[x.role] || null, x.holds_drives && x.role === 'system' ? 'holds the drives' : null].filter(Boolean).join(' · ');
      const kv = document.createElement('dl'); kv.className = 'hdisk-kv';
      const add = (k, v) => { if (v == null) return; const w = document.createElement('div'); const dt = document.createElement('dt'); dt.textContent = k; const dd = document.createElement('dd'); dd.textContent = v; w.append(dt, dd); kv.appendChild(w); };
      add('Temp', x.temp != null ? x.temp + ' °C' : null);
      add('On for', x.hours != null ? (x.hours >= 48 ? nf0.format(x.hours / 24) + ' days' : x.hours + ' h') : null);
      add('Wear', x.wear != null ? x.wear + '%' : null);
      c.append(top, sub);
      if (kv.children.length) c.appendChild(kv);
      (x.notes || []).forEach((n) => { const p = document.createElement('p'); p.className = 'hdisk-note'; p.textContent = n; c.appendChild(p); });
      box.appendChild(c);
    });
    if (!d.smart_tool) {
      const p = document.createElement('p'); p.className = 'dim hdisk-tip'; p.textContent = 'Install smartmontools on the server to see health details: sudo apt install smartmontools';
      box.appendChild(p);
    }
    $('health-meta').textContent = newest ? 'Checked ' + ago(newest) : '';
    if (lastHealthLevel !== null && worst !== lastHealthLevel && worst !== 'ok') log(worst === 'bad' ? 'A disk is failing: copy its data off it' : 'A disk needs attention', worst === 'bad' ? 'crit' : 'warn');
    lastHealthLevel = worst;
  }
  $('btn-health-now').addEventListener('click', () => loadHealth(true));
  loadHealth();
  setInterval(() => { if (!document.hidden) loadHealth(); }, 5 * 60 * 1000);

  poll();
})();
