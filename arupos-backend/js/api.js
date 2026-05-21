/**
 * js/api.js — Cliente HTTP para el backend ARUPOS
 *
 * Uso en cualquier módulo de página:
 *   import { api } from '../api.js';
 *   const data = await api.get('/inventarios/total?bodega=BPRG');
 *   await api.post('/toma/iniciar', { bodega_codigo: 'BPRG', tipo: 'TOTAL' });
 */

// ── Configuración ──────────────────────────────────────────
const API_BASE = (window.ARUPOS_API_BASE ?? 'http://localhost/arupos-backend') + '/api';

// ── Token helpers ──────────────────────────────────────────
export const token = {
    get:    ()      => sessionStorage.getItem('arupos_token'),
    set:    (t)     => sessionStorage.setItem('arupos_token', t),
    clear:  ()      => sessionStorage.removeItem('arupos_token'),
    payload:()      => {
        const t = token.get();
        if (!t) return null;
        try {
            return JSON.parse(atob(t.split('.')[1].replace(/-/g,'+').replace(/_/g,'/')));
        } catch { return null; }
    },
};

// ── Fetch base ─────────────────────────────────────────────
async function request(method, endpoint, body = null, opts = {}) {
    const url     = API_BASE + endpoint;
    const headers = { 'Content-Type': 'application/json' };

    if (token.get()) headers['Authorization'] = `Bearer ${token.get()}`;

    const res = await fetch(url, {
        method,
        headers,
        body: body ? JSON.stringify(body) : null,
        ...opts,
    });

    const json = await res.json().catch(() => ({}));

    // Token expirado → redirigir al login
    if (res.status === 401) {
        token.clear();
        window.location.href = '/login.html';
        return;
    }

    if (!json.ok) {
        const msg = json.error?.message ?? `Error ${res.status}`;
        throw new ApiError(msg, res.status, json.error?.code);
    }

    return json;   // { ok, data, meta, error }
}

// ── Error personalizado ────────────────────────────────────
export class ApiError extends Error {
    constructor(message, status, code) {
        super(message);
        this.status = status;
        this.code   = code;
    }
}

// ── Métodos públicos ───────────────────────────────────────
export const api = {
    get:    (endpoint)        => request('GET',    endpoint),
    post:   (endpoint, body)  => request('POST',   endpoint, body),
    patch:  (endpoint, body)  => request('PATCH',  endpoint, body),
    put:    (endpoint, body)  => request('PUT',    endpoint, body),
    delete: (endpoint)        => request('DELETE', endpoint),

    /** Login — guarda el token automáticamente */
    async login(correo, password) {
        const res = await request('POST', '/auth/login', { correo, password });
        token.set(res.data.token);
        return res.data;
    },

    logout() {
        token.clear();
        window.location.href = '/login.html';
    },
};

// ── Notificaciones Toast ───────────────────────────────────
export function toast(message, type = 'info') {
    const colors = {
        ok:    'var(--color-ok)',
        error: 'var(--color-error)',
        warn:  'var(--color-warn)',
        info:  'var(--accent)',
    };

    const el = document.createElement('div');
    el.style.cssText = `
        position:fixed; bottom:24px; right:24px; z-index:9999;
        background:var(--bg-panel); border:1px solid ${colors[type] ?? colors.info};
        color:var(--text-primary); padding:12px 20px;
        border-radius:var(--radius); font-size:13px;
        box-shadow:0 4px 20px rgba(0,0,0,.4);
        animation:slideUp .2s ease; max-width:340px;
        font-family:'IBM Plex Sans',sans-serif;
    `;
    el.textContent = message;

    const style = document.createElement('style');
    style.textContent = `@keyframes slideUp{from{transform:translateY(10px);opacity:0}to{transform:translateY(0);opacity:1}}`;
    document.head.appendChild(style);
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 3500);
}

// ── Helpers de UI compartidos ──────────────────────────────

/** Renderiza una tabla genérica en un contenedor */
export function renderTable(container, columns, rows, emptyMsg = 'Sin resultados') {
    if (!rows.length) {
        container.innerHTML = `
          <div style="padding:40px;text-align:center;color:var(--text-muted);
            font-family:'IBM Plex Mono',monospace;font-size:12px;">${emptyMsg}</div>`;
        return;
    }

    container.innerHTML = `
      <div class="table-wrapper">
        <table>
          <thead><tr>${columns.map(c =>
              `<th>${c.label}</th>`).join('')}</tr></thead>
          <tbody>${rows.map(row =>
              `<tr>${columns.map(c =>
                  `<td class="${c.mono ? 'mono' : ''}">${
                      c.render ? c.render(row[c.key], row) : (row[c.key] ?? '—')
                  }</td>`).join('')}</tr>`
          ).join('')}</tbody>
        </table>
      </div>`;
}

/** Genera un badge HTML por estado */
export function badge(text, type) {
    const map = {
        'CUADRADO': 'ok',   'ACTIVA': 'ok',     'DISPONIBLE': 'ok',   'RECIBIDO': 'ok',
        'APROBADO': 'ok',   'EN_PROCESO': 'info','ABIERTO': 'info',    'EN_TRANSITO': 'info',
        'SOLICITADO': 'info','FALTANTE': 'warn', 'BAJO': 'warn',       'RESERVADO': 'warn',
        'SOBRANTE': 'warn', 'AGOTADO': 'error',  'CRITICO': 'error',   'RECHAZADO': 'error',
        'CERRADO': 'ok',    'DESPACHADO': 'info', 'MAL_ESTADO': 'error',
    };
    const cls = type ?? map[text] ?? 'info';
    return `<span class="badge ${cls}">${text}</span>`;
}

/** Formatea moneda USD */
export function money(val) {
    return new Intl.NumberFormat('es-EC', { style:'currency', currency:'USD' }).format(val ?? 0);
}

/** Formatea números con separadores */
export function num(val) {
    return new Intl.NumberFormat('es-EC').format(val ?? 0);
}

/** Loader estándar */
export function loadingHTML() {
    return `<div style="display:flex;align-items:center;gap:10px;color:var(--text-muted);padding:40px 0;">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
        style="animation:spin .8s linear infinite">
        <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
      </svg>
      <span style="font-family:'IBM Plex Mono',monospace;font-size:12px;">Cargando…</span>
      <style>@keyframes spin{to{transform:rotate(360deg)}}</style>
    </div>`;
}
