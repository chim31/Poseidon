// Poseidon — interface web (JavaScript natif, aucune dépendance, aucun CDN).

import { flagFor, flagSvg, termColor } from './flags.js';

const letter = (i) => h('span', { class: 'letter', 'aria-hidden': 'true', text: flagFor(i).letter });

const $ = (id) => document.getElementById(id);
const h = (tag, attrs = {}, ...children) => {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') el.className = v;
    else if (k === 'text') el.textContent = v;
    else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else el.setAttribute(k, v === true ? '' : v);
  }
  for (const c of children.flat()) if (c !== null && c !== undefined && c !== false) el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  return el;
};
const icon = (name) => {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
  use.setAttribute('href', '#i-' + name);
  svg.append(use);
  return svg;
};
const nf = new Intl.NumberFormat('fr-FR');
const store = {
  get(key, fallback) { try { const v = localStorage.getItem('poseidon.' + key); return v === null ? fallback : JSON.parse(v); } catch { return fallback; } },
  set(key, value) { try { localStorage.setItem('poseidon.' + key, JSON.stringify(value)); } catch { /* navigation privée */ } },
};

const FORMAT_GROUPS = {
  pdf: ['pdf'],
  word: ['doc', 'docx', 'docm', 'dotx', 'dot', 'rtf'],
  excel: ['xls', 'xlsx', 'xlsm', 'xltx', 'xlt', 'ods'],
  csv: ['csv', 'tsv'],
  office: ['odt', 'odp', 'pptx', 'pptm', 'ppt', 'pps'],
  text: ['txt', 'text', 'md', 'markdown', 'rst'],
  mail: ['eml', 'msg'],
  image: ['png', 'jpg', 'jpeg', 'tif', 'tiff', 'bmp', 'gif', 'webp'],
  code: ['log', 'json', 'xml', 'html', 'htm', 'css', 'js', 'ts', 'php', 'py', 'java', 'c', 'h', 'cpp', 'cs', 'go', 'rs', 'rb', 'sh', 'bat', 'cmd', 'ps1', 'sql', 'yml', 'yaml', 'ini', 'conf', 'cfg', 'env', 'properties', 'toml'],
};

const REASONS = {
  protected: { label: 'Protégé', icon: 'lock' },
  denied: { label: 'Accès refusé', icon: 'lock' },
  corrupt: { label: 'Illisible', icon: 'alert' },
  too_large: { label: 'Trop volumineux', icon: 'alert' },
  timeout: { label: 'Délai dépassé', icon: 'alert' },
  limit: { label: 'Limite atteinte', icon: 'archive' },
  unsupported: { label: 'Non pris en charge', icon: 'alert' },
};

const state = {
  info: null,
  terms: [],
  lastParams: null,
  results: [],
  notices: [],
  summary: null,
  sort: { key: 'total', dir: 'desc' },
  selectedId: null,
  checked: new Set(),
  shown: 300,
  controller: null,
  preview: null,
  occurrence: -1,
  pickedColumns: [],
  alertDismissed: false,
};

/* ------------------------------------------------------------------ Filtre */

function splitTerms(input, mode) {
  const value = input.trim();
  if (!value) return [];
  if (mode === 'phrase' || mode === 'regex') return [value];  // proximité : découpage comme « tous les mots »
  const terms = [];
  const re = /"([^"]+)"|«\s*([^»]+?)\s*»|(\S+)/gu;
  let m;
  while ((m = re.exec(value))) {
    let term = (m[1] || m[2] || m[3] || '').trim();
    if (!term.includes(' ')) term = term.replace(/^[\p{P}]+|[\p{P}]+$/gu, '');
    if (term && !terms.includes(term)) terms.push(term);
  }
  return terms.length ? terms : [value];
}

function currentMode() {
  return document.querySelector('input[name="mode"]:checked').value;
}

function renderHoist() {
  const terms = splitTerms($('q').value, currentMode());
  const hoist = $('hoist');
  const previous = [...hoist.querySelectorAll('.pennant')].map((p) => p.dataset.term);
  hoist.replaceChildren(...terms.map((t, i) => {
    const pennant = h('span', { class: 'pennant', 'data-term': t }, flagSvg(i, { title: t }), letter(i), h('span', { text: t }));
    if (previous[i] === t) pennant.style.animation = 'none';
    return pennant;
  }));
}

function selectedExtensions() {
  const pressed = [...document.querySelectorAll('#formats .chip[aria-pressed="true"]')].map((c) => c.dataset.format);
  if (pressed.includes('all') || pressed.length === 0) return [];
  return pressed.flatMap((f) => FORMAT_GROUPS[f] || []);
}

function searchParams() {
  return {
    root: $('root').value.trim(),
    q: $('q').value.trim(),
    mode: currentMode(),
    caseSensitive: $('opt-case').checked ? '1' : '0',
    wholeWord: $('opt-word').checked ? '1' : '0',
    ignoreAccents: $('opt-accents').checked ? '1' : '0',
    fuzzy: $('opt-fuzzy').checked ? '1' : '0',
    distance: $('distance').value || '5',
    archives: $('opt-archives').checked ? '1' : '0',
    target: $('opt-target').value,
    name: $('opt-name').value.trim(),
    extensions: selectedExtensions().join(','),
  };
}

/** Remplit le formulaire à partir de paramètres (historique, lien partagé, modèle). */
function applyParams(p) {
  if (p.root !== undefined && p.root !== '') $('root').value = p.root;
  if (p.q !== undefined) $('q').value = p.q;
  const mode = document.querySelector(`input[name="mode"][value="${p.mode || 'all'}"]`);
  if (mode) mode.checked = true;
  const bool = (v, d) => (v === undefined ? d : v === true || v === '1' || v === 'true');
  $('opt-case').checked = bool(p.caseSensitive, false);
  $('opt-word').checked = bool(p.wholeWord, false);
  $('opt-accents').checked = bool(p.ignoreAccents, true);
  $('opt-fuzzy').checked = bool(p.fuzzy, false);
  $('opt-archives').checked = bool(p.archives, true);
  if (p.target) $('opt-target').value = p.target;
  if (p.name !== undefined) $('opt-name').value = p.name;
  if (p.distance !== undefined) $('distance').value = p.distance;
  applyExtensions(String(p.extensions || ''));
  syncModeFields();
  renderHoist();
}

function applyExtensions(list) {
  const exts = list.split(/[\s,;]+/).filter(Boolean);
  const chips = [...$('formats').querySelectorAll('.chip')];
  chips.forEach((c) => {
    const group = FORMAT_GROUPS[c.dataset.format];
    c.setAttribute('aria-pressed', String(c.dataset.format === 'all' ? exts.length === 0 : !!group && exts.length > 0 && group.some((e) => exts.includes(e))));
  });
}

function syncModeFields() {
  $('distance-field').hidden = currentMode() !== 'near';
}

/* ------------------------------------------------------------------ Historique et partage */

const HISTORY_KEYS = ['root', 'q', 'mode', 'caseSensitive', 'wholeWord', 'ignoreAccents', 'fuzzy', 'distance', 'archives', 'target', 'name', 'extensions'];
const pick = (p) => Object.fromEntries(HISTORY_KEYS.filter((k) => p[k] !== undefined).map((k) => [k, p[k]]));
const sameSearch = (a, b) => HISTORY_KEYS.every((k) => String(a[k] ?? '') === String(b[k] ?? ''));

function recordHistory(params, matched) {
  const entry = { ...pick(params), at: Date.now(), matched };
  const recent = [entry, ...store.get('history', []).filter((h) => !sameSearch(h, entry))].slice(0, 15);
  store.set('history', recent);
}

