<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as Middleware;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Laravel's CSRF check, with the token cookie named after this app instead of
 * the shared "XSRF-TOKEN".
 *
 * Another site on the same parent domain (e.g. bakhtech.com.ng with
 * SESSION_DOMAIN=.bakhtech.com.ng) can plant its own "XSRF-TOKEN" for every
 * subdomain. Browsers then send two cookies with that name, JavaScript picks
 * the wrong one, and every save, and even logging out, fails with "Your session
 * expired". A name of our own can't collide. The front end reads the name from
 * the `xsrf-cookie` meta tag (resources/views/app.blade.php).
 */
class ValidateCsrfToken extends Middleware
{
    /** e.g. "product-catalog-xsrf", from the session cookie name (unique per app). */
    public static function cookieName(): string
    {
        $base = Str::of((string) config('session.cookie'))->slug()->replaceLast('-session', '');

        return ($base->isEmpty() ? 'app' : (string) $base).'-xsrf';
    }

    protected function newCookie($request, $config)
    {
        $cookie = parent::newCookie($request, $config);

        return new Cookie(
            self::cookieName(),
            $cookie->getValue(),
            $cookie->getExpiresTime(),
            $cookie->getPath(),
            $cookie->getDomain(),
            $cookie->isSecure(),
            false,
            false,
            $cookie->getSameSite(),
        );
    }

    public static function serialized()
    {
        return EncryptCookies::serialized(self::cookieName());
    }
}
