<?php

namespace App\Services\Projects;

use App\Models\BoqTemplate;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PricingRule;
use App\Models\Project;
use App\Models\Property;
use App\Models\SignedLink;
use App\Models\User;
use App\Services\Boq\BoqTemplateCommitService;
use App\Services\Boq\BoqTemplatePreviewService;
use App\Services\ClientPortal\ClientPortalLinks;
use App\Services\Proposals\ProposalVersionService;
use Illuminate\Support\Facades\DB;

/**
 * docs/SIMPLIFICATION_REVIEW.md P4.3 — a sample project a company can click around before
 * entering real data: a sample client and apartment, a priced price list (Standard full
 * finishing), a 15% markup and a written draft proposal. Created on request (not on every
 * signup — that would put fake numbers in every new company's dashboard), clearly flagged
 * `is_sample`, kept out of money KPIs/reports and the getting-started checklist, and removed
 * in one click with everything attached to it — even if someone took it through contract and
 * payments.
 */
class SampleProjectService
{
    public const TEMPLATE_CODE = 'FULL-FINISHING-STANDARD';

    public function __construct(
        private readonly BoqTemplatePreviewService $preview,
        private readonly BoqTemplateCommitService $commit,
        private readonly ProposalVersionService $proposals,
    ) {}

    public function current(Organization $organization): ?Project
    {
        return Project::query()
            ->where('organization_id', $organization->id)
            ->where('is_sample', true)
            ->first();
    }

    /** Idempotent: a company has at most one sample project. */
    public function create(Organization $organization, User $actor, string $locale = 'en'): Project
    {
        if ($existing = $this->current($organization)) {
            return $existing;
        }

        $ar = $locale === 'ar';

        return DB::transaction(function () use ($organization, $actor, $locale, $ar) {
            $client = new Client([
                'organization_id' => $organization->id,
                'name' => $ar ? 'نور حسن (عميل نموذجي)' : 'Nour Hassan (sample client)',
                'notes' => $ar ? 'عميل نموذجي للتجربة — يمكن حذفه بضغطة واحدة.' : 'Sample client to try things out — delete it in one click.',
            ]);
            $client->forceFill(['is_sample' => true])->save();

            $property = Property::create([
                'organization_id' => $organization->id,
                'client_id' => $client->id,
                'type' => 'apartment',
                'compound' => $ar ? 'كمبوند نموذجي، التجمع الخامس' : 'Sample compound, New Cairo',
                'area_m2' => 140,
                'bedrooms' => 3,
                'bathrooms' => 2,
            ]);

            $project = new Project([
                'organization_id' => $organization->id,
                'client_id' => $client->id,
                'property_id' => $property->id,
                'code' => 'SAMPLE',
                'name' => $ar ? 'نموذج — شقة 3 غرف' : 'Sample — 3-bedroom apartment',
                'status' => 'draft',
                'start_date' => now()->toDateString(),
                'target_end_date' => now()->addMonths(4)->toDateString(),
            ]);
            $project->forceFill(['is_sample' => true])->save();

            $this->applyStandardTemplate($project, $organization, $actor, $locale);

            PricingRule::create([
                'project_id' => $project->id,
                'name' => $ar ? 'هامش الربح' : 'Markup',
                'type' => 'markup',
                'method' => 'percentage',
                'value' => 15,
                'base_selector' => 'boq_client_subtotal',
                'sort_order' => 1,
                'active' => true,
            ]);

            $this->proposals->createDraft($project, $this->proposalContent($ar), $actor->id);

            return $project->fresh();
        });
    }

    /**
     * Removes the sample project and its sample client. Payments and contracts go first: they're
     * the two links into a project's records that restrict rather than cascade on delete.
     */
    public function delete(Organization $organization): bool
    {
        $project = $this->current($organization);

        if (! $project) {
            return false;
        }

        DB::transaction(function () use ($project) {
            Payment::query()->where('project_id', $project->id)->delete();
            Contract::query()->where('project_id', $project->id)->delete();

            SignedLink::query()
                ->where('purpose', ClientPortalLinks::PURPOSE)
                ->where('payload_json->project_id', $project->id)
                ->delete();

            $client = Client::query()->find($project->client_id);
            $project->delete();

            if ($client?->is_sample && ! Project::query()->where('client_id', $client->id)->exists()) {
                Property::query()->where('client_id', $client->id)->delete();
                $client->delete();
            }
        });

        return true;
    }

    private function applyStandardTemplate(Project $project, Organization $organization, User $actor, string $locale): void
    {
        $template = BoqTemplate::withoutGlobalScopes()
            ->where('code', self::TEMPLATE_CODE)
            ->whereNull('organization_id')
            ->whereNotNull('active_version_id')
            ->first();

        if (! $template) {
            return; // Template seeds missing (e.g. a bare test DB): the sample still works, just empty.
        }

        $preview = $this->preview->preview([['template_version_id' => $template->active_version_id]], $organization->id);

        $rows = [];
        $walk = function (array $nodes) use (&$walk, &$rows) {
            foreach ($nodes as $node) {
                foreach ($node['items'] as $item) {
                    if (($item['quantity'] ?? null) === null || (float) $item['quantity'] <= 0) {
                        continue;
                    }
                    $first = $item['contributed_by'][0] ?? null;
                    $rows[] = array_filter([
                        'catalog_item_id' => $item['catalog_item_id'],
                        'category_id' => $node['id'],
                        'quantity' => $item['quantity'],
                        'unit' => $item['default_unit']['code'] ?? null,
                        'source_template_id' => $first['template_id'] ?? null,
                        'source_template_version_id' => $first['template_version_id'] ?? null,
                        'source_template_item_id' => $first['template_item_id'] ?? null,
                    ], fn ($v) => $v !== null);
                }
                $walk($node['children']);
            }
        };
        $walk($preview['categories']);

        if ($rows !== []) {
            $this->commit->commit($project, $rows, $actor, $locale);
        }
    }

    /** @return array<string, string> */
    private function proposalContent(bool $ar): array
    {
        return $ar ? [
            'cover' => 'شكرًا لاختيارك لنا. هذا عرض نموذجي لتشطيب شقة 3 غرف — جرّب تعديله وإرساله لنفسك.',
            'scope' => 'تشطيب كامل للشقة: أعمال المحارة والأرضيات والدهانات والكهرباء والسباكة والأبواب والمطبخ والحمامات بمواصفات عادية.',
            'exclusions' => 'الأثاث والأجهزة الكهربائية والستائر.',
            'timeline' => '16 أسبوعًا من تاريخ استلام المقدم.',
            'terms' => 'الأسعار سارية لمدة 30 يومًا.',
            'payment_plan' => '30٪ مقدم، 40٪ عند الانتهاء من الأعمال الأساسية، 30٪ عند التسليم.',
        ] : [
            'cover' => 'Thank you for choosing us. This is a sample proposal for a 3-bedroom apartment — try editing it and sending it to yourself.',
            'scope' => 'Full finishing of the apartment: plaster, flooring, paint, electrical, plumbing, doors, kitchen and bathrooms at a standard specification.',
            'exclusions' => 'Furniture, appliances and curtains.',
            'timeline' => '16 weeks from receipt of the down payment.',
            'terms' => 'Prices are valid for 30 days.',
            'payment_plan' => '30% down payment, 40% when the main works are done, 30% on handover.',
        ];
    }
}
