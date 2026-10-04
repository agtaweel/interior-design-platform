<?php

use App\Services\Boq\LegacyTemplateMigrator;
use Illuminate\Database\Migrations\Migration;

/**
 * BOQ Master Catalog + Standard Templates — runs App\Services\Boq\LegacyTemplateMigrator once,
 * transcribing every organization's legacy flat template (renamed to *_legacy by the earlier
 * rename_legacy_boq_template_tables migration) into the new model. Idempotent (the migrator
 * itself skips an organization whose LEGACY-{id} template already exists), so this is safe to
 * re-run if a deploy is retried.
 *
 * Deliberately NOT reversible: this is a one-way data transcription, not a schema change — a
 * `down()` that tried to delete the migrated rows could not distinguish them from legitimate new
 * templates an organization created afterward. Reversing this replacement means restoring from
 * a backup taken before it ran, same posture as any other irreversible data migration in this
 * codebase.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(LegacyTemplateMigrator::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException('Irreversible data migration — restore from a pre-migration backup instead.');
    }
};
