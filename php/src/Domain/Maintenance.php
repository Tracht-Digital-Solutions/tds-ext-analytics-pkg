<?php
declare(strict_types=1);

namespace Tds\Ext\Analytics\Domain;

use PDO;
use Tds\Ext\Analytics\Support\Clock;

/**
 * Retention, run in-process: the host has no cron.
 *
 * At most once an hour (claimed atomically through `analytics_rate`, so two
 * concurrent requests never both run it) it folds every raw day older than the
 * retention window into `analytics_daily`, deletes that day's raw rows, and
 * drops rate-limit rows older than an hour. Each day is idempotent: the totals
 * are upserted, so a run interrupted after the insert simply repeats the day.
 *
 * Plain DML only — no DDL, which would commit implicitly.
 */
final class Maintenance
{
    /** Days handled per run, so one request never carries the whole backlog. */
    private const DAYS_PER_RUN = 7;

    public function __construct(private readonly PDO $pdo, private readonly Metrics $metrics)
    {
    }

    /** Claim this hour's run. True for exactly one caller per hour. */
    public function claim(int $now): bool
    {
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO analytics_rate (ip_hash, minute, hits) VALUES (:h, :m, 1)');
        $stmt->execute(['h' => str_repeat('0', 64), 'm' => gmdate('YmdH', $now) . '--']);
        return $stmt->rowCount() === 1;
    }

    /** @return list<string> the days rolled up */
    public function run(int $now, int $retentionDays): array
    {
        $retentionDays = max(7, min(400, $retentionDays));
        $cutoff = Clock::addDays(Clock::day($now), -$retentionDays);

        $this->pdo->prepare('DELETE FROM analytics_rate WHERE minute < :m')
            ->execute(['m' => gmdate('YmdHi', $now - 3600)]);

        $stmt = $this->pdo->prepare(
            'SELECT day FROM (SELECT day FROM analytics_session WHERE day < :c1'
            . ' UNION SELECT day FROM analytics_event WHERE day < :c2) d ORDER BY day LIMIT ' . self::DAYS_PER_RUN,
        );
        $stmt->execute(['c1' => $cutoff, 'c2' => $cutoff]);
        $days = array_map(static fn ($d): string => substr((string) $d, 0, 10), $stmt->fetchAll(PDO::FETCH_COLUMN));

        foreach ($days as $day) {
            $this->rollUp($day);
        }
        return $days;
    }

    public function rollUp(string $day): void
    {
        $sites = $this->pdo->prepare(
            'SELECT site FROM analytics_session WHERE day = :d1 UNION SELECT site FROM analytics_event WHERE day = :d2',
        );
        $sites->execute(['d1' => $day, 'd2' => $day]);
        $insert = $this->pdo->prepare(
            'INSERT INTO analytics_daily (day, site, metric, dim, dkey, count) VALUES (:day, :site, :metric, :dim, :k, :c)'
            . ' ON DUPLICATE KEY UPDATE count = VALUES(count)',
        );
        foreach ($sites->fetchAll(PDO::FETCH_COLUMN) as $site) {
            foreach (Metrics::all() as $pair) {
                [$metric, $dim] = explode('.', $pair, 2);
                foreach ($this->metrics->raw($pair, $day, $day, (string) $site) as $key => $count) {
                    if ($count <= 0) {
                        continue;
                    }
                    $insert->execute([
                        'day' => $day,
                        'site' => $site,
                        'metric' => $metric,
                        'dim' => $dim,
                        'k' => mb_substr((string) $key, 0, 255),
                        'c' => $count,
                    ]);
                }
            }
        }
        $this->pdo->prepare('DELETE FROM analytics_event WHERE day = :d')->execute(['d' => $day]);
        $this->pdo->prepare('DELETE FROM analytics_session WHERE day = :d')->execute(['d' => $day]);
    }
}
