<?php
/**
 * API documentation for the Besucher-Statistik routes — consumed through
 * `ApiDocSource` and rendered in the admin frontend's API reference.
 * `php/tests/AnalyticsApiDocsTest.php` asserts documented and mounted routes are
 * the same set. `pattern` must match the Slim pattern verbatim.
 */

declare(strict_types=1);

$range = [
    ['in' => 'query', 'name' => 'site', 'description' => '`landing`, `blog`, `tools`, `auth` oder `shop`; leer = alle Sites.'],
    ['in' => 'query', 'name' => 'from', 'description' => 'Erster Tag (`YYYY-MM-DD`, Europe/Berlin). Standard: 29 Tage vor `to`.'],
    ['in' => 'query', 'name' => 'to', 'description' => 'Letzter Tag (`YYYY-MM-DD`). Standard: heute; höchstens 400 Tage Spanne.'],
];
$report = static fn (string $pattern, string $summary, string $body): array => [
    'method' => 'GET',
    'pattern' => $pattern,
    'tag' => 'Auswertung',
    'summary' => $summary,
    'permission' => 'analytics:read',
    'params' => $range,
    'responses' => [
        ['status' => 200, 'description' => $body],
        ['status' => 503, 'description' => 'Datenbank nicht erreichbar.'],
    ],
];

return [
    [
        'method' => 'POST',
        'pattern' => '/analytics/collect',
        'tag' => 'Erfassung',
        'summary' => 'Beacon der öffentlichen Sites (nur nach Einwilligung „Statistik“)',
        'description' => 'Body `text/plain` mit JSON `{v:1, site, lang, vid, sid, ret, ref?, utm?, events[]}`, '
            . 'höchstens 16 KB. `Origin` (ersatzweise `Referer`) muss zur angegebenen Site gehören. '
            . 'IP-Adresse und User-Agent werden nicht gespeichert: Land, Geräteklasse und Browserfamilie '
            . 'werden abgeleitet, danach verworfen.',
        'auth' => 'public',
        'params' => [
            ['in' => 'body', 'name' => 'events', 'description' => 'pageview, click, outbound, scroll, section, form_start, form_field, form_submit, exit'],
        ],
        'responses' => [
            ['status' => 204, 'description' => 'Angenommen — oder still verworfen (Bot, GPC, Site aus, Ratenlimit).'],
            ['status' => 400, 'description' => 'Ungültiger Body.'],
            ['status' => 403, 'description' => 'Origin gehört nicht zur angegebenen Site.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/analytics/forget',
        'tag' => 'Erfassung',
        'summary' => 'Alle Rohdaten einer Besucher-Kennung löschen (Art. 17 DSGVO)',
        'description' => 'Body `{visitorId}`. Tagessummen bleiben, sie enthalten keine Kennung.',
        'auth' => 'public',
        'params' => [
            ['in' => 'body', 'name' => 'visitorId', 'description' => 'Die Kennung aus `tds-vid` im Browser.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{removed}` — Anzahl gelöschter Besuche.'],
            ['status' => 400, 'description' => 'Keine gültige Kennung.'],
            ['status' => 429, 'description' => 'Zu viele Anfragen.'],
            ['status' => 503, 'description' => 'Datenbank nicht erreichbar — nichts gelöscht.'],
        ],
    ],
    $report('/analytics/overview', 'Kennzahlen, Vorperiode und Tagesverlauf', '`{totals, previous, series[], range}`'),
    $report('/analytics/pages', 'Seiten mit Einstiegen, Ausstiegen, Ausstiegs- und Absprungrate', '`{pages[]}`'),
    $report('/analytics/scroll', 'Scrolltiefe und erreichte Abschnitte je Seite', '`{pages[{path, reached, sections[]}]}`'),
    $report('/analytics/sources', 'Herkunft: Kanal, Verweis, UTM, Land, Gerät, Browser, OS, Sprache', '`{channel[], ref[], …}`'),
    $report('/analytics/clicks', 'Klicks auf markierte Schaltflächen und ausgehende Links', '`{cta[], outbound[]}`'),
    $report('/analytics/forms', 'Formular-Trichter: begonnen, abgeschickt, abgebrochen nach Feld', '`{forms[]}`'),
    $report('/analytics/summary', 'Die letzten sieben Tage für das Dashboard-Widget', '`{totals, series[]}`'),
];
