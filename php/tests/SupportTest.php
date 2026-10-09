<?php
declare(strict_types=1);

namespace Tds\Ext\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use Tds\Ext\Analytics\Support\Channel;
use Tds\Ext\Analytics\Support\Clock;
use Tds\Ext\Analytics\Support\GeoIp;
use Tds\Ext\Analytics\Support\Payload;
use Tds\Ext\Analytics\Support\Sites;
use Tds\Ext\Analytics\Support\UserAgent;

final class SupportTest extends TestCase
{
    private const VID = '0b7c2f9e-1d2a-4c3b-8e4f-5a6b7c8d9e0f';
    private const SID = '1c8d3a0f-2e3b-4d4c-9f5a-6b7c8d9e0f1a';

    /** @param list<array<string, mixed>> $events */
    private static function batch(array $events, array $extra = []): string
    {
        return json_encode(['v' => 1, 'site' => 'landing', 'lang' => 'de', 'vid' => self::VID, 'sid' => self::SID,
            'ret' => false, 'events' => $events] + $extra, JSON_THROW_ON_ERROR);
    }

    public function testAcceptsAWellFormedBatch(): void
    {
        $b = Payload::parse(self::batch([
            ['t' => 'pageview', 'p' => '/leistungen'],
            ['t' => 'click', 'p' => '/', 'k' => 'hero-primary'],
            ['t' => 'scroll', 'p' => '/', 'n' => 75],
            ['t' => 'form_field', 'p' => '/kontakt', 'k' => 'contact', 'f' => 'email'],
            ['t' => 'exit', 'p' => '/', 'n' => 4200],
        ], ['ref' => 'WWW.Google.de', 'utm' => ['source' => 'Newsletter', 'campaign' => 'herbst']]));

        self::assertNotNull($b);
        self::assertCount(5, $b['events']);
        self::assertSame('www.google.de', $b['ref']);
        self::assertSame(['source' => 'newsletter', 'campaign' => 'herbst'], $b['utm']);
    }

    public function testStripsQueryAndFragmentFromPaths(): void
    {
        $b = Payload::parse(self::batch([['t' => 'pageview', 'p' => '/reset?token=abc#x']]));
        self::assertSame('/reset', $b['events'][0]['p'] ?? null);
    }

    public function testRejectsWhatCouldCarryPersonalData(): void
    {
        // A value-shaped field name, an off-scale scroll depth, an absolute
        // URL as a path, an unknown type: each event is dropped on its own.
        self::assertNull(Payload::parse(self::batch([
            ['t' => 'form_field', 'p' => '/', 'k' => 'contact', 'f' => 'max@example.com'],
            ['t' => 'scroll', 'p' => '/', 'n' => 33],
            ['t' => 'pageview', 'p' => 'https://evil.example/'],
            ['t' => 'keystroke', 'p' => '/'],
        ])));
        $b = Payload::parse(self::batch([['t' => 'pageview', 'p' => '/']], ['utm' => ['campaign' => 'max@example.com']]));
        self::assertNull($b['utm'] ?? null);
    }

    public function testRejectsMalformedEnvelopes(): void
    {
        self::assertNull(Payload::parse(''));
        self::assertNull(Payload::parse('not json'));
        self::assertNull(Payload::parse(str_repeat('x', Payload::MAX_BYTES + 1)));
        self::assertNull(Payload::parse(json_encode(['v' => 1, 'site' => 'landing', 'vid' => 'nope', 'sid' => self::SID,
            'events' => [['t' => 'pageview', 'p' => '/']]], JSON_THROW_ON_ERROR)));
        self::assertNull(Payload::parse(json_encode(['v' => 1, 'site' => 'elsewhere', 'vid' => self::VID, 'sid' => self::SID,
            'events' => [['t' => 'pageview', 'p' => '/']]], JSON_THROW_ON_ERROR)));
    }

    public function testClassifiesChannels(): void
    {
        self::assertSame('direct', Channel::classify(null, null));
        self::assertSame('search', Channel::classify('www.google.de', null));
        self::assertSame('search', Channel::classify('duckduckgo.com', null));
        self::assertSame('social', Channel::classify('www.linkedin.com', null));
        self::assertSame('social', Channel::classify('t.co', null));
        self::assertSame('referral', Channel::classify('reddit-clone.example', null));
        self::assertSame('internal', Channel::classify('blog.tracht-digital.de', null));
        self::assertSame('campaign', Channel::classify('www.google.de', ['source' => 'newsletter', 'medium' => 'email']));
        self::assertSame('social', Channel::classify(null, ['source' => 'instagram.com']));
    }

    public function testParsesUserAgentsCoarsely(): void
    {
        $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
        $edge = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 Edg/129.0';
        $tablet = 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';

        self::assertSame(['device' => 'mobile', 'browser' => 'safari', 'os' => 'ios'], UserAgent::parse($iphone));
        self::assertSame(['device' => 'desktop', 'browser' => 'edge', 'os' => 'windows'], UserAgent::parse($edge));
        self::assertSame(['device' => 'tablet', 'browser' => 'chrome', 'os' => 'android'], UserAgent::parse($tablet));
        self::assertTrue(UserAgent::isBot('Mozilla/5.0 (compatible; Googlebot/2.1)'));
        self::assertTrue(UserAgent::isBot(''));
        self::assertFalse(UserAgent::isBot($edge));
    }

    public function testMapsHostsToSites(): void
    {
        self::assertSame('landing', Sites::forHost('tracht-digital.de'));
        self::assertSame('blog', Sites::forHost('BLOG.tracht-digital.de'));
        self::assertNull(Sites::forHost('evil.example'));
        self::assertSame('shop', Sites::forHost('localhost', "127.0.0.1=blog\nlocalhost=shop"));
        self::assertNull(Sites::forHost('localhost', 'localhost=unknown'));
        self::assertSame('tools.tracht-digital.de', Sites::hostOf('https://tools.tracht-digital.de/qr?x=1'));
    }

    public function testBerlinDaysAndValidation(): void
    {
        // 23:30 UTC on 9 Oct is already 10 Oct in Berlin (CEST).
        self::assertSame('2026-10-10', Clock::day(gmmktime(23, 30, 0, 10, 9, 2026)));
        self::assertSame('2026-10-09', Clock::parseDay('2026-10-09'));
        self::assertNull(Clock::parseDay('2026-02-30'));
        self::assertNull(Clock::parseDay("2026-10-09' OR 1=1"));
        self::assertSame('2026-09-30', Clock::addDays('2026-10-01', -1));
    }

    public function testGeoIpIsSilentWithoutADatabase(): void
    {
        $geo = new GeoIp(sys_get_temp_dir() . '/tds-analytics-test-' . bin2hex(random_bytes(4)));
        self::assertFalse($geo->available());
        self::assertNull($geo->country('8.8.8.8'));
        // A failing download never throws and leaves nothing half-written.
        self::assertFalse($geo->refresh(static fn (): false => false));
        self::assertFalse(is_file($geo->file()));
    }
}
