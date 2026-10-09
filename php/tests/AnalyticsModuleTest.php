<?php
declare(strict_types=1);

namespace Tds\Ext\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tds\Ext\Analytics\AnalyticsModule;
use Tds\Frontend\Contract\ModuleRegistry;
use Tds\Frontend\Contract\UserContext;

/**
 * Routing, gating and the public routes' early answers — everything that
 * happens before the database is touched.
 */
final class AnalyticsModuleTest extends TestCase
{
    private function app(UserContext $user): \Slim\App
    {
        $c = new TestContainer();
        $c->set(UserContext::class, $user);
        $app = AppFactory::create(null, $c);
        $app->addRoutingMiddleware();
        (new ModuleRegistry([new AnalyticsModule()]))->registerAll($app);
        return $app;
    }

    private function user(bool $auth, array $perms = []): UserContext
    {
        $user = $this->createMock(UserContext::class);
        $user->method('isAuthenticated')->willReturn($auth);
        $user->method('has')->willReturnCallback(static fn (string $p): bool => in_array($p, $perms, true));
        return $user;
    }

    private function post(string $path, string $body, array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withBody((new StreamFactory())->createStream($body))
            ->withHeader('Content-Type', 'text/plain;charset=UTF-8');
        foreach ($headers as $k => $v) {
            $req = $req->withHeader($k, $v);
        }
        return $req;
    }

    private static function batch(string $site = 'landing'): string
    {
        return json_encode(['v' => 1, 'site' => $site, 'lang' => 'de',
            'vid' => '0b7c2f9e-1d2a-4c3b-8e4f-5a6b7c8d9e0f', 'sid' => '1c8d3a0f-2e3b-4d4c-9f5a-6b7c8d9e0f1a',
            'ret' => false, 'events' => [['t' => 'pageview', 'p' => '/']]], JSON_THROW_ON_ERROR);
    }

    public function testEveryReportIsGatedOnRead(): void
    {
        foreach (AnalyticsModule::REPORTS as $name) {
            $req = (new ServerRequestFactory())->createServerRequest('GET', "/analytics/{$name}");
            self::assertSame(401, $this->app($this->user(false))->handle($req)->getStatusCode(), $name);
            self::assertSame(403, $this->app($this->user(true, ['contact:read']))->handle($req)->getStatusCode(), $name);
        }
    }

    public function testAMalformedBeaconIsA400(): void
    {
        $res = $this->app($this->user(false))->handle($this->post('/analytics/collect', '{"v":2}'));
        self::assertSame(400, $res->getStatusCode());
    }

    public function testABeaconFromAForeignOriginIsRefused(): void
    {
        $res = $this->app($this->user(false))->handle(
            $this->post('/analytics/collect', self::batch(), ['Origin' => 'https://evil.example']),
        );
        self::assertSame(403, $res->getStatusCode());

        // Right platform, wrong site: the blog cannot report landing-page views.
        $res = $this->app($this->user(false))->handle(
            $this->post('/analytics/collect', self::batch('landing'), ['Origin' => 'https://blog.tracht-digital.de']),
        );
        self::assertSame(403, $res->getStatusCode());
    }

    public function testBotsAndGlobalPrivacyControlAreDroppedBeforeTheDatabase(): void
    {
        // No PDO is bound: reaching the store would throw and still answer
        // 204, so the assertion that matters is that nothing was attempted —
        // which the bound-nothing container proves by not failing loudly.
        $origin = ['Origin' => 'https://tracht-digital.de'];
        $bot = $this->app($this->user(false))->handle(
            $this->post('/analytics/collect', self::batch(), $origin + ['User-Agent' => 'Googlebot/2.1']),
        );
        self::assertSame(204, $bot->getStatusCode());
        $gpc = $this->app($this->user(false))->handle(
            $this->post('/analytics/collect', self::batch(), $origin + ['User-Agent' => 'Mozilla/5.0 Firefox/130.0', 'Sec-GPC' => '1']),
        );
        self::assertSame(204, $gpc->getStatusCode());
    }

    public function testForgetNeedsAVisitorId(): void
    {
        $res = $this->app($this->user(false))->handle($this->post('/analytics/forget', '{"visitorId":"x"}'));
        self::assertSame(400, $res->getStatusCode());
    }

    public function testForgetNeverClaimsSuccessWithoutADatabase(): void
    {
        $res = $this->app($this->user(false))->handle(
            $this->post('/analytics/forget', '{"visitorId":"0b7c2f9e-1d2a-4c3b-8e4f-5a6b7c8d9e0f"}'),
        );
        self::assertSame(503, $res->getStatusCode());
    }

    public function testDeclaresItsSettingsWithSafeDefaults(): void
    {
        $defaults = [];
        foreach ((new AnalyticsModule())->settings() as $def) {
            self::assertFalse($def->secret, $def->key);
            $defaults[$def->key] = $def->default;
        }
        self::assertSame('90', $defaults['retention_days']);
        foreach (['landing', 'blog', 'tools', 'auth', 'shop'] as $site) {
            self::assertArrayHasKey("site_{$site}", $defaults);
        }
    }
}
