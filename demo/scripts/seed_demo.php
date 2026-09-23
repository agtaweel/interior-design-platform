<?php

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\OrganizationMember;
use App\Models\Client;
use App\Models\Property;
use App\Models\Project;
use App\Models\Room;
use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\PricingRule;
use App\Support\Authorization\Permissions;

DB::beginTransaction();

$org = Organization::create([
    'name' => 'Nile & Co. Interiors',
    'legal_name' => 'Nile and Company for Interior Design LLC',
    'phone' => '+20 2 2735 4400',
    'email' => 'studio@nileandco.example',
    'currency' => 'EGP',
    'timezone' => 'Africa/Cairo',
]);

$ownerRole = Role::firstOrCreate(
    ['organization_id' => null, 'name' => 'Owner'],
    ['permissions_json' => array_fill_keys(Permissions::ALL, true)]
);

$user = User::firstOrCreate(
    ['email' => 'sara@nileandco.example'],
    ['name' => 'Sara Hassan', 'password' => bcrypt('password'), 'status' => 'active', 'phone' => '01098765432']
);

OrganizationMember::updateOrCreate(
    ['organization_id' => $org->id, 'user_id' => $user->id],
    ['role_id' => $ownerRole->id, 'status' => 'active']
);

$client = Client::create([
    'organization_id' => $org->id,
    'name' => 'Laila Farouk',
    'phone' => '01055512233',
    'email' => 'laila.farouk@example.com',
    'address' => 'Zamalek, Cairo',
]);

$property = Property::create([
    'organization_id' => $org->id,
    'client_id' => $client->id,
    'type' => 'apartment',
    'compound' => 'Zamalek Nile View',
    'address' => '12 Brazil Street, Zamalek, Cairo',
    'area_m2' => 210,
    'bedrooms' => 3,
    'bathrooms' => 3,
]);

$project = Project::create([
    'organization_id' => $org->id,
    'client_id' => $client->id,
    'property_id' => $property->id,
    'name' => 'Zamalek Penthouse Renovation',
    'status' => 'draft',
    'responsible_user_id' => $user->id,
    'start_date' => now()->addWeek(),
    'target_end_date' => now()->addMonths(4),
]);

// Rooms
$living = Room::create(['project_id' => $project->id, 'name' => 'Living Room', 'area_m2' => 45, 'sort_order' => 1]);
$kitchen = Room::create(['project_id' => $project->id, 'name' => 'Kitchen', 'area_m2' => 22, 'sort_order' => 2]);
$master = Room::create(['project_id' => $project->id, 'name' => 'Master Bedroom', 'area_m2' => 30, 'sort_order' => 3]);

// Categories
$flooring = BoqCategory::create(['project_id' => $project->id, 'name' => 'Flooring', 'sort_order' => 1]);
$painting = BoqCategory::create(['project_id' => $project->id, 'name' => 'Painting', 'sort_order' => 2]);
$electrical = BoqCategory::create(['project_id' => $project->id, 'name' => 'Electrical', 'sort_order' => 3]);
$kitchenCat = BoqCategory::create(['project_id' => $project->id, 'name' => 'Kitchen', 'sort_order' => 4]);

$items = [
    [$flooring, $living, 'Italian porcelain flooring', 45, 'm2', 850, 150, 0, 1400],
    [$flooring, $master, 'Engineered oak flooring', 30, 'm2', 950, 180, 0, 1600],
    [$painting, $living, 'Premium emulsion paint - feature wall', 45, 'm2', 60, 40, 0, 180],
    [$painting, $master, 'Premium emulsion paint', 30, 'm2', 55, 35, 0, 160],
    [$electrical, $living, 'Recessed LED downlight', 12, 'pcs', 180, 60, 0, 420],
    [$electrical, $kitchen, 'Under-cabinet LED strip', 8, 'm', 120, 40, 0, 260],
    [$kitchenCat, $kitchen, 'Custom kitchen cabinetry', 8, 'lm', 4200, 900, 0, 7800],
    [$kitchenCat, $kitchen, 'Quartz countertop', 8, 'm2', 1800, 350, 0, 3200],
];

foreach ($items as [$cat, $room, $name, $qty, $unit, $material, $labor, $other, $clientPrice]) {
    BoqItem::create([
        'project_id' => $project->id,
        'category_id' => $cat->id,
        'room_id' => $room->id,
        'name' => $name,
        'quantity' => $qty,
        'unit' => $unit,
        'material_unit_cost' => $material,
        'labor_unit_cost' => $labor,
        'other_unit_cost' => $other,
        'client_unit_price' => $clientPrice,
    ]);
}

// Pricing rules
PricingRule::create([
    'project_id' => $project->id,
    'name' => 'Contractor Markup',
    'type' => 'markup',
    'method' => 'percentage',
    'value' => 12,
    'base_selector' => 'boq_direct_cost',
    'sort_order' => 1,
    'active' => true,
]);

PricingRule::create([
    'project_id' => $project->id,
    'name' => 'Design & Supervision Fee',
    'type' => 'fee',
    'method' => 'percentage',
    'value' => 10,
    'base_selector' => 'boq_client_subtotal',
    'sort_order' => 2,
    'active' => true,
]);

DB::commit();

echo "org_id={$org->id} user_email={$user->email} project_id={$project->id} client_id={$client->id}\n";