function togglePin(params) {
  const pinned = store.get('pinned', []);
  const exists = pinned.some((h) => sameSearch(h, params));
  store.set('pinned', exists ? pinned.filter((h) => !sameSearch(h, params)) : [{ ...pick(params), at: Date.now() }, ...pinned].slice(0, 30));
  renderHistory();
}

function historyItem(entry, pinned) {
  const isPinned = pinned.some((p) => sameSearch(p, entry));
  const modeLabel = { all: 'tous les mots', any: 'un des mots', phrase: 'phrase', regex: 'regex', near: `proximité ${entry.distance || 5}` }[entry.mode || 'all'];
  return h('div', { class: 'history-item' },
    h('button', { type: 'button', onclick: () => { applyParams(entry); toggleHistory(false); runSearch(); } },
      h('span', { class: 'hq', text: entry.q }),
      h('span', { class: 'hr', text: `${entry.root} · ${modeLabel}${entry.matched !== undefined ? ` · ${nf.format(entry.matched)} fichier(s)` : ''}` })),
    h('button', { class: 'icon-btn pin', type: 'button', 'aria-pressed': String(isPinned), title: isPinned ? 'Retirer des recherches enregistrées' : 'Enregistrer cette recherche', onclick: () => togglePin(entry) }, icon('star')));
}

function renderHistory() {
  const pinned = store.get('pinned', []);
  const recent = store.get('history', []);
  $('history-pinned').replaceChildren(h('h3', { text: 'Recherches enregistrées' }), ...(pinned.length ? pinned.map((x) => historyItem(x, pinned)) : [h('p', { class: 'history-empty', text: 'Clique sur l\'étoile d\'une recherche récente pour la garder ici.' })]));
  $('history-recent').replaceChildren(h('h3', { text: 'Récentes' }), ...(recent.length ? recent.map((x) => historyItem(x, pinned)) : [h('p', { class: 'history-empty', text: 'Aucune recherche pour l\'instant.' })]));
}

function toggleHistory(force) {
  const open = force ?? $('history').hidden;
  $('history').hidden = !open;
  $('history-btn').setAttribute('aria-expanded', String(open));
  if (open) renderHistory();
}

function shareLink() {
  const params = state.lastParams || searchParams();
  return `${location.origin}${location.pathname}#${new URLSearchParams(pick(params))}`;
}

/* ------------------------------------------------------------------ Modèles d'extraction */

let templates = [];
async function loadTemplates() {
  try {
    templates = (await (await api('/api/templates')).json()).templates;
  } catch { templates = []; }
  const select = $('template-select');
  const current = select.value;
  select.replaceChildren(h('option', { value: '', text: 'Aucun modèle' }), ...templates.map((t) => h('option', { value: t.name, text: t.name })));
  select.value = templates.some((t) => t.name === current) ? current : '';
  $('template-delete').hidden = !select.value || state.isAdmin === false;
}

function applyTemplate(name) {
  const t = templates.find((x) => x.name === name);
  $('template-delete').hidden = !t || state.isAdmin === false;
  if (!t) return;
  applyParams({ ...t.query, ...t.options });
  const x = t.extraction || {};
  const radio = (n, v) => { const el = document.querySelector(`input[name="${n}"][value="${v}"]`); if (el) el.checked = true; };
  radio('extract', x.extract || 'matches');
  radio('format', x.format || 'csv');
  $('ex-columns').value = x.columns || '';
  $('ex-header').value = x.headerRow ?? 1;
  const b = (v, d) => (v === undefined ? d : v === true || v === '1' || v === 'true');
  $('ex-allrows').checked = b(x.allRows, false);
  $('ex-source').checked = b(x.withSource, true);
  $('ex-unique').checked = b(x.unique, false);
  syncExtractForm();
  toast('ok', 'Modèle chargé', `« ${t.name} » : choisis la racine puis « Sonder » ou « Créer le document ».`, { icon: 'check' });
}

async function saveTemplate() {
  const name = $('template-name').value.trim();
  if (!name) { $('template-name').focus(); return; }
  const body = {
    ...searchParams(),
    extract: document.querySelector('input[name="extract"]:checked').value,
    format: document.querySelector('input[name="format"]:checked').value,
    columns: $('ex-columns').value, headerRow: $('ex-header').value,
    allRows: $('ex-allrows').checked, withSource: $('ex-source').checked, unique: $('ex-unique').checked,
    templateName: name,
  };
  try {
    await api('/api/templates', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    $('template-name').value = '';
    await loadTemplates();
    $('template-select').value = name;
    $('template-delete').hidden = false;
    toast('ok', 'Modèle enregistré', `« ${name} » est rejouable sur n'importe quel dossier.`, { icon: 'check' });
  } catch (err) {
    toast('error', 'Enregistrement impossible', err.message);
  }
}

async function deleteTemplate() {
  const name = $('template-select').value;
  if (!name) return;
  try {
    await api('/api/templates/delete', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name }) });
    toast('ok', 'Modèle supprimé', `« ${name} »`, { icon: 'check' });
  } catch (err) {
    toast('error', 'Suppression impossible', err.message);
  }
  loadTemplates();
}

/* ------------------------------------------------------------------ API */

async function api(path, options = {}) {
  const res = await fetch(path, { ...options, headers: { 'X-Poseidon': '1', ...(options.headers || {}) } });
  if (res.status === 401 && state.info?.authRequired) { showLogin(); throw new Error('Authentification requise.'); }
  if (res.status === 421) throw new Error('Hôte non autorisé : ouvre Poseidon via 127.0.0.1 ou localhost.');
  if (!res.ok) {
    let message = `Erreur ${res.status}`;
    try { message = (await res.json()).error || message; } catch { /* corps non JSON */ }
    throw new Error(message);
  }
  return res;
}

/* ------------------------------------------------------------------ Recherche */

async function runSearch() {
  const params = searchParams();
  if (!params.root) { toast('error', 'Racine manquante', 'Indique le dossier à fouiller (chemin local, C:\\…, \\\\serveur\\partage).'); $('root').focus(); return; }
  if (!params.q) { toast('error', 'Filtre vide', 'Tape au moins un mot, une phrase ou un motif.'); $('q').focus(); return; }

  state.controller?.abort();
  const controller = new AbortController();
  state.controller = controller;
  state.terms = splitTerms(params.q, params.mode);
  state.lastParams = params;
  state.results = [];
  state.notices = [];
  state.summary = null;
  state.selectedId = null;
  state.checked.clear();
  state.shown = 300;
  state.alertDismissed = false;
  $('alert').hidden = true;
  state.changes = null;
  $('changes-band').hidden = true;
  $('changes').hidden = true;
  rememberRoot(params.root);
  closeRail();

  $('empty-start').hidden = true;
  $('empty-none').hidden = true;
  $('diff').hidden = true;
  $('summary').hidden = false;
  $('progress').hidden = false;
  $('progress-text').textContent = 'Parcours de ' + params.root + '…';
  $('go').setAttribute('aria-busy', 'true');
  $('go').textContent = 'En cours…';
  showPane('empty');
  renderTermTotals();
  renderSummary();
  renderTable();

  const started = performance.now();
  let pending = false;
  const schedule = () => {
    if (pending) return;
    pending = true;
    requestAnimationFrame(() => { pending = false; renderSummary(); renderTable(); });
  };

  try {
    const res = await api('/api/search?' + new URLSearchParams(params), { signal: controller.signal });
    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    for (;;) {
      const { value, done } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      let nl;
      while ((nl = buffer.indexOf('\n')) >= 0) {
        const line = buffer.slice(0, nl);
        buffer = buffer.slice(nl + 1);
        if (line.trim()) handleEvent(JSON.parse(line), schedule);
      }
      $('stat-duration').textContent = formatMs(performance.now() - started);
    }
  } catch (err) {
    if (err.name !== 'AbortError') toast('error', 'Recherche impossible', err.message);
    else toast('warn', 'Recherche annulée', `${nf.format(state.results.length)} fichier(s) trouvé(s) avant l'arrêt.`);
  } finally {
    if (state.controller === controller) {
      state.controller = null;
      $('progress').hidden = true;
      $('go').removeAttribute('aria-busy');
      $('go').textContent = 'Sonder';
      renderSummary();
      renderTable();
      finalNoticeToast();
    }
  }
}

