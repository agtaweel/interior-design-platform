<?php

namespace Tests\Feature\Boq;

use App\Models\BoqTemplate;
use App\Models\BoqTemplateVersion;
use App\Models\Organization;
use App\Services\Boq\LegacyTemplateMigrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * BOQ Master Catalog + Standard Templates — LegacyTemplateMigrator, exercised against the old
 * Sprint 2 flat shape. The real `*_legacy` tables only existed transiently during the actual
 * production migration (renamed in, read, then dropped — see the 2026_10_02_0000{05,11,12}
 * migrations) and no longer exist in a fresh schema, so this test recreates them itself for the
 * duration of each test method; Postgres DDL is transactional, so RefreshDatabase's
 * per-test transaction wrapping cleans them up automatically — no explicit teardown needed.
 */
class LegacyTemplateMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('boq_template_categories_legacy', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('boq_template_items_legacy', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('category_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit');
            $table->decimal('material_unit_cost', 12, 2)->default(0);
            $table->decimal('labor_unit_cost', 12, 2)->default(0);
            $table->decimal('other_unit_cost', 12, 2)->default(0);
            $table->decimal('client_unit_price', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    private function insertLegacyCategory(int $organizationId, string $name, ?int $parentId = null): int
    {
        return DB::table('boq_template_categories_legacy')->insertGetId([
            'organization_id' => $organizationId,
            'parent_id' => $parentId,
            'name' => $name,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLegacyItem(int $organizationId, int $categoryId, string $name, string $unit = 'pcs'): void
    {
        DB::table('boq_template_items_legacy')->insert([
            'organization_id' => $organizationId,
            'category_id' => $categoryId,
            'name' => $name,
            'description' => 'Legacy description',
            'unit' => $unit,
            'material_unit_cost' => 800,
            'labor_unit_cost' => 300,
            'other_unit_cost' => 0,
            'client_unit_price' => 1500,
            'notes' => null,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_migrator_transcribes_legacy_data_with_zero_loss(): void
    {
        $organization = Organization::factory()->create();
        $categoryId = $this->insertLegacyCategory($organization->id, 'Kitchen');
        $this->insertLegacyItem($organization->id, $categoryId, 'Cabinet');

        $summary = app(LegacyTemplateMigrator::class)->run();

        $this->assertCount(1, $summary);
        $this->assertSame('migrated', $summary[0]['status']);
        $this->assertSame(1, $summary[0]['categories']);
        $this->assertSame(1, $summary[0]['items']);

        $template = BoqTemplate::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('code', 'LEGACY-'.$organization->id)
            ->first();
        $this->assertNotNull($template);
        $this->assertNotNull($template->active_version_id);

        $version = $template->activeVersion;
        $this->assertSame(BoqTemplateVersion::STATUS_PUBLISHED, $version->status);

        $templateItem = $version->items()->with('catalogItem')->first();
        $this->assertNotNull($templateItem);
        $this->assertSame('Cabinet', $templateItem->catalogItem->name);
        $this->assertSame('1500.00', (string) $templateItem->catalogItem->default_client_unit_price);
    }

    public function test_migrator_preserves_the_parent_child_category_chain(): void
    {
        $organization = Organization::factory()->create();
        $parentId = $this->insertLegacyCategory($organization->id, 'Kitchen');
        $childId = $this->insertLegacyCategory($organization->id, 'Cabinets', $parentId);
        $this->insertLegacyItem($organization->id, $childId, 'Base Unit');

        app(LegacyTemplateMigrator::class)->run();

        $newParent = \App\Models\BoqCatalogCategory::withoutGlobalScopes()->where('organization_id', $organization->id)->where('name', 'Kitchen')->first();
        $newChild = \App\Models\BoqCatalogCategory::withoutGlobalScopes()->where('organization_id', $organization->id)->where('name', 'Cabinets')->first();

        $this->assertNotNull($newParent);
        $this->assertNotNull($newChild);
        $this->assertSame($newParent->id, $newChild->parent_id);
    }

    public function test_migrator_is_idempotent_and_skips_an_already_migrated_organization(): void
    {
        $organization = Organization::factory()->create();
        $categoryId = $this->insertLegacyCategory($organization->id, 'Kitchen');
        $this->insertLegacyItem($organization->id, $categoryId, 'Cabinet');

        app(LegacyTemplateMigrator::class)->run();
        $countBefore = BoqTemplate::withoutGlobalScopes()->count();

        $summary = app(LegacyTemplateMigrator::class)->run();

        $this->assertSame('skipped_already_migrated', $summary[0]['status']);
        $this->assertSame($countBefore, BoqTemplate::withoutGlobalScopes()->count());
    }

    public function test_dry_run_reports_without_writing_anything(): void
    {
        $organization = Organization::factory()->create();
        $categoryId = $this->insertLegacyCategory($organization->id, 'Kitchen');
        $this->insertLegacyItem($organization->id, $categoryId, 'Cabinet');

        $summary = app(LegacyTemplateMigrator::class)->run(dryRun: true);

        $this->assertSame('would_migrate', $summary[0]['status']);
        $this->assertSame(1, $summary[0]['categories']);
        $this->assertSame(1, $summary[0]['items']);
        $this->assertSame(0, BoqTemplate::withoutGlobalScopes()->count());
    }

    public function test_an_unresolvable_legacy_unit_falls_back_to_a_null_default_unit(): void
    {
        $organization = Organization::factory()->create();
        $categoryId = $this->insertLegacyCategory($organization->id, 'Kitchen');
        $this->insertLegacyItem($organization->id, $categoryId, 'Cabinet', 'totally-unknown-unit');

        app(LegacyTemplateMigrator::class)->run();

        $catalogItem = \App\Models\BoqCatalogItem::withoutGlobalScopes()->where('organization_id', $organization->id)->where('name', 'Cabinet')->first();
        $this->assertNull($catalogItem->default_unit_id);
    }
}
