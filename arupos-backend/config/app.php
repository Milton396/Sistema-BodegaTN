<?php
/**
 * config/app.php — Configuración general de la aplicación
 */

return [
    /* ── Entorno ── */
    'env'     => getenv('APP_ENV') ?: 'production',  // development | production
    'debug'   => getenv('APP_ENV') === 'development',

    /* ── JWT ── */
    'jwt_secret'  => getenv('JWT_SECRET') ?: 'CAMBIA_ESTE_SECRETO_EN_PRODUCCION_min32chars!!',
    'jwt_expiry'  => 28800,   // 8 horas en segundos

    /* ── CORS ── */
    'cors_origins' => [
        'http://localhost',
        'http://localhost:5500',   // Live Server de VS Code
        'http://127.0.0.1:5500',
        // Agrega aquí tu dominio de producción
    ],

    /* ── Paginación ── */
    'per_page_default' => 50,
    'per_page_max'     => 500,

    /* ── Zona horaria ── */
    'timezone' => 'America/Guayaquil',
];
