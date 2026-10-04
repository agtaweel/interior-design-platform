<?php

namespace App\Console\Commands;

use App\Services\Boq\LegacyTemplateMigrator;
use Illuminate\Console\Command;

/**
 * `php artisan boq:migrate-legacy-templates [--dry-run]` — operator-visible entry point for
 * App\Services\Boq\LegacyTemplateMigrator, which the migrate_legacy_boq_templates_data migration
 * also calls directly. Exists so an operator can preview exactly what will be migrated (counts
 * per organization) before the migration actually runs it, and so it can be re-run manually if
 * ever needed outside the migration lifecycle.
 */
class MigrateLegacyBoqTemplates extends Command
{
    protected $signature = 'boq:migrate-legacy-templates {--dry-run : Report what would be migrated without writing anything}';

    protected $description = 'Transcribe every organization\'s legacy flat BOQ template into the new Master Catalog + Templates model';

    public function handle(LegacyTemplateMigrator $migrator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $results = $migrator->run($dryRun);

        if (empty($results)) {
            $this->info('No legacy template data found — nothing to migrate.');

            return self::SUCCESS;
        }

        $this->table(
            ['Organization ID', 'Status', 'Categories', 'Items'],
            array_map(fn (array $row) => [
                $row['organization_id'],
                $row['status'],
                $row['categories'] ?? '—',
                $row['items'] ?? '—',
            ], $results),
        );

        return self::SUCCESS;
    }
}
