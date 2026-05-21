/* ============================================================
   ARUPOS · Sistema de Control de Inventarios
   sidebar.js — Lógica del acordeón y navegación lateral
   ============================================================ */

(function () {
  'use strict';

  const sidebar = document.getElementById('sidebar');
  const main    = document.getElementById('main');
  const overlay = document.getElementById('overlay');
  const toggle  = document.getElementById('sidebarToggle');

  const isMobile = () => window.innerWidth <= 768;

  /* ── Toggle sidebar (colapsar / drawer móvil) ── */
  if (toggle) {
    toggle.addEventListener('click', () => {
      if (isMobile()) {
        sidebar.classList.toggle('mobile-open');
        overlay.classList.toggle('visible');
      } else {
        sidebar.classList.toggle('collapsed');
        main && main.classList.toggle('collapsed');
        // Guardar estado en localStorage
        const isCollapsed = sidebar.classList.contains('collapsed');
        localStorage.setItem('sidebar_collapsed', isCollapsed);
      }
    });
  }

  /* ── Cerrar con overlay ── */
  if (overlay) {
    overlay.addEventListener('click', closeMobileSidebar);
  }

  /* ── Cerrar en ESC ── */
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeMobileSidebar();
  });

  function closeMobileSidebar() {
    sidebar && sidebar.classList.remove('mobile-open');
    overlay && overlay.classList.remove('visible');
  }

  /* ── Restaurar estado colapsado ── */
  const wasCollapsed = localStorage.getItem('sidebar_collapsed') === 'true';
  if (wasCollapsed && !isMobile()) {
    sidebar && sidebar.classList.add('collapsed');
    main    && main.classList.add('collapsed');
  }

  /* ── Accordion ── */
  window.toggleSub = function (item, subId) {
    const sub  = document.getElementById(subId);
    if (!sub) return;
    const open = sub.classList.contains('open');

    // Cerrar todos
    document.querySelectorAll('.sub-menu.open')
      .forEach(m => m.classList.remove('open'));
    document.querySelectorAll('.nav-item.has-sub.open')
      .forEach(i => i.classList.remove('open'));

    // Abrir si estaba cerrado
    if (!open) {
      sub.classList.add('open');
      item.classList.add('open');
    }
  };

  /* ── Activar sub-ítem ── */
  window.setActive = function (el, section, page) {
    document.querySelectorAll('.sub-item.active')
      .forEach(i => i.classList.remove('active'));
    document.querySelectorAll('.nav-item.active')
      .forEach(i => i.classList.remove('active'));
    el.classList.add('active');
    updateBreadcrumb(section, page);
    if (isMobile()) closeMobileSidebar();
    loadPage(section, page);
  };

  /* ── Activar ítem de nivel superior ── */
  window.setActiveItem = function (el, section, page) {
    document.querySelectorAll('.nav-item.active')
      .forEach(i => i.classList.remove('active'));
    document.querySelectorAll('.sub-item.active')
      .forEach(i => i.classList.remove('active'));
    el.classList.add('active');
    updateBreadcrumb(section, page);
    if (isMobile()) closeMobileSidebar();
    loadPage(section, page);
  };

  /* ── Actualizar breadcrumb y títulos ── */
  function updateBreadcrumb(section, page) {
    const bcSection = document.getElementById('bcSection');
    const bcPage    = document.getElementById('bcPage');
    const pageTitle = document.getElementById('pageTitle');
    const pageSub   = document.getElementById('pageSub');

    if (bcSection) bcSection.textContent = section;
    if (bcPage)    bcPage.textContent    = page;
    if (pageTitle) pageTitle.textContent = page;
    if (pageSub)   pageSub.textContent   =
      `SISTEMAS DE CONTROL DE INVENTARIOS · ${section.toUpperCase()}`;
  }

  /* ── Router de páginas ──
     Extiende esta función para cargar el contenido de cada página.
     Puedes usar fetch() para cargar HTMLs externos o renderizar
     desde un objeto de templates. */
  function loadPage(section, page) {
    const contentArea = document.getElementById('pageContent');
    if (!contentArea) return;

    // Ejemplo: cada página tiene su propio módulo JS en /js/pages/
    const pageKey = `${section}__${page}`
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '_');

    // Dispara evento personalizado para que cada módulo reaccione
    document.dispatchEvent(new CustomEvent('arupos:navigate', {
      detail: { section, page, pageKey }
    }));
  }

  /* ── Abrir primer acordeón por defecto ── */
  window.addEventListener('DOMContentLoaded', () => {
    const first = document.querySelector('.nav-item.has-sub');
    if (first) {
      const onclickAttr = first.getAttribute('onclick') || '';
      const match = onclickAttr.match(/'([^']+)'/g);
      if (match && match[0]) {
        const subId = match[0].replace(/'/g, '');
        const sub   = document.getElementById(subId);
        if (sub) {
          sub.classList.add('open');
          first.classList.add('open');
        }
      }
    }
  });

})();
