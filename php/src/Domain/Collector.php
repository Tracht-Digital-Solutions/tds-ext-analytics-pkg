<?php
declare(strict_types=1);

namespace Tds\Ext\Analytics\Domain;

use PDO;
use Tds\Ext\Analytics\Support\Clock;

/**
 * Writes one validated beacon batch: upserts its session, appends its events.
 *
 * A session belongs to the visitor id that created it. A batch naming someone
 * else's session id (a forged or colliding beacon) is dropped whole rather than
 * merged into a stranger's visit.
 */
final class Collector
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{visitor_id: string, site: string}|null */
    public function sessionOwner(string $sessionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT visitor_id, site FROM analytics_session WHERE id = :id');
        $stmt->execute(['id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['visitor_id' => (string) $row['visitor_id'], 'site' => (string) $row['site']] : null;
    }

    /**
     * @param array{site: string, lang: string, vid: string, sid: string, ret: bool, ref: ?string,
     *              utm: ?array<string, string>, events: list<array{t: string, p: string, k: ?string, f: ?string, n: ?int}>} $batch
     * @param array{channel: string, country: ?string, device: string, browser: string, os: string} $origin
     *        Derived on the server; only used when this batch opens the session.
     */
    public function store(array $batch, array $origin, int $now): void
    {
        $pageviews = 0;
        $entry = null;
        $exit = null;
        $duration = 0;
        $rows = [];
        foreach ($batch['events'] as $e) {
            if ($e['t'] === 'pageview') {
                $pageviews++;
                $entry ??= $e['p'];
                $exit = $e['p'];
            }
            if ($e['t'] === 'exit') {
                // Engaged time is a property of the visit, not a row of its own.
                $duration += (int) $e['n'];
                continue;
            }
            $rows[] = $e;
        }

        $ts = Clock::utc($now);
        $day = Clock::day($now);
        $utm = $batch['utm'] ?? [];

        $this->pdo->prepare(
            'INSERT INTO analytics_session (id, visitor_id, site, day, started_at, last_seen_at, entry_path, exit_path,'
            . ' pageviews, duration_ms, is_returning, ref_domain, channel, utm_source, utm_medium, utm_campaign,'
            . ' country, device, browser, os, lang)'
            . ' VALUES (:id, :vid, :site, :day, :started, :seen, :entry, :exit, :pv, :dur, :ret, :ref, :channel,'
            . ' :us, :um, :uc, :country, :device, :browser, :os, :lang)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' last_seen_at = IF(visitor_id = VALUES(visitor_id), VALUES(last_seen_at), last_seen_at),'
            . ' entry_path = IF(visitor_id = VALUES(visitor_id), COALESCE(entry_path, VALUES(entry_path)), entry_path),'
            . ' exit_path = IF(visitor_id = VALUES(visitor_id) AND VALUES(exit_path) IS NOT NULL, VALUES(exit_path), exit_path),'
            . ' pageviews = IF(visitor_id = VALUES(visitor_id), pageviews + VALUES(pageviews), pageviews),'
            . ' duration_ms = IF(visitor_id = VALUES(visitor_id), duration_ms + VALUES(duration_ms), duration_ms)',
        )->execute([
            'id' => $batch['sid'],
            'vid' => $batch['vid'],
            'site' => $batch['site'],
            'day' => $day,
            'started' => $ts,
            'seen' => $ts,
            'entry' => $entry,
            'exit' => $exit,
            'pv' => $pageviews,
            'dur' => $duration,
            'ret' => $batch['ret'] ? 1 : 0,
            'ref' => $batch['ref'],
            'channel' => $origin['channel'],
            'us' => $utm['source'] ?? null,
            'um' => $utm['medium'] ?? null,
            'uc' => $utm['campaign'] ?? null,
            'country' => $origin['country'],
            'device' => $origin['device'],
            'browser' => $origin['browser'],
            'os' => $origin['os'],
            'lang' => $batch['lang'],
        ]);

        if ($rows === []) {
            return;
        }
        $values = [];
        $params = [];
        foreach ($rows as $i => $e) {
            $values[] = "(:s{$i}, :site{$i}, :d{$i}, :ts{$i}, :t{$i}, :p{$i}, :k{$i}, :f{$i}, :n{$i})";
            $params["s{$i}"] = $batch['sid'];
            $params["site{$i}"] = $batch['site'];
            $params["d{$i}"] = $day;
            $params["ts{$i}"] = $ts;
            $params["t{$i}"] = $e['t'];
            $params["p{$i}"] = $e['p'];
            $params["k{$i}"] = $e['k'];
            $params["f{$i}"] = $e['f'];
            $params["n{$i}"] = $e['n'];
        }
        $this->pdo->prepare(
            'INSERT INTO analytics_event (session_id, site, day, ts, type, path, target, field, value) VALUES '
            . implode(', ', $values),
        )->execute($params);
    }

    /**
     * Erase everything recorded under one visitor id (Art. 17 DSGVO).
     * Day totals stay: they hold no visitor id and cannot be traced back.
     *
     * @return int sessions removed
     */
    public function forget(string $visitorId): int
    {
        $this->pdo->prepare(
            'DELETE FROM analytics_event WHERE session_id IN (SELECT id FROM analytics_session WHERE visitor_id = :vid)',
        )->execute(['vid' => $visitorId]);
        $stmt = $this->pdo->prepare('DELETE FROM analytics_session WHERE visitor_id = :vid');
        $stmt->execute(['vid' => $visitorId]);
        return $stmt->rowCount();
    }

    /**
     * Count one request against a salted IP hash; true while under the limit.
     */
    public function withinRate(string $ipHash, int $now, int $perMinute): bool
    {
        $minute = gmdate('YmdHi', $now);
        $this->pdo->prepare(
            'INSERT INTO analytics_rate (ip_hash, minute, hits) VALUES (:h, :m, 1)'
            . ' ON DUPLICATE KEY UPDATE hits = hits + 1',
        )->execute(['h' => $ipHash, 'm' => $minute]);
        $stmt = $this->pdo->prepare('SELECT hits FROM analytics_rate WHERE ip_hash = :h AND minute = :m');
        $stmt->execute(['h' => $ipHash, 'm' => $minute]);
        return (int) $stmt->fetchColumn() <= $perMinute;
    }
}
