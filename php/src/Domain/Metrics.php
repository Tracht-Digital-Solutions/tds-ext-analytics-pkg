<?php
declare(strict_types=1);

namespace Tds\Ext\Analytics\Domain;

use PDO;

/**
 * Every number the dashboard shows, defined ONCE as a (metric, dimension) pair.
 *
 * The same definition answers two questions: "what are the counts in this raw
 * window" (reports over the last `retention_days`) and "what do I keep of this
 * day" (the roll-up that replaces raw rows with anonymous day totals). Keeping
 * one definition is what makes a report spanning both look continuous — a
 * second, hand-written roll-up would drift from the live query the first time
 * either changed.
 *
 * Days up to `MAX(day)` of `analytics_daily` are read from the totals, later
 * days from the raw tables. A day without traffic has no rows on either side,
 * so the boundary needs no bookkeeping of its own.
 */
final class Metrics
{
    /** Session columns a visit can be broken down by: dimension → column. */
    public const SESSION_DIMS = [
        'channel' => 's.channel',
        'ref' => 's.ref_domain',
        'source' => 's.utm_source',
        'medium' => 's.utm_medium',
        'campaign' => 's.utm_campaign',
        'country' => 's.country',
        'device' => 's.device',
        'browser' => 's.browser',
        'os' => 's.os',
        'lang' => 's.lang',
    ];

