<?php
declare(strict_types=1);

namespace Tds\Ext\Analytics;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Tds\Ext\Analytics\Domain\Collector;
use Tds\Ext\Analytics\Domain\Maintenance;
use Tds\Ext\Analytics\Domain\Metrics;
use Tds\Ext\Analytics\Domain\Reports;
use Tds\Ext\Analytics\Support\Channel;
use Tds\Ext\Analytics\Support\ClientIp;
use Tds\Ext\Analytics\Support\Clock;
use Tds\Ext\Analytics\Support\GeoIp;
use Tds\Ext\Analytics\Support\Payload;
use Tds\Ext\Analytics\Support\Sites;
use Tds\Ext\Analytics\Support\UserAgent;
use Tds\Frontend\Contract\AbstractModule;
use Tds\Frontend\Contract\ApiDocSource;
use Tds\Frontend\Contract\ModuleHttp;
use Tds\Frontend\Contract\PermissionDef;
use Tds\Frontend\Contract\SettingDef;
use Tds\Frontend\Contract\SettingsStore;
use Tds\Frontend\Contract\SiteKeyProtected;
use Tds\Frontend\Contract\UserContext;

/**
 * Besucher-Statistik: the public sites' own, consent-gated audience measurement.
 *
 * Two public routes take the beacon (`/analytics/collect`) and the visitor's
 * erasure request (`/analytics/forget`); the rest is the dashboard, gated on
 * `analytics:read`. Measurement only ever starts in the browser after the
 * visitor agreed to the `analytics` consent category — see
 * `tds-shared/analytics`. This module never sees an un-consented request, and
 * stores neither an IP address nor a user agent from the ones it does see.
 *
 * Public routes never fail loudly: a beacon is fire-and-forget, so policy drops
 * (bot, Global Privacy Control, disabled site, rate limit, a database that is
 * not there) all answer 204. Only a malformed body (400) or a foreign origin
 * (403) answers otherwise, which is what someone debugging a site needs.
 */
final class AnalyticsModule extends AbstractModule implements ApiDocSource, SiteKeyProtected
{
    use ModuleHttp;

    public const NS = 'analytics';

    /** Requests per minute per IP, both public routes together. */
    private const RATE_PER_MINUTE = 120;

    public const REPORTS = ['overview', 'pages', 'scroll', 'sources', 'clicks', 'forms', 'summary'];

    /** Longest window a report covers, in days. */
    private const MAX_SPAN = 400;

    public function id(): string
    {
        return 'analytics';
    }

    /** @return PermissionDef[] */
    public function permissions(): array
    {
        return [new PermissionDef('analytics:read', 'Besucher-Statistik ansehen', 'analytics')];
    }

    /** @return string[] */
    public function migrations(): array
    {
        return [__DIR__ . '/../db/migrations'];
    }

    /** @return SettingDef[] */
    public function settings(): array
    {
        $defs = [];
        foreach (Sites::ALL as $site => $label) {
            $defs[] = new SettingDef("site_{$site}", "Messung aktiv: {$label}", false, self::NS, '1');
        }
        $defs[] = new SettingDef('retention_days', 'Rohdaten aufbewahren (Tage)', false, self::NS, '90');
        $defs[] = new SettingDef('excluded_paths', 'Ausgeschlossene Pfade (je Zeile ein Präfix)', false, self::NS, '');
        $defs[] = new SettingDef('extra_hosts', 'Zusätzliche Hosts (host=site je Zeile)', false, self::NS, '');
        $defs[] = new SettingDef('geoip_enabled', 'Land aus IP ermitteln (lokale DB-IP-Datenbank)', false, self::NS, '1');
        return $defs;
    }

    public function register(App $app): void
    {
        $c = $app->getContainer();

        // Bound unconditionally: a `has()` guard is always true under PHP-DI
        // autowiring and silently skipped the binding on every other module.
        $c?->set(Metrics::class, static fn ($c): Metrics => new Metrics($c->get(PDO::class)));
        $c?->set(Collector::class, static fn ($c): Collector => new Collector($c->get(PDO::class)));
        $c?->set(Reports::class, static fn ($c): Reports => new Reports($c->get(Metrics::class)));
        $c?->set(Maintenance::class, static fn ($c): Maintenance => new Maintenance($c->get(PDO::class), $c->get(Metrics::class)));
        $c?->set(GeoIp::class, static fn (): GeoIp => new GeoIp(GeoIp::defaultDir()));

        $app->post('/analytics/collect', function (Request $req, Response $res) use ($c): Response {
            return self::collect($c, $req, $res, time());
        });

        $app->post('/analytics/forget', function (Request $req, Response $res) use ($c): Response {
            return self::forget($c, $req, $res, time());
        });

        // Read counts per path for a public site's own "most read" lists.
        // A server-side read by the site (site key), never a browser call.
        $app->get('/content/analytics/reads', function (Request $req, Response $res) use ($c): Response {
            return self::reads($c, $req, $res, time());
        });

        foreach (self::REPORTS as $name) {
            $app->get("/analytics/{$name}", function (Request $req, Response $res) use ($c, $name): Response {
                return self::report($c, $name, $req, $res);
            });
        }
    }

