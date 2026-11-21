/**
 * Name of this app's CSRF cookie (set by App\Http\Middleware\ValidateCsrfToken
 * and announced in the page's `xsrf-cookie` meta tag). Not "XSRF-TOKEN": another
 * site on the same parent domain may set one of those for every subdomain.
 */
export function xsrfCookieName() {
    return document.querySelector('meta[name="xsrf-cookie"]')?.content || 'XSRF-TOKEN';
}
