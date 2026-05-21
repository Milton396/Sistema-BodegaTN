/**
 * js/pages/dashboard.js
 * Dashboard (Toma Actual) — KPIs + gráfica mensual + alertas + tomas activas
 */

import { api, badge, money, num, loadingHTML, toast } from '../api.js';

export async function render(container) {
    container.innerHTML = loadingHTML();

    try {
        const [kpisRes, graficaRes, alertasRes, tomasRes] = await Promise.all([
            api.get('/dashboard/kpis'),
            api.get(`/dashboard/grafica-mensual?año=${new Date().getFullYear()}`),
            api.get('/dashboard/alertas?limite=8'),
            api.get('/dashboard/tomas-activas'),
        ]);

        const k = kpisRes.data;
        const g = graficaRes.data;
        const alertas = alertasRes.data;
        const tomas   = tomasRes.data;

        container.innerHTML = `
        <!-- KPIs -->
        <div class="cards">
          ${kpiCard('Total Artículos',   num(k.total_articulos),  'accent', iconCube())}
          ${kpiCard('Valor en Stock',    money(k.valor_total),    'accent', iconDollar())}
          ${kpiCard('Cuadrado',          num(k.cuadrado),         'ok',     iconCheck())}
          ${kpiCard('Faltante',          num(k.faltante),         'warn',   iconAlert())}
          ${kpiCard('Sobrante',          num(k.sobrante),         'cyan',   iconPlus())}
          ${kpiCard('Stock Crítico',     num(k.criticos),         'error',  iconFire())}
          ${kpiCard('Tomas del Mes',     num(k.tomas_mes),        'accent', iconClip())}
          ${kpiCard('Distrib. Pendientes', num(k.dist_pendientes),'warn',   iconTruck())}
        </div>

        <!-- Gráfica mensual + alertas -->
        <div style="display:grid;grid-template-columns:1fr 380px;gap:20px;margin-bottom:20px;">

          <!-- Gráfica -->
          <div class="section-block">
            <div class="section-title">Inventarios Disponibles — ${new Date().getFullYear()}</div>
            <div id="chartContainer" style="position:relative;height:220px;"></div>
            <div style="display:flex;gap:20px;margin-top:12px;font-size:11px;color:var(--text-muted);font-family:'IBM Plex Mono',monospace;">
              <span style="display:flex;align-items:center;gap:5px;"><i style="width:10px;height:10px;background:#10b981;border-radius:2px;display:inline-block;"></i>Cuadrado</span>
              <span style="display:flex;align-items:center;gap:5px;"><i style="width:10px;height:10px;background:#f59e0b;border-radius:2px;display:inline-block;"></i>Faltante</span>
              <span style="display:flex;align-items:center;gap:5px;"><i style="width:10px;height:10px;background:#06b6d4;border-radius:2px;display:inline-block;"></i>Sobrante</span>
            </div>
          </div>

          <!-- Alertas stock -->
          <div class="section-block" style="overflow:auto;max-height:320px;">
            <div class="section-title">Alertas de Stock</div>
            ${alertas.length ? alertas.map(a => `
              <div style="display:flex;justify-content:space-between;align-items:center;
                padding:8px 0;border-bottom:1px solid var(--border);gap:10px;">
                <div>
                  <div style="font-size:12px;color:var(--text-secondary);">${a.descripcion}</div>
                  <div style="font-size:11px;color:var(--text-muted);font-family:'IBM Plex Mono',monospace;">${a.bodega_codigo} · ${a.ciudad}</div>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                  ${badge(a.nivel)}
                  <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">${num(a.stock)} uds</div>
                </div>
              </div>`).join('') : '<div style="color:var(--text-muted);font-size:12px;">Sin alertas críticas</div>'}
          </div>
        </div>

        <!-- Tomas activas -->
        <div class="section-block">
          <div class="section-title">Tomas Activas</div>
          ${tomas.length ? `
          <div class="table-wrapper">
            <table>
              <thead><tr>
                <th>ID</th><th>Bodega</th><th>Ciudad</th><th>Estado</th>
                <th>Inicio</th><th>Progreso</th><th>Responsable</th>
              </tr></thead>
              <tbody>
                ${tomas.map(t => {
                    const pct = t.total > 0 ? Math.round((t.contados / t.total) * 100) : 0;
                    return `<tr>
                      <td class="mono">#${t.id}</td>
                      <td>${t.bodega_nombre}</td>
                      <td>${t.ciudad}</td>
                      <td>${badge(t.estado)}</td>
                      <td class="mono">${t.fecha_inicio}</td>
                      <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                          <div style="flex:1;background:var(--bg-deep);border-radius:3px;height:6px;">
                            <div style="width:${pct}%;background:var(--accent);border-radius:3px;height:100%;"></div>
                          </div>
                          <span style="font-size:11px;color:var(--text-muted);font-family:'IBM Plex Mono',monospace;white-space:nowrap;">${t.contados}/${t.total}</span>
                        </div>
                      </td>
                      <td>${t.responsable ?? '—'}</td>
                    </tr>`;
                }).join('')}
              </tbody>
            </table>
          </div>` : '<div style="color:var(--text-muted);font-size:12px;">No hay tomas activas en este momento</div>'}
        </div>`;

        // Dibujar gráfica de barras con canvas
        drawChart(g);

    } catch (err) {
        container.innerHTML = `<div class="section-block" style="border-color:var(--color-error);">
          <div style="color:var(--color-error);font-size:13px;">Error cargando dashboard: ${err.message}</div>
        </div>`;
        toast(err.message, 'error');
    }
}

