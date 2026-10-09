<?php
declare(strict_types=1);

namespace Tds\Ext\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Tds\Ext\Analytics\AnalyticsModule;
use Tds\Frontend\Contract\ModuleRegistry;

/**
 * Documented and registered routes are the SAME set — renaming a path fails
 * here instead of leaving a stale description or a blank row behind.
 */
final class AnalyticsApiDocsTest extends TestCase
{
    /** @return string[] */
    private static function mountedRoutes(): array
    {
        $app = AppFactory::create();
        $registry = new ModuleRegistry([new AnalyticsModule()]);
        $registry->registerAll($app);
        return array_keys($registry->routeOwners());
    }

    /** @return string[] */
    private static function documentedRoutes(): array
    {
        return array_map(
            static fn (array $doc): string => strtoupper((string) $doc['method']) . ' ' . $doc['pattern'],
            (new AnalyticsModule())->apiDocs(),
        );
    }

    public function testDocumentsExactlyTheRoutesItMounts(): void
    {
        $mounted = self::mountedRoutes();
        $documented = self::documentedRoutes();
        sort($mounted);
        sort($documented);

        self::assertSame($mounted, $documented);
    }

    public function testEveryEntryIsWellFormed(): void
    {
        $permissions = array_map(static fn ($p): string => $p->id, (new AnalyticsModule())->permissions());

        foreach ((new AnalyticsModule())->apiDocs() as $doc) {
            $where = $doc['method'] . ' ' . $doc['pattern'];
            self::assertNotSame('', trim((string) $doc['summary']), "Leere Zusammenfassung: {$where}");
            $auth = $doc['auth'] ?? (isset($doc['permission']) ? 'permission' : 'public');
            self::assertContains($auth, ['public', 'session', 'permission', 'admin', 'token'], "Unbekannter auth-Wert: {$where}");
            if (isset($doc['permission'])) {
                self::assertContains($doc['permission'], $permissions, "Unbekannte Permission: {$where}");
            }
            foreach ($doc['params'] ?? [] as $param) {
                self::assertContains($param['in'], ['path', 'query', 'body', 'header'], "Unbekanntes in: {$where}");
                self::assertNotSame('', trim((string) $param['name']), "Parameter ohne Namen: {$where}");
            }
            foreach ($doc['responses'] ?? [] as $response) {
                self::assertIsInt($response['status'], "Status ist kein int: {$where}");
                self::assertNotSame('', trim((string) $response['description']), "Antwort ohne Text: {$where}");
            }
        }
    }

    public function testPublicRoutesAreExactlyTheTwoBrowserEndpoints(): void
    {
        $public = [];
        foreach ((new AnalyticsModule())->apiDocs() as $doc) {
            if (($doc['auth'] ?? null) === 'public') {
                $public[] = $doc['method'] . ' ' . $doc['pattern'];
            }
        }
        sort($public);
        self::assertSame(['POST /analytics/collect', 'POST /analytics/forget'], $public);
    }
}
