/* ============================================================
   ARUPOS · Sistema de Control de Inventarios
   router.js — Carga dinámica de páginas en #pageContent
   ============================================================ */

(function () {
  'use strict';

  /* ── Mapa de páginas → módulos JS ──
     Agrega aquí cada nueva página que construyas.
     El pageKey se genera automáticamente en sidebar.js como:
     `${section}__${page}`.toLowerCase().replace(/[^a-z0-9]+/g,'_')  */
  const PAGE_MODULES = {
    // ── INVENTARIOS ──
    'inventarios__inventario_total':          () => import('./pages/inventario-total.js'),
    'inventarios__por_categor_a_a_b_c_s_':   () => import('./pages/inventario-categoria.js'),
    'inventarios__por_bodegas':               () => import('./pages/inventario-bodegas.js'),
    'inventarios__historial_de_informes':     () => import('./pages/inventario-historial.js'),
    'inventarios__dashboard_toma_actual_':    () => import('./pages/dashboard.js'),
    'inventarios__resumen_por_mes_ciudad':    () => import('./pages/resumen-mes-ciudad.js'),

    // ── PARÁMETROS ──
    'par_metros__inventario_con_serie':       () => import('./pages/params-con-serie.js'),
    'par_metros__inventario_sin_serie':       () => import('./pages/params-sin-serie.js'),

    // ── CONSULTA DE STOCK ──
    'consulta_de_stock__consulta_global_de_existencias': () => import('./pages/stock-global.js'),
    'consulta_de_stock__consulta_por_modelo':            () => import('./pages/stock-modelo.js'),
    'consulta_de_stock__reserva_de_equipo':              () => import('./pages/stock-reserva.js'),

    // ── DISTRIBUCIÓN ──
    'distribuci_n__solicitar_distribuci_n':  () => import('./pages/dist-solicitar.js'),
    'distribuci_n__historial_distribuciones':() => import('./pages/dist-historial.js'),

    // ── REPORTERÍA ──
    'reporter_a__faltantes_sobrantes':       () => import('./pages/reporte-faltantes.js'),
    'reporter_a__reporte_por_ciudad':        () => import('./pages/reporte-ciudad.js'),
    'reporter_a__reporte_por_per_odo':       () => import('./pages/reporte-periodo.js'),
    'reporter_a__hist_rico_de_inventarios':  () => import('./pages/reporte-historico.js'),

    // ── TOMA FÍSICA ──
    'toma_f_sica__quito':       () => import('./pages/toma-ciudad.js'),
    'toma_f_sica__guayaquil':   () => import('./pages/toma-ciudad.js'),
    'toma_f_sica__ambato':      () => import('./pages/toma-ciudad.js'),
    'toma_f_sica__cuenca':      () => import('./pages/toma-ciudad.js'),
    'toma_f_sica__manta':       () => import('./pages/toma-ciudad.js'),
    'toma_f_sica__esmeraldas':  () => import('./pages/toma-ciudad.js'),
    'toma_f_sica__milagro':     () => import('./pages/toma-ciudad.js'),
    'toma_f_sica__ibarra':      () => import('./pages/toma-ciudad.js'),

    // ── CONFIGURACIÓN ──
    'configuraci_n__layout_din_mico':    () => import('./pages/config-layout.js'),
    'configuraci_n__gesti_n_de_bodegas': () => import('./pages/config-bodegas.js'),
    'configuraci_n__usuarios_y_permisos':() => import('./pages/config-usuarios.js'),
  };

  /* ── Escuchar evento de navegación ── */
  document.addEventListener('arupos:navigate', async ({ detail }) => {
    const { section, page, pageKey } = detail;
    const contentArea = document.getElementById('pageContent');
    if (!contentArea) return;

    // Mostrar loader
    contentArea.innerHTML = renderLoader();

    const loader = PAGE_MODULES[pageKey];
    if (!loader) {
      contentArea.innerHTML = renderEmpty(section, page);
      return;
    }

    try {
      const mod = await loader();
      // Cada módulo de página debe exportar una función render(container, context)
      if (mod && typeof mod.render === 'function') {
        mod.render(contentArea, { section, page });
      }
    } catch (err) {
      console.error('[Router] Error cargando página:', pageKey, err);
      contentArea.innerHTML = renderError(page);
    }
  });

  /* ── Templates internos ── */
  function renderLoader() {
    return `
      <div style="display:flex;align-items:center;gap:10px;color:var(--text-muted);padding:40px 0;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
          style="animation:spin .8s linear infinite">
          <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
        </svg>
        <span style="font-family:'IBM Plex Mono',monospace;font-size:12px;">Cargando módulo…</span>
      </div>
      <style>@keyframes spin{to{transform:rotate(360deg)}}</style>`;
  }

  function renderEmpty(section, page) {
    return `
      <div class="section-block" style="text-align:center;padding:48px;">
        <div style="color:var(--text-muted);font-family:'IBM Plex Mono',monospace;font-size:12px;margin-bottom:8px;">
          MÓDULO EN CONSTRUCCIÓN
        </div>
        <div style="color:var(--text-secondary);font-size:15px;font-weight:600;">
          ${page}
        </div>
        <div style="color:var(--text-muted);font-size:12px;margin-top:8px;">
          Sección: ${section}
        </div>
      </div>`;
  }

  function renderError(page) {
    return `
      <div class="section-block" style="border-color:var(--color-error);">
        <div style="color:var(--color-error);font-family:'IBM Plex Mono',monospace;font-size:11px;">
          ERROR AL CARGAR MÓDULO
        </div>
        <div style="color:var(--text-muted);font-size:13px;margin-top:4px;">
          No se pudo cargar: <strong>${page}</strong>. Revisa la consola.
        </div>
      </div>`;
  }

})();
