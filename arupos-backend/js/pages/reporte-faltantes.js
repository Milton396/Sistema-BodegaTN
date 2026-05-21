/**
 * js/pages/reporte-faltantes.js
 * Reporte Faltantes / Sobrantes — filtros por bodega, mes y año
 */

import { api, badge, money, num, loadingHTML, renderTable, toast } from '../api.js';

export async function render(container) {
    const bodegasRes = await api.get('/bodegas').catch(() => ({ data: [] }));
    const bodegas    = bodegasRes.data ?? [];
    const hoy        = new Date();

    container.innerHTML = `
      <div class="toolbar">
        <div class="form-group" style="margin:0;flex-direction:row;align-items:center;gap:8px;">
          <label class="form-label" style="margin:0;">Bodega</label>
          <select class="form-select" id="f-bodega">
            <option value="">Todas</option>
            ${bodegas.map(b => `<option value="${b.codigo}">${b.codigo} — ${b.ciudad}</option>`).join('')}
          </select>
        </div>
        <div class="form-group" style="margin:0;flex-direction:row;align-items:center;gap:8px;">
          <label class="form-label" style="margin:0;">Año</label>
          <select class="form-select" id="f-anio" style="width:90px;">
            ${[hoy.getFullYear(), hoy.getFullYear()-1, hoy.getFullYear()-2]
              .map(y=>`<option value="${y}">${y}</option>`).join('')}
          </select>
        </div>
        <div class="form-group" style="margin:0;flex-direction:row;align-items:center;gap:8px;">
          <label class="form-label" style="margin:0;">Mes</label>
          <select class="form-select" id="f-mes" style="width:120px;">
            ${['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
               'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre']
              .map((m,i)=>`<option value="${i}" ${i===hoy.getMonth()+1?'selected':''}>${m||'Todos'}</option>`).join('')}
          </select>
        </div>
        <button class="btn btn-primary" id="btn-buscar">Generar</button>
        <div class="toolbar-right">
          <button class="btn btn-secondary" id="btn-csv">↓ CSV</button>
        </div>
      </div>

      <!-- Totalizadores -->
      <div id="totales" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;"></div>
      <div id="tablaWrap"></div>`;

    container.querySelector('#btn-buscar').addEventListener('click', () => loadReporte(container));
    container.querySelector('#btn-csv').addEventListener('click', () => exportCSV(container));
    loadReporte(container);
}

async function loadReporte(container) {
    const wrap = container.querySelector('#tablaWrap');
    wrap.innerHTML = loadingHTML();
    container.querySelector('#totales').innerHTML = '';

    const params = new URLSearchParams({
        bodega: container.querySelector('#f-bodega').value,
        año:    container.querySelector('#f-anio').value,
        mes:    container.querySelector('#f-mes').value,
        per_page: 500,
    });
    // Limpiar vacíos
    [...params.keys()].forEach(k => { if (!params.get(k)) params.delete(k); });

    try {
        const res  = await api.get(`/reportes/faltantes?${params}`);
        const rows = res.data ?? [];

        const faltantes = rows.filter(r => r.resultado === 'FALTANTE');
        const sobrantes = rows.filter(r => r.resultado === 'SOBRANTE');
        const cuadrado  = rows.filter(r => r.resultado === 'CUADRADO');

        container.querySelector('#totales').innerHTML = [
            ['Faltantes', faltantes.length, 'warn'],
            ['Sobrantes', sobrantes.length, 'cyan'],
            ['Cuadrado',  cuadrado.length,  'ok'],
        ].map(([l,v,c]) => `<div class="card" style="padding:14px;">
          <div class="card-label">${l}</div>
          <div class="card-value ${c}" style="font-size:24px;">${num(v)}</div>
        </div>`).join('');

        const columns = [
            { key:'articulo_codigo', label:'Código',      mono:true },
            { key:'descripcion',     label:'Descripción' },
            { key:'tipo',            label:'Tipo' },
            { key:'bodega_nombre',   label:'Bodega' },
            { key:'ciudad',          label:'Ciudad' },
            { key:'cantidad_sistema',label:'Stk. Sistema',mono:true, render:v=>num(v) },
            { key:'cantidad_contada',label:'Contado',     mono:true, render:v=>num(v??0) },
            { key:'diferencia',      label:'Diferencia',  mono:true,
              render:(v,r) => `<span style="color:${v<0?'var(--color-warn)':v>0?'var(--accent2)':'var(--color-ok)'}">${v>0?'+':''}${num(v)}</span>` },
            { key:'resultado',       label:'Resultado',   render:v=>badge(v) },
        ];

        renderTable(wrap, columns, rows, 'No se encontraron diferencias en el período seleccionado');
    } catch (err) {
        wrap.innerHTML = `<div class="section-block" style="border-color:var(--color-error);">
          <span style="color:var(--color-error);">${err.message}</span></div>`;
        toast(err.message, 'error');
    }
}

async function exportCSV(container) {
    const params = new URLSearchParams({
        bodega:   container.querySelector('#f-bodega').value,
        año:      container.querySelector('#f-anio').value,
        mes:      container.querySelector('#f-mes').value,
        per_page: 5000,
    });
    [...params.keys()].forEach(k => { if (!params.get(k)) params.delete(k); });
    try {
        const res  = await api.get(`/reportes/faltantes?${params}`);
        const rows = res.data ?? [];
        if (!rows.length) { toast('Sin datos para exportar', 'warn'); return; }
        const cols = ['articulo_codigo','descripcion','tipo','ciudad','bodega_nombre','cantidad_sistema','cantidad_contada','diferencia','resultado'];
        const csv  = [cols.join(','), ...rows.map(r => cols.map(c=>`"${r[c]??''}"`).join(','))].join('\n');
        const a    = Object.assign(document.createElement('a'),{
            href: URL.createObjectURL(new Blob([csv],{type:'text/csv'})),
            download:`faltantes_sobrantes_${new Date().toISOString().slice(0,10)}.csv`});
        a.click(); toast('Exportación lista', 'ok');
    } catch (err) { toast(err.message, 'error'); }
}
