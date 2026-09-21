<?php

namespace App\Services\Boq;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\Project;
use App\Models\Room;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * CSV import for POST /projects/{project}/boq/import (PROJECT_CONTEXT.md Sprint 2
 * "Import/export" — CSV, not full Excel binary, is this sprint's explicit scope decision).
 *
 * Columns: category_name, room_name (optional), name, description, quantity, unit,
 * material_unit_cost, labor_unit_cost, other_unit_cost, client_unit_price, notes. Categories
 * and rooms are looked up case-insensitively by name within the project and created on first
 * use; a room is only created/attached when room_name is non-empty (room_id stays null
 * otherwise, same as the manual item-create endpoint).
 *
 * The whole file is processed inside one DB transaction (financial data, multi-row write —
 * PROJECT_CONTEXT.md's transactional-writes rule), but a row failing *validation* (missing
 * required field, non-numeric cost/quantity) does not abort the import — it is recorded in the
 * returned summary and the loop continues, so one malformed row in an otherwise-good 200-row
 * file doesn't force the user to fix and re-upload everything. The transaction only rolls back
 * everything on a genuine unexpected failure (e.g. a DB-level error), which is the scenario the
 * "atomic" requirement is guarding against.
 */
class BoqCsvImporter
{
    private const REQUIRED_COLUMNS = ['category_name', 'name', 'quantity', 'unit'];

    private const NUMERIC_COLUMNS = ['quantity', 'material_unit_cost', 'labor_unit_cost', 'other_unit_cost', 'client_unit_price'];

    /**
     * @return array{created: int, skipped: int, errors: array<int, array{row: int, reason: string}>}
     */
    public function import(Project $project, UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return ['created' => 0, 'skipped' => 1, 'errors' => [['row' => 0, 'reason' => 'Could not read the uploaded file.']]];
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return ['created' => 0, 'skipped' => 1, 'errors' => [['row' => 0, 'reason' => 'The CSV file is empty.']]];
        }

        $header = array_map(fn ($column) => strtolower(trim((string) $column)), $header);

        $created = 0;
        $errors = [];
        $rowNumber = 1; // row 1 is the header; data rows are numbered from 2, matching what a spreadsheet user sees.

        DB::transaction(function () use ($handle, $header, $project, &$created, &$errors, &$rowNumber) {
            /** @var array<string, BoqCategory> $categoryCache */
            $categoryCache = [];
            /** @var array<string, Room> $roomCache */
            $roomCache = [];

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if (count(array_filter($row, fn ($value) => $value !== null && $value !== '')) === 0) {
                    continue; // blank line — not an error, just noise from spreadsheet exports.
                }

                $data = $this->rowToAssoc($header, $row);

                $missing = array_filter(self::REQUIRED_COLUMNS, fn ($column) => ! isset($data[$column]) || $data[$column] === '');

                if (! empty($missing)) {
                    $errors[] = ['row' => $rowNumber, 'reason' => 'Missing required value(s): '.implode(', ', $missing)];

                    continue;
                }

                $nonNumeric = array_filter(
                    self::NUMERIC_COLUMNS,
                    fn ($column) => isset($data[$column]) && $data[$column] !== '' && ! is_numeric($data[$column])
                );

                if (! empty($nonNumeric)) {
                    $errors[] = ['row' => $rowNumber, 'reason' => 'Non-numeric value(s) for: '.implode(', ', $nonNumeric)];

                    continue;
                }

                $category = $this->findOrCreateCategory($project, $data['category_name'], $categoryCache);
                $roomId = $this->findOrCreateRoomId($project, $data['room_name'] ?? null, $roomCache);

                BoqItem::create([
                    'project_id' => $project->id,
                    'category_id' => $category->id,
                    'room_id' => $roomId,
                    'name' => $data['name'],
                    'description' => $this->nullableOrDefault($data['description'] ?? null),
                    'quantity' => $data['quantity'],
                    'unit' => $data['unit'],
                    'material_unit_cost' => $data['material_unit_cost'] ?? 0,
                    'labor_unit_cost' => $data['labor_unit_cost'] ?? 0,
                    'other_unit_cost' => $data['other_unit_cost'] ?? 0,
                    'client_unit_price' => $data['client_unit_price'] ?? 0,
                    'notes' => $this->nullableOrDefault($data['notes'] ?? null),
                    'sort_order' => 0,
                ]);

                $created++;
            }
        });

        fclose($handle);

        return [
            'created' => $created,
            'skipped' => count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, BoqCategory>  $cache
     */
    private function findOrCreateCategory(Project $project, string $name, array &$cache): BoqCategory
    {
        $name = trim($name);
        $key = mb_strtolower($name);

        if (! isset($cache[$key])) {
            $cache[$key] = BoqCategory::query()
                ->where('project_id', $project->id)
                ->whereRaw('LOWER(name) = ?', [$key])
                ->first() ?? BoqCategory::create([
                    'project_id' => $project->id,
                    'parent_id' => null,
                    'name' => $name,
                    'sort_order' => 0,
                ]);
        }

        return $cache[$key];
    }

    /**
     * @param  array<string, Room>  $cache
     */
    private function findOrCreateRoomId(Project $project, ?string $name, array &$cache): ?int
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $key = mb_strtolower($name);

        if (! isset($cache[$key])) {
            $cache[$key] = Room::query()
                ->where('project_id', $project->id)
                ->whereRaw('LOWER(name) = ?', [$key])
                ->first() ?? Room::create([
                    'project_id' => $project->id,
                    'name' => $name,
                    'area_m2' => null,
                    'sort_order' => 0,
                ]);
        }

        return $cache[$key]->id;
    }

    /**
     * @return array<string, string|null>
     */
    private function rowToAssoc(array $header, array $row): array
    {
        $assoc = [];

        foreach ($header as $index => $column) {
            $assoc[$column] = array_key_exists($index, $row) ? trim((string) $row[$index]) : null;
        }

        return $assoc;
    }

    private function nullableOrDefault(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
