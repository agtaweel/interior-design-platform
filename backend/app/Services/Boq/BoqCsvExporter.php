<?php

namespace App\Services\Boq;

use App\Models\Project;

/**
 * CSV export for GET /projects/{project}/boq/export — same column shape as BoqCsvImporter so a
 * downloaded file round-trips back through import unchanged (PROJECT_CONTEXT.md Sprint 2
 * "Import/export"). Only non-archived items are included, matching the default (non-archived)
 * view of GET /projects/{project}/boq.
 */
class BoqCsvExporter
{
    /** Column order — keep in sync with BoqCsvImporter::REQUIRED_COLUMNS/NUMERIC_COLUMNS. */
    public const HEADER = [
        'category_name', 'room_name', 'name', 'description', 'quantity', 'unit',
        'material_unit_cost', 'labor_unit_cost', 'other_unit_cost', 'client_unit_price', 'notes',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rowsForProject(Project $project): array
    {
        $items = $project->boqItems()
            ->whereNull('archived_at')
            ->with(['category', 'room'])
            ->orderBy('sort_order')
            ->get();

        return $items->map(fn ($item) => [
            'category_name' => $item->category?->name ?? '',
            'room_name' => $item->room?->name ?? '',
            'name' => $item->name,
            'description' => $item->description ?? '',
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'material_unit_cost' => $item->material_unit_cost,
            'labor_unit_cost' => $item->labor_unit_cost,
            'other_unit_cost' => $item->other_unit_cost,
            'client_unit_price' => $item->client_unit_price,
            'notes' => $item->notes ?? '',
        ])->all();
    }
}
