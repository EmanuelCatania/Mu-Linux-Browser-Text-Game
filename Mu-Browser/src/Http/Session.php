<?php

declare(strict_types=1);

namespace Mu\Http;

/** Envoltorio mínimo de la sesión nativa de PHP. */
final class Session
{
    public const string NAME = 'mu_session';

    public function start(bool $secure): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(self::NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Cambia el id de sesión. Llamar siempre que cambie el nivel de privilegio (login, cambio de contraseña). */
    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** Mensaje de un solo uso para mostrar tras un redirect. $key es una clave de traducción. */
    public function flash(string $type, string $key): void
    {
        $_SESSION['_flash'] = ['type' => $type, 'key' => $key];
    }

    /** @return array{type: string, key: string}|null */
    public function pullFlash(): ?array
    {
        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);

        return $flash;
    }

    public function csrfToken(): string
    {
        return $_SESSION['_csrf'] ??= bin2hex(random_bytes(32));
    }
}
