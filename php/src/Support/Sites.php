<?php
declare(strict_types=1);

namespace Tds\Ext\Analytics\Support;

/**
 * The public sites that may send measurements, and the host each one lives on.
 *
 * The collector accepts a batch only when the browser's `Origin` (or, failing
 * that, `Referer`) host maps to the SAME site the batch claims to be from. A
 * beacon is trivially forgeable, so this is not authentication — it keeps a
 * page on somebody else's domain from filling the statistics by accident.
 */
final class Sites
{
    /** @var array<string, string> site id → label */
    public const ALL = [
        'landing' => 'Landingpage',
        'blog' => 'Blog',
        'tools' => 'Tools',
        'auth' => 'Login',
        'shop' => 'Shop',
    ];

    /** @var array<string, string> production host → site id */
    private const HOSTS = [
        'tracht-digital.de' => 'landing',
        'www.tracht-digital.de' => 'landing',
        'blog.tracht-digital.de' => 'blog',
        'tools.tracht-digital.de' => 'tools',
        'auth.tracht-digital.de' => 'auth',
        'shop.tracht-digital.de' => 'shop',
    ];

    public static function isSite(string $site): bool
    {
        return isset(self::ALL[$site]);
    }

    /**
     * @param string $extra Panel setting, one `host=site` per line (local
     *                      stacks, staging hosts).
     */
    public static function forHost(string $host, string $extra = ''): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }
        foreach (preg_split('/\R/', $extra) ?: [] as $line) {
            $parts = array_map('trim', explode('=', $line, 2));
            if (count($parts) === 2 && strtolower($parts[0]) === $host && self::isSite($parts[1])) {
                return $parts[1];
            }
        }
        return self::HOSTS[$host] ?? null;
    }

    /** Host part of an `Origin` or `Referer` header value. */
    public static function hostOf(string $url): string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
    }

    /** Hosts that belong to this platform — a referrer from one is internal, not a source. */
    public static function isOwnHost(string $host): bool
    {
        $host = strtolower($host);
        return $host === 'tracht-digital.de' || str_ends_with($host, '.tracht-digital.de');
    }
}
