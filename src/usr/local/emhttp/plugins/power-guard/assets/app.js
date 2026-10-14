/* Power Guard UI – dependency-free, works offline. */
(() => {
  'use strict';

  const root = document.getElementById('pg-app');
  if (!root) return;

  const API = root.dataset.api;
  const CSRF = root.dataset.csrf;
  const SPARK_SAMPLES = 300; // 10 min at 2 s

  const PROFILES = { efficiency: 'Effizienz', balanced: 'Ausgewogen', performance: 'Leistung' };
  const STATUS = {
    optimal: 'Optimal', recommended: 'Empfohlen', custom: 'Abweichend (manuell)', conflict: 'Konflikt',
    warning: 'Warnung', na: 'Nicht zutreffend', info: 'Info', unmanaged: 'Nicht verwaltet',
  };
  const RISK = { low: 'niedrig', medium: 'mittel', high: 'hoch' };
  const ROLE = { storage: 'Speicher', 'storage-path': 'Hub → Speicher', hid: 'Eingabe', ups: 'USV', kvm: 'KVM' };

  const ui = {
    status: null,
    filter: localGet('filter', 'deviations'),
    open: new Set(),
    tel: { prev: null, power: [], timer: null },
  };

  // ---------- helpers ----------
  function localGet(k, d) { try { return localStorage.getItem('pg.' + k) ?? d; } catch { return d; } }
  function localSet(k, v) { try { localStorage.setItem('pg.' + k, v); } catch { /* ignore */ } }

  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function fmtBytes(b) {
    if (b >= 1073741824) return (b / 1073741824).toFixed(b % 1073741824 ? 1 : 0) + ' GiB';
    if (b >= 1048576) return Math.round(b / 1048576) + ' MiB';
    if (b >= 1024) return Math.round(b / 1024) + ' KiB';
    return b + ' B';
  }

  function fmtVal(v, format) {
    if (v === null || v === undefined) return '–';
    if (typeof v === 'number') {
      if (format === 'bytes') return fmtBytes(v);
      if (format === 'kib') return fmtBytes(v * 1024);
      if (format === 'bool') return v ? 'an' : 'aus';
    }
    if (typeof v === 'object') return JSON.stringify(v);
    return String(v);
  }

  async function api(action, data = {}) {
    // URL-encoded on purpose: Unraid's nginx/php-fpm stalls multipart POSTs to plugin endpoints.
    const body = new URLSearchParams();
    body.append('csrf_token', CSRF);
    body.append('action', action);
    for (const [k, v] of Object.entries(data)) body.append(k, typeof v === 'string' ? v : JSON.stringify(v));
    const res = await fetch(API, { method: 'POST', body, credentials: 'same-origin' });
    let json = null;
    try { json = await res.json(); } catch { /* non-JSON */ }
    if (!res.ok || !json || json.error) throw new Error((json && json.error) || `HTTP ${res.status}`);
    return json;
  }

  let toastTimer;
  function toast(msg, err = false) {
    let el = document.querySelector('.pg-toast');
    if (!el) { el = document.createElement('div'); el.className = 'pg-toast'; document.body.appendChild(el); }
    el.textContent = msg;
    el.classList.toggle('err', err);
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('show'), 3500);
  }

  // ---------- render: header ----------
  function ring(score) {
    const r = 26, c = 2 * Math.PI * r;
    const off = c * (1 - score / 100);
    return `<svg class="pg-ring" viewBox="0 0 64 64" role="img" aria-label="Optimierungs-Score ${score} von 100">
      <circle class="bg" cx="32" cy="32" r="${r}"/>
      <circle class="fg" cx="32" cy="32" r="${r}" stroke-dasharray="${c.toFixed(2)}" stroke-dashoffset="${off.toFixed(2)}"/>
      <text x="32" y="32">${score}</text></svg>`;
  }

  function header(s) {
    const cfg = s.config;
    const seg = Object.entries(PROFILES).map(([k, l]) =>
      `<button type="button" data-profile="${k}" class="${cfg.profile === k ? 'on' : ''}" aria-pressed="${cfg.profile === k}">${l}</button>`).join('');
    const pending = s.plan.rows.filter((r) => r.status === 'recommended').length;
    const applicable = s.plan.rows.filter((r) => r.status === 'recommended' && r.supported).length;
    const active = cfg.mode === 'active';
    return `<div class="pg-card pg-header">
      <div class="pg-title"><div class="bolt" aria-hidden="true">⚡</div>
        <div><h2>Power Guard</h2><small>v${esc(s.version)} · ${esc(s.hardware.os.unraid || '?')} · Kernel ${esc(s.hardware.os.kernel || '?')}</small></div></div>
      <span class="pg-mode ${active ? 'active' : ''}" title="${active ? 'Power Guard verwaltet das System' : 'Power Guard ändert nichts, es zeigt nur Ist-Werte und Empfehlungen'}">${active ? 'Aktiv' : 'Beobachten'}</span>
      <div class="grow"></div>
      <div class="pg-seg" role="group" aria-label="Profil">${seg}</div>
      <div class="pg-score">${ring(s.plan.score.value)}<div><div><b>${pending}</b> Empfehlung${pending === 1 ? '' : 'en'}</div><div class="lbl">Optimierungs-Score</div></div></div>
      <button type="button" class="pg-btn primary" data-act="preview" ${applicable ? '' : 'disabled'} title="${applicable ? 'Vorschau aller Änderungen öffnen' : 'Keine anwendbaren Empfehlungen'}">Empfehlungen anwenden${applicable ? ` (${applicable})` : ''}</button>
    </div>`;
  }

  // ---------- render: live tiles ----------
  function tiles(hw) {
    const cpu = hw.cpu;
    const bars = Array.from({ length: cpu.threads }, (_, i) =>
      `<span data-cpu="${i}" class="${cpu.eCores.includes(i) ? 'e' : ''}" style="height:2%"></span>`).join('');
    return `<div class="pg-grid cols-4">
      <div class="pg-card pg-tile"><h3>Paketleistung</h3>
        <div class="big" id="pg-watts">${hw.rapl ? '…' : '–'}<small>W</small></div>
        <div class="sub" id="pg-watts-sub">${hw.rapl ? 'RAPL, Mittel über 2 s' : 'RAPL nicht verfügbar'}</div>
        <svg class="pg-spark" id="pg-spark" viewBox="0 0 300 42" preserveAspectRatio="none" aria-hidden="true"><polygon points=""/><polyline points=""/></svg></div>
      <div class="pg-card pg-tile"><h3>Leerlauf-Zustände</h3>
        <div class="big" id="pg-idle">…<small>% tief</small></div>
        <div class="sub" id="pg-idle-sub">Anteil der Zeit in den tiefsten C-States (alle Kerne)</div>
        <div class="pg-stack" id="pg-idle-stack"></div><div class="pg-legend" id="pg-idle-legend"></div></div>
      <div class="pg-card pg-tile"><h3>Takt je Kern</h3>
        <div class="big" id="pg-freq">…<small>GHz Ø</small></div>
        <div class="pg-bars" id="pg-bars">${bars}</div>
        <div class="pg-legend">${cpu.hybrid ? '<span><i style="background:var(--p-core)"></i>P-Kerne</span><span><i style="background:var(--e-core)"></i>E-Kerne</span>' : ''}<span>max. ${cpu.maxKHz ? (cpu.maxKHz / 1e6).toFixed(1) : '?'} GHz</span></div></div>
      <div class="pg-card pg-tile"><h3>Temperaturen</h3><div class="pg-temps" id="pg-temps"><span class="sub">…</span></div></div>
    </div>`;
  }

  // ---------- render: hardware ----------
  function hardware(hw) {
    const cpu = hw.cpu, boot = hw.boot;
    const range = (a) => {
      if (!a.length) return '';
      const out = []; let s = a[0], p = a[0];
      for (const x of a.slice(1).concat([null])) { if (x === p + 1) { p = x; continue; } out.push(s === p ? `${s}` : `${s}-${p}`); s = p = x; }
      return out.join(',');
    };
    const topo = cpu.hybrid ? `P-Kerne ${range(cpu.pCores)} · E-Kerne ${range(cpu.eCores)}` : `${cpu.threads} Threads`;
    const nics = hw.nics.map((n) => `${esc(n.name)} <span class="mono">${esc(n.driver)}</span> ${n.speed ? (n.speed >= 1000 ? n.speed / 1000 + ' Gb/s' : n.speed + ' Mb/s') : esc(n.state)}${n.wol && n.wol !== 'd' ? ' · WoL ' + esc(n.wol) : ''}`).join('<br>') || '–';
    const pools = hw.zfs.pools.map((p) => `${esc(p.name)}: ${fmtBytes(p.alloc)} / ${fmtBytes(p.size)} (${p.cap} %)`).join('<br>') || '–';
    const gpu = hw.gpu.cards.map((g) => `${esc(g.card)} <span class="mono">${esc(g.driver || '–')}</span> ${esc(g.pci || '')}`).join('<br>') || '–';
    const mem = hw.memory;
    const usb = hw.usb.devices.filter((d) => !d.root).map((d) => {
      const depth = (d.id.match(/[.-]/g) || []).length - 1;
      const roles = d.roles.map((r) => ROLE[r] || r).join(', ');
      const lock = d.protected ? `<span class="lock ${d.violation ? 'bad' : ''}" title="${d.violation ? 'Geschützt, aber power/control=' + esc(d.control) : 'Geschützt (power/control=on)'}">${d.violation ? '⚠' : '🔒'}</span>` : '<span class="dim">·</span>';
      return `<li style="padding-left:${depth * 14}px">${lock}<span>${esc(d.product || d.vid + ':' + d.pid)}</span><span class="dim mono">${esc(d.id)}${roles ? ' · ' + esc(roles) : ''} · ${esc(d.control)}</span></li>`;
    }).join('');

    return `<div class="pg-card"><h3>Hardware</h3>
      <dl class="pg-facts">
        <dt>CPU</dt><dd>${esc(cpu.model)}<br><span class="mono">${esc(topo)} · ${esc(cpu.driver)} (${esc(cpu.driverStatus || '–')})${cpu.hwp ? ' · HWP' : ''} · ${esc(cpu.idleDriver || '')}</span></dd>
        <dt>RAM</dt><dd>${fmtBytes((mem.totalKiB || 0) * 1024)} · Swap ${mem.swapKiB ? fmtBytes(mem.swapKiB * 1024) : 'keiner'}${mem.zram.length ? ' · zram' : ''}</dd>
        <dt>Boot</dt><dd>${esc(boot.loader || 'unbekannt')}${boot.loaders[0] ? ` <span class="mono">${esc(boot.loaders[0].file)} (${esc(boot.loaders[0].eol.toUpperCase())}, ${boot.loaders[0].entries} Einträge)</span>` : ''}${boot.bootFs ? ' · ' + esc(boot.bootFs) : ''}<br><span class="mono">${esc(boot.cmdline)}</span></dd>
        <dt>GPU</dt><dd>${gpu}</dd>
        <dt>Netz</dt><dd>${nics}</dd>
        <dt>ZFS</dt><dd>${pools}${hw.zfs.arc ? `<br><span class="mono">ARC ${fmtBytes(hw.zfs.arc.size || 0)} / max ${fmtBytes(hw.zfs.arc.c_max || 0)}</span>` : ''}</dd>
        <dt>ASPM</dt><dd>${esc(hw.pcie.policy || '–')} · ${hw.pcie.osControl ? 'OS hat Kontrolle' : 'Firmware behält Kontrolle'}</dd>
        <dt>Last</dt><dd>${hw.containers.running ?? '?'} Container</dd>
      </dl>
      <h3 style="margin-top:14px">USB</h3><ul class="pg-usb">${usb || '<li class="dim">keine Geräte</li>'}</ul>
    </div>`;
  }

  // ---------- render: conflicts ----------
  function conflicts(list) {
    if (!list.length) return '';
    const label = { conflict: 'Konflikt', warning: 'Hinweis', info: 'Info' };
    return `<div class="pg-section">${list.map((c) => `
      <details class="pg-banner ${esc(c.severity)}" ${c.severity === 'conflict' ? 'open' : ''}>
        <summary><span class="pg-chip ${c.severity === 'conflict' ? 'conflict' : c.severity === 'warning' ? 'warning' : ''}">${label[c.severity]}</span>${esc(c.title)}</summary>
        <p>${esc(c.detail)}</p>
        ${c.source ? `<p class="mono">${esc(c.source)}</p>` : ''}
        ${c.lines && c.lines.length ? `<pre>${esc(c.lines.join('\n'))}</pre>` : ''}
        ${c.settings ? `<pre>${esc(Object.entries(c.settings).map(([k, v]) => `${k}="${v}"`).join('\n'))}</pre>` : ''}
        ${c.action ? `<p class="todo"><b>Was tun?</b> ${esc(c.action)}</p>` : ''}
      </details>`).join('')}</div>`;
  }

  // ---------- render: tweaks ----------
  function tweakRow(r) {
    const vals = r.status === 'na' ? `<span class="dim">${esc(r.reason || '')}</span>`
      : `${esc(fmtVal(r.current, r.format))}${r.target !== null && r.status !== 'optimal' && r.status !== 'custom'
        ? `<span class="arrow">→</span><span class="to">${esc(fmtVal(r.target, r.format))}</span>` : ''}`;
    const badges = [
      r.managed ? '<span class="pg-chip managed" title="Wird von Power Guard verwaltet und beim Boot gesetzt">verwaltet</span>' : '',
      r.risk !== 'low' ? `<span class="pg-chip risk-${esc(r.risk)}">Risiko ${RISK[r.risk]}</span>` : '',
      r.timing === 'reboot' ? '<span class="pg-chip reboot">Reboot</span>' : '',
    ].join('');
    const open = ui.open.has(r.id);
    const canOverride = r.status !== 'na' && r.manageable;
    return `<div class="pg-row ${open ? 'open' : ''}" data-id="${esc(r.id)}">
      <button type="button" class="head" aria-expanded="${open}">
        <span><span class="pg-chip ${esc(r.status)}">${STATUS[r.status] || esc(r.status)}</span></span>
        <span class="name">${esc(r.title)}<code>${esc(r.id)}</code></span>
        <span class="vals">${vals}</span>
        <span class="badges">${badges}</span>
      </button>
      <div class="body">
        ${r.reason && r.status !== 'na' ? `<p class="reason ${r.status === 'conflict' ? 'conflict' : ''}">${esc(r.reason)}</p>` : ''}
        ${r.why ? `<p>${esc(r.why)}</p>` : ''}
        ${r.source ? `<p>Quelle: ${/^https?:/.test(r.source) ? `<a href="${esc(r.source)}" target="_blank" rel="noopener noreferrer">${esc(r.source)}</a>` : esc(r.source)}</p>` : ''}
        ${canOverride ? `<div class="override">
          <label>Verwaltung <select data-role="mode">
            <option value="auto" ${r.mode === 'auto' ? 'selected' : ''}>Auto (Empfehlung)</option>
            <option value="custom" ${r.mode === 'custom' ? 'selected' : ''}>Eigener Wert</option>
            <option value="off" ${r.mode === 'off' ? 'selected' : ''}>Nicht verwalten</option></select></label>
          <input data-role="value" type="text" placeholder="${esc(r.recommended ?? '')}" title="${r.format === 'bytes' ? 'Wert in Bytes' : r.format === 'kib' ? 'Wert in KiB' : 'Rohwert wie in sysfs/sysctl'}" value="${r.mode === 'custom' ? esc(r.target ?? '') : ''}" ${r.mode === 'custom' ? '' : 'hidden'} aria-label="Eigener Wert">
          <button type="button" class="pg-btn small" data-role="save">Speichern</button>
          ${r.status === 'recommended' && r.supported ? `<button type="button" class="pg-btn small primary" data-act="preview" data-ids="${esc(r.id)}">Anwenden …</button>` : ''}
          ${r.touched ? `<button type="button" class="pg-btn small" data-act="revert" data-ids="${esc(r.id)}">Zurücknehmen</button>` : ''}
        </div>` : ''}
        ${r.manageable && !r.supported ? '<p class="dim">Anwenden folgt in Phase 4 (NIC/PCI/USB).</p>' : ''}
      </div></div>`;
  }

  function tweaks(s) {
    const rows = s.plan.rows;
    const counts = s.plan.score.counts;
    const visible = (r) => ui.filter === 'all' || !['optimal', 'na', 'info', 'unmanaged'].includes(r.status);
    const cats = Object.entries(s.categories).map(([cat, title]) => {
      const list = rows.filter((r) => r.category === cat && visible(r));
      return list.length ? `<div class="pg-cat"><h3>${esc(title)}</h3>${list.map(tweakRow).join('')}</div>` : '';
    }).join('');
    const chips = Object.entries(counts).map(([k, n]) => `<span class="pg-chip ${esc(k)}">${n} ${STATUS[k] || esc(k)}</span>`).join('');
    return `<div class="pg-card"><div class="pg-toolbar"><h3 style="margin:0">Tweaks</h3>
        <label><input type="checkbox" id="pg-filter" ${ui.filter === 'deviations' ? 'checked' : ''}> nur Abweichungen</label>
        <div class="pg-counts">${chips}</div></div>
      ${cats || '<p class="sub">Keine Abweichungen, alles ist optimal. 🎉</p>'}</div>`;
  }

  // ---------- render: journal ----------
  function journal(s) {
    const list = s.journal || [];
    const touched = s.plan.rows.filter((r) => r.touched).length;
    const title = Object.fromEntries(s.plan.rows.map((r) => [r.id, r.title]));
    const items = list.slice(0, 15).map((e) => `<li class="${e.ok ? '' : 'bad'}">
        <time>${new Date(e.t * 1000).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })}</time>
        <span><b>${e.action === 'revert' ? 'Zurückgesetzt' : 'Angewendet'}${e.boot ? ' (Boot)' : ''}${e.ok ? '' : ' – Fehler'}</b>
        ${esc(title[e.id] || e.id)}${e.action === 'apply' && e.ok ? ` <span class="mono dim">${esc(fmtVal(e.from))} → ${esc(fmtVal(e.to))}</span>` : ''}
        ${e.msg ? `<br><span class="dim">${esc(e.msg)}</span>` : ''}</span></li>`).join('');
    return `<div class="pg-card pg-journal"><div class="pg-toolbar"><h3 style="margin:0">Journal</h3>
        <div class="grow"></div>
        <button type="button" class="pg-btn small" data-act="revert" data-ids="all" ${touched ? '' : 'disabled'}
          title="Alle von Power Guard geänderten Werte auf den Stand vor der ersten Änderung zurücksetzen und in den Beobachtungsmodus wechseln">Alles auf Baseline</button></div>
      ${items ? `<ul>${items}</ul>` : '<p class="dim">Noch keine Änderungen.</p>'}</div>`;
  }

  // ---------- preview dialog ----------
  async function openPreview(ids) {
    let changes;
    try { ({ changes } = await api('preview', ids ? { ids: JSON.stringify(ids) } : {})); } catch (e) { toast(e.message, true); return; }
    if (!changes.length) { toast('Keine Änderungen nötig'); return; }
    const dlg = document.createElement('dialog');
    dlg.className = 'pg pg-dialog';
    dlg.dataset.theme = root.dataset.theme;
    const row = (c) => {
      const ok = c.supported && !c.error;
      const checked = ok && (ids || c.risk === 'low');
      return `<label class="pg-change ${ok ? '' : 'off'}">
        <input type="checkbox" value="${esc(c.id)}" ${ok ? '' : 'disabled'} ${checked ? 'checked' : ''}>
        <span><b>${esc(c.title)}</b> ${c.risk !== 'low' ? `<span class="pg-chip risk-${esc(c.risk)}">Risiko ${RISK[c.risk]}</span>` : ''}
          <span class="mono"><br>${esc(fmtVal(c.from, c.format))} <span class="arrow">→</span> <span class="to">${esc(fmtVal(c.to, c.format))}</span></span>
          ${c.error ? `<br><span class="reason conflict">${esc(c.error)}</span>` : ''}
          ${!c.supported ? '<br><span class="dim">Anwenden folgt in Phase 4</span>' : ''}
          ${c.writes.length ? `<details><summary class="dim">${c.writes.length} Schreibzugriff${c.writes.length > 1 ? 'e' : ''}</summary><pre>${esc(c.writes.map((w) => `${w.path} = ${w.value}`).join('\n'))}</pre></details>` : ''}
        </span></label>`;
    };
    dlg.innerHTML = `<form method="dialog">
      <h2>Vorschau: ${changes.length} Änderung${changes.length > 1 ? 'en' : ''}</h2>
      <p class="dim">Jeder Wert wird vor dem ersten Schreiben als Baseline gesichert und kann einzeln zurückgenommen werden. Angewendete Tweaks setzt Power Guard bei jedem Boot erneut. Alle Änderungen wirken sofort, ohne Neustart.</p>
      <div class="pg-changes">${changes.map(row).join('')}</div>
      <div class="pg-dialog-foot"><button class="pg-btn" value="cancel">Abbrechen</button>
        <button class="pg-btn primary" value="apply" data-role="go">Anwenden</button></div></form>`;
    document.body.appendChild(dlg);
    const go = dlg.querySelector('[data-role="go"]');
    const selected = () => [...dlg.querySelectorAll('input[type=checkbox]:checked')].map((i) => i.value);
    const sync = () => { const n = selected().length; go.disabled = !n; go.textContent = `${n} anwenden`; };
    dlg.addEventListener('change', sync);
    sync();
    dlg.addEventListener('close', async () => {
      const chosen = selected();
      const confirmed = dlg.returnValue === 'apply';
      dlg.remove();
      if (!confirmed || !chosen.length) return;
      try {
        const res = await api('apply', { ids: JSON.stringify(chosen) });
        const msg = `${res.applied.length} angewendet` + (res.failed.length ? `, ${res.failed.length} fehlgeschlagen: ${res.failed.map((f) => f.reason).join('; ')}` : '')
          + (res.skipped.length ? `, ${res.skipped.length} übersprungen` : '');
        toast(msg, res.failed.length > 0);
        await load(true);
      } catch (e) { toast(e.message, true); }
    });
    dlg.showModal();
  }

  async function revert(ids) {
    const all = ids === 'all';
    if (all && !confirm('Alle von Power Guard geänderten Werte zurücksetzen und in den Beobachtungsmodus wechseln?')) return;
    try {
      const res = await api('revert', all ? {} : { ids: JSON.stringify([ids]) });
      toast(`${res.reverted.length} zurückgesetzt` + (res.failed.length ? `, ${res.failed.length} fehlgeschlagen` : ''), res.failed.length > 0);
      await load(true);
    } catch (e) { toast(e.message, true); }
  }

  function render() {
    const s = ui.status;
    root.innerHTML = `${header(s)}
      <div class="pg-section">${tiles(s.hardware)}</div>
      ${conflicts(s.conflicts)}
      <div class="pg-grid main pg-section">${tweaks(s)}<div class="pg-side">${journal(s)}${hardware(s.hardware)}</div></div>
      <div class="pg-foot">Erkannt ${new Date(s.hardware.detected * 1000).toLocaleTimeString()} · <a href="#" id="pg-refresh">Neu erkennen</a></div>`;
    ui.tel.prev = null;
    drawSpark();
  }

  // ---------- events ----------
  root.addEventListener('click', async (ev) => {
    const prof = ev.target.closest('[data-profile]');
    if (prof) {
      await saveConfig({ profile: prof.dataset.profile }, `Profil: ${PROFILES[prof.dataset.profile]}`);
      return;
    }
    if (ev.target.id === 'pg-refresh') { ev.preventDefault(); load(); return; }
    const act = ev.target.closest('[data-act]');
    if (act) {
      ev.stopPropagation();
      if (act.dataset.act === 'preview') openPreview(act.dataset.ids ? [act.dataset.ids] : null);
      else if (act.dataset.act === 'revert') revert(act.dataset.ids);
      return;
    }
    const head = ev.target.closest('.pg-row > button.head');
    if (head) {
      const row = head.parentElement, id = row.dataset.id;
      ui.open.has(id) ? ui.open.delete(id) : ui.open.add(id);
      row.classList.toggle('open');
      head.setAttribute('aria-expanded', row.classList.contains('open'));
      return;
    }
    const save = ev.target.closest('[data-role="save"]');
    if (save) {
      const row = save.closest('.pg-row');
      const mode = row.querySelector('[data-role="mode"]').value;
      const tweak = { id: row.dataset.id, mode };
      if (mode === 'custom') {
        const input = row.querySelector('[data-role="value"]');
        tweak.value = input.value.trim();
        if (!tweak.value) { input.focus(); toast('Bitte einen Wert eingeben', true); return; }
      }
      await saveConfig({ tweak }, 'Gespeichert');
    }
  });

  root.addEventListener('change', (ev) => {
    if (ev.target.id === 'pg-filter') {
      ui.filter = ev.target.checked ? 'deviations' : 'all';
      localSet('filter', ui.filter);
      render();
    } else if (ev.target.dataset.role === 'mode') {
      ev.target.closest('.override').querySelector('[data-role="value"]').hidden = ev.target.value !== 'custom';
    }
  });

  async function saveConfig(patch, msg) {
    try {
      await api('config', { patch });
      toast(msg);
      await load(true);
    } catch (e) { toast(e.message, true); }
  }

  // ---------- telemetry ----------
  function drawSpark() {
    const svg = document.getElementById('pg-spark');
    if (!svg) return;
    const d = ui.tel.power;
    if (d.length < 2) return;
    const max = Math.max(...d) * 1.15 || 1;
    const step = 300 / (SPARK_SAMPLES - 1);
    const x0 = 300 - (d.length - 1) * step;
    const pts = d.map((v, i) => `${(x0 + i * step).toFixed(1)},${(42 - (v / max) * 40).toFixed(1)}`).join(' ');
    svg.querySelector('polyline').setAttribute('points', pts);
    svg.querySelector('polygon').setAttribute('points', `${x0.toFixed(1)},42 ${pts} 300,42`);
  }

  const IDLE_COLORS = ['#9aa3ad', '#7fb6e6', '#3f8fd2', '#2fa66a', '#1f7a4c', '#145233'];

  function updateTelemetry(t) {
    const prev = ui.tel.prev;
    ui.tel.prev = t;
    const hw = ui.status.hardware;

    // Frequencies
    const freqs = Object.values(t.freq).filter((v) => v);
    const max = hw.cpu.maxKHz || Math.max(...freqs, 1);
    if (freqs.length) document.getElementById('pg-freq').innerHTML = `${(freqs.reduce((a, b) => a + b, 0) / freqs.length / 1e6).toFixed(2)}<small>GHz Ø</small>`;
    for (const [cpu, khz] of Object.entries(t.freq)) {
      const bar = document.querySelector(`#pg-bars [data-cpu="${cpu}"]`);
      if (bar) { bar.style.height = Math.max(2, Math.min(100, (khz || 0) / max * 100)) + '%'; bar.title = `CPU ${cpu}: ${((khz || 0) / 1e6).toFixed(2)} GHz`; }
    }

    // Temps: the most relevant sensors first
    const pick = (t.temps.filter((x) => /package|tctl|composite|edge|spd|cpu|temp1/i.test(x.label + x.chip)) || []).slice(0, 7);
    const seen = {};
    document.getElementById('pg-temps').innerHTML = (pick.length ? pick : t.temps.slice(0, 7)).map((x) => {
      const key = `${x.chip} ${x.label}`;
      seen[key] = (seen[key] || 0) + 1;
      // Several identical chips (two NVMe, two DIMMs) get a running number.
      const dup = t.temps.filter((y) => `${y.chip} ${y.label}` === key).length > 1;
      return `<span>${esc(x.chip)}${dup ? ' ' + seen[key] : ''} ${esc(x.label)}</span><b>${x.c.toFixed(0)} °C</b>`;
    }).join('') || '<span class="sub">keine Sensoren</span>';

    if (!prev) return;
    const dt = t.t - prev.t;
    if (dt <= 0) return;

    // Power (package zone)
    const pkg = t.rapl.find((z) => z.zone === 'intel-rapl:0');
    const ppk = prev.rapl.find((z) => z.zone === 'intel-rapl:0');
    if (pkg && ppk) {
      let d = pkg.uj - ppk.uj;
      if (d < 0 && pkg.max) d += pkg.max;
      const w = d / 1e6 / dt;
      ui.tel.power.push(w);
      if (ui.tel.power.length > SPARK_SAMPLES) ui.tel.power.shift();
      document.getElementById('pg-watts').innerHTML = `${w.toFixed(1)}<small>W</small>`;
      const avg = ui.tel.power.reduce((a, b) => a + b, 0) / ui.tel.power.length;
      const span = ui.tel.power.length * 2;
      document.getElementById('pg-watts-sub').textContent = `Ø ${avg.toFixed(1)} W · min ${Math.min(...ui.tel.power).toFixed(1)} W · letzte ${span < 60 ? span + ' s' : Math.round(span / 60) + ' min'}`;
      drawSpark();
    }

    // Idle residency: share of wall time per state across all CPUs
    const n = Object.keys(t.freq).length || 1;
    const names = Object.keys(t.idle);
    const shares = names.map((k) => Math.max(0, (t.idle[k] - (prev.idle[k] ?? 0)) / 1e6 / dt / n));
    const deep = names.reduce((a, k, i) => a + (/^C([6-9]|10)/i.test(k) ? shares[i] : 0), 0);
    document.getElementById('pg-idle').innerHTML = `${Math.min(100, deep * 100).toFixed(0)}<small>% tief</small>`;
    document.getElementById('pg-idle-stack').innerHTML = names.map((k, i) =>
      `<span style="width:${(shares[i] * 100).toFixed(1)}%;background:${IDLE_COLORS[i % IDLE_COLORS.length]}" title="${esc(k)} ${(shares[i] * 100).toFixed(1)} %"></span>`).join('');
    document.getElementById('pg-idle-legend').innerHTML = names.map((k, i) => shares[i] > 0.005
      ? `<span><i style="background:${IDLE_COLORS[i % IDLE_COLORS.length]}"></i>${esc(k)} ${(shares[i] * 100).toFixed(0)} %</span>` : '').join('');
  }

  async function pollTelemetry() {
    clearTimeout(ui.tel.timer);
    if (document.hidden || !ui.status) return;
    try { updateTelemetry(await api('telemetry')); } catch { /* transient */ }
    ui.tel.timer = setTimeout(pollTelemetry, 2000);
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) clearTimeout(ui.tel.timer);
    else { ui.tel.prev = null; pollTelemetry(); }
  });

  async function load(quiet = false) {
    if (!quiet && ui.status) root.querySelector('.pg-foot')?.insertAdjacentHTML('afterbegin', '<span class="pg-spinner"></span> ');
    try {
      ui.status = await api('status');
      render();
      pollTelemetry();
    } catch (e) {
      root.innerHTML = `<div class="pg-card"><h3>Fehler</h3><p>${esc(e.message)}</p></div>`;
    }
  }

  load();
})();
