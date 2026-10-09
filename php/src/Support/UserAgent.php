<?php
declare(strict_types=1);

namespace Tds\Ext\Analytics\Support;

/**
 * Coarse device class, browser family and OS family from a user agent — and
 * nothing finer. No versions, no device models: the combination of those is a
 * fingerprint, and "Chrome on Android, phone" answers every question the
 * dashboard asks. The user agent itself is never stored.
 */
final class UserAgent
{
    public const DEVICES = ['mobile', 'tablet', 'desktop'];

    private const BOT = '/bot|crawl|spider|slurp|scrape|headless|lighthouse|pagespeed|preview|monitor|'
        . 'uptime|curl|wget|python-requests|httpclient|go-http|java\/|axios|node-fetch|phantom|'
        . 'facebookexternalhit|embedly|whatsapp|telegrambot|discordbot|bingpreview|petalbot|gptbot|'
        . 'ccbot|claudebot|anthropic|perplexitybot|bytespider/i';

    public static function isBot(string $ua): bool
    {
        return trim($ua) === '' || preg_match(self::BOT, $ua) === 1;
    }

    /** @return array{device: string, browser: string, os: string} */
    public static function parse(string $ua): array
    {
        return [
            'device' => self::device($ua),
            'browser' => self::browser($ua),
            'os' => self::os($ua),
        ];
    }

    private static function device(string $ua): string
    {
        if (preg_match('/iPad|Tablet|PlayBook|Silk|Kindle|(Android(?!.*Mobile))/i', $ua) === 1) {
            return 'tablet';
        }
        if (preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|Opera Mini/i', $ua) === 1) {
            return 'mobile';
        }
        return 'desktop';
    }

    private static function browser(string $ua): string
    {
        // Order matters: Edge and Opera also say "Chrome", Chrome also says "Safari".
        return match (true) {
            preg_match('/Edg(e|A|iOS)?\//', $ua) === 1 => 'edge',
            preg_match('/OPR\/|Opera/', $ua) === 1 => 'opera',
            preg_match('/SamsungBrowser/', $ua) === 1 => 'samsung',
            preg_match('/Firefox\/|FxiOS/', $ua) === 1 => 'firefox',
            preg_match('/Chrome\/|CriOS/', $ua) === 1 => 'chrome',
            preg_match('/Safari\//', $ua) === 1 => 'safari',
            default => 'other',
        };
    }

    private static function os(string $ua): string
    {
        return match (true) {
            preg_match('/iPhone|iPad|iPod/', $ua) === 1 => 'ios',
            preg_match('/Android/', $ua) === 1 => 'android',
            preg_match('/Windows/', $ua) === 1 => 'windows',
            preg_match('/Mac OS X|Macintosh/', $ua) === 1 => 'macos',
            preg_match('/CrOS/', $ua) === 1 => 'chromeos',
            preg_match('/Linux/', $ua) === 1 => 'linux',
            default => 'other',
        };
    }
}
