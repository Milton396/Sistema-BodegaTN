/**
 * js/pages/toma-ciudad.js
 * Toma Física — iniciar, registrar conteos y cerrar toma para una ciudad
 */

import { api, badge, num, loadingHTML, toast } from '../api.js';

export async function render(container, { page: ciudad }) {
    ciudad = ciudad.toUpperCase();

    // Cargar bodegas de esa ciudad
    const bodegasRes = await api.get('/bodegas').catch(() => ({ data: [] }));
    const bodegas = (bodegasRes.data ?? []).filter(b =>
        b.ciudad.toUpperCase() === ciudad
    );

    container.innerHTML = `
      <div style="display:grid;grid-template-columns:320px 1fr;gap:20px;align-items:start;">

        <!-- Panel izquierdo: iniciar toma -->
        <div class="section-block">
          <div class="section-title">Iniciar Inventario</div>
          <div class="form-group">
            <label class="form-label">Bodega</label>
            <select class="form-select" id="sel-bodega">
              <option value="">Seleccionar…</option>
              ${bodegas.map(b=>`<option value="${b.codigo}">${b.codigo} — ${b.descripcion}</option>`).join('')}
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Tipo de Toma</label>
            <select class="form-select" id="sel-tipo">
              <option value="TOTAL">Total</option>
              <option value="CATEGORIA">Por Categoría</option>
              <option value="PARCIAL">Parcial</option>
            </select>
          </div>
          <div class="form-group" id="grp-cat" style="display:none;">
            <label class="form-label">Categoría</label>
            <select class="form-select" id="sel-cat">
              <option value="A">A — Alta rotación</option>
              <option value="B">B — Media</option>
              <option value="C">C — Baja</option>
              <option value="S">S — Con serie</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Observación</label>
            <input class="form-input" id="txt-obs" placeholder="Opcional…">
          </div>
          <button class="btn btn-primary" id="btn-iniciar" style="width:100%;">
            Iniciar Toma
          </button>
        </div>

        <!-- Panel derecho: tomas activas de esa ciudad -->
        <div>
          <div class="section-block" style="margin-bottom:16px;">
            <div class="section-title">Tomas Activas — ${ciudad}</div>
            <div id="tomas-activas">${loadingHTML()}</div>
          </div>
          <!-- Detalle de toma seleccionada -->
          <div id="detalle-toma"></div>
        </div>
      </div>`;

    // Mostrar/ocultar categoría
    container.querySelector('#sel-tipo').addEventListener('change', e => {
        container.querySelector('#grp-cat').style.display =
            e.target.value === 'CATEGORIA' ? '' : 'none';
    });

    // Botón iniciar
    container.querySelector('#btn-iniciar').addEventListener('click', async () => {
        const bodega = container.querySelector('#sel-bodega').value;
        if (!bodega) { toast('Selecciona una bodega', 'warn'); return; }

        const btn = container.querySelector('#btn-iniciar');
        btn.disabled = true; btn.textContent = 'Iniciando…';

        try {
            const tipo = container.querySelector('#sel-tipo').value;
            const body = {
                bodega_codigo:  bodega,
                tipo,
                categoria_abc:  tipo === 'CATEGORIA' ? container.querySelector('#sel-cat').value : null,
                observacion:    container.querySelector('#txt-obs').value || null,
            };
            const res = await api.post('/toma/iniciar', body);
            toast(`Toma #${res.data.id} iniciada con ${res.data.items_cargados} ítems`, 'ok');
            loadTomasActivas(container, ciudad);
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            btn.disabled = false; btn.textContent = 'Iniciar Toma';
        }
    });

    loadTomasActivas(container, ciudad);
}

async function loadTomasActivas(container, ciudad) {
    const wrap = container.querySelector('#tomas-activas');
    wrap.innerHTML = loadingHTML();

    try {
        const res   = await api.get('/toma');
        const tomas = (res.data ?? []).filter(t =>
            t.ciudad.toUpperCase() === ciudad &&
            ['ABIERTO','EN_PROCESO'].includes(t.estado)
        );

        if (!tomas.length) {
            wrap.innerHTML = `<div style="color:var(--text-muted);font-size:12px;">No hay tomas activas en ${ciudad}</div>`;
            return;
        }

        wrap.innerHTML = tomas.map(t => {
            const pct = t.total > 0 ? Math.round((t.contados / t.total) * 100) : 0;
            return `
              <div style="padding:12px 0;border-bottom:1px solid var(--border);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                  <div>
                    <span style="font-size:13px;color:var(--text-secondary);font-weight:500;">
                      Toma #${t.id} — ${t.bodega_nombre}
                    </span>
                    <span style="margin-left:8px;">${badge(t.estado)}</span>
                  </div>
                  <div style="display:flex;gap:6px;">
                    <button class="btn btn-secondary" style="padding:4px 10px;font-size:11px;"
                            onclick="abrirDetalle(${t.id})">Ver Detalle</button>
                    ${t.estado === 'EN_PROCESO' ? `
                    <button class="btn btn-primary" style="padding:4px 10px;font-size:11px;"
                            onclick="cerrarToma(${t.id})">Cerrar Toma</button>` : ''}
                  </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                  <div style="flex:1;background:var(--bg-deep);border-radius:3px;height:6px;">
                    <div style="width:${pct}%;background:var(--accent);border-radius:3px;height:100%;transition:width .3s;"></div>
                  </div>
                  <span style="font-size:11px;color:var(--text-muted);font-family:'IBM Plex Mono',monospace;white-space:nowrap;">
                    ${t.contados}/${t.total} ítems (${pct}%)
                  </span>
                </div>
              </div>`;
        }).join('');

        // Exponer funciones globales para los botones inline
        window.abrirDetalle = (id) => loadDetalle(container, id, ciudad);
        window.cerrarToma   = (id) => confirmarCierre(container, id, ciudad);

    } catch (err) {
        wrap.innerHTML = `<span style="color:var(--color-error);">${err.message}</span>`;
    }
}

