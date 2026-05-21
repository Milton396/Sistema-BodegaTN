/**
 * js/pages/stock-modelo.js
 * Consulta por Modelo/Código — muestra stock en todas las bodegas + series
 */

import { api, badge, money, num, loadingHTML, toast } from '../api.js';

export async function render(container) {
    container.innerHTML = `
      <div class="section-block" style="max-width:600px;">
        <div class="section-title">Consulta por Modelo</div>
        <div style="display:flex;gap:10px;align-items:flex-end;">
          <div class="form-group" style="margin:0;flex:1;">
            <label class="form-label">Código de Artículo</label>
            <input class="form-input" id="inp-codigo" placeholder="Ej: 10-03-04-005" style="width:100%;">
          </div>
          <button class="btn btn-primary" id="btn-buscar">Consultar</button>
        </div>
      </div>
      <div id="resultado"></div>`;

    const buscar = async () => {
        const codigo = container.querySelector('#inp-codigo').value.trim();
        if (!codigo) { toast('Ingresa un código de artículo', 'warn'); return; }
        const wrap = container.querySelector('#resultado');
        wrap.innerHTML = loadingHTML();
        try {
            const res = await api.get(`/stock/modelo?codigo=${encodeURIComponent(codigo)}`);
            const { articulo, stock, series, totales } = res.data;

            wrap.innerHTML = `
              <!-- Info del artículo -->
              <div class="section-block">
                <div class="section-title">${articulo.descripcion}</div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:16px;">
                  ${infoItem('Código',        articulo.codigo)}
                  ${infoItem('Tipo',          articulo.tipo)}
                  ${infoItem('Unidad',        articulo.unidad_medida)}
                  ${infoItem('Con Serie',     articulo.tiene_serie ? 'SÍ' : 'NO')}
                  ${infoItem('Stock Total',   num(totales.stock_total))}
                  ${infoItem('Valor Total',   money(totales.valor_total))}
                  ${infoItem('# Bodegas',     totales.num_bodegas)}
                </div>

                <!-- Stock por bodega -->
                <div class="section-title" style="margin-top:8px;">Stock por Bodega</div>
                <div class="table-wrapper">
                  <table>
                    <thead><tr>
                      <th>Bodega</th><th>Ciudad</th><th>Stock</th>
                      <th>Reservado</th><th>Días</th><th>Valor</th><th>Ubicación</th>
                    </tr></thead>
                    <tbody>
                      ${stock.map(s => `<tr>
                        <td class="mono">${s.bodega_codigo}</td>
                        <td>${s.ciudad}</td>
                        <td class="mono">${num(s.stock)}</td>
                        <td class="mono">${num(s.reservado)}</td>
                        <td class="mono">${s.dias_stock ?? '—'}</td>
                        <td class="mono">${money(s.costo_total)}</td>
                        <td class="mono" style="font-size:11px;">${s.ubicacion ?? '—'}</td>
                      </tr>`).join('')}
                    </tbody>
                  </table>
                </div>

                <!-- Series si aplica -->
                ${series.length ? `
                <div class="section-title" style="margin-top:16px;">Números de Serie (${series.length})</div>
                <div class="table-wrapper">
                  <table>
                    <thead><tr><th>Serie</th><th>MAC</th><th>Bodega</th><th>Estado</th><th>Ingreso</th></tr></thead>
                    <tbody>
                      ${series.map(s => `<tr>
                        <td class="mono">${s.serie}</td>
                        <td class="mono">${s.mac ?? '—'}</td>
                        <td class="mono">${s.bodega_codigo}</td>
                        <td>${badge(s.estado)}</td>
                        <td class="mono">${s.fecha_ingreso ?? '—'}</td>
                      </tr>`).join('')}
                    </tbody>
                  </table>
                </div>` : ''}
              </div>`;
        } catch (err) {
            wrap.innerHTML = `<div class="section-block" style="border-color:var(--color-error);">
              <span style="color:var(--color-error);">${err.message}</span></div>`;
        }
    };

    container.querySelector('#btn-buscar').addEventListener('click', buscar);
    container.querySelector('#inp-codigo').addEventListener('keydown', e => {
        if (e.key === 'Enter') buscar();
    });
}

const infoItem = (label, val) => `
  <div style="background:var(--bg-deep);border-radius:var(--radius);padding:10px 14px;">
    <div style="font-size:10px;color:var(--text-muted);font-family:'IBM Plex Mono',monospace;
      text-transform:uppercase;letter-spacing:.08em;margin-bottom:3px;">${label}</div>
    <div style="font-size:14px;color:var(--text-primary);font-weight:500;">${val}</div>
  </div>`;
