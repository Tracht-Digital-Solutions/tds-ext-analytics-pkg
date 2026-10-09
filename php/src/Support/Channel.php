<?php
declare(strict_types=1);

namespace Tds\Ext\Analytics\Support;

/**
 * Where a visit came from, in five buckets a person can act on.
 *
 * UTM wins over the referrer: a tagged link was placed on purpose, and the
 * referrer of a click from a newsletter or a messenger is usually empty or a
 * redirector. Only the referrer HOST ever reaches this class — the beacon
 * strips the path, which is where search terms and tokens live.
 */
final class Channel
{
    public const ALL = ['direct', 'search', 'social', 'campaign', 'referral', 'internal'];

    private const SEARCH = [
        'google.', 'bing.com', 'duckduckgo.com', 'ecosia.org', 'yahoo.', 'startpage.com',
        'qwant.com', 'search.brave.com', 'yandex.', 'baidu.com', 'perplexity.ai', 'chatgpt.com',
    ];

    private const SOCIAL = [
        'facebook.com', 'instagram.com', 'linkedin.com', 'lnkd.in', 'xing.com', 't.co', 'twitter.com',
        'x.com', 'youtube.com', 'tiktok.com', 'pinterest.', 'reddit.com', 'mastodon.', 'threads.net',
        'bsky.app', 'whatsapp.com', 'wa.me', 't.me', 'kleinanzeigen.de',
    ];

    /**
     * @param array{source?: string, medium?: string, campaign?: string}|null $utm
     */
    public static function classify(?string $refHost, ?array $utm): string
    {
        if ($utm !== null && ($utm['source'] ?? '') !== '') {
            $medium = $utm['medium'] ?? '';
            if (in_array($medium, ['cpc', 'ppc', 'paid', 'email', 'newsletter', 'display', 'affiliate'], true)
                || ($utm['campaign'] ?? '') !== '') {
                return 'campaign';
            }
            if (in_array($medium, ['social', 'social-media', 'sm'], true)) {
                return 'social';
            }
            return self::fromHost((string) $utm['source']) ?? 'campaign';
        }
        if ($refHost === null || $refHost === '') {
            return 'direct';
        }
        if (Sites::isOwnHost($refHost)) {
            return 'internal';
        }
        return self::fromHost($refHost) ?? 'referral';
    }

    private static function fromHost(string $host): ?string
    {
        $host = strtolower($host);
        foreach (self::SEARCH as $needle) {
            if (self::matches($host, $needle)) {
                return 'search';
            }
        }
        foreach (self::SOCIAL as $needle) {
            if (self::matches($host, $needle)) {
                return 'social';
            }
        }
        return null;
    }

    /** `google.` matches google.de and www.google.com; `t.co` matches t.co but not reddit.com. */
    private static function matches(string $host, string $needle): bool
    {
        if (str_ends_with($needle, '.')) {
            return str_starts_with($host, $needle) || str_contains($host, '.' . $needle);
        }
        return $host === $needle || str_ends_with($host, '.' . $needle);
    }
}