/* ── Gráfica de barras SVG ── */
function drawChart(data) {
    const wrap = document.getElementById('chartContainer');
    if (!wrap) return;

    const W = wrap.clientWidth || 600, H = 220;
    const pad = { top: 16, right: 10, bottom: 32, left: 44 };
    const innerW = W - pad.left - pad.right;
    const innerH = H - pad.top  - pad.bottom;
    const barW   = Math.floor(innerW / data.length / 4);
    const maxVal = Math.max(...data.flatMap(d => [d.cuadrado, d.faltante, d.sobrante]), 1);

    const scaleY = v => innerH - Math.round((v / maxVal) * innerH);
    const x      = (i, offset) => pad.left + Math.round((i / data.length) * innerW) + offset;

    const bars = data.map((d, i) => `
      <rect x="${x(i, 4)}"          y="${pad.top + scaleY(d.cuadrado)}" width="${barW}" height="${innerH - scaleY(d.cuadrado)}" fill="#10b981" rx="2"/>
      <rect x="${x(i, 4+barW+2)}"   y="${pad.top + scaleY(d.faltante)}" width="${barW}" height="${innerH - scaleY(d.faltante)}" fill="#f59e0b" rx="2"/>
      <rect x="${x(i, 4+barW*2+4)}" y="${pad.top + scaleY(d.sobrante)}" width="${barW}" height="${innerH - scaleY(d.sobrante)}" fill="#06b6d4" rx="2"/>
      <text x="${x(i, barW)}" y="${H - 8}" text-anchor="middle"
            font-size="9" fill="#4a5568" font-family="IBM Plex Mono,monospace">${d.mes_nombre}</text>
    `).join('');

    // Líneas horizontales guía
    const guias = [0, 0.25, 0.5, 0.75, 1].map(p => {
        const yy = pad.top + Math.round(innerH * (1 - p));
        const val = Math.round(maxVal * p);
        return `
          <line x1="${pad.left}" x2="${W - pad.right}" y1="${yy}" y2="${yy}"
                stroke="#1e2230" stroke-width="1"/>
          <text x="${pad.left - 4}" y="${yy + 4}" text-anchor="end"
                font-size="9" fill="#4a5568" font-family="IBM Plex Mono,monospace">${val}</text>`;
    }).join('');

    wrap.innerHTML = `
      <svg viewBox="0 0 ${W} ${H}" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:100%;">
        ${guias}${bars}
      </svg>`;
}

/* ── KPI card helper ── */
const kpiCard = (label, value, cls, icon) => `
  <div class="card">
    <div class="card-icon">${icon}</div>
    <div class="card-label">${label}</div>
    <div class="card-value ${cls}">${value}</div>
  </div>`;

/* ── SVG icons ── */
const svg = (path) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">${path}</svg>`;
const iconCube   = () => svg(`<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>`);
const iconDollar = () => svg(`<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>`);
const iconCheck  = () => svg(`<polyline points="20 6 9 17 4 12"/>`);
const iconAlert  = () => svg(`<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>`);
const iconPlus   = () => svg(`<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>`);
const iconFire   = () => svg(`<path d="M12 2c0 0-4 4-4 8a4 4 0 0 0 8 0c0-4-4-8-4-8z"/><path d="M12 10c0 0-2 2-2 4a2 2 0 0 0 4 0c0-2-2-4-2-4z"/>`);
const iconClip   = () => svg(`<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/>`);
const iconTruck  = () => svg(`<rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>`);