function handleEvent(event, schedule) {
  switch (event.type) {
    case 'match':
      state.results.push(event);
      schedule();
      break;
    case 'notice':
      state.notices.push(event);
      noticeToast(event);
      schedule();
      break;
    case 'changes':
      state.changes = event.comparison;
      renderChangesBand();
      break;
    case 'progress':
      $('progress-text').textContent = `${nf.format(event.scanned)} fichiers parcourus · ${event.current}`;
      $('stat-scanned').textContent = nf.format(event.scanned);
      break;
    case 'done':
      state.summary = event.summary;
      if (state.lastParams) recordHistory(state.lastParams, event.summary.matched);
      $('stat-scanned').textContent = nf.format(event.summary.scanned);
      $('stat-duration').textContent = formatMs(event.summary.durationMs);
      $('stat-skipped-row').hidden = !event.summary.index;
      if (event.summary.index) $('stat-skipped').textContent = nf.format(event.summary.index.skippedFiles);
      if (event.summary.truncated) toast('warn', 'Résultats tronqués', 'La limite de résultats est atteinte : affine le filtre.');
      break;
    default:
      break;
  }
}

function plural(label, n) {
  return n > 1 ? label.replace(/(\p{L}+)$/u, (w) => (/[sx]$/.test(w) ? w : w + 's')) : label;
}

/** Alerte en ligne regroupée, mise à jour à chaque fichier ignoré : visible sans rien recouvrir. */
function noticeToast(last) {
  const counts = {};
  for (const n of state.notices) counts[n.reason] = (counts[n.reason] || 0) + 1;
  const total = state.notices.length;
  $('alert-title').textContent = total === 1 ? '1 fichier ignoré' : `${nf.format(total)} fichiers ignorés`;
  $('alert-detail').textContent = '— ' + Object.entries(counts).map(([r, c]) => `${c} ${plural((REASONS[r] || REASONS.corrupt).label.toLowerCase(), c)}`).join(' · ');
  $('alert-path').textContent = '\u200E' + last.path;
  $('alert-path').title = last.path;
  if (!state.alertDismissed) $('alert').hidden = false;
}

function finalNoticeToast() {}

/* ------------------------------------------------------------------ Synthèse */

function renderTermTotals() {
  $('term-totals').replaceChildren(...state.terms.map((t, i) => h('span', { class: 'term-total', title: t, 'data-i': i }, flagSvg(i, { title: t }), letter(i), h('span', { text: '0' }))));
}

function renderSummary() {
  const files = state.results.length;
  const totals = state.terms.map((_, i) => state.results.reduce((s, r) => s + (r.counts[i] || 0), 0));
  $('sum-files').textContent = nf.format(files);
  $('sum-occ').textContent = nf.format(totals.reduce((a, b) => a + b, 0));
  $('sum-zip').textContent = nf.format(state.results.filter((r) => r.file.archiveDepth > 0).length);
  $('sum-notices').textContent = nf.format(state.notices.length);
  $('sum-notices-btn').hidden = state.notices.length === 0;
  $('term-totals').querySelectorAll('.term-total').forEach((el) => { el.lastChild.textContent = nf.format(totals[+el.dataset.i] || 0); });
  $('compare-btn').disabled = state.checked.size !== 2;
  $('extract-btn').disabled = files === 0 && !state.controller;

  const done = !state.controller;
  $('empty-none').hidden = !(done && files === 0 && state.lastParams);
  if (done && files === 0 && state.lastParams) {
    $('empty-none-text').textContent = state.lastParams.mode === 'all' && state.terms.length > 1
      ? 'Aucun fichier ne contient tous ces mots. Essaie le mode « Un des mots », ou retire un terme.'
      : 'Vérifie l\'orthographe, élargis les formats, ou active « Fouiller les ZIP ».';
  }
}

/* ------------------------------------------------------------------ Tableau */

function sortedResults() {
  const { key, dir } = state.sort;
  const sign = dir === 'asc' ? 1 : -1;
  const value = (r) => {
    if (key === 'name') return r.file.name.toLowerCase();
    if (key === 'path') return r.file.relativePath.toLowerCase();
    if (key === 'total') return r.total;
    if (key.startsWith('t')) return r.counts[+key.slice(1)] || 0;
    return 0;
  };
  return [...state.results].sort((a, b) => {
    const va = value(a); const vb = value(b);
    if (va < vb) return -sign;
    if (va > vb) return sign;
    return a.file.relativePath.localeCompare(b.file.relativePath, 'fr');
  });
}

function headerCell(label, key, extraClass = '', content = null) {
  const sorted = state.sort.key === key;
  const th = h('th', { class: extraClass, scope: 'col', 'aria-sort': sorted ? (state.sort.dir === 'asc' ? 'ascending' : 'descending') : null });
  const btn = h('button', { type: 'button', onclick: () => {
    state.sort = sorted ? { key, dir: state.sort.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: key === 'name' || key === 'path' ? 'asc' : 'desc' };
    renderTable();
  } }, content || label);
  if (sorted) btn.append(icon(state.sort.dir === 'asc' ? 'chev-up' : 'chev-down'));
  if (content) btn.setAttribute('aria-label', label);
  th.append(btn);
  return th;
}

function renderTable() {
  const wrap = $('table-wrap');
  if (state.results.length === 0) { wrap.hidden = true; return; }
  wrap.hidden = false;
  $('diff').hidden = true;
  $('changes').hidden = true;

  const multi = state.terms.length > 1;
  const head = h('tr', {},
    h('th', { class: 'sel', scope: 'col' }, h('span', { class: 'sr-only', text: 'Sélection pour comparaison' })),
    headerCell('Fichier', 'name'),
    ...state.terms.map((t, i) => headerCell(`Occurrences de « ${t} »`, 't' + i, 'num term-col', h('span', { class: 'th-flag' }, flagSvg(i, { title: t }), letter(i)))),
    multi || state.terms.length === 0 ? headerCell('Total', 'total', 'num') : null,
  );
  $('grid-head').replaceChildren(head);

  const rows = sortedResults();
  const body = document.createDocumentFragment();
  for (const r of rows.slice(0, state.shown)) body.append(resultRow(r, multi));
  $('grid-body').replaceChildren(body);
  $('grid').classList.toggle('has-selection', !!state.selectedId);
  $('more').hidden = rows.length <= state.shown;
  $('more').textContent = `Afficher plus (${nf.format(rows.length - state.shown)} restants)`;
}

