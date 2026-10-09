<?php
declare(strict_types=1);

namespace Tds\Ext\Analytics\Tests;

use PDO;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Tds\Ext\Analytics\AnalyticsModule;
use Tds\Ext\Analytics\Domain\Collector;
use Tds\Ext\Analytics\Domain\Maintenance;
use Tds\Ext\Analytics\Domain\Metrics;
use Tds\Ext\Analytics\Domain\Reports;
use Tds\Ext\Analytics\Support\Clock;
use Tds\Ext\Analytics\Support\GeoIp;

/**
 * The write path, the reports and the roll-up against a real MySQL/MariaDB,
 * with the schema built by THIS module's Phinx migrations — a test-local DDL
 * would only ever test itself.
 *
 * Set TDS_TEST_DB_DSN (+ _USER/_PASS) to run; skipped otherwise.
 */
final class AnalyticsDatabaseTest extends TestCase
{
    private const IP = '203.0.113.7';
    private const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private PDO $pdo;
    private TestContainer $c;

    protected function setUp(): void
    {
        $dsn = getenv('TDS_TEST_DB_DSN') ?: '';
        if ($dsn === '') {
            self::markTestSkipped('Set TDS_TEST_DB_DSN to run the analytics database tests.');
        }
        $this->pdo = new PDO($dsn, getenv('TDS_TEST_DB_USER') ?: null, getenv('TDS_TEST_DB_PASS') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        foreach (['analytics_event', 'analytics_session', 'analytics_daily', 'analytics_rate', 'analytics_phinxlog'] as $t) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$t}");
        }
        preg_match('/dbname=([^;]+)/', $dsn, $m);
        $config = new Config([
            'paths' => ['migrations' => __DIR__ . '/../db/migrations'],
            'environments' => [
                'default_migration_table' => 'analytics_phinxlog',
                'default_environment' => 'test',
                'test' => ['adapter' => 'mysql', 'connection' => $this->pdo, 'name' => $m[1] ?? 'test'],
            ],
        ]);
        (new Manager($config, new StringInput(' '), new NullOutput()))->migrate('test');