async function loadDetalle(container, tomaId, ciudad) {
    const wrap = container.querySelector('#detalle-toma');
    wrap.innerHTML = `<div class="section-block">${loadingHTML()}</div>`;

    try {
        const [cabRes, detRes] = await Promise.all([
            api.get(`/toma/${tomaId}`),
            api.get(`/toma/${tomaId}/detalle?per_page=100&estado=PENDIENTE`),
        ]);

        const toma     = cabRes.data;
        const pendientes = detRes.data ?? [];

        wrap.innerHTML = `
          <div class="section-block">
            <div class="section-title">Detalle Toma #${tomaId} — Ítems Pendientes (${pendientes.length})</div>

            ${pendientes.length === 0 ? `
              <div style="color:var(--color-ok);font-size:13px;">✓ Todos los ítems han sido contados</div>` : `

            <!-- Formulario de conteo rápido -->
            <div style="display:flex;gap:10px;align-items:flex-end;margin-bottom:16px;flex-wrap:wrap;">
              <div class="form-group" style="margin:0;">
                <label class="form-label">Código Artículo</label>
                <select class="form-select" id="sel-art" style="width:300px;">
                  <option value="">Seleccionar ítem…</option>
                  ${pendientes.map(p=>`<option value="${p.articulo_codigo}">${p.articulo_codigo} — ${p.descripcion}</option>`).join('')}
                </select>
              </div>
              <div class="form-group" style="margin:0;">
                <label class="form-label">Cantidad Contada</label>
                <input class="form-input" id="inp-cant" type="number" min="0" style="width:120px;" placeholder="0">
              </div>
              <div class="form-group" style="margin:0;" id="grp-serie" style="display:none;">
                <label class="form-label">Nro. Serie</label>
                <input class="form-input" id="inp-serie" style="width:180px;" placeholder="Serie…">
              </div>
              <button class="btn btn-primary" id="btn-registrar">Registrar</button>
            </div>

            <!-- Tabla pendientes -->
            <div class="table-wrapper">
              <table>
                <thead><tr><th>Código</th><th>Descripción</th><th>Tipo</th>
                  <th>Stk. Sistema</th><th>Con Serie</th></tr></thead>
                <tbody>
                  ${pendientes.map(p=>`<tr>
                    <td class="mono">${p.articulo_codigo}</td>
                    <td>${p.descripcion}</td>
                    <td>${p.tipo}</td>
                    <td class="mono">${num(p.cantidad_sistema)}</td>
                    <td>${p.tiene_serie ? badge('SÍ','info') : '—'}</td>
                  </tr>`).join('')}
                </tbody>
              </table>
            </div>`}
          </div>`;

        // Mostrar campo serie si artículo lo requiere
        wrap.querySelector('#sel-art')?.addEventListener('change', e => {
            const art = pendientes.find(p => p.articulo_codigo === e.target.value);
            if (wrap.querySelector('#grp-serie')) {
                wrap.querySelector('#grp-serie').style.display =
                    art?.tiene_serie ? '' : 'none';
            }
        });

        // Registrar conteo
        wrap.querySelector('#btn-registrar')?.addEventListener('click', async () => {
            const artCod = wrap.querySelector('#sel-art').value;
            const cant   = parseFloat(wrap.querySelector('#inp-cant').value);
            const serie  = wrap.querySelector('#inp-serie')?.value || null;

            if (!artCod)       { toast('Selecciona un artículo', 'warn'); return; }
            if (isNaN(cant))   { toast('Ingresa una cantidad válida', 'warn'); return; }

            try {
                await api.post(`/toma/${tomaId}/registrar`, {
                    articulo_codigo: artCod,
                    cantidad_contada: cant,
                    serie,
                });
                toast('Conteo registrado', 'ok');
                loadDetalle(container, tomaId, ciudad);
                loadTomasActivas(container, ciudad);
            } catch (err) { toast(err.message, 'error'); }
        });

    } catch (err) {
        wrap.innerHTML = `<div class="section-block" style="border-color:var(--color-error);">
          <span style="color:var(--color-error);">${err.message}</span></div>`;
    }
}

async function confirmarCierre(container, tomaId, ciudad) {
    if (!confirm(`¿Confirmas el cierre de la Toma #${tomaId}?\nEsta acción no se puede deshacer.`)) return;
    try {
        const res = await api.post(`/toma/${tomaId}/cerrar`, {});
        const r   = res.data.resumen;
        toast(`Toma cerrada — Cuadrado: ${r.total_cuadrado} | Faltante: ${r.total_faltante} | Sobrante: ${r.total_sobrante}`, 'ok');
        container.querySelector('#detalle-toma').innerHTML = '';
        loadTomasActivas(container, ciudad);
    } catch (err) { toast(err.message, 'error'); }
}