function resultRow(r, multi) {
  const f = r.file;
  const firstHit = r.hits[0];
  const nameCell = h('td', {},
    h('div', { class: 'file-name' },
      h('span', { class: 'badge badge-ext', text: f.extension || '—' }),
      f.name,
      f.archiveDepth > 0 ? h('span', { class: 'badge badge-zip', title: 'Dans un conteneur (archive ou courriel)' }, containerLabel(f)) : null,
      r.nameMatch ? h('span', { class: 'badge badge-name', text: 'nom' }) : null,
    ),
    h('div', { class: 'file-path', text: f.relativePath }),
    multi ? h('div', { class: 'hoist-mini' }, ...state.terms.map((t, i) => h('span', { class: 'pin', title: `${t} : ${r.counts[i] || 0}` }, flagSvg(i, { off: !(r.counts[i] > 0), title: t }), h('span', { class: 'pin-count', text: nf.format(r.counts[i] || 0) })))) : null,
    firstHit ? h('div', { class: 'proof' }, h('span', { class: 'loc', text: firstHit.location }), highlighted(firstHit.text, firstHit.spans)) : null,
  );
  const check = h('input', { type: 'checkbox', class: 'row-check', 'aria-label': 'Sélectionner ' + f.name + ' pour comparer', onclick: (e) => e.stopPropagation(), onchange: (e) => {
    if (e.target.checked) {
      state.checked.add(f.id);
      if (state.checked.size > 2) { const first = state.checked.values().next().value; state.checked.delete(first); renderTable(); }
    } else state.checked.delete(f.id);
    $('compare-btn').disabled = state.checked.size !== 2;
  } });
  check.checked = state.checked.has(f.id);

  const tr = h('tr', { 'data-id': f.id, 'aria-selected': state.selectedId === f.id ? 'true' : 'false', tabindex: '0', onclick: () => openPreview(r), onkeydown: (e) => {
    if (e.key === 'Enter') openPreview(r);
    if (e.key === 'ArrowDown') { e.preventDefault(); tr.nextElementSibling?.focus(); }
    if (e.key === 'ArrowUp') { e.preventDefault(); tr.previousElementSibling?.focus(); }
  } },
  h('td', { class: 'sel' }, check),
  nameCell,
  ...state.terms.map((_, i) => h('td', { class: 'num term-col' + (r.counts[i] ? '' : ' zero'), text: nf.format(r.counts[i] || 0) })),
  multi ? h('td', { class: 'num total', text: nf.format(r.total) }) : null);
  return tr;
}

/** Type du conteneur direct (ZIP, 7Z, GZ, EML…) et profondeur d'imbrication. */
function containerLabel(f) {
  const parts = f.relativePath.split(' › ');
  const container = parts[parts.length - 2] || '';
  const kind = (container.split('.').pop() || 'archive').toUpperCase();
  return f.archiveDepth > 1 ? `${kind} ×${f.archiveDepth}` : kind;
}

/** Texte avec correspondances surlignées ; chaque <mark> porte la couleur de son pavillon. */
function highlighted(text, spans) {
  const frag = document.createDocumentFragment();
  const chars = Array.from(text);
  let pos = 0;
  for (const [start, length, term] of spans) {
    if (start < pos) continue;
    frag.append(chars.slice(pos, start).join(''));
    const mark = h('mark', { text: chars.slice(start, start + length).join('') });
    mark.style.setProperty('--term', termColor(term));
    frag.append(mark);
    pos = start + length;
  }
  frag.append(chars.slice(pos).join(''));
  return frag;
}

/* ------------------------------------------------------------------ Aperçu */

function showPane(which) {
  $('audit').hidden = which !== 'audit';
  $('pane-empty').hidden = which !== 'empty';
  $('preview').hidden = which !== 'preview';
  $('extract').hidden = which !== 'extract';
  $('notices').hidden = which !== 'notices';
  $('pane').classList.toggle('open', which !== 'empty');
}

