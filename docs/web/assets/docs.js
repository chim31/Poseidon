// Poseidon — rendu du cahier des charges et de la roadmap depuis data/projet.js
// (source unique : data/projet.json, synchronisée par php docs/tools/sync.php).

(() => {
  const data = window.POSEIDON_PROJET;
  if (!data) return;

  const h = (tag, attrs = {}, ...children) => {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'text') el.textContent = v;
      else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else el.setAttribute(k, v);
    }
    for (const c of children.flat()) if (c !== null && c !== undefined && c !== false) el.append(c instanceof Node ? c : document.createTextNode(String(c)));
    return el;
  };
  const $ = (id) => document.getElementById(id);
  const ST = { 'Terminé': 'done', 'En cours': 'doing', 'À faire': 'todo', 'Bloqué': 'blocked' };
  const stClass = (s) => 'st-' + (ST[s] || 'todo');
  const all = data.lots.flatMap((l) => l.exigences.map((e) => ({ ...e, lot: l.id })));
  const count = (items) => items.reduce((c, e) => { c[e.statut] = (c[e.statut] || 0) + 1; return c; }, {});
  const pct = (items) => Math.round(100 * (count(items)['Terminé'] || 0) / Math.max(1, items.length));
  const fmtDate = (iso) => iso ? new Date(iso + 'T12:00:00').toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
  const status = (s) => h('span', { class: 'status ' + stClass(s) }, h('i', { 'aria-hidden': 'true' }), s);

  document.querySelectorAll('[data-meta]').forEach((el) => {
    const key = el.dataset.meta;
    el.textContent = key === 'miseAJour' ? fmtDate(data.meta.miseAJour) : key === 'pct' ? pct(all) + ' %' : data.meta[key] ?? '';
  });

  /* ---------------------------------------------------- Cahier des charges */

  if ($('dash')) {
    const c = count(all);
    $('dash').replaceChildren(
      h('div', {}, h('strong', { text: all.length }), h('span', { text: 'exigences' })),
      h('div', {}, h('strong', { text: c['Terminé'] || 0 }), h('span', { text: 'terminées' })),
      h('div', {}, h('strong', { text: c['En cours'] || 0 }), h('span', { text: 'en cours' })),
      h('div', {}, h('strong', { text: (c['À faire'] || 0) + (c['Bloqué'] || 0) }), h('span', { text: 'à faire' + (c['Bloqué'] ? ` (dont ${c['Bloqué']} bloquées)` : '') })),
    );
    const bar = $('statusbar');
    for (const [s, cls] of [['Terminé', 's-done'], ['En cours', 's-doing'], ['Bloqué', 's-blocked']]) {
      if (c[s]) { const span = h('span', { class: cls, title: `${s} : ${c[s]}` }); span.style.width = (100 * c[s] / all.length) + '%'; bar.append(span); }
    }
    bar.setAttribute('aria-label', `${c['Terminé'] || 0} terminées, ${c['En cours'] || 0} en cours, ${c['À faire'] || 0} à faire sur ${all.length}`);

    $('lots').replaceChildren(...data.lots.map((l) => h('a', { class: 'lot-row', href: '#lot-' + l.id },
      h('span', { class: 'lot-id', text: l.id }),
      h('span', { class: 'lot-title', text: l.titre }),
      h('span', { class: 'drisse', 'aria-label': `${count(l.exigences)['Terminé'] || 0} exigences terminées sur ${l.exigences.length}` }, ...l.exigences.map((e) => h('i', { class: stClass(e.statut), title: `${e.id} · ${e.titre} — ${e.statut}` }))),
      h('span', { class: 'lot-pct', text: pct(l.exigences) + ' %' }),
    )));
  }

  if ($('requirements')) {
    let filter = 'Tous';
    let query = '';
    const counts = count(all);
    const chips = ['Tous', 'Terminé', 'En cours', 'À faire', 'Bloqué'].filter((s) => s === 'Tous' || counts[s]);
    $('filter-chips').replaceChildren(...chips.map((s) => h('button', { class: 'chip', type: 'button', 'aria-pressed': String(s === filter), onclick: (e) => {
      filter = s;
      $('filter-chips').querySelectorAll('.chip').forEach((c) => c.setAttribute('aria-pressed', String(c === e.currentTarget)));
      render();
    } }, s, h('small', { text: s === 'Tous' ? all.length : counts[s] }))));
    $('filter-q').addEventListener('input', (e) => { query = e.target.value.trim().toLowerCase(); render(); });

    function render() {
      const blocks = [];
      for (const l of data.lots) {
        const rows = l.exigences.filter((e) => (filter === 'Tous' || e.statut === filter) && (!query || (e.id + ' ' + e.titre + ' ' + e.description + ' ' + e.ref).toLowerCase().includes(query)));
        if (!rows.length) continue;
        blocks.push(h('div', { class: 'lot', id: 'lot-' + l.id },
          h('div', { class: 'lot-head' }, h('span', { class: 'lot-id', text: l.id }), h('h3', { text: l.titre }), h('span', { class: 'lot-count', text: `${pct(l.exigences)} % · ${count(l.exigences)['Terminé'] || 0}/${l.exigences.length}` })),
          h('p', { class: 'lot-obj', text: l.objectif }),
          h('table', { class: 'req' },
            h('thead', {}, h('tr', {}, h('th', { text: 'ID' }), h('th', { text: 'Exigence' }), h('th', { text: 'Statut' }), h('th', { text: 'Priorité' }), h('th', { text: 'Suivi' }))),
            h('tbody', {}, ...rows.map((e) => h('tr', {},
              h('td', { text: e.id }),
              h('td', {}, h('span', { class: 't', text: e.titre }), h('span', { class: 'd', text: e.description })),
              h('td', {}, status(e.statut)),
              h('td', {}, h('span', { class: 'prio p-' + e.priorite.toLowerCase(), text: e.priorite })),
              h('td', { class: 'meta' }, e.responsable, h('br'), e.date ? fmtDate(e.date) : '—', h('br'), e.ref || '—'),
            ))),
          ),
        ));
      }
      $('requirements').replaceChildren(...(blocks.length ? blocks : [h('p', { class: 'empty-filter', text: 'Aucune exigence ne correspond à ce filtre.' })]));
    }
    render();
  }

  if ($('props')) {
    const group = (type, title) => h('div', {}, h('h3', { text: title }), ...data.propositions.filter((p) => p.type === type).map((p) => h('div', { class: 'prop' }, h('b', { text: p.titre }), h('p', { text: p.texte }))));
    $('props').replaceChildren(group('Correction', 'Points à corriger ou à préciser'), group('Fonctionnalité', 'Nouvelles fonctionnalités'));
  }

  /* ---------------------------------------------------- Roadmap */

  if ($('frise')) {
    const start = new Date(data.phases[0].debut + 'T00:00:00');
    start.setDate(1);
    const endLast = new Date(data.phases[data.phases.length - 1].fin + 'T00:00:00');
    const end = new Date(endLast.getFullYear(), endLast.getMonth() + 1, 1);
    const months = [];
    for (let d = new Date(start); d < end; d.setMonth(d.getMonth() + 1)) months.push(new Date(d));
    const span = end - start;
    const pos = (iso, endOfDay = false) => 100 * ((new Date(iso + 'T00:00:00') - start) + (endOfDay ? 864e5 : 0)) / span;

    const grid = h('div', { class: 'frise-grid' });
    grid.style.setProperty('--months', months.length);
    const monthRow = h('div', { class: 'frise-months' }, ...months.map((m) => h('span', { text: m.toLocaleDateString('fr-FR', { month: 'short', year: '2-digit' }) })));
    monthRow.style.gridTemplateColumns = `repeat(${months.length}, 1fr)`;
    grid.append(h('span', {}), monthRow);

    const today = new Date(data.meta.miseAJour + 'T12:00:00');
    data.phases.forEach((p, i) => {
      const track = h('div', { class: 'frise-track' });
      const bar = h('span', { class: 'frise-bar ' + stClass(p.statut), title: `${p.id} · ${p.titre} : ${fmtDate(p.debut)} → ${fmtDate(p.fin)} (${p.statut})` });
      bar.style.left = pos(p.debut) + '%';
      bar.style.width = Math.max(0.6, pos(p.fin, true) - pos(p.debut)) + '%';
      track.append(bar);
      if (i === 0) {
        const t = h('span', { class: 'frise-today', title: 'Aujourd\'hui' });
        t.style.left = (100 * (today - start) / span) + '%';
        track.append(t);
      }
      grid.append(h('span', { class: 'frise-label', text: `${p.id} · ${p.titre}` }), track);
    });
    $('frise').replaceChildren(grid);
  }

  if ($('route')) {
    const current = data.phases.find((p) => p.statut === 'En cours');
    $('route').replaceChildren(...data.phases.map((p) => h('article', { class: 'station ' + stClass(p.statut), id: 'phase-' + p.id },
      h('div', { class: 'station-date' }, h('b', { text: fmtDate(p.debut) }), p.fin !== p.debut ? '→ ' + fmtDate(p.fin) : ''),
      h('span', { class: 'station-pin', 'aria-hidden': 'true' }),
      h('div', { class: 'station-card' },
        h('header', {}, h('span', { class: 'pid', text: p.id }), h('h3', { text: p.titre }), p === current ? h('span', { class: 'here', text: 'Vous êtes ici' }) : null, status(p.statut)),
        h('ul', {}, ...p.livrables.map((l) => h('li', { text: l })))),
    )));
    const done = data.phases.filter((p) => p.statut === 'Terminé').length;
    const el = $('phase-count');
    if (el) el.textContent = `${done} / ${data.phases.length}`;
  }

  /* ---------------------------------------------------- Sommaire actif */

  const links = [...document.querySelectorAll('.toc a')];
  if (links.length && 'IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
      for (const e of entries) if (e.isIntersecting) links.forEach((a) => a.classList.toggle('active', a.getAttribute('href') === '#' + e.target.id));
    }, { rootMargin: '-30% 0px -60% 0px' });
    links.forEach((a) => { const t = document.querySelector(a.getAttribute('href')); if (t) io.observe(t); });
  }
})();