        $this->c = new TestContainer();
        $this->c->set(PDO::class, $this->pdo);
        $this->c->set(Metrics::class, fn () => new Metrics($this->pdo));
        $this->c->set(Collector::class, fn () => new Collector($this->pdo));
        $this->c->set(Reports::class, fn ($c) => new Reports($c->get(Metrics::class)));
        $this->c->set(Maintenance::class, fn ($c) => new Maintenance($this->pdo, $c->get(Metrics::class)));
        $this->c->set(GeoIp::class, new GeoIp(sys_get_temp_dir() . '/tds-analytics-none'));
    }

    private static function uuid(int $n): string
    {
        return sprintf('%08x-0000-4000-8000-%012x', $n, $n);
    }

    /** @param list<array<string, mixed>> $events */
    private function send(int $visitor, int $session, array $events, int $now, array $extra = [], string $site = 'landing'): int
    {
        $body = json_encode(['v' => 1, 'site' => $site, 'lang' => 'de', 'vid' => self::uuid($visitor),
            'sid' => self::uuid(1000 + $session), 'ret' => false, 'events' => $events] + $extra, JSON_THROW_ON_ERROR);
        $host = ['landing' => 'tracht-digital.de', 'blog' => 'blog.tracht-digital.de'][$site];
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/analytics/collect', ['REMOTE_ADDR' => self::IP])
            ->withBody((new StreamFactory())->createStream($body))
            ->withHeader('Origin', "https://{$host}")
            ->withHeader('User-Agent', self::UA);
        return AnalyticsModule::collect($this->c, $req, new Response(), $now)->getStatusCode();
    }

    private function rows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    public function testStoresAVisitWithoutTheAddressOrTheUserAgent(): void
    {
        $now = time();
        self::assertSame(204, $this->send(1, 1, [['t' => 'pageview', 'p' => '/']], $now, ['ref' => 'www.google.de']));
        self::assertSame(204, $this->send(1, 1, [
            ['t' => 'pageview', 'p' => '/leistungen'],
            ['t' => 'click', 'p' => '/leistungen', 'k' => 'pricing-cta'],
            ['t' => 'exit', 'p' => '/leistungen', 'n' => 5000],
        ], $now + 30));

        $s = $this->pdo->query('SELECT * FROM analytics_session')->fetch();
        self::assertSame('/', $s['entry_path']);
        self::assertSame('/leistungen', $s['exit_path']);
        self::assertSame(2, (int) $s['pageviews']);
        self::assertSame(5000, (int) $s['duration_ms']);
        self::assertSame('search', $s['channel']);
        self::assertSame('mobile', $s['device']);
        self::assertSame('ios', $s['os']);
        self::assertSame(3, $this->rows('analytics_event'));

        // Nothing anywhere in the visit's rows holds the address or the UA.
        $dump = json_encode([
            $this->pdo->query('SELECT * FROM analytics_session')->fetchAll(),
            $this->pdo->query('SELECT * FROM analytics_event')->fetchAll(),
            $this->pdo->query('SELECT * FROM analytics_rate')->fetchAll(),
        ], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::IP, $dump);
        self::assertStringNotContainsString('iPhone', $dump);
    }

    public function testAStrangersSessionIdIsNotMergedIntoTheirVisit(): void
    {
        $now = time();
        $this->send(1, 1, [['t' => 'pageview', 'p' => '/']], $now);
        $this->send(2, 1, [['t' => 'pageview', 'p' => '/fremd'], ['t' => 'pageview', 'p' => '/fremd2']], $now);

        $s = $this->pdo->query('SELECT * FROM analytics_session')->fetch();
        self::assertSame(1, (int) $s['pageviews']);
        self::assertSame(1, $this->rows('analytics_event'));
    }

    public function testReportsAnswerTheDashboardsQuestions(): void
    {
        $now = time();
        // Visit 1: bounced on the home page after starting the contact form.
        $this->send(1, 1, [
            ['t' => 'pageview', 'p' => '/'],
            ['t' => 'scroll', 'p' => '/', 'n' => 25],
            ['t' => 'section', 'p' => '/', 'k' => 'contact'],
            ['t' => 'form_start', 'p' => '/', 'k' => 'contact'],
            ['t' => 'form_field', 'p' => '/', 'k' => 'contact', 'f' => 'name'],
            ['t' => 'form_field', 'p' => '/', 'k' => 'contact', 'f' => 'message'],
        ], $now);
        // Visit 2: two pages, sent the form, left via an outbound link.
        $this->send(2, 2, [
            ['t' => 'pageview', 'p' => '/'],
            ['t' => 'click', 'p' => '/', 'k' => 'hero-primary'],
            ['t' => 'pageview', 'p' => '/kontakt'],
            ['t' => 'form_start', 'p' => '/kontakt', 'k' => 'contact'],
            ['t' => 'form_field', 'p' => '/kontakt', 'k' => 'contact', 'f' => 'email'],
            ['t' => 'form_submit', 'p' => '/kontakt', 'k' => 'contact'],
            ['t' => 'outbound', 'p' => '/kontakt', 'k' => 'www.linkedin.com'],
        ], $now, ['utm' => ['source' => 'newsletter', 'medium' => 'email', 'campaign' => 'herbst']]);
        // A blog visit, to prove the site filter.
        $this->send(3, 3, [['t' => 'pageview', 'p' => '/artikel']], $now, [], 'blog');

        $today = Clock::day($now);
        $r = new Reports(new Metrics($this->pdo));

        $all = $r->overview($today, $today, null)['totals'];
        self::assertSame(3, $all['visits']);
        $o = $r->overview($today, $today, 'landing')['totals'];
        self::assertSame(2, $o['visits']);
        self::assertSame(3, $o['pageviews']);
        self::assertSame(0.5, $o['bounceRate']);

        $pages = array_column($r->pages($today, $today, 'landing')['pages'], null, 'path');
        self::assertSame(2, $pages['/']['entries']);
        self::assertSame(1, $pages['/']['exits']);
        self::assertSame(0.5, $pages['/']['bounceRate']);
        self::assertSame(1.0, $pages['/kontakt']['exitRate']);

        $forms = $r->forms($today, $today, 'landing')['forms'][0];
        self::assertSame('contact', $forms['form']);
        self::assertSame(2, $forms['started']);
        self::assertSame(1, $forms['submitted']);
        self::assertSame([['field' => 'message', 'count' => 1]], $forms['abandonedAt']);

        $clicks = $r->clicks($today, $today, 'landing');
        self::assertSame([['key' => 'hero-primary', 'count' => 1]], $clicks['cta']);
        self::assertSame([['key' => 'www.linkedin.com', 'count' => 1]], $clicks['outbound']);

        $sources = $r->sources($today, $today, 'landing');
        self::assertContains(['key' => 'campaign', 'count' => 1], $sources['channel']);
        self::assertContains(['key' => 'herbst', 'count' => 1], $sources['campaign']);

        $scroll = $r->scroll($today, $today, 'landing')['pages'];
        $home = array_column($scroll, null, 'path')['/'];
        self::assertSame(0.5, $home['reached']['25']);
        self::assertSame('contact', $home['sections'][0]['id']);
    }

    public function testTheRollUpKeepsEveryReportContinuous(): void
    {
        $now = time();
        $old = $now - 100 * 86400;
        $this->send(1, 1, [
            ['t' => 'pageview', 'p' => '/'],
            ['t' => 'click', 'p' => '/', 'k' => 'hero-primary'],
            ['t' => 'form_start', 'p' => '/', 'k' => 'contact'],
            ['t' => 'form_field', 'p' => '/', 'k' => 'contact', 'f' => 'email'],
        ], $old, ['ref' => 'www.linkedin.com']);
        $this->send(2, 2, [['t' => 'pageview', 'p' => '/'], ['t' => 'pageview', 'p' => '/preise']], $old);
        $this->send(3, 3, [['t' => 'pageview', 'p' => '/heute']], $now);

        $day = Clock::day($old);
        $today = Clock::day($now);
        $r = new Reports(new Metrics($this->pdo));
        $before = [
            $r->overview($day, $today, null)['totals'],
            $r->pages($day, $today, null),
            $r->forms($day, $today, null),
            $r->clicks($day, $today, null),
            $r->sources($day, $today, null),
        ];

        $m = new Maintenance($this->pdo, new Metrics($this->pdo));
        self::assertTrue($m->claim($now));
        self::assertFalse($m->claim($now), 'a second caller in the same hour must not run it again');
        self::assertSame([$day], $m->run($now, 90));

        // The old day is now only totals — no visitor id, no session left.
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM analytics_session WHERE day = '{$day}'")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM analytics_event WHERE day = '{$day}'")->fetchColumn());
        self::assertGreaterThan(0, $this->rows('analytics_daily'));

        $after = [
            $r->overview($day, $today, null)['totals'],
            $r->pages($day, $today, null),
            $r->forms($day, $today, null),
            $r->clicks($day, $today, null),
            $r->sources($day, $today, null),
        ];
        self::assertEquals($before, $after);

        // Repeating the day (an interrupted run) changes nothing.
        $m->rollUp($day);
        self::assertEquals($after[0], $r->overview($day, $today, null)['totals']);
    }

    public function testForgetErasesOneVisitorsRawRows(): void
    {
        $now = time();
        $this->send(1, 1, [['t' => 'pageview', 'p' => '/']], $now);
        $this->send(2, 2, [['t' => 'pageview', 'p' => '/']], $now);

        $req = (new ServerRequestFactory())->createServerRequest('POST', '/analytics/forget', ['REMOTE_ADDR' => self::IP])
            ->withBody((new StreamFactory())->createStream(json_encode(['visitorId' => self::uuid(1)])));
        $res = AnalyticsModule::forget($this->c, $req, new Response(), $now);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame(['removed' => 1], json_decode((string) $res->getBody(), true));
        self::assertSame(1, $this->rows('analytics_session'));
        self::assertSame(1, $this->rows('analytics_event'));
    }

    public function testRateLimitPerSaltedAddress(): void
    {
        $c = new Collector($this->pdo);
        $now = time();
        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($c->withinRate(str_repeat('a', 64), $now, 3));
        }
        self::assertFalse($c->withinRate(str_repeat('a', 64), $now, 3));
        self::assertTrue($c->withinRate(str_repeat('b', 64), $now, 3));
    }
}
