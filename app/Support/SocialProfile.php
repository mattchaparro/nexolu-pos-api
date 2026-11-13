<?php

namespace App\Support;

/**
 * Enlace al perfil de una red social a partir de lo que escribió el usuario.
 *
 * En Ajustes el usuario escribe "@mitienda", "mitienda" o pega la URL
 * completa: los tres tienen que terminar en el mismo enlace.
 */
class SocialProfile
{
    public static function instagram(?string $handle): ?string
    {
        return self::url('https://www.instagram.com/', $handle);
    }

    public static function tiktok(?string $handle): ?string
    {
        return self::url('https://www.tiktok.com/@', $handle);
    }

    private static function url(string $base, ?string $handle): ?string
    {
        $handle = trim((string) $handle);
        if ($handle === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $handle)) {
            return $handle;
        }

        $handle = ltrim($handle, '@');

        return preg_match('/^[A-Za-z0-9._]{1,60}$/', $handle) ? $base.$handle : null;
    }
}
