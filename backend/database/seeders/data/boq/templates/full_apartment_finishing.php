<?php

/**
 * BOQ Master Catalog + Standard Templates — the three flagship "Full Apartment Finishing"
 * templates (Standard/Premium/Luxury), fully populated per the locked content-depth decision.
 * These are three SEPARATE BoqTemplate rows (distinguished by `finishing_level`), not three
 * versions of one template — an organization can apply any of the three to a project, and
 * publishing Luxury must never make Standard unavailable, which a single-active-version-per-
 * template model would preclude.
 *
 * 'standard' is the full base item list, keyed by catalog item key (database/seeders/data/boq/catalog.php)
 * => ['quantity' => float|null, 'required' => bool]. 'premium'/'luxury' are expressed as deltas
 * over the previous tier — 'remove' drops a key entirely (typically because a higher-tier
 * catalog item replaces it), 'add_or_override' introduces a new key or replaces an existing
 * key's quantity/required spec — composed by BoqTemplateSeeder, not at runtime.
 */
return [
    'standard' => [
        'code' => 'FULL-FINISHING-STANDARD',
        'name_en' => 'Full Apartment Finishing - Standard',
        'name_ar' => 'تشطيب شقة كامل - عادي',
        'finishing_level' => 'STANDARD',
        'items' => [
            'plaster.walls.cement_plaster' => ['quantity' => 180, 'required' => true],
            'plaster.walls.corner_beads' => ['quantity' => 40, 'required' => true],
            'plaster.floors.screed' => ['quantity' => 120, 'required' => true],
            'plaster.floors.sloped_screed' => ['quantity' => 10, 'required' => true],

            'waterproofing.wet_areas.bathroom_floor' => ['quantity' => 10, 'required' => true],
            'waterproofing.wet_areas.bathroom_wall' => ['quantity' => 20, 'required' => true],
            'waterproofing.wet_areas.kitchen_floor' => ['quantity' => 10, 'required' => true],
            'waterproofing.wet_areas.balcony' => ['quantity' => 6, 'required' => true],

            'plumbing.rough_in.cold_water_point' => ['quantity' => 6, 'required' => true],
            'plumbing.rough_in.hot_water_point' => ['quantity' => 5, 'required' => true],
            'plumbing.rough_in.drainage_point' => ['quantity' => 7, 'required' => true],
            'plumbing.rough_in.floor_drain' => ['quantity' => 3, 'required' => true],
            'plumbing.rough_in.main_riser' => ['quantity' => 1, 'required' => true],
            'plumbing.rough_in.water_meter' => ['quantity' => 1, 'required' => true],
            'plumbing.fixtures.wc' => ['quantity' => 2, 'required' => true],
            'plumbing.fixtures.wash_basin' => ['quantity' => 2, 'required' => true],
            'plumbing.fixtures.basin_mixer' => ['quantity' => 2, 'required' => true],
            'plumbing.fixtures.shower_mixer' => ['quantity' => 2, 'required' => true],
            'plumbing.fixtures.kitchen_sink' => ['quantity' => 1, 'required' => true],
            'plumbing.fixtures.kitchen_mixer' => ['quantity' => 1, 'required' => true],
            'plumbing.fixtures.bidet' => ['quantity' => null, 'required' => false],
            'plumbing.fixtures.bathtub' => ['quantity' => null, 'required' => false],
            'plumbing.heating.electric_large' => ['quantity' => 1, 'required' => true],
            'plumbing.heating.electric_small' => ['quantity' => 1, 'required' => false],

            'electrical.rough_in.lighting_point' => ['quantity' => 18, 'required' => true],
            'electrical.rough_in.socket_point' => ['quantity' => 28, 'required' => true],
            'electrical.rough_in.ac_point' => ['quantity' => 4, 'required' => true],
            'electrical.rough_in.cooker_point' => ['quantity' => 1, 'required' => true],
            'electrical.rough_in.water_heater_point' => ['quantity' => 2, 'required' => true],
            'electrical.rough_in.exhaust_fan_point' => ['quantity' => 2, 'required' => true],
            'electrical.rough_in.tv_point' => ['quantity' => 2, 'required' => true],
            'electrical.rough_in.telephone_point' => ['quantity' => 1, 'required' => true],
            'electrical.distribution.board_small' => ['quantity' => 1, 'required' => true],
            'electrical.distribution.main_breaker' => ['quantity' => 1, 'required' => true],
            'electrical.distribution.earthing' => ['quantity' => 1, 'required' => true],
            'electrical.fixtures.ceiling_light' => ['quantity' => 10, 'required' => true],
            'electrical.fixtures.recessed_spotlight' => ['quantity' => 12, 'required' => true],
            'electrical.fixtures.switch_standard' => ['quantity' => 30, 'required' => true],
            'electrical.fixtures.wall_light' => ['quantity' => 4, 'required' => false],
            'electrical.fixtures.chandelier' => ['quantity' => 1, 'required' => false],
            'electrical.wiring.concealed' => ['quantity' => 250, 'required' => true],
            'electrical.testing.certification' => ['quantity' => 1, 'required' => true],

            'hvac.split_units.wiring' => ['quantity' => 4, 'required' => true],
            'hvac.split_units.unit_15hp' => ['quantity' => 3, 'required' => false],
            'hvac.ventilation.bathroom_fan' => ['quantity' => 2, 'required' => true],
            'hvac.ventilation.kitchen_hood' => ['quantity' => 1, 'required' => true],

            'flooring.tiles.porcelain_60' => ['quantity' => 90, 'required' => true],
            'flooring.tiles.anti_slip' => ['quantity' => 10, 'required' => true],
            'flooring.tiles.ceramic_standard' => ['quantity' => 10, 'required' => true],
            'flooring.skirting.ceramic' => ['quantity' => 85, 'required' => true],
            'flooring.outdoor.tile' => ['quantity' => 6, 'required' => true],

            'wall_finishes.tiles.bathroom' => ['quantity' => 36, 'required' => true],
            'wall_finishes.tiles.kitchen_backsplash' => ['quantity' => 6, 'required' => true],

            'paint.walls.putty_primer' => ['quantity' => 300, 'required' => true],
            'paint.walls.standard' => ['quantity' => 220, 'required' => true],
            'paint.ceiling.standard' => ['quantity' => 110, 'required' => true],
            'paint.metal.door_window' => ['quantity' => 20, 'required' => false],

            'gypsum.flat_ceiling.flat' => ['quantity' => 40, 'required' => false],
            'gypsum.flat_ceiling.cove_lighting' => ['quantity' => 15, 'required' => false],

            'doors_joinery.internal_doors.standard' => ['quantity' => 7, 'required' => true],
            'doors_joinery.internal_doors.frame' => ['quantity' => 7, 'required' => true],
            'doors_joinery.internal_doors.hardware' => ['quantity' => 7, 'required' => true],
            'doors_joinery.internal_doors.main_entrance' => ['quantity' => 1, 'required' => true],
            'doors_joinery.wardrobes.built_in' => ['quantity' => 12, 'required' => true],
            'doors_joinery.carpentry.window_sill' => ['quantity' => 10, 'required' => true],

            'kitchen.cabinets.base' => ['quantity' => 4, 'required' => true],
            'kitchen.cabinets.wall' => ['quantity' => 3, 'required' => true],
            'kitchen.cabinets.tall_unit' => ['quantity' => 1, 'required' => true],
            'kitchen.countertop.granite' => ['quantity' => 4, 'required' => true],
            'kitchen.accessories.handles' => ['quantity' => 20, 'required' => true],

            'aluminum_glass.windows.sliding' => ['quantity' => 24, 'required' => true],
            'aluminum_glass.doors.balcony' => ['quantity' => 6, 'required' => true],
            'aluminum_glass.shower.screen' => ['quantity' => 4, 'required' => true],
            'aluminum_glass.mirrors.bathroom' => ['quantity' => 2, 'required' => true],

            'low_current.data.point' => ['quantity' => 3, 'required' => true],
            'low_current.security.video_intercom' => ['quantity' => 1, 'required' => true],

            'finalization.cleaning.deep_clean' => ['quantity' => 120, 'required' => true],
            'finalization.cleaning.glass' => ['quantity' => 24, 'required' => true],
            'finalization.snagging.rectification' => ['quantity' => 1, 'required' => true],
            'finalization.handover.inspection' => ['quantity' => 1, 'required' => true],
            'finalization.handover.as_built' => ['quantity' => 1, 'required' => true],
            'finalization.protection_removal.removal' => ['quantity' => 1, 'required' => true],
        ],
    ],

    'premium' => [
        'code' => 'FULL-FINISHING-PREMIUM',
        'name_en' => 'Full Apartment Finishing - Premium',
        'name_ar' => 'تشطيب شقة كامل - متميز',
        'finishing_level' => 'PREMIUM',
        'remove' => [
            'flooring.tiles.porcelain_60', 'paint.walls.standard', 'electrical.fixtures.switch_standard',
            'kitchen.countertop.granite', 'doors_joinery.internal_doors.standard',
            'aluminum_glass.windows.sliding', 'plumbing.fixtures.wc',
        ],
        'add_or_override' => [
            'flooring.tiles.porcelain_80' => ['quantity' => 90, 'required' => true],
            'flooring.natural_stone.marble' => ['quantity' => 15, 'required' => true],
            'wall_finishes.tiles.feature_wall' => ['quantity' => 10, 'required' => true],
            'wall_finishes.cladding.3d_panel' => ['quantity' => 8, 'required' => false],
            'gypsum.flat_ceiling.flat' => ['quantity' => 50, 'required' => true],
            'gypsum.flat_ceiling.cove_lighting' => ['quantity' => 20, 'required' => true],
            'gypsum.flat_ceiling.bulkhead' => ['quantity' => 10, 'required' => true],
            'paint.walls.premium' => ['quantity' => 220, 'required' => true],
            'paint.walls.decorative' => ['quantity' => 15, 'required' => false],
            'electrical.fixtures.switch_premium' => ['quantity' => 30, 'required' => true],
            'electrical.fixtures.wall_light' => ['quantity' => 6, 'required' => true],
            'low_current.data.wifi_ap' => ['quantity' => 1, 'required' => true],
            'low_current.security.cctv_indoor' => ['quantity' => 2, 'required' => false],
            'hvac.split_units.unit_2hp' => ['quantity' => 1, 'required' => false],
            'kitchen.countertop.quartz' => ['quantity' => 4, 'required' => true],
            'kitchen.cabinets.base' => ['quantity' => 5, 'required' => true],
            'kitchen.cabinets.wall' => ['quantity' => 4, 'required' => true],
            'doors_joinery.internal_doors.premium' => ['quantity' => 7, 'required' => true],
            'aluminum_glass.windows.upvc' => ['quantity' => 24, 'required' => true],
            'aluminum_glass.shower.cabin' => ['quantity' => 1, 'required' => false],
            'aluminum_glass.mirrors.decorative' => ['quantity' => 4, 'required' => false],
            'plumbing.fixtures.wall_hung_wc' => ['quantity' => 2, 'required' => true],
            'plumbing.fixtures.bathtub' => ['quantity' => 1, 'required' => true],
            'plumbing.heating.electric_small' => ['quantity' => 1, 'required' => true],
        ],
    ],

    'luxury' => [
        'code' => 'FULL-FINISHING-LUXURY',
        'name_en' => 'Full Apartment Finishing - Luxury',
        'name_ar' => 'تشطيب شقة كامل - فاخر',
        'finishing_level' => 'LUXURY',
        'remove' => [
            'flooring.tiles.porcelain_80',
        ],
        'add_or_override' => [
            'flooring.natural_stone.marble' => ['quantity' => 105, 'required' => true],
            'flooring.wood.solid_parquet' => ['quantity' => 36, 'required' => false],
            'wall_finishes.cladding.stone' => ['quantity' => 12, 'required' => true],
            'wall_finishes.cladding.3d_panel' => ['quantity' => 10, 'required' => true],
            'doors_joinery.wardrobes.walk_in_closet' => ['quantity' => 1, 'required' => false],
            'low_current.smart_home.smart_switch' => ['quantity' => 10, 'required' => true],
            'low_current.smart_home.thermostat' => ['quantity' => 2, 'required' => true],
            'low_current.smart_home.curtain_motor' => ['quantity' => 20, 'required' => false],
            'low_current.smart_home.automation_hub' => ['quantity' => 1, 'required' => true],
            'low_current.security.access_control' => ['quantity' => 1, 'required' => false],
            'low_current.audio_visual.home_theater_wiring' => ['quantity' => 1, 'required' => false],
            'low_current.audio_visual.ceiling_speaker' => ['quantity' => 6, 'required' => false],
            'aluminum_glass.shower.cabin' => ['quantity' => 2, 'required' => true],
            'plumbing.fixtures.bidet' => ['quantity' => 2, 'required' => true],
            'kitchen.cabinets.tall_unit' => ['quantity' => 2, 'required' => true],
            'kitchen.accessories.drawer_organizer' => ['quantity' => 6, 'required' => true],
            'kitchen.accessories.pull_out_basket' => ['quantity' => 4, 'required' => true],
            'electrical.fixtures.chandelier' => ['quantity' => 2, 'required' => true],
            'gypsum.flat_ceiling.bulkhead' => ['quantity' => 20, 'required' => true],
        ],
    ],
];