async function openPreview(r) {
  state.selectedId = r.file.id;
  state.occurrence = -1;
  state.pickedColumns = [];
  document.querySelectorAll('#grid-body tr').forEach((tr) => tr.setAttribute('aria-selected', tr.dataset.id === r.file.id ? 'true' : 'false'));
  $('grid').classList.add('has-selection');
  showPane('preview');

  const f = r.file;
  $('pv-name').textContent = f.name;
  $('pv-path').textContent = f.absolutePath;
  $('pv-meta').replaceChildren(
    h('span', { text: [formatSize(f.size), f.modified ? new Date(f.modified).toLocaleString('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }) : null].filter(Boolean).join(' · ') }),
    ...state.terms.map((t, i) => h('span', { class: 'term-total', title: t }, flagSvg(i, { title: t }), nf.format(r.counts[i] || 0))),
  );
  const id = encodeURIComponent(f.id);
  $('pv-download').href = '/api/file?id=' + id;
  $('pv-download').setAttribute('download', f.name);
  $('pv-open').href = '/api/file?inline=1&id=' + id;
  $('pv-open').hidden = !['pdf', 'txt', 'md', 'log', 'csv', 'png', 'jpg', 'jpeg'].includes(f.extension);
  $('pv-text').href = '/api/text?id=' + id;
  $('pv-lines').replaceChildren();
  $('pv-table').replaceChildren();
  $('pv-loading').hidden = false;
  selectTab('text');
  loadGitLog(f);

  const p = state.lastParams;
  try {
    const res = await api('/api/preview?' + new URLSearchParams({ id: f.id, q: p.q, mode: p.mode, caseSensitive: p.caseSensitive, wholeWord: p.wholeWord, ignoreAccents: p.ignoreAccents, fuzzy: p.fuzzy, distance: p.distance }));
    const data = await res.json();
    if (state.selectedId !== f.id) return;
    state.preview = data;
    renderLines(data);
    renderSheet(data);
    $('tab-table').hidden = data.tables.length === 0;
    if (data.warnings?.length) toast('warn', 'Extraction approximative', data.warnings[0]);
    nextOccurrence(1);
  } catch (err) {
    $('pv-lines').replaceChildren(h('li', { class: 'binary-note', text: err.message }));
  } finally {
    $('pv-loading').hidden = true;
  }
}

function renderLines(data) {
  const list = $('pv-lines');
  if (data.binary) {
    list.replaceChildren(h('li', { class: 'binary-note', text: 'Fichier non textuel (image, archive, binaire) : télécharge-le pour l\'ouvrir.' }));
    return;
  }
  const frag = document.createDocumentFragment();
  for (const line of data.lines) {
    frag.append(h('li', { class: line.spans.length ? 'hit' : null }, h('span', { class: 'loc', text: line.location, title: line.location }), h('span', { class: 'txt' }, highlighted(line.text, line.spans))));
  }
  if (data.truncated) frag.append(h('li', {}, h('span', { class: 'more-lines', text: `Aperçu limité aux ${nf.format(data.lines.length)} premières lignes sur ${nf.format(data.totalLines)}. Télécharge le texte pour tout lire.` })));
  if (!data.lines.length) frag.append(h('li', { class: 'binary-note', text: 'Aucun texte extrait de ce fichier.' }));
  list.replaceChildren(frag);
}

function nextOccurrence(step) {
  const marks = [...$('pv-lines').querySelectorAll('mark')];
  const pos = $('occ-pos');
  if (!marks.length) { pos.textContent = '—'; return; }
  marks[state.occurrence]?.classList.remove('current');
  state.occurrence = (state.occurrence + step + marks.length) % marks.length;
  const mark = marks[state.occurrence];
  mark.classList.add('current');
  mark.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
  pos.textContent = `${state.occurrence + 1} / ${marks.length}`;
}

function selectTab(which) {
  $('tab-text').setAttribute('aria-selected', String(which === 'text'));
  $('tab-table').setAttribute('aria-selected', String(which === 'table'));
  $('tab-git').setAttribute('aria-selected', String(which === 'git'));
  $('pv-lines').hidden = which !== 'text';
  $('pv-table').hidden = which !== 'table';
  $('pv-git').hidden = which !== 'git';
  $('occ-nav').hidden = which !== 'text';
}

function columnLetter(i) {
  let s = '';
  for (i += 1; i > 0; i = Math.floor((i - 1) / 26)) s = String.fromCharCode(65 + ((i - 1) % 26)) + s;
  return s;
}

function renderSheet(data, active = 0) {
  const box = $('pv-table');
  if (!data.tables.length) { box.replaceChildren(); return; }
  const table = data.tables[active];
  const tabs = h('div', { class: 'sheet-tabs' }, ...data.tables.map((t, i) => h('button', { type: 'button', 'aria-pressed': String(i === active), onclick: () => renderSheet(data, i), text: t.name })));
  const headerRow = Number($('ex-header').value) || 0;
  const head = h('tr', {}, h('th', {}, ''), ...Array.from({ length: table.columns }, (_, c) => {
    const letter = columnLetter(c);
    const label = table.rows.find((r) => r.n === headerRow)?.cells[c] || letter;
    const picked = state.pickedColumns.includes(label);
    return h('th', { class: picked ? 'picked' : null, scope: 'col' }, h('button', { type: 'button', title: 'Ajouter « ' + label + ' » aux colonnes à extraire', 'aria-pressed': String(picked), onclick: () => togglePickedColumn(label, data, active), text: letter }));
  }));
  const rows = table.rows.map((r) => h('tr', { class: r.n === headerRow ? 'header-row' : null }, h('th', { scope: 'row', text: String(r.n) }), ...Array.from({ length: table.columns }, (_, c) => h('td', { title: r.cells[c] ?? '', text: r.cells[c] ?? '' }))));
  box.replaceChildren(
    tabs,
    h('p', { class: 'sheet-help', text: `Clique sur une lettre de colonne pour la choisir, puis « Extraire ». ${table.totalRows > table.rows.length ? `Aperçu : ${table.rows.length} lignes sur ${nf.format(table.totalRows)}.` : ''}` }),
    h('table', {}, h('thead', {}, head), h('tbody', {}, ...rows)),
  );
}

function togglePickedColumn(label, data, active) {
  const i = state.pickedColumns.indexOf(label);
  if (i >= 0) state.pickedColumns.splice(i, 1); else state.pickedColumns.push(label);
  $('ex-columns').value = state.pickedColumns.join(', ');
  if (state.pickedColumns.length) document.querySelector('input[name="extract"][value="columns"]').checked = true;
  syncExtractForm();
  renderSheet(data, active);
}

/* ------------------------------------------------------------------ Extraction */

function syncExtractForm() {
  $('extract-columns').hidden = document.querySelector('input[name="extract"]:checked').value !== 'columns';
}

async function runExtract(event) {
  event.preventDefault();
  if (!state.lastParams) { toast('error', 'Aucune recherche', 'Lance d\'abord une recherche : l\'extraction porte sur ses résultats.'); return; }
  const btn = $('extract-go');
  btn.disabled = true;
  btn.lastChild.textContent = 'Extraction…';
  const format = document.querySelector('input[name="format"]:checked').value;
  const body = {
    ...state.lastParams,
    extract: document.querySelector('input[name="extract"]:checked').value,
    format,
    columns: $('ex-columns').value,
    headerRow: $('ex-header').value,
    allRows: $('ex-allrows').checked,
    withSource: $('ex-source').checked,
    unique: $('ex-unique').checked,
  };
  try {
    const res = await api('/api/extract', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const rows = Number(res.headers.get('X-Poseidon-Rows') || 0);
    const blob = await res.blob();
    const name = /filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') || '')?.[1] || `poseidon-extraction.${format}`;
    const url = URL.createObjectURL(blob);
    const a = h('a', { href: url, download: name });
    document.body.append(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
    toast(rows ? 'ok' : 'warn', rows ? `${nf.format(rows)} ligne(s) extraite(s)` : 'Extraction vide', rows ? name : 'Rien ne correspond à cette extraction. Vérifie les colonnes ou le mode.', { icon: rows ? 'check' : 'alert' });
  } catch (err) {
    toast('error', 'Extraction impossible', err.message);
  } finally {
    btn.disabled = false;
    btn.lastChild.textContent = 'Créer le document';
  }
}

/* ------------------------------------------------------------------ Changements depuis la recherche précédente */

function renderChangesBand() {
  const c = state.changes;
  const n = c ? c.appeared.length + c.disappeared.length + c.changed.length : 0;
  $('changes-band').hidden = !c || n === 0;
  if (!c || n === 0) return;
  const when = new Date(c.from.at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
  $('changes-text').replaceChildren(`Depuis la recherche du ${when} : `,
    h('span', { class: 'add', text: `+${c.appeared.length}` }), ' nouveau(x) · ',
    h('span', { class: 'del', text: `−${c.disappeared.length}` }), ' disparu(s) · ',
    h('b', { text: String(c.changed.length) }), ' modifié(s)');
}

function showChanges() {
  const c = state.changes;
  if (!c) return;
  const when = (at) => new Date(at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
  const group = (title, items, cls, sign, extra) => items.length ? h('div', {}, h('h3', { text: `${title} (${items.length})` }), h('ul', {}, ...items.map((it) => h('li', { class: cls }, h('span', { class: 'sign', text: sign }), h('span', { text: it.path }), h('span', { class: 'n', text: extra(it) }))))) : null;
  $('changes-title').textContent = 'Changements';
  $('changes-stats').textContent = `${when(c.from.at)} → ${when(c.to.at)} · ${nf.format(c.unchanged)} inchangé(s)`;
  $('changes-body').replaceChildren(...[
    group('Nouveaux fichiers', c.appeared, 'c-add', '+', (it) => `${nf.format(it.total)} occ.`),
    group('Fichiers disparus', c.disappeared, 'c-del', '−', (it) => `${nf.format(it.total)} occ.`),
    group('Fichiers modifiés', c.changed, 'c-mod', '~', (it) => `${nf.format(it.before)} → ${nf.format(it.after)} occ.${it.modified ? ' · fichier modifié' : ''}`),
  ].filter(Boolean));
  $('table-wrap').hidden = true;
  $('diff').hidden = true;
  $('changes').hidden = false;
}

/* ------------------------------------------------------------------ Historique Git */

async function loadGitLog(f) {
  $('tab-git').hidden = true;
  if (!state.info?.git || f.archiveDepth > 0) return;
  try {
    const data = await (await api('/api/git/log?' + new URLSearchParams({ id: f.id }))).json();
    if (state.selectedId !== f.id || !data.available) return;
    $('tab-git').hidden = data.commits.length === 0;
    $('pv-git').replaceChildren(...data.commits.map((c) => h('li', {}, h('button', { type: 'button', onclick: () => runGitDiff(f, c) },
      h('span', { class: 'hash', text: c.short }),
      h('span', { class: 'subj', text: c.subject }),
      h('span', { class: 'meta', text: `${c.author} · ${new Date(c.date).toLocaleString('fr-FR', { dateStyle: 'medium', timeStyle: 'short' })}` })))));
  } catch { /* pas d'historique : onglet masqué */ }
}

async function runGitDiff(f, commit) {
  $('table-wrap').hidden = true;
  $('changes').hidden = true;
  $('diff').hidden = false;
  $('diff-title').textContent = `Commit ${commit.short} — ${commit.subject}`;
  $('diff-body').replaceChildren(h('div', { class: 'skeleton' }, h('span'), h('span'), h('span')));
  try {
    const data = await (await api('/api/git/diff?' + new URLSearchParams({ id: f.id, commit: commit.hash }))).json();
    renderDiffData(data, `--- ${data.path}@${data.parent ? data.parent.slice(0, 8) : 'vide'}`, `+++ ${data.path}@${commit.short}`);
  } catch (err) {
    $('diff-body').replaceChildren(h('p', { class: 'diff-same', text: err.message }));
  }
}

async function runGitSearch() {
  const p = state.lastParams || searchParams();
  if (!p.root || !p.q) { toast('error', 'Rien à chercher', 'Lance d\'abord une recherche.'); return; }
  $('table-wrap').hidden = true;
  $('diff').hidden = true;
  $('changes').hidden = false;
  $('changes-title').textContent = 'Historique Git';
  $('changes-stats').textContent = 'Recherche dans les commits…';
  $('changes-body').replaceChildren(h('div', { class: 'skeleton' }, h('span'), h('span'), h('span')));
  try {
    const data = await (await api('/api/git/search?' + new URLSearchParams(p))).json();
    $('changes-stats').textContent = `${data.repository} · ${nf.format(data.commits.length)} commit(s) où le nombre d'occurrences a changé`;
    $('changes-body').replaceChildren(...(data.commits.length ? data.commits.map((c) => h('div', {},
      h('h3', { class: 'commit-head' }, h('b', { text: c.short }), c.subject),
      h('p', { class: 'n', text: `${c.author} · ${new Date(c.date).toLocaleString('fr-FR', { dateStyle: 'medium', timeStyle: 'short' })}` }),
      h('ul', {}, ...c.files.map((f) => h('li', { class: f.after > f.before ? 'c-add' : 'c-del' }, h('span', { class: 'sign', text: f.after > f.before ? '+' : '−' }), h('span', { text: f.path }), h('span', { class: 'n', text: `${f.before} → ${f.after} occ.` }))))))
      : [h('p', { class: 'diff-same', text: 'Aucun commit n\'a ajouté ni retiré ce filtre.' })]));
  } catch (err) {
    $('changes-body').replaceChildren(h('p', { class: 'diff-same', text: err.message }));
    $('changes-stats').textContent = '';
  }
}

/* ------------------------------------------------------------------ Comparaison */

async function runDiff() {
  const [a, b] = [...state.checked];
  if (!a || !b) return;
  $('table-wrap').hidden = true;
  $('diff').hidden = false;
  $('diff-body').replaceChildren(h('div', { class: 'skeleton' }, h('span'), h('span'), h('span')));
  try {
    const data = await (await api('/api/diff?' + new URLSearchParams({ a, b }))).json();
    $('diff-title').textContent = 'Comparaison';
    renderDiffData(data, '--- ' + data.a.relativePath, '+++ ' + data.b.relativePath);
  } catch (err) {
    $('diff-body').replaceChildren(h('p', { class: 'diff-same', text: err.message }));
  }
}

function renderDiffData(data, labelA, labelB) {
  $('diff-stats').replaceChildren(h('span', { class: 'add', text: `+${nf.format(data.added)}` }), ' ', h('span', { class: 'del', text: `−${nf.format(data.removed)}` }));
  $('diff-files').replaceChildren(h('span', { class: 'del', text: labelA }), h('span', { class: 'add', text: labelB }));
  $('diff-copy').onclick = () => copy(data.unified, 'Diff copié au format unifié.');
  if (data.identical) { $('diff-body').replaceChildren(h('p', { class: 'diff-same', text: 'Les deux versions ont un texte identique.' })); return; }
  const frag = document.createDocumentFragment();
  for (const hunk of data.hunks) {
    frag.append(h('div', { class: 'hunk-head', text: hunk.header }));
    let [, la, lb] = /-(\d+)(?:,\d+)? \+(\d+)/.exec(hunk.header).map(Number);
    for (const [type, text] of hunk.lines) {
      const cls = type === '+' ? 'add' : type === '-' ? 'del' : '';
      frag.append(h('div', { class: 'dl ' + cls },
        h('span', { class: 'n', text: type === '+' ? '' : String(la) }),
        h('span', { class: 'n', text: type === '-' ? '' : String(lb) }),
        h('span', { class: 's', text: type === ' ' ? '' : type === '-' ? '−' : '+' }),
        h('span', { class: 't', text })));
      if (type !== '+') la++;
      if (type !== '-') lb++;
    }
  }
  $('diff-body').replaceChildren(frag);
}

/* ------------------------------------------------------------------ Avertissements */

function showNotices() {
  const list = $('notice-list');
  list.replaceChildren(...state.notices.map((n) => {
    const reason = REASONS[n.reason] || REASONS.corrupt;
    return h('li', {}, h('span', { class: 'why why-' + n.reason }, icon(reason.icon), reason.label), h('span', { class: 'p', text: n.path }), h('span', { class: 'm', text: n.message }));
  }));
  showPane('notices');
}

/* ------------------------------------------------------------------ Notifications */

function toast(kind, title, message = '', { path = null, action = null, icon: iconName = null, sticky = false } = {}) {
  const box = $('toasts');
  const titleEl = h('b', { text: title });
  const messageEl = h('span', { text: message });
  const pathEl = h('span', { class: 'p', text: path ? '\u200E' + path : '', title: path || '' });
  pathEl.hidden = !path;
  const el = h('div', { class: 'toast toast-' + kind },
    icon(iconName || (kind === 'ok' ? 'check' : kind === 'warn' ? 'lock' : 'alert')),
    h('div', {}, titleEl, messageEl, pathEl,
      action ? h('button', { class: 'link-btn', type: 'button', text: action.label, onclick: () => { action.run(); dismiss(); } }) : null),
    h('button', { class: 'icon-btn toast-x', type: 'button', 'aria-label': 'Fermer', onclick: () => dismiss() }, icon('x')));
  let timer;
  const delay = kind === 'error' ? 9000 : action ? 12000 : 6000;
  function dismiss() {
    clearTimeout(timer);
    el.classList.add('leaving');
    setTimeout(() => el.remove(), 200);
  }
  function arm(ms) { clearTimeout(timer); timer = setTimeout(dismiss, ms); }
  box.append(el);
  while (box.children.length > 3) box.firstElementChild.remove();
  if (!sticky) arm(delay);
  el.addEventListener('mouseenter', () => clearTimeout(timer));
  el.addEventListener('mouseleave', () => { if (!sticky) arm(4000); });
  el.dismiss = dismiss;
  el.release = () => { sticky = false; arm(7000); };
  el.update = (t, m, p) => { titleEl.textContent = t; messageEl.textContent = m; pathEl.textContent = '\u200E' + p; pathEl.title = p; pathEl.hidden = !p; };
  return el;
}

/* ------------------------------------------------------------------ Index */

let indexTimer = null;
function scheduleIndexStatus() {
  clearTimeout(indexTimer);
  indexTimer = setTimeout(refreshIndexStatus, 350);
}

async function refreshIndexStatus() {
  if (!state.info?.indexAvailable) return;
  const root = $('root').value.trim();
  $('index-group').hidden = !root;
  if (!root) return;
  try {
    const data = await (await api('/api/index?' + new URLSearchParams({ root }))).json();
    renderIndexState(data);
  } catch {
    $('index-state').textContent = 'Dossier introuvable : pas d\'index.';
    $('index-build').disabled = true;
    $('index-delete').hidden = true;
  }
}

function renderIndexState(data) {
  const st = data.stats;
  $('index-build').disabled = false;
  $('index-delete').hidden = !data.exists || state.isAdmin === false;
  $('index-build').hidden = state.isAdmin === false;
  $('index-build').lastChild.textContent = data.exists ? 'Mettre à jour l\'index' : 'Indexer ce dossier';
  if (!data.exists) {
    $('index-state').textContent = 'Aucun index : chaque recherche relit les fichiers. Indexer accélère les recherches suivantes.';
    return;
  }
  const when = st.builtAt ? new Date(st.builtAt).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '—';
  $('index-state').replaceChildren(
    h('b', { text: `${nf.format(st.files)} fichiers` }), ` indexés · ${formatSize(st.sizeBytes)}`, h('br'),
    `Mis à jour le ${when}${st.root !== $('root').value.trim() ? ` (index de ${st.root})` : ''}`,
  );
}

async function buildIndex() {
  const root = $('root').value.trim();
  if (!root) return;
  const btn = $('index-build');
  btn.disabled = true;
  btn.lastChild.textContent = 'Indexation…';
  try {
    const res = await api('/api/index', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ root }) });
    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let summary = null;
    for (;;) {
      const { value, done } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      let nl;
      while ((nl = buffer.indexOf('\n')) >= 0) {
        const line = buffer.slice(0, nl);
        buffer = buffer.slice(nl + 1);
        if (!line.trim()) continue;
        const ev = JSON.parse(line);
        if (ev.type === 'progress') $('index-state').textContent = `${nf.format(ev.scanned)} fichiers parcourus, ${nf.format(ev.indexed)} indexés…`;
        if (ev.type === 'done') summary = ev.summary;
      }
    }
    if (summary) {
      toast('ok', 'Index à jour', `${nf.format(summary.files)} fichiers · ${nf.format(summary.indexed)} (ré)indexés · ${nf.format(summary.removed)} retirés · ${formatMs(summary.durationMs)}`, { icon: 'check' });
      renderIndexState({ exists: true, stats: summary.stats });
    }
  } catch (err) {
    toast('error', 'Indexation impossible', err.message);
    refreshIndexStatus();
  }
}

async function deleteIndex() {
  try {
    await api('/api/index/delete', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ root: $('root').value.trim() }) });
    toast('ok', 'Index supprimé', 'Les recherches relisent de nouveau tous les fichiers.', { icon: 'check' });
  } catch (err) {
    toast('error', 'Suppression impossible', err.message);
  }
  refreshIndexStatus();
}

/* ------------------------------------------------------------------ Racine et dossiers */

function rememberRoot(root) {
  const recent = [root, ...store.get('recent', []).filter((r) => r !== root)].slice(0, 5);
  store.set('recent', recent);
  renderRecent();
}

function renderRecent() {
  const current = $('root').value.trim();
  $('recent').replaceChildren(...store.get('recent', []).filter((r) => r !== current).slice(0, 4).map((r) => h('li', {}, h('button', { type: 'button', title: r, onclick: () => { $('root').value = r; renderRecent(); scheduleIndexStatus(); } }, icon('history'), r))));
}

let browserPath = '';
async function browse(path) {
  try {
    const data = await (await api('/api/browse?' + new URLSearchParams({ path }))).json();
    browserPath = data.path;
    $('browser-path').textContent = data.path || 'Racines';
    $('browser-up').disabled = data.parent === null;
    $('browser-up').onclick = () => browse(data.parent ?? '');
    $('browser-pick').disabled = !data.path;
    $('browser-list').replaceChildren(...(data.dirs.length
      ? data.dirs.map((d) => h('li', {}, h('button', { type: 'button', onclick: () => browse(d.path) }, icon('folder'), d.name)))
      : [h('li', { class: 'browser-empty', text: 'Aucun sous-dossier.' })]));
  } catch (err) {
    toast('error', 'Dossier inaccessible', err.message);
  }
}

function toggleBrowser(force) {
  const open = force ?? $('browser').hidden;
  $('browser').hidden = !open;
  $('browse').setAttribute('aria-expanded', String(open));
  if (open) browse($('root').value.trim());
}

/* ------------------------------------------------------------------ Divers */

function formatMs(ms) { return ms < 1000 ? `${Math.round(ms)} ms` : `${(ms / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 2 })} s`; }
function formatSize(bytes) {
  if (!bytes) return '';
  const units = ['o', 'Ko', 'Mo', 'Go'];
  let i = 0; let v = bytes;
  while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
  return `${i ? v.toLocaleString('fr-FR', { maximumFractionDigits: 1 }) : v} ${units[i]}`;
}
async function copy(text, done) {
  try { await navigator.clipboard.writeText(text); toast('ok', 'Copié', done, { icon: 'check' }); } catch { toast('error', 'Copie impossible', 'Le navigateur refuse l\'accès au presse-papiers.'); }
}
function closeRail() { $('rail').classList.remove('open'); }

function showLogin() {
  const users = state.info?.authMode === 'users';
  $('login-user').hidden = !users;
  $('login-help').textContent = users ? 'Connecte-toi avec ton identifiant Poseidon.' : 'Cette instance est protégée. Entre le mot de passe d\'accès.';
  $('login').hidden = false;
  (users ? $('login-name') : $('login-password')).focus();
}

function renderSession(user) {
  const mode = state.info?.authMode || 'none';
  $('session').hidden = mode === 'none' || !user;
  if (!user) return;
  $('session-user').textContent = user.login;
  $('session-role').textContent = `(${user.role})`;
  const admin = user.role === 'admin';
  $('audit-btn').hidden = !admin;
  state.isAdmin = admin;
  $('index-build').hidden = !admin;
  $('index-delete').hidden = $('index-delete').hidden || !admin;
  $('template-delete').dataset.admin = admin ? '1' : '0';
}

async function showAudit() {
  try {
    const data = await (await api('/api/audit?limit=300')).json();
    $('audit-list').replaceChildren(...data.entries.map((e) => {
      const details = Object.entries(e).filter(([k]) => !['time', 'user', 'ip', 'action'].includes(k)).map(([k, v]) => `${k} : ${v}`).join(' · ');
      return h('li', {},
        h('span', { class: 'a-head' }, h('span', { class: 'a-act', text: e.action }), h('span', { text: e.user })),
        h('span', { class: 'a-meta', text: `${new Date(e.time).toLocaleString('fr-FR')} · ${e.ip}` }),
        details ? h('span', { class: 'a-det', text: details }) : null);
    }));
    if (!data.entries.length) $('audit-list').replaceChildren(h('li', { text: 'Journal vide.' }));
    showPane('audit');
  } catch (err) {
    toast('error', 'Journal indisponible', err.message);
  }
}

/* Thème : auto (système), clair ou sombre, mémorisé dans le navigateur. */
function applyTheme(theme) {
  if (theme === 'auto') document.documentElement.removeAttribute('data-theme');
  else document.documentElement.setAttribute('data-theme', theme);
  $('theme-switch').querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.theme === theme)));
  store.set('theme', theme);
}

