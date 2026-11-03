/* =====================================================================
   MiniDrive — interfaz
   ===================================================================== */
(() => {
  'use strict';

  const app = document.getElementById('app');
  const CSRF = app.dataset.csrf;
  const MAX_MB = parseInt(app.dataset.maxMb, 10);
  const SITE = app.dataset.site || 'MiniDrive';
  const $ = (id) => document.getElementById(id);

  const els = {
    list: $('file-list'),
    table: $('table'),
    empty: $('empty-state'),
    noResults: $('no-results'),
    noResultsQ: $('no-results-q'),
    breadcrumb: $('breadcrumb'),
    count: $('item-count'),
    back: $('btn-back'),
    search: $('search'),
    checkAll: $('check-all'),
    selBar: $('selection-bar'),
    selCount: $('sel-count'),
    dropzone: $('dropzone'),
    dropTarget: $('dropzone-target'),
    toasts: $('toast-container'),
    menu: $('ctx-menu'),
    dlFolder: $('btn-download-folder'),
    dlFolderLabel: $('btn-download-folder-label'),
    filesView: $('files-view'),
    trashView: $('trash-view'),
    trashList: $('trash-list'),
    trashEmpty: $('trash-empty'),
    trashCount: $('trash-count'),
    banner: $('storage-banner'),
  };
  const TRASH_DAYS = parseInt(app.dataset.trashDays, 10) || 30;

  // localStorage may be unavailable (private mode): it must never break the app
  const store = {
    get(k) { try { return localStorage.getItem(k); } catch { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch { /* no storage */ } },
  };

  const isTouch = window.matchMedia('(hover: none), (pointer: coarse)').matches;

  // ---------------- Estado ----------------
  let currentPath = '';
  let items = [];          // items of the current folder (from the server)
  let visible = [];        // after filtering and sorting
  const selected = new Set();
  let anchorIndex = null;  // for Shift-click ranges
  let query = '';
  let lastDisk = null;  // latest space figures from the server
  let view = store.get('md-view') || 'list';
  let sort = (() => { try { return JSON.parse(store.get('md-sort')) || { key: 'name', dir: 1 }; } catch { return { key: 'name', dir: 1 }; } })();
  let draggedPaths = null;
  let mode = 'files';      // 'files' | 'trash'
  let storageState = (() => { try { return JSON.parse(app.dataset.storage) || { state: 'ok' }; } catch { return { state: 'ok' }; } })();

  // ---------------- Utilidades ----------------
  function icon(name, cls = '') {
    return `<svg class="ic ${cls}" aria-hidden="true"><use href="#i-${name}"/></svg>`;
  }

  const nf1 = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 1 });
  const nf0 = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 0 });
  function fmtSize(bytes) {
    if (bytes == null) return '';
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0, s = Number(bytes);
    while (s >= 1024 && i < u.length - 1) { s /= 1024; i++; }
    return (i > 0 && s < 10 ? nf1 : nf0).format(s) + ' ' + u[i];
  }
  function fmtDuration(sec) {
    if (!isFinite(sec) || sec < 0) return '';
    if (sec < 60) return Math.max(1, Math.round(sec)) + ' s';
    if (sec < 3600) return Math.round(sec / 60) + ' min';
    return Math.floor(sec / 3600) + ' h ' + Math.round((sec % 3600) / 60) + ' min';
  }
  function fmtDate(ts) {
    const d = new Date(ts * 1000);
    const now = new Date();
    const time = d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    if (d.toDateString() === now.toDateString()) return 'Today, ' + time;
    const y = new Date(now); y.setDate(now.getDate() - 1);
    if (d.toDateString() === y.toDateString()) return 'Yesterday, ' + time;
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) + ', ' + time;
  }
  function plural(n, one, many) { return nf0.format(n) + ' ' + (n === 1 ? one : many); }
  function baseName(p) { const i = p.lastIndexOf('/'); return i === -1 ? p : p.slice(i + 1); }
  function parentOf(p) { const i = p.lastIndexOf('/'); return i === -1 ? '' : p.slice(0, i); }
  function folderLabel(p) { return p === '' ? 'Home' : baseName(p); }

  // ---------------- File types ----------------
  const KINDS = {
    image: { icon: 'file-image', ext: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif', 'tif', 'tiff', 'svg', 'raw', 'cr2', 'nef', 'arw', 'dng', 'avif', 'ico'] },
    video: { icon: 'file-video', ext: ['mp4', 'mov', 'mkv', 'avi', 'webm', 'm4v', 'wmv', 'flv', 'ogv', '3gp', 'mts'] },
    audio: { icon: 'file-music', ext: ['mp3', 'wav', 'flac', 'aac', 'm4a', 'ogg', 'opus', 'wma', 'aiff', 'alac'] },
    pdf: { icon: 'file-text', ext: ['pdf'] },
    doc: { icon: 'file-text', ext: ['doc', 'docx', 'odt', 'rtf', 'pages', 'txt', 'md'] },
    sheet: { icon: 'file-spreadsheet', ext: ['xls', 'xlsx', 'ods', 'csv', 'tsv', 'numbers'] },
    slides: { icon: 'presentation', ext: ['ppt', 'pptx', 'odp', 'key'] },
    archive: { icon: 'file-archive', ext: ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz', 'iso', 'dmg'] },
    code: { icon: 'file-code', ext: ['js', 'ts', 'json', 'html', 'htm', 'css', 'php', 'py', 'sh', 'c', 'cpp', 'h', 'java', 'go', 'rs', 'rb', 'yml', 'yaml', 'xml', 'ini', 'conf', 'sql', 'log'] },
  };
  const EXT_KIND = {};
  for (const k in KINDS) KINDS[k].ext.forEach((e) => { EXT_KIND[e] = k; });

  function extOf(name) { const i = name.lastIndexOf('.'); return i > 0 ? name.slice(i + 1).toLowerCase() : ''; }
  function kindOf(item) {
    if (item.type === 'folder') return { kind: 'folder', icon: 'folder' };
    const k = EXT_KIND[extOf(item.name)];
    return k ? { kind: k, icon: KINDS[k].icon } : { kind: 'other', icon: 'file' };
  }
  const KIND_LABEL = { folder: 'Folder', image: 'Image', video: 'Video', audio: 'Audio', pdf: 'PDF document', doc: 'Document', sheet: 'Spreadsheet', slides: 'Presentation', archive: 'Archive', code: 'Code' };
  function kindLabel(item) {
    const k = kindOf(item).kind;
    if (KIND_LABEL[k]) return KIND_LABEL[k];
    const e = extOf(item.name);
    return e ? e.toUpperCase() + ' file' : 'File';
  }
  function tileHtml(item) {
    const k = kindOf(item);
    return `<span class="tile t-${k.kind}">${icon(k.icon)}</span>`;
  }

  // Types the server can show in the browser (see api/preview.php)
  const PREVIEW = {
    image: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
    video: ['mp4', 'webm', 'ogv', 'mov'],
    audio: ['mp3', 'wav', 'ogg', 'm4a'],
    pdf: ['pdf'],
    text: ['txt', 'md', 'log', 'csv', 'json', 'js', 'css', 'ini', 'conf', 'yaml', 'yml', 'sh', 'py'],
  };
  const TEXT_PREVIEW_MAX = 2 * 1024 * 1024;
  function previewKind(item) {
    if (item.type !== 'file') return null;
    const e = extOf(item.name);
    for (const k in PREVIEW) if (PREVIEW[k].includes(e)) return k;
    return null;
  }

  // ---------------- API ----------------
  async function api(url, options = {}) {
    options.headers = options.headers || {};
    if (options.body && !(options.body instanceof FormData)) options.headers['Content-Type'] = 'application/json';
    if (options.method && options.method !== 'GET') options.headers['X-CSRF-Token'] = CSRF;
    let res;
    try {
      res = await fetch(url, options);
    } catch {
      throw new Error('Cannot reach the server. Check that you are on your home network.');
    }
    if (res.status === 401) { window.location.href = 'login.php'; throw new Error('Session expired'); }
    let data = null;
    try { data = await res.json(); } catch { /* not a JSON answer */ }
    if (!res.ok) {
      const err = new Error((data && data.error) || ('Server error (' + res.status + ')'));
      if (data && data.storage) err.storage = data.storage;
      throw err;
    }
    return data;
  }
  const post = (url, body) => api(url, { method: 'POST', body: JSON.stringify(body) });

  // ---------------- Notifications ----------------
  function toast(message, opts = {}) {
    const type = opts.type || 'ok';
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    const ic = type === 'error' ? 'circle-alert' : type === 'busy' ? 'loader-circle' : 'circle-check';
    el.innerHTML = icon(ic) + '<div class="toast-body"><div class="t-msg"></div><small class="t-sub"></small></div>';
    const set = (msg, sub) => {
      el.querySelector('.t-msg').textContent = msg;
      const s = el.querySelector('.t-sub');
      s.textContent = sub || '';
      s.hidden = !sub;
    };
    set(message, opts.sub);
    if (opts.action) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'toast-action';
      b.textContent = opts.action.label;
      b.addEventListener('click', () => { close(); opts.action.fn(); });
      (opts.sticky ? el.querySelector('.toast-body') : el).appendChild(b);
    }
    // A sticky toast waits for the user (e.g. "Try again" after downloading files)
    if (opts.sticky) {
      el.classList.add('sticky');
      const x = document.createElement('button');
      x.type = 'button';
      x.className = 'toast-close';
      x.setAttribute('aria-label', 'Close');
      x.innerHTML = icon('x');
      x.addEventListener('click', () => close());
      el.appendChild(x);
    }
    els.toasts.appendChild(el);
    let timer = null;
    const close = () => {
      clearTimeout(timer);
      el.classList.add('leaving');
      setTimeout(() => el.remove(), 220);
    };
    const arm = (ms) => { clearTimeout(timer); timer = setTimeout(close, ms); };
    if (type !== 'busy' && !opts.sticky) arm(type === 'error' ? 7000 : opts.action ? 8000 : 4000);
    return {
      update(msg, o = {}) {
        set(msg, o.sub);
        const t = o.type || 'ok';
        el.className = 'toast ' + t;
        el.querySelector('.ic').outerHTML = icon(t === 'error' ? 'circle-alert' : t === 'busy' ? 'loader-circle' : 'circle-check');
        if (t !== 'busy') arm(t === 'error' ? 7000 : o.duration || 5000);
      },
      close,
    };
  }

  // ---------------- Navigation (the folder is in the URL: #/Photos/2026) ----------------
  function pathFromHash() {
    const h = location.hash.replace(/^#\/?/, '');
    if (!h) return '';
    try { return h.split('/').map(decodeURIComponent).join('/'); } catch { return ''; }
  }
  function go(path) {
    const h = path ? '#/' + path.split('/').map(encodeURIComponent).join('/') : '#/';
    if (location.hash === h || (!path && (location.hash === '' || location.hash === '#/'))) loadFolder(path);
    else location.hash = h;
  }
  function route() {
    if (location.hash === '#trash') { showMode('trash'); loadTrash(); }
    else { showMode('files'); loadFolder(pathFromHash()); }
  }
  window.addEventListener('hashchange', route);

  function showMode(m) {
    mode = m;
    els.filesView.hidden = m !== 'files';
    els.trashView.hidden = m !== 'trash';
    $('nav-files').toggleAttribute('aria-current', m === 'files');
    $('nav-trash').toggleAttribute('aria-current', m === 'trash');
    if (m === 'files') $('nav-files').setAttribute('aria-current', 'page'); else $('nav-trash').setAttribute('aria-current', 'page');
    if (m === 'trash') { clearSelection(); document.title = 'Trash · ' + SITE; }
    applyStorageState();
  }

  async function loadFolder(path, { keepSelection = false } = {}) {
    try {
      const data = await api('api/list.php?path=' + encodeURIComponent(path));
      const changed = data.path !== currentPath;
      currentPath = data.path;
      items = data.items;
      if (changed || !keepSelection) { selected.clear(); anchorIndex = null; }
      else {
        const still = new Set(items.map((i) => i.path));
        [...selected].forEach((p) => { if (!still.has(p)) selected.delete(p); });
      }
      if (changed) { query = ''; els.search.value = ''; }
      renderBreadcrumb();
      render();
      updateDisk(data.disk);
      setTrashCount(data.trash);
      if (data.storage) { storageState = data.storage; applyStorageState(); }
      els.back.disabled = currentPath === '';
      document.title = (currentPath ? folderLabel(currentPath) + ' · ' : '') + SITE;
    } catch (err) {
      if (err.storage) { storageState = err.storage; applyStorageState(); items = []; render(); return; }
      toast(err.message, { type: 'error' });
      if (path !== '' && /not found/i.test(err.message)) go('');
    }
  }
  function reload() { return loadFolder(currentPath, { keepSelection: true }); }

  els.back.addEventListener('click', () => { if (currentPath) go(parentOf(currentPath)); });

  function renderBreadcrumb() {
    const bc = els.breadcrumb;
    bc.innerHTML = '';
    const parts = currentPath ? currentPath.split('/') : [];
    const crumbs = [{ label: 'Home', path: '' }];
    let acc = '';
    parts.forEach((p) => { acc = acc ? acc + '/' + p : p; crumbs.push({ label: p, path: acc }); });

    crumbs.forEach((c, i) => {
      if (i > 0) {
        const sep = document.createElement('span');
        sep.className = 'crumb-sep';
        sep.innerHTML = icon('chevron-right');
        bc.appendChild(sep);
      }
      const b = document.createElement('button');
      const isLast = i === crumbs.length - 1;
      b.className = 'crumb' + (isLast ? ' current' : '');
      b.type = 'button';
      if (i === 0) b.innerHTML = icon('house');
      b.appendChild(document.createTextNode(c.label));
      b.title = c.label;
      if (isLast) b.setAttribute('aria-current', 'page');
      else b.addEventListener('click', () => go(c.path));
      addDropTarget(b, c.path);
      bc.appendChild(b);
    });

    els.dlFolderLabel.textContent = currentPath ? 'Download folder' : 'Download all';
    els.dlFolder.title = currentPath
      ? 'Download “' + folderLabel(currentPath) + '” as a .zip'
      : 'Download all of ' + SITE + ' as a .zip';
  }

  function updateDisk(disk) {
    if (!disk) return;
    const fill = $('disk-fill');
    fill.style.width = disk.percent + '%';
    fill.parentElement.setAttribute('aria-valuenow', disk.percent);
    $('disk-label').innerHTML = '<strong>' + fmtSize(disk.used) + '</strong> used of ' + fmtSize(disk.total);
    $('disk-free').textContent = fmtSize(disk.free);
    const fl = $('disk-free-label'); if (fl) fl.textContent = disk.limited ? 'left in your drive' : 'free on the disk';
    lastDisk = disk;
    $('disk-pct').textContent = nf0.format(disk.percent) + '%';
  }

  // ---------------- File list ----------------
  function computeVisible() {
    const q = query.trim().toLocaleLowerCase('en');
    let list = q ? items.filter((i) => i.name.toLocaleLowerCase('en').includes(q)) : items.slice();
    const coll = new Intl.Collator('en', { numeric: true, sensitivity: 'base' });
    list.sort((a, b) => {
      if (a.type !== b.type) return a.type === 'folder' ? -1 : 1;
      let r = 0;
      if (sort.key === 'size') r = (a.size || 0) - (b.size || 0);
      else if (sort.key === 'modified') r = a.modified - b.modified;
      if (r === 0) r = coll.compare(a.name, b.name);
      return r * sort.dir;
    });
    return list;
  }

  function highlight(el, text) {
    const q = query.trim();
    el.textContent = '';
    if (!q) { el.textContent = text; return; }
    const lower = text.toLocaleLowerCase('en');
    const ql = q.toLocaleLowerCase('en');
    let i = 0, j;
    while ((j = lower.indexOf(ql, i)) !== -1) {
      el.appendChild(document.createTextNode(text.slice(i, j)));
      const m = document.createElement('mark');
      m.textContent = text.slice(j, j + q.length);
      el.appendChild(m);
      i = j + q.length;
    }
    el.appendChild(document.createTextNode(text.slice(i)));
  }

  function render() {
    visible = computeVisible();
    const list = els.list;
    list.innerHTML = '';
    list.className = 'file-list ' + (view === 'grid' ? 'grid-view' : 'list-view');
    els.table.classList.toggle('grid-mode', view === 'grid');
    document.querySelectorAll('.seg-btn').forEach((b) => b.classList.toggle('active', b.dataset.view === view));

    // Sorting header
    document.querySelectorAll('.th.sort').forEach((th) => {
      const on = th.dataset.sort === sort.key;
      th.classList.toggle('sorted', on);
      th.querySelector('.sort-ic').innerHTML = on ? icon(sort.dir === 1 ? 'arrow-up' : 'arrow-down') : '';
      th.setAttribute('aria-sort', on ? (sort.dir === 1 ? 'ascending' : 'descending') : 'none');
    });

    const isEmpty = items.length === 0;
    const noMatch = !isEmpty && visible.length === 0;
    els.empty.hidden = !isEmpty;
    els.noResults.hidden = !noMatch;
    els.noResultsQ.textContent = query.trim();
    els.table.hidden = isEmpty || noMatch;
    els.dlFolder.disabled = isEmpty;

    const frag = document.createDocumentFragment();
    visible.forEach((item, index) => frag.appendChild(buildRow(item, index)));
    list.appendChild(frag);

    // Resumen
    const nFolders = items.filter((i) => i.type === 'folder').length;
    const nFiles = items.length - nFolders;
    const bytes = items.reduce((s, i) => s + (i.size || 0), 0);
    const parts = [];
    if (nFolders) parts.push(plural(nFolders, 'folder', 'folders'));
    if (nFiles) parts.push(plural(nFiles, 'file', 'files'));
    els.count.textContent = items.length === 0 ? 'Empty' : parts.join(' and ') + (nFiles ? ', ' + fmtSize(bytes) : '');

    updateSelectionUI();
  }

  function buildRow(item, index) {
    const row = document.createElement('div');
    row.className = 'row' + (selected.has(item.path) ? ' selected' : '');
    row.setAttribute('role', 'listitem');
    row.tabIndex = 0;
    row.dataset.index = index;

    // Casilla
    const check = document.createElement('label');
    check.className = 'check';
    check.title = 'Select';
    check.innerHTML = '<input type="checkbox"><span class="check-box">' + icon('check', 'check-on') + '</span>';
    const cb = check.querySelector('input');
    cb.checked = selected.has(item.path);
    cb.setAttribute('aria-label', 'Select ' + item.name);
    cb.addEventListener('click', (e) => {
      e.stopPropagation();
      if (e.shiftKey && anchorIndex !== null) selectRange(anchorIndex, index, true);
      else { toggleSelect(item.path, cb.checked); anchorIndex = index; }
    });
    check.addEventListener('click', (e) => e.stopPropagation());

    // Nombre
    const cell = document.createElement('div');
    cell.className = 'cell-name';
    cell.innerHTML = tileHtml(item);
    const wrap = document.createElement('div');
    wrap.className = 'name-wrap';
    const name = document.createElement('button');
    name.type = 'button';
    name.className = 'name' + (item.type === 'folder' ? ' is-folder' : '');
    name.tabIndex = -1;
    name.title = item.type === 'folder' ? 'Open “' + item.name + '”'
      : previewKind(item) ? 'View “' + item.name + '”' : 'Download “' + item.name + '”';
    highlight(name, item.name);
    name.addEventListener('click', (e) => { e.stopPropagation(); openItem(item); });
    const meta = document.createElement('div');
    meta.className = 'meta-mobile';
    if (item.type === 'file') {
      const s = document.createElement('span'); s.textContent = fmtSize(item.size); meta.appendChild(s);
    }
    const md = document.createElement('span'); md.className = 'm-date'; md.textContent = fmtDate(item.modified); meta.appendChild(md);
    const kind = document.createElement('div');
    kind.className = 'kind';
    kind.textContent = kindLabel(item);
    wrap.append(name, kind, meta);
    cell.appendChild(wrap);

    const size = document.createElement('div');
    size.className = 'col-size';
    size.textContent = item.type === 'folder' ? '—' : fmtSize(item.size);

    const date = document.createElement('div');
    date.className = 'col-date';
    date.textContent = fmtDate(item.modified);

    // Actions: Download always visible + "more" menu
    const actions = document.createElement('div');
    actions.className = 'actions';
    const dl = document.createElement('button');
    dl.type = 'button';
    dl.className = 'icon-btn accent';
    dl.innerHTML = icon('download');
    dl.title = item.type === 'folder' ? 'Download folder (.zip)' : 'Download';
    dl.setAttribute('aria-label', dl.title + ': ' + item.name);
    dl.addEventListener('click', (e) => { e.stopPropagation(); downloadItems([item]); });
    const more = document.createElement('button');
    more.type = 'button';
    more.className = 'icon-btn more';
    more.innerHTML = icon('ellipsis');
    more.title = 'More actions';
    more.setAttribute('aria-label', 'More actions: ' + item.name);
    more.setAttribute('aria-haspopup', 'menu');
    more.addEventListener('click', (e) => {
      e.stopPropagation();
      const r = more.getBoundingClientRect();
      openMenu(item, r.right, r.bottom + 4, { alignRight: true, anchor: more });
    });
    actions.append(dl, more);

    row.append(check, cell, size, date, actions);

    // Click on a row: select (desktop) / open (mobile)
    row.addEventListener('click', (e) => {
      if (isTouch && !selected.size) { openItem(item); return; }
      if (e.shiftKey && anchorIndex !== null) { selectRange(anchorIndex, index, e.metaKey || e.ctrlKey); return; }
      if (e.metaKey || e.ctrlKey || isTouch) { toggleSelect(item.path); anchorIndex = index; return; }
      selected.clear();
      selected.add(item.path);
      anchorIndex = index;
      syncRows();
    });
    row.addEventListener('dblclick', (e) => {
      if (e.target.closest('.actions, .check')) return;
      openItem(item);
    });
    row.addEventListener('contextmenu', (e) => {
      e.preventDefault();
      if (!selected.has(item.path)) { selected.clear(); selected.add(item.path); anchorIndex = index; syncRows(); }
      openMenu(item, e.clientX, e.clientY);
    });
    row.addEventListener('keydown', (e) => {
      if (e.target !== row) return;
      if (e.key === 'Enter') { e.preventDefault(); openItem(item); }
      else if (e.key === ' ') { e.preventDefault(); toggleSelect(item.path); anchorIndex = index; }
      else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        const next = els.list.children[index + (e.key === 'ArrowDown' ? 1 : -1)];
        if (next) next.focus();
      } else if (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')) {
        e.preventDefault();
        const r = row.getBoundingClientRect();
        openMenu(item, r.left + 60, r.bottom);
      }
    });

    // Drag to move (desktop only)
    if (!isTouch) {
      row.draggable = true;
      row.addEventListener('dragstart', (e) => {
        draggedPaths = selected.has(item.path) ? [...selected] : [item.path];
        row.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', item.name);
      });
      row.addEventListener('dragend', () => { row.classList.remove('dragging'); draggedPaths = null; });
      if (item.type === 'folder') addDropTarget(row, item.path);
    }
    return row;
  }

  function addDropTarget(el, destPath) {
    el.addEventListener('dragover', (e) => {
      if (!draggedPaths || draggedPaths.includes(destPath)) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      el.classList.add('drop-target');
    });
    el.addEventListener('dragleave', () => el.classList.remove('drop-target'));
    el.addEventListener('drop', (e) => {
      el.classList.remove('drop-target');
      if (!draggedPaths) return;
      e.preventDefault();
      e.stopPropagation();
      const paths = draggedPaths;
      draggedPaths = null;
      moveItems(itemsByPath(paths), destPath);
    });
  }

  function itemsByPath(paths) {
    const map = new Map(items.map((i) => [i.path, i]));
    return paths.map((p) => map.get(p)).filter(Boolean);
  }

  // ---------------- Selection ----------------
  function toggleSelect(path, force) {
    const on = force === undefined ? !selected.has(path) : force;
    if (on) selected.add(path); else selected.delete(path);
    syncRows();
  }
  function selectRange(a, b, additive) {
    if (!additive) selected.clear();
    const [from, to] = a < b ? [a, b] : [b, a];
    for (let i = from; i <= to; i++) selected.add(visible[i].path);
    syncRows();
  }
  function clearSelection() { selected.clear(); anchorIndex = null; syncRows(); }
  function syncRows() {
    [...els.list.children].forEach((row) => {
      const item = visible[row.dataset.index];
      const on = selected.has(item.path);
      row.classList.toggle('selected', on);
      row.querySelector('.check input').checked = on;
    });
    updateSelectionUI();
  }
  function updateSelectionUI() {
    const n = selected.size;
    const nVisibleSel = visible.filter((i) => selected.has(i.path)).length;
    els.checkAll.checked = visible.length > 0 && nVisibleSel === visible.length;
    els.checkAll.indeterminate = nVisibleSel > 0 && nVisibleSel < visible.length;
    els.list.classList.toggle('has-selection', n > 0);
    els.selBar.hidden = n === 0;
    if (n) {
      const sel = itemsByPath([...selected]);
      const bytes = sel.reduce((s, i) => s + (i.size || 0), 0);
      const hasFolder = sel.some((i) => i.type === 'folder');
      els.selCount.innerHTML = '';
      els.selCount.append(n + ' selected');
      if (!hasFolder && bytes) {
        const sm = document.createElement('small'); sm.textContent = fmtSize(bytes); els.selCount.appendChild(sm);
      }
      $('sel-download').title = (n === 1 && !hasFolder) ? 'Download' : 'Download as a .zip';
    }
  }

  els.checkAll.addEventListener('change', () => {
    if (els.checkAll.checked) visible.forEach((i) => selected.add(i.path));
    else visible.forEach((i) => selected.delete(i.path));
    syncRows();
  });
  $('sel-clear').addEventListener('click', clearSelection);
  $('sel-download').addEventListener('click', () => downloadItems(itemsByPath([...selected])));
  $('sel-move').addEventListener('click', () => openMoveModal(itemsByPath([...selected])));
  $('sel-delete').addEventListener('click', () => deleteItems(itemsByPath([...selected])));

  // Click on an empty area: clear the selection
  $('content').addEventListener('click', (e) => {
    if (e.target === $('content') || e.target === els.list) clearSelection();
  });

  // ---------------- Abrir / descargar ----------------
  function openItem(item) {
    if (item.type === 'folder') go(item.path);
    else if (previewKind(item)) openPreview(item);
    else downloadItems([item]);
  }

  function triggerDownload(url) {
    const a = document.createElement('a');
    a.href = url;
    a.download = '';
    a.rel = 'noopener';
    document.body.appendChild(a);
    a.click();
    a.remove();
  }

  /**
   * One file -> direct download.
   * Folders or several items -> the server packs them into a .zip on the fly.
   */
  async function downloadItems(list) {
    if (!list.length) return;
    if (list.length === 1 && list[0].type === 'file') {
      triggerDownload('api/download.php?path=' + encodeURIComponent(list[0].path));
      toast('Downloading “' + list[0].name + '”', { sub: fmtSize(list[0].size) });
      return;
    }
    await downloadZip(list.map((i) => i.path), list.length === 1 ? list[0].name : null);
  }

  async function downloadZip(paths, label) {
    const t = toast('Preparing the .zip' + (label ? ' of “' + label + '”' : '') + '…', { type: 'busy', sub: 'Counting files' });
    try {
      const r = await post('api/zip.php', { paths, base: currentPath });
      triggerDownload(r.url);
      t.update('Downloading “' + r.name + '”', {
        sub: plural(r.files, 'file', 'files') + ', ' + fmtSize(r.size) + '. Progress shows in your browser downloads.',
        duration: 8000,
      });
    } catch (err) {
      t.update('Could not prepare the download', { type: 'error', sub: err.message });
    }
  }

  els.dlFolder.addEventListener('click', () => downloadZip([currentPath], folderLabel(currentPath)));

  // ---------------- Item menu ----------------
  let menuAnchor = null;
  function openMenu(item, x, y, { alignRight = false, anchor = null } = {}) {
    const multi = selected.size > 1 && selected.has(item.path);
    const targets = multi ? itemsByPath([...selected]) : [item];
    const m = els.menu;
    m.innerHTML = '';
    const add = (ic, label, fn, cls = '', hint = '') => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'menu-item ' + cls;
      b.setAttribute('role', 'menuitem');
      b.innerHTML = icon(ic) + '<span></span>' + (hint ? '<span class="hint"></span>' : '');
      b.children[1].textContent = label;
      if (hint) b.children[2].textContent = hint;
      b.addEventListener('click', () => { closeMenu(); fn(); });
      m.appendChild(b);
    };
    const sep = () => { const s = document.createElement('div'); s.className = 'menu-sep'; m.appendChild(s); };

    if (multi) {
      add('download', 'Download ' + targets.length + ' as .zip', () => downloadItems(targets));
      add('folder-input', 'Move ' + targets.length + ' to…', () => openMoveModal(targets));
      sep();
      add('trash-2', 'Delete ' + targets.length, () => deleteItems(targets), 'danger');
    } else {
      if (item.type === 'folder') add('folder-open', 'Open', () => go(item.path));
      else if (previewKind(item)) add('eye', 'Preview', () => openPreview(item));
      add('download', item.type === 'folder' ? 'Download as .zip' : 'Download', () => downloadItems([item]));
      sep();
      add('pencil', 'Rename', () => renameItem(item), '', isTouch ? '' : 'F2');
      add('folder-input', 'Move to…', () => openMoveModal([item]));
      sep();
      add('trash-2', 'Delete', () => deleteItems([item]), 'danger', isTouch ? '' : 'Del');
    }

    m.hidden = false;
    const w = m.offsetWidth, h = m.offsetHeight;
    let left = alignRight ? x - w : x;
    let top = y;
    left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
    if (top + h > window.innerHeight - 8) top = Math.max(8, (anchor ? anchor.getBoundingClientRect().top - 4 : y) - h);
    m.style.left = left + 'px';
    m.style.top = top + 'px';
    menuAnchor = anchor;
    const first = m.querySelector('.menu-item');
    if (first) first.focus({ preventScroll: true });
  }
  function closeMenu() {
    if (els.menu.hidden) return;
    els.menu.hidden = true;
    if (menuAnchor) { menuAnchor.focus({ preventScroll: true }); menuAnchor = null; }
  }
  els.menu.addEventListener('keydown', (e) => {
    const its = [...els.menu.querySelectorAll('.menu-item')];
    const i = its.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') { e.preventDefault(); its[(i + 1) % its.length].focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); its[(i - 1 + its.length) % its.length].focus(); }
    else if (e.key === 'Tab') { closeMenu(); }
  });
  document.addEventListener('mousedown', (e) => { if (!els.menu.hidden && !els.menu.contains(e.target)) closeMenu(); });
  window.addEventListener('resize', closeMenu);
  $('content').addEventListener('scroll', closeMenu, { passive: true });

  // ---------------- Generic dialog ----------------
  const dlg = {
    root: $('dialog'), form: $('dialog-form'), title: $('dialog-title'), text: $('dialog-text'),
    input: $('dialog-input'), error: $('dialog-error'), ok: $('dialog-ok'), cancel: $('dialog-cancel'),
    alt: $('dialog-alt'),
  };
  let dlgState = null;

  /**
   * Opens a dialog. onSubmit(value) may be async; if it throws, the error is shown
   * inside the dialog, which stays open (so a name can be corrected).
   */
  function openDialog({ title, text = '', value = null, selectUntilDot = false, okLabel = 'OK', danger = false, onSubmit, alt = null, onCancel = null }) {
    closeMenu();
    dlg.title.textContent = title;
    dlg.text.textContent = text;
    dlg.error.hidden = true;
    dlg.ok.textContent = okLabel;
    dlg.ok.className = 'btn ' + (danger ? 'btn-danger-solid' : 'btn-primary');
    dlg.ok.disabled = false;
    const hasInput = value !== null;
    dlg.input.hidden = !hasInput;
    dlg.alt.hidden = !alt;
    dlg.alt.textContent = alt ? alt.label : '';
    dlg.form.classList.toggle('has-alt', !!alt);
    dlgState = { onSubmit, hasInput, alt, onCancel, prevFocus: document.activeElement };
    dlg.root.hidden = false;
    if (hasInput) {
      dlg.input.value = value;
      dlg.input.focus();
      const dot = value.lastIndexOf('.');
      dlg.input.setSelectionRange(0, selectUntilDot && dot > 0 ? dot : value.length);
    } else {
      (danger ? dlg.cancel : dlg.ok).focus();
    }
  }
  function closeDialog(chosen = false) {
    const st = dlgState;
    dlg.root.hidden = true;
    dlgState = null;
    if (st && st.prevFocus && st.prevFocus.focus) st.prevFocus.focus({ preventScroll: true });
    if (st && !chosen && st.onCancel) st.onCancel();
  }
  dlg.form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!dlgState) return;
    const val = dlgState.hasInput ? dlg.input.value.trim() : true;
    if (dlgState.hasInput && !val) { showDlgError('Enter a name.'); return; }
    dlg.ok.disabled = true;
    try {
      await dlgState.onSubmit(val);
      closeDialog(true);
    } catch (err) {
      showDlgError(err.message);
      dlg.ok.disabled = false;
    }
  });
  function showDlgError(msg) {
    dlg.error.textContent = msg;
    dlg.error.hidden = false;
    if (dlgState && dlgState.hasInput) dlg.input.focus();
  }
  dlg.input.addEventListener('input', () => { dlg.error.hidden = true; });
  dlg.cancel.addEventListener('click', () => closeDialog());
  dlg.alt.addEventListener('click', () => { const a = dlgState && dlgState.alt; closeDialog(true); if (a) a.fn(); });
  dlg.root.addEventListener('mousedown', (e) => { if (e.target === dlg.root) closeDialog(); });

  function validName(n) {
    if (/[\/\\]/.test(n)) throw new Error('The name cannot contain “/” or “\\”.');
    if (n === '.' || n === '..') throw new Error('That name is not allowed.');
  }

  // ---------------- Create / rename / delete / move ----------------
  $('btn-newfolder').addEventListener('click', () => {
    openDialog({
      title: 'New folder',
      text: 'It will be created in “' + folderLabel(currentPath) + '”.',
      value: 'Untitled folder',
      okLabel: 'Create folder',
      onSubmit: async (name) => {
        validName(name);
        await post('api/mkdir.php', { path: currentPath, name });
        toast('Folder “' + name + '” created');
        await loadFolder(currentPath);
      },
    });
  });

  function renameItem(item) {
    openDialog({
      title: 'Rename',
      value: item.name,
      selectUntilDot: item.type === 'file',
      okLabel: 'Rename',
      onSubmit: async (name) => {
        if (name === item.name) return;
        validName(name);
        await post('api/rename.php', { path: item.path, new_name: name });
        toast('Renamed to “' + name + '”');
        selected.delete(item.path);
        await reload();
      },
    });
  }

  /** Delete = move to the Trash. No question asked: there is an Undo. */
  async function deleteItems(list) {
    if (!list.length) return;
    const one = list.length === 1 ? list[0] : null;
    const ids = [];
    const failed = [];
    for (const it of list) {
      try { const r = await post('api/delete.php', { path: it.path }); ids.push(r.trash_id); selected.delete(it.path); }
      catch (err) { failed.push(it.name + ': ' + err.message); }
    }
    if (ids.length) {
      toast(one ? '“' + one.name + '” moved to the trash' : plural(ids.length, 'item', 'items') + ' moved to the trash', {
        sub: 'Kept for ' + TRASH_DAYS + ' days.',
        action: { label: 'Undo', fn: () => restoreItems(ids, true) },
      });
    }
    failed.slice(0, 4).forEach((f) => toast(f, { type: 'error' }));
    await reload();
  }

  async function restoreItems(ids, fromUndo = false) {
    try {
      const r = await post('api/trash.php', { action: 'restore', ids });
      (r.errors || []).forEach((e) => toast(e, { type: 'error' }));
      if (r.done) toast(fromUndo ? 'Restored' : plural(r.done, 'item restored', 'items restored'), { sub: !fromUndo && r.restored.length === 1 ? 'Back in ' + (parentOf(r.restored[0]) ? folderLabel(parentOf(r.restored[0])) : 'Home') + '.' : '' });
      setTrashCount(r.items.length);
      if (mode === 'trash') renderTrash(r); else await reload();
      if (r.disk) updateDisk(r.disk);
    } catch (err) { toast(err.message, { type: 'error' }); }
  }

  // ---------------- Trash ----------------
  function setTrashCount(n) {
    if (n == null) return;
    els.trashCount.textContent = n > 99 ? '99+' : String(n);
    els.trashCount.hidden = !n;
  }

  async function loadTrash() {
    try { renderTrash(await api('api/trash.php')); }
    catch (err) {
      if (err.storage) { storageState = err.storage; applyStorageState(); }
      else toast(err.message, { type: 'error' });
      renderTrash({ items: [], size: 0 });
    }
  }

  function renderTrash(data) {
    const list = els.trashList;
    list.textContent = '';
    const items = data.items || [];
    setTrashCount(items.length);
    if (data.disk) updateDisk(data.disk);
    els.trashEmpty.hidden = items.length > 0;
    list.hidden = !items.length;
    $('btn-empty-trash').disabled = !items.length || storageState.state !== 'ok';
    $('trash-sub').textContent = items.length
      ? plural(items.length, 'item', 'items') + ' · ' + fmtSize(data.size) + '. Deleted items wait here for ' + TRASH_DAYS + ' days, then they are removed for good. They still count in your drive until then.'
      : 'Deleted items wait here for ' + TRASH_DAYS + ' days, then they are removed for good.';
    const now = Date.now() / 1000;
    items.forEach((it) => {
      const row = document.createElement('div');
      row.className = 'trow';
      row.setAttribute('role', 'listitem');
      const cell = document.createElement('div');
      cell.className = 'cell-name';
      cell.innerHTML = tileHtml(it);
      const wrap = document.createElement('div');
      wrap.className = 'name-wrap';
      const nm = document.createElement('span');
      nm.className = 'name';
      nm.textContent = it.name;
      const from = document.createElement('div');
      from.className = 'kind';
      from.textContent = 'From ' + (it.from ? it.from.split('/').join(' › ') : 'Home');
      wrap.append(nm, from);
      cell.appendChild(wrap);

      const when = document.createElement('div');
      when.className = 't-when';
      const left = Math.max(0, Math.ceil((it.expires - now) / 86400));
      when.innerHTML = '<span></span><small></small>';
      when.querySelector('span').textContent = 'Deleted ' + fmtDate(it.deleted);
      when.querySelector('small').textContent = left <= 1 ? 'Removed for good tomorrow' : 'Removed for good in ' + left + ' days';
      if (left <= 3) when.classList.add('soon');

      const size = document.createElement('div');
      size.className = 'col-size';
      size.textContent = fmtSize(it.size);

      const acts = document.createElement('div');
      acts.className = 'actions t-actions';
      const rs = document.createElement('button');
      rs.type = 'button';
      rs.className = 'btn btn-sm';
      rs.innerHTML = icon('rotate-ccw') + '<span>Restore</span>';
      rs.setAttribute('aria-label', 'Restore ' + it.name);
      rs.disabled = storageState.state !== 'ok';
      rs.addEventListener('click', () => restoreItems([it.id]));
      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'icon-btn';
      del.innerHTML = icon('trash-2');
      del.title = 'Delete for good';
      del.setAttribute('aria-label', 'Delete ' + it.name + ' for good');
      del.disabled = storageState.state !== 'ok';
      del.addEventListener('click', () => openDialog({
        title: 'Delete “' + it.name + '” for good?',
        text: (it.type === 'folder' ? 'The folder and everything in it will be deleted. ' : '') + 'This cannot be undone.',
        okLabel: 'Delete for good',
        danger: true,
        onSubmit: () => trashAction({ action: 'delete', ids: [it.id] }, '“' + it.name + '” deleted for good'),
      }));
      acts.append(rs, del);

      row.append(cell, when, size, acts);
      list.appendChild(row);
    });
  }

  async function trashAction(body, okMsg) {
    try {
      const r = await post('api/trash.php', body);
      renderTrash(r);
      toast(okMsg, { sub: r.disk ? fmtSize(r.disk.free) + ' free in your drive now.' : '' });
    } catch (err) { toast(err.message, { type: 'error' }); }
  }

  $('btn-empty-trash').addEventListener('click', () => openDialog({
    title: 'Empty the trash?',
    text: 'Everything in the trash will be deleted for good. This cannot be undone.',
    okLabel: 'Empty trash',
    danger: true,
    onSubmit: () => trashAction({ action: 'empty' }, 'The trash is empty'),
  }));

  // ---------------- Drives offline / being moved ----------------
  function applyStorageState() {
    const st = storageState || { state: 'ok' };
    const ok = st.state === 'ok';
    els.banner.hidden = ok;
    if (!ok) {
      els.banner.className = 'storage-banner ' + (st.state === 'offline' ? 'bad' : 'info');
      els.banner.innerHTML = icon(st.state === 'offline' ? 'plug' : 'arrow-right-left') + '<div><b></b><span></span></div>';
      els.banner.querySelector('b').textContent = st.state === 'offline' ? 'Your files are offline for a moment' : 'Your files are being moved to a new disk';
      els.banner.querySelector('span').textContent = st.state === 'offline'
        ? 'The disk that holds them is not connected. Nothing is lost; ask your administrator to plug it back in.'
        : 'You can open and download everything, but changes are paused until it finishes.';
    }
    ['btn-upload', 'btn-upload-folder', 'btn-newfolder'].forEach((id) => { $(id).disabled = !ok || mode === 'trash'; });
  }

  async function moveItems(list, dest) {
    list = list.filter((i) => i.path !== dest && !(i.type === 'folder' && dest.startsWith(i.path + '/')));
    if (!list.length) return;
    let ok = 0, same = 0;
    const failed = [];
    for (const it of list) {
      try {
        const r = await post('api/move.php', { path: it.path, dest });
        if (r && r.unchanged) same++; else { ok++; selected.delete(it.path); }
      } catch (err) { failed.push(it.name + ': ' + err.message); }
    }
    if (ok) toast((ok === 1 && list.length === 1 ? '“' + list[0].name + '” moved' : plural(ok, 'item moved', 'items moved')) + ' to “' + folderLabel(dest) + '”');
    else if (same && !failed.length) toast('Already in “' + folderLabel(dest) + '”');
    failed.slice(0, 4).forEach((f) => toast(f, { type: 'error' }));
    await reload();
  }

  // ---------------- Modal "Mover a…" ----------------
  const mv = { root: $('move-modal'), list: $('move-folder-list'), title: $('move-title'), confirm: $('move-confirm') };
  let moving = [];
  let moveTarget = null;

  async function openMoveModal(list) {
    closeMenu();
    if (!list.length) return;
    moving = list;
    moveTarget = null;
    mv.confirm.disabled = true;
    mv.title.textContent = list.length === 1 ? 'Move “' + list[0].name + '”' : 'Move ' + list.length + ' items';
    mv.list.innerHTML = '<div class="move-empty">Loading folders…</div>';
    mv.root.hidden = false;
    try {
      const data = await api('api/tree.php');
      const excluded = list.filter((i) => i.type === 'folder').map((i) => i.path);
      const folders = data.folders.filter((f) => !excluded.some((x) => f.path === x || f.path.startsWith(x + '/')));
      mv.list.innerHTML = '';
      const addRow = (label, path, depth, ic) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'move-row';
        b.setAttribute('role', 'option');
        b.style.paddingLeft = (10 + depth * 20) + 'px';
        b.innerHTML = icon(ic);
        b.appendChild(document.createTextNode(label));
        if (path === currentPath) {
          const s = document.createElement('span');
          s.style.cssText = 'margin-left:auto;color:var(--faint);font-size:12px';
          s.textContent = 'current folder';
          b.appendChild(s);
        }
        b.addEventListener('click', () => {
          mv.list.querySelectorAll('.selected').forEach((x) => x.classList.remove('selected'));
          b.classList.add('selected');
          moveTarget = path;
          mv.confirm.disabled = false;
        });
        b.addEventListener('dblclick', () => { moveTarget = path; confirmMove(); });
        mv.list.appendChild(b);
      };
      addRow('Home', '', 0, 'house');
      folders.forEach((f) => addRow(f.name, f.path, f.depth + 1, 'folder'));
    } catch (err) {
      mv.list.innerHTML = '';
      const d = document.createElement('div');
      d.className = 'move-empty';
      d.textContent = 'Could not load the folder list: ' + err.message;
      mv.list.appendChild(d);
    }
  }
  function closeMoveModal() { mv.root.hidden = true; moving = []; }
  async function confirmMove() {
    if (moveTarget === null || !moving.length) return;
    const list = moving, dest = moveTarget;
    closeMoveModal();
    await moveItems(list, dest);
  }
  mv.confirm.addEventListener('click', confirmMove);
  $('move-close').addEventListener('click', closeMoveModal);
  $('move-cancel').addEventListener('click', closeMoveModal);
  mv.root.addEventListener('mousedown', (e) => { if (e.target === mv.root) closeMoveModal(); });

  // ---------------- Preview ----------------
  const pv = { root: $('preview-modal'), body: $('preview-body'), name: $('preview-item-name') };
  let previewing = null;
  const previewUrl = (p) => 'api/preview.php?path=' + encodeURIComponent(p);

  async function openPreview(item) {
    closeMenu();
    previewing = item;
    pv.name.textContent = item.name;
    pv.name.title = item.name;
    pv.root.hidden = false;
    pv.body.innerHTML = '<div class="preview-fallback">Loading…</div>';
    const kind = previewKind(item);

    const mount = (el) => { pv.body.innerHTML = ''; pv.body.appendChild(el); };
    if (kind === 'image') {
      const img = new Image();
      img.alt = item.name;
      img.onerror = () => previewFallback(item, 'Could not load the image.');
      img.src = previewUrl(item.path);
      mount(img);
    } else if (kind === 'video' || kind === 'audio') {
      const m = document.createElement(kind);
      m.controls = true;
      m.preload = 'metadata';
      m.onerror = () => previewFallback(item, 'Your browser cannot play this file. Download it to view it.');
      m.src = previewUrl(item.path);
      mount(m);
    } else if (kind === 'pdf') {
      const f = document.createElement('iframe');
      f.src = previewUrl(item.path);
      f.title = item.name;
      mount(f);
    } else if (kind === 'text') {
      if (item.size > TEXT_PREVIEW_MAX) {
        previewFallback(item, 'Too large to view here (over 2 MB).');
      } else {
        try {
          const res = await fetch(previewUrl(item.path));
          if (!res.ok) throw new Error('HTTP ' + res.status);
          const pre = document.createElement('pre');
          pre.textContent = await res.text();
          if (previewing === item) mount(pre);
        } catch (err) {
          previewFallback(item, 'Could not load the file (' + err.message + ').');
        }
      }
    } else {
      previewFallback(item, 'This file type cannot be viewed in the browser.');
    }
  }
  function previewFallback(item, message) {
    pv.body.innerHTML = '<div class="preview-fallback">' + tileHtml(item) + '<div class="pf-msg"></div>'
      + '<button class="btn btn-primary" type="button">' + icon('download') + '<span>Download</span></button></div>';
    pv.body.querySelector('.pf-msg').textContent = message;
    pv.body.querySelector('button').addEventListener('click', () => downloadItems([item]));
  }
  function closePreview() {
    pv.root.hidden = true;
    pv.body.querySelectorAll('video, audio').forEach((m) => { m.pause(); m.removeAttribute('src'); m.load(); });
    pv.body.innerHTML = '';
    previewing = null;
  }
  $('preview-download').addEventListener('click', () => { if (previewing) downloadItems([previewing]); });
  $('preview-close').addEventListener('click', closePreview);
  pv.root.addEventListener('mousedown', (e) => { if (e.target === pv.root) closePreview(); });

  // ---------------- Uploads ----------------
  // At most 15 files per request, well under PHP's max_file_uploads (which
  // silently drops the rest), and never more bytes than post_max_size.
  const UPLOAD_BATCH = 15;                       // files per request at most…
  const BATCH_BYTES = 256 * 1024 * 1024;         // …and about this many bytes (big files go alone)
  // What the server really accepts (php.ini), so a request is never refused as a whole
  const LIMITS = (() => { try { return JSON.parse(app.dataset.uploadLimits) || {}; } catch { return {}; } })();
  const up = { panel: $('upload-panel'), title: $('upload-title'), pct: $('upload-pct'), fill: $('upload-fill'), sub: $('upload-sub') };
  let uploading = false;

  function uploadChunk(chunk, dest, onProgress) {
    return new Promise((resolve, reject) => {
      const fd = new FormData();
      fd.append('path', dest);
      // Numbered fields: if the browser leaves a file out, the server says which one
      fd.append('count', String(chunk.length));
      // and its size, so a file that arrives empty or cut short is caught too
      chunk.forEach((o, i) => {
        fd.append('files[' + i + ']', o.file);
        fd.append('relpaths[' + i + ']', o.relpath || '');
        fd.append('sizes[' + i + ']', String(o.file.size));
      });
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'api/upload.php');
      xhr.setRequestHeader('X-CSRF-Token', CSRF);
      xhr.upload.addEventListener('progress', (e) => { if (e.lengthComputable) onProgress(e.loaded); });
      xhr.onload = () => {
        if (xhr.status === 401) { window.location.href = 'login.php'; return; }
        let data;
        try { data = JSON.parse(xhr.responseText); }
        catch { reject(new Error(xhr.status === 413 ? 'The file is too large for the server' : 'Invalid server response (' + xhr.status + ')')); return; }
        if (xhr.status < 200 || xhr.status >= 300) { const er = new Error(data.error || 'HTTP error ' + xhr.status); er.status = xhr.status; er.storage = data.storage; reject(er); return; }
        resolve(data);
      };
      xhr.onerror = () => reject(new Error('The upload stopped: connection lost, or the file could not be read on this computer (if it is in iCloud, download it first)'));
      xhr.send(fd);
    });
  }

  // Files macOS adds to folders by itself (not the user's): skipped quietly
  const isSystemJunk = (name) => name === '.DS_Store' || name === 'Icon\r' || name === '.localized' || name.startsWith('._') || name === 'Thumbs.db' || name === 'desktop.ini';

  /**
   * Second chance for a file the browser did not really send: the page reads
   * the file itself and sends a copy under a plain name; the real name travels
   * in "relpaths" and is the one the server keeps.
   */
  const RESCUE_MAX = 1024 * 1024 * 1024;
  class Unreadable extends Error {}
  async function uploadRescued(o, dest) {
    if (o.file.size > RESCUE_MAX) throw new Error('The browser could not send this file and it is too big to retry (' + fmtSize(o.file.size) + '). Try with Chrome or Firefox.');
    let buf;
    try { buf = await o.file.arrayBuffer(); }
    catch (e) { throw new Unreadable((e && e.name) || 'Error'); }
    if (buf.byteLength !== o.file.size) throw new Unreadable('SizeMismatch');
    const dot = o.file.name.lastIndexOf('.');
    const ext = dot > 0 ? o.file.name.slice(dot).replace(/[^A-Za-z0-9.]/g, '') : '';
    const copy = new File([buf], 'upload' + ext, { type: o.file.type || 'application/octet-stream', lastModified: o.file.lastModified });
    return uploadChunk([{ file: copy, relpath: o.relpath || o.file.name }], dest, () => {});
  }

  /** Asks what to do with files that are already in the drive. Resolves 'skip', 'all' or 'cancel'. */
  function askAboutDuplicates(dupCount, newCount, dest) {
    return new Promise((resolve) => {
      const where = '“' + folderLabel(dest) + '”';
      if (newCount === 0) {
        openDialog({
          title: 'These files are already in ' + where,
          text: (dupCount === 1 ? 'It has' : 'All ' + nf0.format(dupCount) + ' have') + ' the same name and size as the ones you chose, so there is nothing new to upload.',
          okLabel: 'Upload copies anyway',
          onSubmit: () => resolve('all'),
          onCancel: () => resolve('cancel'),
        });
        return;
      }
      openDialog({
        title: plural(dupCount, 'file is', 'files are') + ' already in ' + where,
        text: 'Same name and size as the ones you chose. ' + (newCount === 1 ? 'Only the remaining file' : 'Only the other ' + plural(newCount, 'file', 'files')) + ' will be uploaded.',
        okLabel: 'Upload ' + plural(newCount, 'new file', 'new files'),
        alt: { label: 'Upload all (keep both)', fn: () => resolve('all') },
        onSubmit: () => resolve('skip'),
        onCancel: () => resolve('cancel'),
      });
    });
  }

  async function uploadFiles(objs, opts = {}) {
    objs = objs.filter((o) => !isSystemJunk(o.file.name));
    if (!objs.length) return;
    if (uploading) { toast('Wait for the current upload to finish', { type: 'error' }); return; }
    uploading = true;
    try { await runUpload(objs, opts); }
    finally {
      uploading = false;
      window.removeEventListener('beforeunload', warnUnload);
      up.panel.hidden = true;
    }
  }

  async function runUpload(objs, opts) {
    const dest = opts.dest !== undefined ? opts.dest : currentPath;
    const limit = Math.min(MAX_MB * 1024 * 1024, LIMITS.file || Infinity);
    const tooBig = objs.filter((o) => o.file.size > limit);
    if (tooBig.length) toast(plural(tooBig.length, 'file is', 'files are') + ' bigger than the ' + fmtSize(limit) + ' the server accepts and will be skipped', { type: 'error', sub: tooBig.slice(0, 3).map((o) => o.relpath || o.file.name).join(', ') });
    let list = objs.filter((o) => o.file.size <= limit);
    if (!list.length) return;

    // Already in the drive? (uploading a folder again only sends what is missing)
    if (!opts.retry && list.length <= 20000) {
      let same = [];
      try { same = (await post('api/exists.php', { path: dest, items: list.map((o) => ({ p: o.relpath || o.file.name, s: o.file.size })) })).same || []; }
      catch { same = []; } // only a convenience: without it, copies get "(1)" names as before
      if (same.length) {
        const choice = await askAboutDuplicates(same.length, list.length - same.length, dest);
        if (choice === 'cancel') return;
        if (choice === 'skip') { const skip = new Set(same); list = list.filter((_, i) => !skip.has(i)); }
        if (!list.length) return;
      }
    }

    // Check the drive's remaining space before sending anything
    const need = list.reduce((s, o) => s + o.file.size, 0);
    if (lastDisk && need > lastDisk.free) {
      toast('Not enough space in your drive', { type: 'error', sub: 'This upload needs ' + fmtSize(need) + ' and ' + fmtSize(lastDisk.free) + ' is left. Ask the administrator for more space or free some up.' });
      return;
    }

    // Batches by count AND size, never above what PHP accepts in one request
    const maxFiles = Math.max(1, Math.min(UPLOAD_BATCH, LIMITS.files || UPLOAD_BATCH));
    const maxBytes = Math.max(1, Math.min(BATCH_BYTES, LIMITS.post ? LIMITS.post * 0.9 : BATCH_BYTES));
    const chunks = [];
    let cur = [], curBytes = 0;
    for (const o of list) {
      if (cur.length && (cur.length >= maxFiles || curBytes + o.file.size > maxBytes)) { chunks.push(cur); cur = []; curBytes = 0; }
      cur.push(o); curBytes += o.file.size;
    }
    if (cur.length) chunks.push(cur);
    const total = need || 1;
    let doneBefore = 0, okCount = 0;
    const failed = [];       // { name, error } that trying again will not fix (too big, no space, bad name…)
    const unreadable = [];   // files the browser could not open on this computer
    const incomplete = [];   // files that did not reach the server whole, even on the second try
    const errNames = new Set();
    const t0 = performance.now();

    up.panel.hidden = false;
    window.addEventListener('beforeunload', warnUnload);
    const paint = (done) => {
      const pct = Math.min(100, Math.floor((done / total) * 100));
      up.fill.style.width = pct + '%';
      up.pct.textContent = pct + ' %';
      const secs = (performance.now() - t0) / 1000;
      const speed = secs > 0.5 ? done / secs : 0;
      up.sub.textContent = fmtSize(done) + ' of ' + fmtSize(total)
        + (speed ? ', ' + fmtSize(speed) + '/s, ' + fmtDuration((total - done) / speed) + ' left' : '');
    };
    up.title.textContent = (opts.retry ? 'Trying again: ' : 'Uploading ') + plural(list.length, 'file', 'files') + ' to “' + folderLabel(dest) + '”';
    paint(0);

    // Results of one request: the files the browser did not really send come
    // back as "missing" and get one second chance each.
    const absorb = async (data, sentChunk, isRescue) => {
      if (data.disk) updateDisk(data.disk);
      const toRescue = [];
      (data.results || []).forEach((r) => {
        if (r.ok) okCount++;
        else if (r.missing && isRescue) incomplete.push(sentChunk[0]);
        else if (r.missing && sentChunk[r.i]) toRescue.push(sentChunk[r.i]);
        else failed.push(r);
      });
      for (const o of toRescue) await rescue(o);
    };
    const rescue = async (o) => {
      up.sub.textContent = 'Trying “' + baseName(o.relpath || o.file.name) + '” another way…';
      try { await absorb(await uploadRescued(o, dest), [o], true); }
      catch (e) {
        if (e instanceof Unreadable) { unreadable.push(o); errNames.add(e.message); }
        else if (e.storage || e.status === 401) failed.push({ name: o.relpath || o.file.name, error: e.message });
        else incomplete.push(o);
      }
    };

    for (const chunk of chunks) {
      const bytes = chunk.reduce((s, o) => s + o.file.size, 0);
      try {
        await absorb(await uploadChunk(chunk, dest, (loaded) => paint(doneBefore + loaded)), chunk);
      } catch (err) {
        if (err.storage || err.status === 401) {
          chunk.forEach((o) => failed.push({ name: o.relpath || o.file.name, error: err.message }));
        } else {
          // One bad file must not sink the others: send them one by one
          let sent = 0;
          for (const o of chunk) {
            try { await absorb(await uploadChunk([o], dest, (loaded) => paint(doneBefore + sent + loaded)), [o]); }
            catch (e1) {
              if (e1.storage || e1.status === 401 || e1.status === 413) failed.push({ name: o.relpath || o.file.name, error: e1.message });
              else await rescue(o);
            }
            sent += o.file.size;
          }
        }
      }
      doneBefore += bytes;
      paint(doneBefore);
    }

    window.removeEventListener('beforeunload', warnUnload);
    up.panel.hidden = true;
    if (okCount) toast(plural(okCount, 'file uploaded', 'files uploaded') + ' to “' + folderLabel(dest) + '”');
    const names = (arr) => arr.slice(0, 3).map((x) => baseName(x.relpath || x.name || x.file.name)).join(', ') + (arr.length > 3 ? ' and ' + nf0.format(arr.length - 3) + ' more' : '');
    const retryAll = [...unreadable, ...incomplete];
    const tryAgain = { label: 'Try again', fn: () => uploadFiles(retryAll, { retry: true, dest }) };
    if (unreadable.length) {
      console.warn('MiniDrive: the browser could not read', unreadable.length, 'files', [...errNames], unreadable.map((o) => o.relpath || o.file.name));
      toast(plural(unreadable.length, 'file could not be opened on this computer', 'files could not be opened on this computer'), {
        type: 'error', sticky: true, action: tryAgain,
        sub: 'The browser cannot read ' + (unreadable.length === 1 ? 'it' : 'them') + '. Most likely ' + (unreadable.length === 1 ? 'it is' : 'they are')
          + ' in iCloud and not downloaded to this Mac: in Finder, right-click the folder, choose Download Now and wait until the cloud icons are gone. Then press Try again. ('
          + names(unreadable) + '. Browser error: ' + [...errNames].join(', ') + ')',
      });
    }
    if (incomplete.length) {
      toast(plural(incomplete.length, 'file did not reach the server whole', 'files did not reach the server whole'), {
        type: 'error', sticky: !unreadable.length, action: unreadable.length ? null : tryAgain,
        sub: 'Even on a second try. Check the connection to the server and press Try again. (' + names(incomplete) + ')',
      });
    }
    if (failed.length) {
      // One message per reason, with a few of the names, instead of a wall of toasts
      const byReason = new Map();
      failed.forEach((f) => { const k = f.error || 'Unknown error'; if (!byReason.has(k)) byReason.set(k, []); byReason.get(k).push(f); });
      [...byReason].slice(0, 3).forEach(([why, items]) => toast(why, { type: 'error', sub: plural(items.length, 'file', 'files') + ' not uploaded: ' + names(items) }));
    }
    if (dest === currentPath) reload();
  }
  function warnUnload(e) { e.preventDefault(); e.returnValue = ''; }

  // The pickers are emptied just BEFORE choosing again, never after an upload:
  // Safari throws away the chosen files as soon as an <input type=file> is reset,
  // and "Try again" still needs them.
  $('btn-upload').addEventListener('click', () => { $('file-input').value = ''; $('file-input').click(); });
  $('file-input').addEventListener('change', (e) => {
    uploadFiles([...e.target.files].map((f) => ({ file: f, relpath: null })));
  });
  $('btn-upload-folder').addEventListener('click', () => { $('folder-input').value = ''; $('folder-input').click(); });
  $('folder-input').addEventListener('change', (e) => {
    uploadFiles([...e.target.files].map((f) => ({ file: f, relpath: f.webkitRelativePath || f.name })));
  });

  // Folders dropped from the desktop are walked with the entries API
  function readEntry(entry, base) {
    return new Promise((resolve) => {
      if (entry.isFile) {
        entry.file((file) => resolve([{ file, relpath: base + file.name }]), () => resolve([]));
      } else if (entry.isDirectory) {
        const reader = entry.createReader();
        const all = [];
        const batch = () => reader.readEntries(async (ents) => {
          if (!ents.length) {
            const res = await Promise.all(all.map((c) => readEntry(c, base + entry.name + '/')));
            resolve(res.flat());
            return;
          }
          all.push(...ents);
          batch();
        }, () => resolve([]));
        batch();
      } else resolve([]);
    });
  }
  async function handleDrop(dt) {
    const its = dt.items ? [...dt.items] : [];
    const entries = its.map((it) => (it.webkitGetAsEntry ? it.webkitGetAsEntry() : null)).filter(Boolean);
    if (!entries.length) { uploadFiles([...dt.files].map((f) => ({ file: f, relpath: null }))); return; }
    const res = (await Promise.all(entries.map((e) => readEntry(e, '')))).flat();
    if (!res.length) { toast('No files found to upload', { type: 'error' }); return; }
    uploadFiles(res);
  }

  let dragDepth = 0;
  const isExternal = (e) => !draggedPaths && e.dataTransfer && [...(e.dataTransfer.types || [])].includes('Files');
  window.addEventListener('dragenter', (e) => {
    if (!isExternal(e) || mode !== 'files' || storageState.state !== 'ok') return;
    e.preventDefault();
    dragDepth++;
    els.dropTarget.textContent = folderLabel(currentPath);
    els.dropzone.classList.add('active');
  });
  window.addEventListener('dragleave', (e) => {
    if (!isExternal(e)) return;
    dragDepth = Math.max(0, dragDepth - 1);
    if (!dragDepth) els.dropzone.classList.remove('active');
  });
  window.addEventListener('dragover', (e) => { if (isExternal(e)) e.preventDefault(); });
  window.addEventListener('drop', (e) => {
    if (!isExternal(e)) return;
    e.preventDefault();
    if (mode !== 'files' || storageState.state !== 'ok') return;
    dragDepth = 0;
    els.dropzone.classList.remove('active');
    handleDrop(e.dataTransfer);
  });

  // ---------------- Search, sorting, view, theme ----------------
  els.search.addEventListener('input', () => { query = els.search.value; render(); });
  els.search.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { els.search.value = ''; query = ''; render(); els.search.blur(); }
    if (e.key === 'Enter' && visible.length === 1) openItem(visible[0]);
  });

  document.querySelectorAll('.th.sort').forEach((th) => th.addEventListener('click', () => {
    const k = th.dataset.sort;
    sort = sort.key === k ? { key: k, dir: -sort.dir } : { key: k, dir: k === 'name' ? 1 : -1 };
    store.set('md-sort', JSON.stringify(sort));
    render();
  }));

  document.querySelectorAll('.seg-btn').forEach((b) => b.addEventListener('click', () => {
    view = b.dataset.view;
    store.set('md-view', view);
    render();
  }));

  // Link to the Monitor (same server, another port)
  // (the Monitor link is set up by account.js, and only exists for administrators)

  // ---------------- Keyboard shortcuts ----------------
  window.addEventListener('keydown', (e) => {
    const typing = /^(INPUT|TEXTAREA)$/.test(document.activeElement.tagName);
    if (e.key === 'Escape') {
      if (!els.menu.hidden) { closeMenu(); return; }
      if (!dlg.root.hidden) { closeDialog(); return; }
      if (!pv.root.hidden) { closePreview(); return; }
      if (!mv.root.hidden) { closeMoveModal(); return; }
      if (selected.size && !typing) { clearSelection(); return; }
    }
    if (typing || !dlg.root.hidden || !pv.root.hidden || !mv.root.hidden || mode !== 'files') return;
    if (e.key === '/') { e.preventDefault(); els.search.focus(); }
    else if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'a') {
      e.preventDefault(); visible.forEach((i) => selected.add(i.path)); syncRows();
    } else if ((e.key === 'Delete' || (e.metaKey && e.key === 'Backspace')) && selected.size) {
      e.preventDefault(); deleteItems(itemsByPath([...selected]));
    } else if (e.key === 'F2' && selected.size === 1) {
      e.preventDefault(); renameItem(itemsByPath([...selected])[0]);
    } else if (e.key === 'Backspace' && currentPath) {
      e.preventDefault(); go(parentOf(currentPath));
    }
  });

  // ---------------- Arranque ----------------
  applyStorageState();
  route();
  // While the drives are offline or being moved, check again now and then
  setInterval(() => { if (storageState.state !== 'ok' && !document.hidden) { if (mode === 'trash') loadTrash(); else reload(); } }, 15000);
})();
