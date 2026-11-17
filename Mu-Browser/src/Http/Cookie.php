<?php

declare(strict_types=1);

namespace Mu\Http;

use Psr\Http\Message\ResponseInterface;

final class Cookie
{
    /** Agrega una cookie a la respuesta. $maxAge <= 0 la borra. */
    public static function set(
        ResponseInterface $response,
        string $name,
        string $value,
        int $maxAge,
        bool $secure,
        bool $httpOnly = true,
    ): ResponseInterface {
        $parts = [
            $name . '=' . rawurlencode($maxAge > 0 ? $value : ''),
            'Path=/',
            'Max-Age=' . max(0, $maxAge),
            'SameSite=Lax',
        ];
        if ($maxAge <= 0) {
            $parts[] = 'Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        }
        if ($secure) {
            $parts[] = 'Secure';
        }
        if ($httpOnly) {
            $parts[] = 'HttpOnly';
        }

        return $response->withAddedHeader('Set-Cookie', implode('; ', $parts));
    }
}
