/**
 * js/pages/inventario-total.js  (reescrito con API real)
 */
import { api, badge, money, num, loadingHTML, renderTable, toast } from '../api.js';

let currentPage = 1;
let currentFilters = {};

export async function render(container) {
    const bodegasRes = await api.get('/bodegas').catch(() => ({ data: [] }));
    const bodegas = bodegasRes.data ?? [];

    container.innerHTML = `
      <div class="toolbar">
        <div class="form-group" style="margin:0;flex-direction:row;align-items:center;gap:8px;">
          <label class="form-label" style="margin:0;white-space:nowrap;">Bodega</label>
          <select class="form-select" id="f-bodega">
            <option value="">Todas</option>
            ${bodegas.map(b => `<option value="${b.codigo}">${b.codigo} — ${b.ciudad}</option>`).join('')}
          </select>
        </div>
        <div class="form-group" style="margin:0;flex-direction:row;align-items:center;gap:8px;">
          <label class="form-label" style="margin:0;">Tipo</label>
          <select class="form-select" id="f-tipo">
            <option value="">Todos</option>
            <option>MATERIALES</option><option>EQUIPOS</option><option>HERRAMIENTAS</option>
          </select>
        </div>
        <input class="form-input" id="f-q" placeholder="Buscar artículo…" style="width:200px;">
        <button class="btn btn-primary" id="btn-buscar">Buscar</button>
        <button class="btn btn-secondary" id="btn-limpiar">Limpiar</button>
        <div class="toolbar-right">
          <button class="btn btn-secondary" id="btn-exportar">↓ Exportar CSV</button>
        </div>
      </div>
      <div id="kpiRow" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;"></div>
      <div id="tablaWrap"></div>
      <div id="paginacion" style="display:flex;align-items:center;justify-content:space-between;
        padding:12px 0;font-size:12px;color:var(--text-muted);font-family:'IBM Plex Mono',monospace;"></div>`;

    container.querySelector('#btn-buscar').addEventListener('click', () => {
        currentPage = 1;
        currentFilters = {
            bodega: container.querySelector('#f-bodega').value,
            tipo:   container.querySelector('#f-tipo').value,
            q:      container.querySelector('#f-q').value.trim(),
        };
        loadTabla(container);
    });
    container.querySelector('#btn-limpiar').addEventListener('click', () => {
        ['#f-bodega','#f-tipo','#f-q'].forEach(s => (container.querySelector(s).value = ''));
        currentFilters = {}; currentPage = 1; loadTabla(container);
    });
    container.querySelector('#btn-exportar').addEventListener('click', () => exportCSV());

    loadKPIs(container);
    loadTabla(container);
}

async function loadKPIs(container) {
    const res = await api.get('/dashboard/kpis').catch(() => null);
    if (!res) return;
    const k = res.data;
    container.querySelector('#kpiRow').innerHTML = [
        ['Total Artículos', num(k.total_articulos), 'accent'],
        ['Valor en Stock',  money(k.valor_total),   'accent'],
        ['Agotados',        num(k.agotados),         'error'],
        ['Críticos ≤7d',    num(k.criticos),         'warn'],
    ].map(([l,v,c]) => `<div class="card" style="padding:14px;">
        <div class="card-label">${l}</div>
        <div class="card-value ${c}" style="font-size:22px;">${v}</div></div>`).join('');
}

async function loadTabla(container) {
    const wrap = container.querySelector('#tablaWrap');
    const pag  = container.querySelector('#paginacion');
    wrap.innerHTML = loadingHTML();
    const params = new URLSearchParams({
        page: currentPage, per_page: 50,
        ...Object.fromEntries(Object.entries(currentFilters).filter(([,v]) => v)),
    });
    try {
        const res = await api.get(`/inventarios/total?${params}`);
        const columns = [
            { key:'codigo',       label:'Código',    mono:true },
            { key:'descripcion',  label:'Descripción' },
            { key:'tipo',         label:'Tipo' },
            { key:'bodega_codigo',label:'Bodega',    mono:true },
            { key:'ciudad',       label:'Ciudad' },
            { key:'stock',        label:'Stock',     mono:true, render: v => num(v) },
            { key:'dias_stock',   label:'Días Stock',mono:true },
            { key:'costo_total',  label:'Valor',     mono:true, render: v => money(v) },
            { key:'nivel_alerta', label:'Estado',    render: v => badge(v) },
        ];
        renderTable(wrap, columns, res.data ?? []);
        const m = res.meta ?? {}; const lp = m.last_page ?? 1;
        pag.innerHTML = `<span>${m.total ?? 0} registros — Página ${currentPage} de ${lp}</span>
          <div style="display:flex;gap:6px;">
            <button class="btn btn-secondary" id="btn-prev" ${currentPage<=1?'disabled':''}>‹ Anterior</button>
            <button class="btn btn-secondary" id="btn-next" ${currentPage>=lp?'disabled':''}>Siguiente ›</button>
          </div>`;
        pag.querySelector('#btn-prev')?.addEventListener('click',()=>{ currentPage--; loadTabla(container); });
        pag.querySelector('#btn-next')?.addEventListener('click',()=>{ currentPage++; loadTabla(container); });
    } catch(err) {
        wrap.innerHTML = `<div class="section-block" style="border-color:var(--color-error);">
          <span style="color:var(--color-error);">${err.message}</span></div>`;
        toast(err.message,'error');
    }
}

async function exportCSV() {
    const params = new URLSearchParams({...currentFilters, per_page:1000, page:1});
    try {
        const res  = await api.get(`/inventarios/total?${params}`);
        const rows = res.data ?? [];
        if (!rows.length) { toast('No hay datos para exportar','warn'); return; }
        const cols = ['codigo','descripcion','tipo','bodega_codigo','ciudad','stock','dias_stock','costo_unitario','costo_total','nivel_alerta'];
        const csv  = [cols.join(','), ...rows.map(r => cols.map(c=>`"${r[c]??''}"`).join(','))].join('\n');
        const a    = Object.assign(document.createElement('a'),{
            href: URL.createObjectURL(new Blob([csv],{type:'text/csv'})),
            download:`inventario_${new Date().toISOString().slice(0,10)}.csv`});
        a.click(); toast('Exportación lista','ok');
    } catch(err) { toast(err.message,'error'); }
}