    /**
     * @var array<string, array{0: 'session'|'event'|'special', 1: string, 2: string, 3?: string}>
     *      "metric.dim" → [table, key expression, aggregate, extra WHERE]
     */
    private const DEFS = [
        'visits.all' => ['session', "''", 'COUNT(*)'],
        'visitors.all' => ['session', "''", 'COUNT(DISTINCT s.visitor_id)'],
        'returning.all' => ['session', "''", 'SUM(s.is_returning)'],
        'bounces.all' => ['session', "''", 'SUM(s.pageviews <= 1)'],
        'duration.all' => ['session', "''", 'SUM(s.duration_ms)'],
        'visits.day' => ['session', 's.day', 'COUNT(*)'],
        'entries.path' => ['session', "COALESCE(s.entry_path, '')", 'COUNT(*)'],
        'exits.path' => ['session', "COALESCE(s.exit_path, '')", 'COUNT(*)'],
        'bounces.path' => ['session', "COALESCE(s.entry_path, '')", 'COUNT(*)', 's.pageviews <= 1'],
        'pageviews.all' => ['event', "''", 'COUNT(*)', "e.type = 'pageview'"],
        'pageviews.day' => ['event', 'e.day', 'COUNT(*)', "e.type = 'pageview'"],
        'pageviews.path' => ['event', 'e.path', 'COUNT(*)', "e.type = 'pageview'"],
        'clicks.cta' => ['event', 'e.target', 'COUNT(*)', "e.type = 'click'"],
        'clicks.outbound' => ['event', 'e.target', 'COUNT(*)', "e.type = 'outbound'"],
        'scroll.path' => ['event', "CONCAT(e.value, '|', e.path)", 'COUNT(*)', "e.type = 'scroll'"],
        'sections.path' => ['event', "CONCAT(e.path, '#', e.target)", 'COUNT(*)', "e.type = 'section'"],
        'forms.start' => ['event', 'e.target', 'COUNT(DISTINCT e.session_id)', "e.type = 'form_start'"],
        'forms.submit' => ['event', 'e.target', 'COUNT(DISTINCT e.session_id)', "e.type = 'form_submit'"],
        'forms.abandon' => ['special', '', ''],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<string> every "metric.dim" pair, including the per-session-column breakdowns */
    public static function all(): array
    {
        $keys = array_keys(self::DEFS);
        foreach (array_keys(self::SESSION_DIMS) as $dim) {
            $keys[] = 'visits.' . $dim;
        }
        return $keys;
    }

    /**
     * Counts for one pair over [from, to], from whichever source holds each day.
     *
     * @return array<string, int> key → count
     */
    public function counts(string $pair, string $from, string $to, ?string $site): array
    {
        $boundary = $this->rolledThrough();
        $out = [];
        if ($boundary !== null && $from <= $boundary) {
            $out = $this->daily($pair, $from, min($to, $boundary), $site);
        }
        $rawFrom = $boundary !== null && $boundary >= $from ? self::nextDay($boundary) : $from;
        if ($rawFrom <= $to) {
            foreach ($this->raw($pair, $rawFrom, $to, $site) as $k => $c) {
                $out[$k] = ($out[$k] ?? 0) + $c;
            }
        }
        arsort($out);
        return $out;
    }

    /** One number: the "''" key of an `.all` pair. */
    public function total(string $pair, string $from, string $to, ?string $site): int
    {
        return $this->counts($pair, $from, $to, $site)[''] ?? 0;
    }

    /** The last day whose raw rows were folded into totals, or null. */
    public function rolledThrough(): ?string
    {
        $v = $this->pdo->query('SELECT MAX(day) FROM analytics_daily')->fetchColumn();
        return is_string($v) && $v !== '' ? substr($v, 0, 10) : null;
    }

    /**
     * Counts from the raw tables.
     *
     * @return array<string, int>
     */
    public function raw(string $pair, string $from, string $to, ?string $site): array
    {
        [$sql, $params] = $this->rawSql($pair, $from, $to, $site);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$k, $c]) {
            $out[(string) $k] = (int) $c;
        }
        // An aggregate over zero rows answers one NULL row — that is "no
        // data", not a zero worth storing.
        if (array_key_exists('', $out) && $out[''] === 0 && str_ends_with($pair, '.all')) {
            unset($out['']);
        }
        return $out;
    }

    /** @return array<string, int> */
    private function daily(string $pair, string $from, string $to, ?string $site): array
    {
        [$metric, $dim] = explode('.', $pair, 2);
        $sql = 'SELECT dkey, SUM(count) FROM analytics_daily'
            . ' WHERE metric = :metric AND dim = :dim AND day BETWEEN :from AND :to'
            . ($site !== null ? ' AND site = :site' : '')
            . ' GROUP BY dkey';
        $params = ['metric' => $metric, 'dim' => $dim, 'from' => $from, 'to' => $to];
        if ($site !== null) {
            $params['site'] = $site;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$k, $c]) {
            $out[(string) $k] = (int) $c;
        }
        return $out;
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function rawSql(string $pair, string $from, string $to, ?string $site): array
    {
        $params = ['from' => $from, 'to' => $to];
        if ($site !== null) {
            $params['site'] = $site;
        }

        if (str_starts_with($pair, 'visits.') && isset(self::SESSION_DIMS[substr($pair, 7)])) {
            $def = ['session', 'COALESCE(' . self::SESSION_DIMS[substr($pair, 7)] . ", '')", 'COUNT(*)'];
        } elseif (isset(self::DEFS[$pair])) {
            $def = self::DEFS[$pair];
        } else {
            throw new \InvalidArgumentException("unknown metric {$pair}");
        }

        if ($def[0] === 'special') {
            // Abandoned forms: sessions that started a form and never submitted
            // it, keyed by the LAST field they entered — "where they gave up".
            $siteWhere = $site !== null ? ' AND f.site = :site' : '';
            $innerSite = $site !== null ? ' AND site = :site2' : '';
            if ($site !== null) {
                $params['site2'] = $site;
            }
            $sql = "SELECT CONCAT(f.target, '|', f.field) AS k, COUNT(*) AS c"
                . ' FROM analytics_event f'
                . ' JOIN (SELECT session_id, target, MAX(id) AS mid FROM analytics_event'
                . "       WHERE type = 'form_field' AND day BETWEEN :from2 AND :to2{$innerSite}"
                . '       GROUP BY session_id, target) last ON f.id = last.mid'
                . " WHERE f.day BETWEEN :from AND :to{$siteWhere}"
                . ' AND NOT EXISTS (SELECT 1 FROM analytics_event s'
                . "   WHERE s.session_id = f.session_id AND s.target = f.target AND s.type = 'form_submit')"
                . ' GROUP BY k';
            $params['from2'] = $from;
            $params['to2'] = $to;
            return [$sql, $params];
        }

        [$table, $key, $agg] = $def;
        $where = $def[3] ?? null;
        $alias = $table === 'session' ? 's' : 'e';
        $sql = "SELECT {$key} AS k, {$agg} AS c FROM analytics_{$table} {$alias}"
            . " WHERE {$alias}.day BETWEEN :from AND :to"
            . ($site !== null ? " AND {$alias}.site = :site" : '')
            . ($where !== null ? " AND {$where}" : '')
            . ' GROUP BY k';
        return [$sql, $params];
    }

    private static function nextDay(string $day): string
    {
        return (new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    }
}
