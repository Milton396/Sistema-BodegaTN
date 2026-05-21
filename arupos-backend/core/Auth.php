<?php
/**
 * core/Auth.php — Autenticación JWT sin dependencias externas
 * Implementación manual de JWT HS256
 */

class Auth
{
    private static string $secret;
    private static int    $expiry;

    private static function boot(): void
    {
        if (!isset(self::$secret)) {
            $cfg          = require __DIR__ . '/../config/app.php';
            self::$secret = $cfg['jwt_secret'];
            self::$expiry = $cfg['jwt_expiry'];
        }
    }

    /* ── Generar token ── */
    public static function generateToken(array $payload): string
    {
        self::boot();

        $header  = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload['iat'] = time();
        $payload['exp'] = time() + self::$expiry;
        $body    = self::b64url(json_encode($payload));
        $sig     = self::b64url(hash_hmac('sha256', "$header.$body", self::$secret, true));

        return "$header.$body.$sig";
    }

    /* ── Verificar y decodificar token ── */
    public static function verifyToken(string $token): ?array
    {
        self::boot();

        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $body, $sig] = $parts;

        $expected = self::b64url(hash_hmac('sha256', "$header.$body", self::$secret, true));
        if (!hash_equals($expected, $sig)) return null;

        $payload = json_decode(self::b64urlDecode($body), true);
        if (!$payload || $payload['exp'] < time()) return null;

        return $payload;
    }

    /* ── Middleware: exige token válido ── */
    public static function require(): array
    {
        $token = self::extractToken();
        if (!$token) Response::unauthorized('Token no proporcionado');

        $payload = self::verifyToken($token);
        if (!$payload) Response::unauthorized('Token inválido o expirado');

        return $payload;
    }

    /* ── Middleware: exige rol mínimo ── */
    public static function requireRole(array $allowedRoles): array
    {
        $payload = self::require();
        if (!in_array($payload['rol'], $allowedRoles, true)) {
            Response::forbidden("Se requiere rol: " . implode(' o ', $allowedRoles));
        }
        return $payload;
    }

    private static function extractToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $header, $m)) return $m[1];
        return $_GET['token'] ?? null;
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
