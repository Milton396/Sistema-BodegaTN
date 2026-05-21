# ARUPOS · Sistema de Control de Inventarios
## Guía de implementación en VS Code

---

## 📁 Estructura del proyecto

```
arupos-inventarios/
│
├── index.html                  ← Entrada principal de la app
│
├── css/
│   ├── variables.css           ← Design tokens (colores, tamaños, fuentes)
│   ├── layout.css              ← Topbar, sidebar, main, overlay, responsive
│   ├── sidebar.css             ← Menú acordeón, íconos, tooltips, badges
│   └── components.css          ← Tarjetas, tablas, botones, inputs, badges
│
├── js/
│   ├── sidebar.js              ← Lógica del acordeón + colapso + mobile
│   ├── router.js               ← Carga dinámica de páginas (ES module)
│   └── pages/
│       ├── inventario-total.js ← Ejemplo completo de módulo de página
│       ├── inventario-categoria.js   ← (por desarrollar)
│       ├── inventario-bodegas.js     ← (por desarrollar)
│       ├── dashboard.js              ← (por desarrollar)
│       ├── stock-global.js           ← (por desarrollar)
│       ├── stock-modelo.js           ← (por desarrollar)
│       ├── stock-reserva.js          ← (por desarrollar)
│       ├── reporte-faltantes.js      ← (por desarrollar)
│       ├── toma-ciudad.js            ← (por desarrollar — reutilizable)
│       └── ... (ver router.js para lista completa)
│
└── README.md                   ← Este archivo
```

---

## ⚙️ Configuración en VS Code

### 1. Abrir el proyecto
```bash
# Clona o copia la carpeta, luego:
code arupos-inventarios
```

### 2. Extensiones recomendadas
Instala estas extensiones en VS Code para una mejor experiencia:

| Extensión | ID | Para qué sirve |
|---|---|---|
| **Live Server** | ritwickdey.LiveServer | Servidor local con hot reload |
| **Prettier** | esbenp.prettier-vscode | Formateo automático de código |
| **IntelliSense for CSS** | Zignd.html-css-class-completion | Autocompletado de clases CSS |
| **ES6 Snippets** | xabikos.JavaScriptSnippets | Snippets de JS moderno |
| **GitLens** | eamodio.gitlens | Control de versiones visual |

### 3. Correr localmente con Live Server
1. Abre `index.html`
2. Click derecho → **"Open with Live Server"**
3. Se abre en `http://127.0.0.1:5500`

> ⚠️ El `router.js` usa ES modules (`import()`).
> **Funciona SOLO con un servidor local** (Live Server, Vite, etc.).
> NO funciona abriendo `index.html` directamente en el navegador (`file://`).

---

## 🔌 Cómo activar el Router dinámico

En `index.html`, al final del `<body>`, cambia:

```html
<!-- ANTES (sidebar básico sin router) -->
<script src="js/sidebar.js"></script>

<!-- DESPUÉS (con router de páginas dinámico) -->
<script src="js/sidebar.js"></script>
<script type="module" src="js/router.js"></script>
```

---

## 🧩 Cómo crear una nueva página

Cada página es un módulo JS que exporta una función `render`.

### Ejemplo: `js/pages/stock-global.js`

```javascript
export function render(container, { section, page }) {
  container.innerHTML = `
    <div class="section-block">
      <div class="section-title">Consulta Global de Existencias</div>
      <!-- tu HTML aquí -->
    </div>`;
}
```

### Luego regístrala en `router.js`:

```javascript
const PAGE_MODULES = {
  // ... existentes ...
  'consulta_de_stock__consulta_global_de_existencias':
    () => import('./pages/stock-global.js'),
};
```

El `pageKey` se genera automáticamente desde `section + page`:
- Sección: `"Consulta de Stock"` + Página: `"Consulta Global de Existencias"`
- Key: `consulta_de_stock__consulta_global_de_existencias`

---

## 🎨 Cómo usar el design system

Todas las variables de diseño están en `css/variables.css`:

```css
/* Colores principales */
var(--accent)          /* Azul activo: #3b82f6 */
var(--accent2)         /* Cyan secundario: #06b6d4 */

/* Fondos */
var(--bg-deep)         /* Fondo más oscuro */
var(--bg-panel)        /* Paneles y sidebar */
var(--bg-hover)        /* Hover de ítems */

/* Texto */
var(--text-primary)    /* Texto principal */
var(--text-secondary)  /* Texto secundario */
var(--text-muted)      /* Texto desactivado */

/* Estados */
var(--color-ok)        /* Verde: #10b981 */
var(--color-warn)      /* Amarillo: #f59e0b */
var(--color-error)     /* Rojo: #ef4444 */
```

### Clases de componente rápidas:

```html
<!-- Tarjeta KPI -->
<div class="card">
  <div class="card-label">ETIQUETA</div>
  <div class="card-value accent">1,234</div>
</div>

<!-- Badge de estado -->
<span class="badge ok">Cuadrado</span>
<span class="badge warn">Faltante</span>
<span class="badge error">Agotado</span>

<!-- Botones -->
<button class="btn btn-primary">Guardar</button>
<button class="btn btn-secondary">Cancelar</button>

<!-- Input -->
<div class="form-group">
  <label class="form-label">Ciudad</label>
  <select class="form-select">...</select>
</div>
```

---

## 🚀 Próximos módulos a desarrollar

Según el mapa del sistema (archivo PDF), estos son los módulos prioritarios:

| Módulo | Archivo | Prioridad |
|---|---|---|
| Dashboard (Toma Actual) | `pages/dashboard.js` | Alta |
| Toma física por ciudad | `pages/toma-ciudad.js` | Alta |
| Consulta por Modelo | `pages/stock-modelo.js` | Alta |
| Faltantes / Sobrantes | `pages/reporte-faltantes.js` | Media |
| Resumen Mes/Ciudad | `pages/resumen-mes-ciudad.js` | Media |
| Historial de Informes | `pages/inventario-historial.js` | Media |
| Usuarios y Permisos | `pages/config-usuarios.js` | Baja |

---

## 📡 Integración con backend (PHP)

Si tu backend es PHP, cada módulo puede hacer `fetch` a tus endpoints:

```javascript
// En cualquier pages/mi-pagina.js
export async function render(container, context) {
  const res  = await fetch('/api/inventario/total.php');
  const data = await res.json();

  container.innerHTML = `
    <div class="cards">
      ${data.kpis.map(k => `
        <div class="card">
          <div class="card-label">${k.label}</div>
          <div class="card-value accent">${k.valor}</div>
        </div>`).join('')}
    </div>`;
}
```

Tu PHP retorna JSON:
```php
<?php
header('Content-Type: application/json');
echo json_encode([
  'kpis' => [
    ['label' => 'Items en Stock', 'valor' => '14,830'],
    // ...
  ]
]);
```