    /**
     * Only the site's server-side read. The beacon and the erasure route are
     * browser calls and must never sit behind a site key.
     *
     * @return string[]
     */
    public function siteKeyRoutes(): array
    {
        return ['/content/analytics'];
    }

    /** @return list<array<string, mixed>> */
    public function apiDocs(): array
    {
        return require __DIR__ . '/../docs/api.php';
    }

    /* ------------------------------------------------------------------ */

    public static function collect(?ContainerInterface $c, Request $req, Response $res, int $now): Response
    {
        $batch = Payload::parse(self::body($req));
        if ($batch === null) {
            return self::json($res, ['error' => 'invalid payload'], 400);
        }
        $host = Sites::hostOf($req->getHeaderLine('Origin')) ?: Sites::hostOf($req->getHeaderLine('Referer'));
        if (Sites::forHost($host, self::setting($c, 'extra_hosts', '')) !== $batch['site']) {
            return self::json($res, ['error' => 'origin not allowed'], 403);
        }
        if (!self::flag($c, "site_{$batch['site']}", '1')
            || $req->getHeaderLine('Sec-GPC') === '1'
            || UserAgent::isBot($req->getHeaderLine('User-Agent'))) {
            return $res->withStatus(204);
        }

        $excluded = array_filter(array_map('trim', preg_split('/\R/', self::setting($c, 'excluded_paths', '')) ?: []));
        if ($excluded !== []) {
            $batch['events'] = array_values(array_filter(
                $batch['events'],
                static function (array $e) use ($excluded): bool {
                    foreach ($excluded as $prefix) {
                        if (str_starts_with($e['p'], $prefix)) {
                            return false;
                        }
                    }
                    return true;
                },
            ));
            if ($batch['events'] === []) {
                return $res->withStatus(204);
            }
        }

        try {
            /** @var Collector $collector */
            $collector = $c->get(Collector::class);
            $ip = ClientIp::of($req);
            if ($ip !== null && !$collector->withinRate(ClientIp::hash($ip, self::salt()), $now, self::RATE_PER_MINUTE)) {
                return $res->withStatus(204);
            }

            $owner = $collector->sessionOwner($batch['sid']);
            if ($owner !== null && ($owner['visitor_id'] !== $batch['vid'] || $owner['site'] !== $batch['site'])) {
                return $res->withStatus(204);
            }
            $origin = ['channel' => 'direct', 'country' => null, 'device' => 'desktop', 'browser' => 'other', 'os' => 'other'];
            if ($owner === null) {
                // Only a batch that opens a visit is looked at this closely;
                // the address and the user agent are discarded right here.
                $origin = UserAgent::parse($req->getHeaderLine('User-Agent')) + [
                    'channel' => Channel::classify($batch['ref'], $batch['utm']),
                    'country' => self::flag($c, 'geoip_enabled', '1') ? $c->get(GeoIp::class)->country($ip) : null,
                ];
            }
            $collector->store($batch, $origin, $now);

            if (random_int(1, 100) === 1) {
                self::maintain($c, $now);
            }
        } catch (\Throwable) {
            // A missing table or an unreachable database must not turn a page
            // view into a console error on a public site.
        }
        return $res->withStatus(204);
    }

