<?php

namespace App\Support;

/**
 * URL of a file on the public disk, relative to the host the page was loaded from ("/storage/products/x.jpg").
 * Storage::url() builds it from APP_URL, so images break whenever the site is opened under another host or port
 * (php artisan serve on 127.0.0.1:8000 while APP_URL says professional-pos.test).
 */
class PublicUrl
{
    public static function of(?string $path): ?string
    {
        return $path ? '/storage/'.ltrim($path, '/') : null;
    }
}