/* ------------------------------------------------------------------ Démarrage */

async function init() {
  try {
    state.info = await (await fetch('/api/info', { headers: { 'X-Poseidon': '1' } })).json();
  } catch {
    toast('error', 'Serveur injoignable', 'Lance Poseidon avec « poseidon serve » ou poseidon.bat.');
    return;
  }
  $('stat-pdf').textContent = (state.info.pdfEngine === 'pdftotext' ? 'pdftotext' : 'PHP (secours)') + (state.info.ocr ? ' + OCR' : '');
  document.querySelector('#formats [data-format="image"]').hidden = !state.info.ocr;
  applyTheme(store.get('theme', 'auto'));
  $('try-demo').hidden = !state.info.demo;
  $('git-btn').hidden = !state.info.git;
  if (state.info.authRequired && !state.info.authenticated) showLogin();
  else renderSession(state.info.user);

  $('root').value = store.get('root', state.info.allowedRoots[0] || '');
  renderRecent();
  const saved = store.get('options', null);
  if (saved) {
    $('opt-archives').checked = saved.archives; $('opt-accents').checked = saved.accents;
    $('opt-case').checked = saved.case; $('opt-word').checked = saved.word; $('opt-target').value = saved.target || 'content';
    $('opt-fuzzy').checked = !!saved.fuzzy;
  }

  $('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const res = await api('/api/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ login: $('login-name').value, password: $('login-password').value }) });
      const data = await res.json();
      $('login').hidden = true;
      $('login-error').textContent = '';
      $('login-password').value = '';
      renderSession(data.user);
      refreshIndexStatus();
    } catch (err) {
      $('login-error').textContent = err.message;
    }
  });

  $('query-form').addEventListener('submit', (e) => { e.preventDefault(); runSearch(); });
  $('q').addEventListener('input', renderHoist);
  document.querySelectorAll('input[name="mode"]').forEach((r) => r.addEventListener('change', () => { syncModeFields(); renderHoist(); }));
  $('history-btn').addEventListener('click', () => toggleHistory());
  document.addEventListener('click', (e) => { if (!$('history').hidden && !e.target.closest('#history, #history-btn')) toggleHistory(false); });
  $('share-btn').addEventListener('click', () => copy(shareLink(), 'Lien de la recherche copié : il la rejoue à l\'ouverture.'));
  $('changes-open').addEventListener('click', showChanges);
  $('changes-close').addEventListener('click', () => { $('changes-band').hidden = true; });
  $('changes-back').addEventListener('click', () => { $('changes').hidden = true; renderTable(); });
  $('tab-git').addEventListener('click', () => selectTab('git'));
  $('git-btn').addEventListener('click', runGitSearch);
  $('report-btn').addEventListener('click', () => {
    const p = state.lastParams || searchParams();
    if (!p.root || !p.q) { toast('error', 'Rien à rapporter', 'Lance d\'abord une recherche.'); return; }
    window.open('/api/report?' + new URLSearchParams({ ...p, inline: '1' }), '_blank', 'noopener');
  });
  $('template-select').addEventListener('change', (e) => applyTemplate(e.target.value));
  $('template-save').addEventListener('click', saveTemplate);
  $('template-delete').addEventListener('click', deleteTemplate);
  $('root').addEventListener('change', () => { store.set('root', $('root').value.trim()); renderRecent(); scheduleIndexStatus(); });
  $('root').addEventListener('input', scheduleIndexStatus);
  $('index-build').addEventListener('click', buildIndex);
  $('index-delete').addEventListener('click', deleteIndex);
  $('root').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); store.set('root', $('root').value.trim()); $('q').focus(); } });
  for (const id of ['opt-archives', 'opt-accents', 'opt-case', 'opt-word', 'opt-target', 'opt-fuzzy']) {
    $(id).addEventListener('change', () => store.set('options', { archives: $('opt-archives').checked, accents: $('opt-accents').checked, case: $('opt-case').checked, word: $('opt-word').checked, target: $('opt-target').value, fuzzy: $('opt-fuzzy').checked }));
  }

  $('formats').addEventListener('click', (e) => {
    const chip = e.target.closest('.chip');
    if (!chip) return;
    const all = $('formats').querySelector('[data-format="all"]');
    if (chip === all) {
      $('formats').querySelectorAll('.chip').forEach((c) => c.setAttribute('aria-pressed', String(c === all)));
    } else {
      chip.setAttribute('aria-pressed', String(chip.getAttribute('aria-pressed') !== 'true'));
      const any = [...$('formats').querySelectorAll('.chip:not([data-format="all"])')].some((c) => c.getAttribute('aria-pressed') === 'true');
      all.setAttribute('aria-pressed', String(!any));
    }
  });

  $('browse').addEventListener('click', () => toggleBrowser());
  $('browser-pick').addEventListener('click', () => { $('root').value = browserPath; store.set('root', browserPath); toggleBrowser(false); renderRecent(); scheduleIndexStatus(); $('q').focus(); });
  $('try-demo').addEventListener('click', () => {
    $('root').value = state.info.demo;
    scheduleIndexStatus();
    $('q').value = 'contrat préavis';
    renderHoist();
    runSearch();
  });
  $('cancel').addEventListener('click', () => state.controller?.abort());
  $('more').addEventListener('click', () => { state.shown += 500; renderTable(); });

  $('pane-close').addEventListener('click', () => { state.selectedId = null; $('grid').classList.remove('has-selection'); document.querySelectorAll('#grid-body tr[aria-selected="true"]').forEach((tr) => tr.setAttribute('aria-selected', 'false')); showPane('empty'); });
  $('extract-close').addEventListener('click', () => showPane(state.selectedId ? 'preview' : 'empty'));
  $('notices-close').addEventListener('click', () => showPane(state.selectedId ? 'preview' : 'empty'));
  $('tab-text').addEventListener('click', () => selectTab('text'));
  $('tab-table').addEventListener('click', () => selectTab('table'));
  $('occ-prev').addEventListener('click', () => nextOccurrence(-1));
  $('occ-next').addEventListener('click', () => nextOccurrence(1));
  $('pv-copy').addEventListener('click', () => copy($('pv-path').textContent, 'Chemin absolu copié.'));
  $('ex-header').addEventListener('change', () => state.preview && renderSheet(state.preview));

  $('extract-btn').addEventListener('click', () => { syncExtractForm(); loadTemplates(); showPane('extract'); });
  document.querySelectorAll('input[name="extract"]').forEach((r) => r.addEventListener('change', syncExtractForm));
  $('extract').addEventListener('submit', runExtract);
  $('sum-notices-btn').addEventListener('click', showNotices);
  $('alert-more').addEventListener('click', showNotices);
  $('alert-close').addEventListener('click', () => { state.alertDismissed = true; $('alert').hidden = true; });
  $('compare-btn').addEventListener('click', runDiff);
  $('diff-back').addEventListener('click', () => { $('diff').hidden = true; renderTable(); });

  $('menu').addEventListener('click', () => $('rail').classList.add('open'));
  $('theme-switch').addEventListener('click', (e) => { const b = e.target.closest('button'); if (b) applyTheme(b.dataset.theme); });
  $('logout').addEventListener('click', async () => { try { await api('/api/logout', { method: 'POST' }); } catch { /* session déjà close */ } location.reload(); });
  $('audit-btn').addEventListener('click', showAudit);
  $('audit-close').addEventListener('click', () => showPane(state.selectedId ? 'preview' : 'empty'));
  $('rail-close').addEventListener('click', closeRail);

  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); $('q').focus(); $('q').select(); }
    if (e.key === 'Escape') {
      if (!$('history').hidden) toggleHistory(false);
      else if (!$('browser').hidden) toggleBrowser(false);
      else if ($('rail').classList.contains('open')) closeRail();
      else if (state.controller) state.controller.abort();
    }
    if (!$('preview').hidden && document.activeElement?.tagName !== 'INPUT' && (e.key === 'n' || e.key === 'N')) nextOccurrence(e.shiftKey ? -1 : 1);
  });

  $('empty-hoist').querySelector('.halyard').append(flagSvg(0), flagSvg(1), flagSvg(2));
  refreshIndexStatus();
  // Lien partagé : #root=…&q=… rejoue la recherche.
  if (location.hash.length > 1) {
    const shared = Object.fromEntries(new URLSearchParams(location.hash.slice(1)));
    if (shared.q && shared.root) {
      applyParams(shared);
      history.replaceState(null, '', location.pathname);
      scheduleIndexStatus();
      runSearch();
    }
  }
  renderHoist();
}

init();
