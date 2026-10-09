<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The individual measurements of a visit: page views, tracked clicks, outbound
 * hosts, scroll milestones, reached sections, form progress, engaged time.
 *
 * `target` is a CTA name, an outbound HOST, a section id or a form name;
 * `field` is a form field's NAME attribute. Neither ever holds what a visitor
 * typed — the beacon does not send it, and the collector would not accept it.
 *
 * Raw rows live for `retention_days` (default 90), then
 * `Maintenance` folds them into `analytics_daily` and deletes them.
 */
final class AnalyticsCreateEvent extends AbstractMigration
{
    public function change(): void
    {
        $this->table('analytics_event', ['signed' => false])
            ->addColumn('session_id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('site', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('day', 'date', ['null' => false])
            ->addColumn('ts', 'datetime', ['null' => false])
            ->addColumn('type', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('path', 'string', ['limit' => 200, 'null' => false])
            ->addColumn('target', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('field', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('value', 'integer', ['signed' => false, 'null' => true])
            ->addIndex(['site', 'type', 'day'])
            ->addIndex(['session_id'])
            ->addIndex(['day'])
            ->create();
    }
}
