<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * One row per visit (session), written by the consent-gated beacon.
 *
 * What is NOT here is the point: no IP address, no user agent, no account id.
 * `visitor_id` is a random id the browser created after the visitor agreed to
 * statistics; it expires in the browser after 30 days and is the erasure key
 * for `POST /analytics/forget`.
 *
 * `day` is the Europe/Berlin calendar day, computed in PHP at insert time, so
 * every report groups on an indexed DATE instead of converting time zones in
 * SQL. The timestamps themselves are UTC (`gmdate`), never `NOW()`.
 */
final class AnalyticsCreateSession extends AbstractMigration
{
    public function change(): void
    {
        $this->table('analytics_session', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('visitor_id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('site', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('day', 'date', ['null' => false])
            ->addColumn('started_at', 'datetime', ['null' => false])
            ->addColumn('last_seen_at', 'datetime', ['null' => false])
            ->addColumn('entry_path', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('exit_path', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('pageviews', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('duration_ms', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('is_returning', 'boolean', ['default' => false])
            ->addColumn('ref_domain', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('channel', 'string', ['limit' => 16, 'null' => false, 'default' => 'direct'])
            ->addColumn('utm_source', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('utm_medium', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('utm_campaign', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('country', 'char', ['limit' => 2, 'null' => true])
            ->addColumn('device', 'string', ['limit' => 10, 'null' => false, 'default' => 'desktop'])
            ->addColumn('browser', 'string', ['limit' => 20, 'null' => false, 'default' => 'other'])
            ->addColumn('os', 'string', ['limit' => 20, 'null' => false, 'default' => 'other'])
            ->addColumn('lang', 'string', ['limit' => 5, 'null' => false, 'default' => 'de'])
            ->addIndex(['site', 'day'])
            ->addIndex(['visitor_id'])
            ->create();
    }
}