    public static function forget(?ContainerInterface $c, Request $req, Response $res, int $now): Response
    {
        try {
            $d = json_decode(self::body($req), true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $d = null;
        }
        $vid = is_array($d) && is_string($d['visitorId'] ?? null) ? strtolower($d['visitorId']) : '';
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $vid) !== 1) {
            return self::json($res, ['error' => 'visitorId required'], 400);
        }
        try {
            /** @var Collector $collector */
            $collector = $c->get(Collector::class);
            $ip = ClientIp::of($req);
            if ($ip !== null && !$collector->withinRate(ClientIp::hash($ip, self::salt()), $now, self::RATE_PER_MINUTE)) {
                return self::json($res, ['error' => 'rate limited'], 429);
            }
            $removed = $collector->forget($vid);
        } catch (\Throwable) {
            // Unlike a beacon, the visitor waits for this answer and must not
            // be told their data is gone when it is not.
            return self::json($res, ['error' => 'unavailable'], 503);
        }
        return self::json($res, ['removed' => $removed]);
    }

    /**
     * Page views per path — day totals only, nothing about who read what.
     * Answers an empty list on any failure: a "most read" tab degrades to
     * newest-first, it never breaks a page.
     */
    public static function reads(?ContainerInterface $c, Request $req, Response $res, int $now): Response
    {
        $q = $req->getQueryParams();
        $site = is_string($q['site'] ?? null) && Sites::isSite($q['site']) ? $q['site'] : null;
        if ($site === null) {
            return self::json($res, ['error' => 'site required'], 400);
        }
        $days = max(1, min(self::MAX_SPAN, (int) ($q['days'] ?? 90)));
        $limit = max(1, min(500, (int) ($q['limit'] ?? 200)));
        $prefix = is_string($q['prefix'] ?? null) && preg_match('#^/[A-Za-z0-9/_\-]*$#', $q['prefix']) === 1 ? $q['prefix'] : null;
        $to = Clock::day($now);
        $from = Clock::addDays($to, -($days - 1));
        try {
            /** @var Metrics $metrics */
            $metrics = $c->get(Metrics::class);
            $out = [];
            foreach ($metrics->counts('pageviews.path', $from, $to, $site) as $path => $views) {
                $path = (string) $path;
                if ($path === '' || ($prefix !== null && !str_starts_with($path, $prefix))) {
                    continue;
                }
                $out[] = ['path' => $path, 'views' => $views];
                if (count($out) >= $limit) {
                    break;
                }
            }
        } catch (\Throwable) {
            $out = [];
        }
        return self::json($res, ['site' => $site, 'from' => $from, 'to' => $to, 'reads' => $out]);
    }

    public static function report(?ContainerInterface $c, string $name, Request $req, Response $res): Response
    {
        if (($deny = self::require($c->get(UserContext::class), 'analytics:read', $res)) !== null) {
            return $deny;
        }
        $q = $req->getQueryParams();
        $today = Clock::day(time());
        $site = is_string($q['site'] ?? null) && Sites::isSite($q['site']) ? $q['site'] : null;
        $to = Clock::parseDay($q['to'] ?? null) ?? $today;
        $from = Clock::parseDay($q['from'] ?? null) ?? Clock::addDays($to, -29);
        if ($to > $today) {
            $to = $today;
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ($from < Clock::addDays($to, -(self::MAX_SPAN - 1))) {
            $from = Clock::addDays($to, -(self::MAX_SPAN - 1));
        }

        try {
            self::maintain($c, time());
            /** @var Reports $reports */
            $reports = $c->get(Reports::class);
            $data = match ($name) {
                'overview' => $reports->overview($from, $to, $site),
                'pages' => $reports->pages($from, $to, $site),
                'scroll' => $reports->scroll($from, $to, $site),
                'sources' => $reports->sources($from, $to, $site),
                'clicks' => $reports->clicks($from, $to, $site),
                'forms' => $reports->forms($from, $to, $site),
                'summary' => $reports->summary($today, $site),
            };
        } catch (\Throwable) {
            return self::json($res, ['error' => 'Statistik ist nicht verfügbar (Datenbank).'], 503);
        }

        $data['site'] = $site;
        $data['retentionDays'] = self::retention($c);
        $data['geoip'] = self::flag($c, 'geoip_enabled', '1') && $c->get(GeoIp::class)->available();
        return self::json($res, $data);
    }

    /** Retention and the GeoIP refresh, at most once an hour. Never throws. */
    private static function maintain(?ContainerInterface $c, int $now): void
    {
        try {
            /** @var Maintenance $m */
            $m = $c->get(Maintenance::class);
            if (!$m->claim($now)) {
                return;
            }
            $m->run($now, self::retention($c));
            if (self::flag($c, 'geoip_enabled', '1')) {
                $c->get(GeoIp::class)->refresh();
            }
        } catch (\Throwable) {
            // Next hour.
        }
    }

    private static function retention(?ContainerInterface $c): int
    {
        return max(7, min(400, (int) self::setting($c, 'retention_days', '90')));
    }

    private static function body(Request $req): string
    {
        $body = $req->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        return substr((string) $body->getContents(), 0, Payload::MAX_BYTES + 1);
    }

    private static function salt(): string
    {
        return (string) (getenv('SETTINGS_ENCRYPTION_KEY') ?: 'tds-analytics');
    }

    /** DB first (SettingsStore ns), coded default after. Tolerates a missing container. */
    private static function setting(?ContainerInterface $c, string $key, string $default): string
    {
        try {
            $store = ($c !== null && $c->has(SettingsStore::class)) ? $c->get(SettingsStore::class) : null;
            $v = $store?->get(self::NS, $key);
            return $v !== null && $v !== '' ? $v : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    private static function flag(?ContainerInterface $c, string $key, string $default): bool
    {
        return self::setting($c, $key, $default) === '1';
    }
}
