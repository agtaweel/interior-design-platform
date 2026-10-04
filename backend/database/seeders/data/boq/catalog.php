<?php

/**
 * BOQ Master Catalog + Standard Templates — the system (organization_id = null) catalog
 * taxonomy: 16 trade categories, each with 1-5 subcategories, each with a handful of items.
 * Transcribed from the product spec's own trade breakdown (demolition through finalization) so
 * the Standard/Premium/Luxury templates and every lighter template/room/package can reference
 * real, consistently-keyed items rather than inventing ad hoc names per template.
 *
 * Every item/category key is a stable, hand-authored string (never a DB id) so template data
 * files below can reference catalog items by key — BoqMasterCatalogSeeder resolves keys to ids
 * and returns the map for BoqTemplateSeeder to consume. Costs are plausible EGP placeholder
 * figures, not researched market rates — per the locked "non-binding suggestion" decision, a
 * template's/catalog item's cost is only ever a starting default, never authoritative (the
 * Project BOQ item the engineer actually edits is the sole source of truth).
 */
return [
    'demolition' => [
        'name_en' => 'Demolition & Prep', 'name_ar' => 'الهدم والتجهيز',
        'subcategories' => [
            'demolition.removal' => [
                'name_en' => 'Removal & Demolition', 'name_ar' => 'أعمال الهدم والإزالة',
                'items' => [
                    'demolition.removal.block_wall' => ['name_en' => 'Demolish Block Wall', 'name_ar' => 'هدم حائط طوب', 'unit' => 'm2', 'material' => 0, 'labor' => 60, 'other' => 10, 'client' => 120],
                    'demolition.removal.partition' => ['name_en' => 'Demolish Light Partition', 'name_ar' => 'هدم قاطع خفيف', 'unit' => 'm2', 'material' => 0, 'labor' => 40, 'other' => 5, 'client' => 90],
                    'demolition.removal.flooring' => ['name_en' => 'Remove Existing Flooring', 'name_ar' => 'إزالة الأرضيات الحالية', 'unit' => 'm2', 'material' => 0, 'labor' => 35, 'other' => 5, 'client' => 70],
                    'demolition.removal.wall_tiles' => ['name_en' => 'Remove Existing Wall Tiles', 'name_ar' => 'إزالة بلاط الحوائط الحالي', 'unit' => 'm2', 'material' => 0, 'labor' => 40, 'other' => 5, 'client' => 80],
                    'demolition.removal.false_ceiling' => ['name_en' => 'Remove Existing False Ceiling', 'name_ar' => 'إزالة السقف المستعار الحالي', 'unit' => 'm2', 'material' => 0, 'labor' => 30, 'other' => 5, 'client' => 60],
                    'demolition.removal.doors_windows' => ['name_en' => 'Remove Existing Doors/Windows', 'name_ar' => 'إزالة الأبواب والشبابيك الحالية', 'unit' => 'pcs', 'material' => 0, 'labor' => 150, 'other' => 20, 'client' => 250],
                    'demolition.removal.sanitary_fixtures' => ['name_en' => 'Remove Existing Sanitary Fixtures', 'name_ar' => 'إزالة الأدوات الصحية الحالية', 'unit' => 'pcs', 'material' => 0, 'labor' => 100, 'other' => 15, 'client' => 180],
                    'demolition.removal.debris' => ['name_en' => 'Debris Removal & Disposal', 'name_ar' => 'نقل وتصريف المخلفات', 'unit' => 'm3', 'material' => 0, 'labor' => 200, 'other' => 50, 'client' => 350],
                ],
            ],
            'demolition.protection' => [
                'name_en' => 'Protection & Site Prep', 'name_ar' => 'الحماية وتجهيز الموقع',
                'items' => [
                    'demolition.protection.floor_protection' => ['name_en' => 'Floor Protection (Corrugated/Plywood)', 'name_ar' => 'حماية الأرضيات', 'unit' => 'm2', 'material' => 25, 'labor' => 10, 'other' => 0, 'client' => 55],
                    'demolition.protection.site_cleaning' => ['name_en' => 'Initial Site Cleaning', 'name_ar' => 'تنظيف الموقع المبدئي', 'unit' => 'ls', 'material' => 200, 'labor' => 1500, 'other' => 0, 'client' => 2500],
                ],
            ],
        ],
    ],

    'masonry' => [
        'name_en' => 'Masonry & Block Work', 'name_ar' => 'المباني والطوب',
        'subcategories' => [
            'masonry.walls' => [
                'name_en' => 'Walls & Partitions', 'name_ar' => 'الحوائط والقواطيع',
                'items' => [
                    'masonry.walls.block_12' => ['name_en' => 'Red Brick Wall 12cm', 'name_ar' => 'حائط طوب أحمر ١٢سم', 'unit' => 'm2', 'material' => 90, 'labor' => 60, 'other' => 0, 'client' => 220],
                    'masonry.walls.block_25' => ['name_en' => 'Red Brick Wall 25cm', 'name_ar' => 'حائط طوب أحمر ٢٥سم', 'unit' => 'm2', 'material' => 170, 'labor' => 90, 'other' => 0, 'client' => 380],
                    'masonry.walls.lightweight_partition' => ['name_en' => 'Lightweight Block Partition', 'name_ar' => 'قاطع طوب خفيف', 'unit' => 'm2', 'material' => 110, 'labor' => 70, 'other' => 0, 'client' => 260],
                    'masonry.walls.lintel' => ['name_en' => 'Reinforced Concrete Lintel', 'name_ar' => 'كمرة خرسانية مسلحة', 'unit' => 'm', 'material' => 80, 'labor' => 50, 'other' => 0, 'client' => 180],
                ],
            ],
            'masonry.openings' => [
                'name_en' => 'Openings Prep', 'name_ar' => 'تجهيز الفتحات',
                'items' => [
                    'masonry.openings.door_prep' => ['name_en' => 'Door Opening Preparation', 'name_ar' => 'تجهيز فتحة باب', 'unit' => 'pcs', 'material' => 0, 'labor' => 100, 'other' => 10, 'client' => 180],
                    'masonry.openings.window_prep' => ['name_en' => 'Window Opening Preparation', 'name_ar' => 'تجهيز فتحة شباك', 'unit' => 'pcs', 'material' => 0, 'labor' => 120, 'other' => 10, 'client' => 200],
                ],
            ],
        ],
    ],

    'plaster' => [
        'name_en' => 'Plaster & Screed', 'name_ar' => 'البياض والمحارة',
        'subcategories' => [
            'plaster.walls' => [
                'name_en' => 'Wall Plaster', 'name_ar' => 'بياض الحوائط',
                'items' => [
                    'plaster.walls.cement_plaster' => ['name_en' => 'Cement Sand Plaster - Walls', 'name_ar' => 'بياض أسمنتي للحوائط', 'unit' => 'm2', 'material' => 35, 'labor' => 45, 'other' => 0, 'client' => 120],
                    'plaster.walls.gypsum_plaster' => ['name_en' => 'Gypsum Plaster - Walls', 'name_ar' => 'بياض جبسي للحوائط', 'unit' => 'm2', 'material' => 40, 'labor' => 40, 'other' => 0, 'client' => 115],
                    'plaster.walls.corner_beads' => ['name_en' => 'Plaster Corner Beads', 'name_ar' => 'زاوية بياض معدنية', 'unit' => 'm', 'material' => 8, 'labor' => 7, 'other' => 0, 'client' => 25],
                ],
            ],
            'plaster.floors' => [
                'name_en' => 'Floor Screed', 'name_ar' => 'محارة الأرضيات',
                'items' => [
                    'plaster.floors.screed' => ['name_en' => 'Floor Screed (Sand/Cement)', 'name_ar' => 'محارة أرضية رملية أسمنتية', 'unit' => 'm2', 'material' => 45, 'labor' => 35, 'other' => 0, 'client' => 130],
                    'plaster.floors.sloped_screed' => ['name_en' => 'Sloped Screed - Wet Areas', 'name_ar' => 'محارة ميول للأماكن الرطبة', 'unit' => 'm2', 'material' => 55, 'labor' => 45, 'other' => 0, 'client' => 150],
                ],
            ],
        ],
    ],

    'waterproofing' => [
        'name_en' => 'Waterproofing', 'name_ar' => 'العزل المائي',
        'subcategories' => [
            'waterproofing.wet_areas' => [
                'name_en' => 'Wet Areas', 'name_ar' => 'الأماكن الرطبة',
                'items' => [
                    'waterproofing.wet_areas.bathroom_floor' => ['name_en' => 'Waterproofing - Bathroom Floor', 'name_ar' => 'عزل مائي - أرضية الحمام', 'unit' => 'm2', 'material' => 90, 'labor' => 50, 'other' => 0, 'client' => 220],
                    'waterproofing.wet_areas.bathroom_wall' => ['name_en' => 'Waterproofing - Bathroom Wall (1m height)', 'name_ar' => 'عزل مائي - حائط الحمام', 'unit' => 'm2', 'material' => 70, 'labor' => 40, 'other' => 0, 'client' => 180],
                    'waterproofing.wet_areas.kitchen_floor' => ['name_en' => 'Waterproofing - Kitchen Floor', 'name_ar' => 'عزل مائي - أرضية المطبخ', 'unit' => 'm2', 'material' => 90, 'labor' => 50, 'other' => 0, 'client' => 220],
                    'waterproofing.wet_areas.balcony' => ['name_en' => 'Waterproofing - Balcony/Terrace', 'name_ar' => 'عزل مائي - البلكونة', 'unit' => 'm2', 'material' => 100, 'labor' => 55, 'other' => 0, 'client' => 240],
                ],
            ],
            'waterproofing.roof' => [
                'name_en' => 'Roof Insulation', 'name_ar' => 'عزل الأسطح',
                'items' => [
                    'waterproofing.roof.membrane' => ['name_en' => 'Roof Waterproofing Membrane', 'name_ar' => 'عزل مائي للسطح', 'unit' => 'm2', 'material' => 120, 'labor' => 60, 'other' => 0, 'client' => 280],
                    'waterproofing.roof.thermal' => ['name_en' => 'Thermal + Water Insulation - Roof', 'name_ar' => 'عزل حراري ومائي للسطح', 'unit' => 'm2', 'material' => 150, 'labor' => 70, 'other' => 0, 'client' => 320],
                ],
            ],
        ],
    ],

    'plumbing' => [
        'name_en' => 'Plumbing', 'name_ar' => 'السباكة',
        'subcategories' => [
            'plumbing.rough_in' => [
                'name_en' => 'Rough-In', 'name_ar' => 'التمديدات',
                'items' => [
                    'plumbing.rough_in.cold_water_point' => ['name_en' => 'Cold Water Point', 'name_ar' => 'نقطة مياه باردة', 'unit' => 'point', 'material' => 150, 'labor' => 100, 'other' => 0, 'client' => 400],
                    'plumbing.rough_in.hot_water_point' => ['name_en' => 'Hot Water Point', 'name_ar' => 'نقطة مياه ساخنة', 'unit' => 'point', 'material' => 180, 'labor' => 110, 'other' => 0, 'client' => 450],
                    'plumbing.rough_in.drainage_point' => ['name_en' => 'Drainage Point', 'name_ar' => 'نقطة صرف', 'unit' => 'point', 'material' => 200, 'labor' => 120, 'other' => 0, 'client' => 480],
                    'plumbing.rough_in.floor_drain' => ['name_en' => 'Floor Drain Installation', 'name_ar' => 'تركيب بالوعة أرضية', 'unit' => 'pcs', 'material' => 90, 'labor' => 60, 'other' => 0, 'client' => 220],
                    'plumbing.rough_in.main_riser' => ['name_en' => 'Main Riser Connection', 'name_ar' => 'توصيل الطرد الرئيسي', 'unit' => 'pcs', 'material' => 250, 'labor' => 150, 'other' => 0, 'client' => 600],
                    'plumbing.rough_in.water_meter' => ['name_en' => 'Water Meter Installation', 'name_ar' => 'تركيب عداد مياه', 'unit' => 'pcs', 'material' => 300, 'labor' => 100, 'other' => 0, 'client' => 550],
                ],
            ],
            'plumbing.fixtures' => [
                'name_en' => 'Sanitary Fixtures', 'name_ar' => 'الأدوات الصحية',
                'items' => [
                    'plumbing.fixtures.wc' => ['name_en' => 'WC Installation (Supply + Fit)', 'name_ar' => 'تركيب كرسي حمام', 'unit' => 'pcs', 'material' => 1800, 'labor' => 300, 'other' => 0, 'client' => 3200],
                    'plumbing.fixtures.wall_hung_wc' => ['name_en' => 'Wall-Hung WC + Concealed Cistern', 'name_ar' => 'كرسي حمام معلق بسيفون مخفي', 'unit' => 'pcs', 'material' => 3200, 'labor' => 500, 'other' => 0, 'client' => 5500],
                    'plumbing.fixtures.wash_basin' => ['name_en' => 'Wash Basin + Pedestal', 'name_ar' => 'حوض غسيل مع قاعدة', 'unit' => 'pcs', 'material' => 1200, 'labor' => 200, 'other' => 0, 'client' => 2200],
                    'plumbing.fixtures.basin_mixer' => ['name_en' => 'Basin Mixer Tap', 'name_ar' => 'خلاط حوض', 'unit' => 'pcs', 'material' => 600, 'labor' => 100, 'other' => 0, 'client' => 1100],
                    'plumbing.fixtures.shower_mixer' => ['name_en' => 'Shower Mixer Set', 'name_ar' => 'طقم خلاط دش', 'unit' => 'pcs', 'material' => 1400, 'labor' => 200, 'other' => 0, 'client' => 2500],
                    'plumbing.fixtures.bathtub' => ['name_en' => 'Bathtub Installation', 'name_ar' => 'تركيب بانيو', 'unit' => 'pcs', 'material' => 4500, 'labor' => 600, 'other' => 0, 'client' => 7500],
                    'plumbing.fixtures.kitchen_sink' => ['name_en' => 'Kitchen Sink (Stainless Steel)', 'name_ar' => 'حوض مطبخ استانلس', 'unit' => 'pcs', 'material' => 1500, 'labor' => 200, 'other' => 0, 'client' => 2600],
                    'plumbing.fixtures.kitchen_mixer' => ['name_en' => 'Kitchen Mixer Tap', 'name_ar' => 'خلاط مطبخ', 'unit' => 'pcs', 'material' => 700, 'labor' => 100, 'other' => 0, 'client' => 1300],
                    'plumbing.fixtures.bidet' => ['name_en' => 'Bidet Installation', 'name_ar' => 'تركيب شطاف', 'unit' => 'pcs', 'material' => 1600, 'labor' => 250, 'other' => 0, 'client' => 2800],
                ],
            ],
            'plumbing.heating' => [
                'name_en' => 'Water Heating', 'name_ar' => 'تسخين المياه',
                'items' => [
                    'plumbing.heating.electric_small' => ['name_en' => 'Electric Water Heater - 30L', 'name_ar' => 'سخان كهربائي ٣٠ لتر', 'unit' => 'pcs', 'material' => 1800, 'labor' => 200, 'other' => 0, 'client' => 2800],
                    'plumbing.heating.electric_large' => ['name_en' => 'Electric Water Heater - 100L', 'name_ar' => 'سخان كهربائي ١٠٠ لتر', 'unit' => 'pcs', 'material' => 3200, 'labor' => 300, 'other' => 0, 'client' => 4800],
                    'plumbing.heating.gas' => ['name_en' => 'Gas Water Heater', 'name_ar' => 'سخان غاز', 'unit' => 'pcs', 'material' => 4500, 'labor' => 400, 'other' => 0, 'client' => 6500],
                ],
            ],
            'plumbing.drainage' => [
                'name_en' => 'Drainage', 'name_ar' => 'الصرف',
                'items' => [
                    'plumbing.drainage.floor_trap' => ['name_en' => 'Floor Trap Installation', 'name_ar' => 'تركيب مصيدة أرضية', 'unit' => 'pcs', 'material' => 120, 'labor' => 60, 'other' => 0, 'client' => 260],
                    'plumbing.drainage.vent_pipe' => ['name_en' => 'Vent Pipe Installation', 'name_ar' => 'تركيب ماسورة تهوية', 'unit' => 'm', 'material' => 80, 'labor' => 50, 'other' => 0, 'client' => 180],
                ],
            ],
        ],
    ],

    'electrical' => [
        'name_en' => 'Electrical', 'name_ar' => 'الكهرباء',
        'subcategories' => [
            'electrical.rough_in' => [
                'name_en' => 'Rough-In', 'name_ar' => 'التمديدات',
                'items' => [
                    'electrical.rough_in.lighting_point' => ['name_en' => 'Lighting Point', 'name_ar' => 'نقطة إنارة', 'unit' => 'point', 'material' => 120, 'labor' => 80, 'other' => 0, 'client' => 320],
                    'electrical.rough_in.socket_point' => ['name_en' => 'Socket Point (13A)', 'name_ar' => 'نقطة فيشة ١٣ أمبير', 'unit' => 'point', 'material' => 100, 'labor' => 70, 'other' => 0, 'client' => 280],
                    'electrical.rough_in.ac_point' => ['name_en' => 'AC Power Point (20A)', 'name_ar' => 'نقطة كهرباء تكييف', 'unit' => 'point', 'material' => 180, 'labor' => 110, 'other' => 0, 'client' => 420],
                    'electrical.rough_in.cooker_point' => ['name_en' => 'Cooker Power Point', 'name_ar' => 'نقطة كهرباء بوتاجاز', 'unit' => 'point', 'material' => 200, 'labor' => 120, 'other' => 0, 'client' => 450],
                    'electrical.rough_in.water_heater_point' => ['name_en' => 'Water Heater Power Point', 'name_ar' => 'نقطة كهرباء سخان', 'unit' => 'point', 'material' => 180, 'labor' => 110, 'other' => 0, 'client' => 400],
                    'electrical.rough_in.exhaust_fan_point' => ['name_en' => 'Exhaust Fan Point', 'name_ar' => 'نقطة شفاط هواء', 'unit' => 'point', 'material' => 130, 'labor' => 80, 'other' => 0, 'client' => 300],
                    'electrical.rough_in.tv_point' => ['name_en' => 'TV Point (Power + Coax)', 'name_ar' => 'نقطة تليفزيون', 'unit' => 'point', 'material' => 150, 'labor' => 90, 'other' => 0, 'client' => 350],
                    'electrical.rough_in.telephone_point' => ['name_en' => 'Telephone/Intercom Point', 'name_ar' => 'نقطة تليفون/إنتركم', 'unit' => 'point', 'material' => 110, 'labor' => 70, 'other' => 0, 'client' => 260],
                ],
            ],
            'electrical.distribution' => [
                'name_en' => 'Distribution', 'name_ar' => 'التوزيع',
                'items' => [
                    'electrical.distribution.board_small' => ['name_en' => 'Distribution Board - Small (8-way)', 'name_ar' => 'لوحة توزيع صغيرة', 'unit' => 'pcs', 'material' => 1200, 'labor' => 300, 'other' => 0, 'client' => 2200],
                    'electrical.distribution.board_large' => ['name_en' => 'Distribution Board - Large (18-way)', 'name_ar' => 'لوحة توزيع كبيرة', 'unit' => 'pcs', 'material' => 2500, 'labor' => 500, 'other' => 0, 'client' => 4200],
                    'electrical.distribution.main_breaker' => ['name_en' => 'Main Breaker Installation', 'name_ar' => 'تركيب قاطع رئيسي', 'unit' => 'pcs', 'material' => 800, 'labor' => 200, 'other' => 0, 'client' => 1500],
                    'electrical.distribution.earthing' => ['name_en' => 'Earthing System Installation', 'name_ar' => 'تركيب منظومة التأريض', 'unit' => 'ls', 'material' => 1500, 'labor' => 500, 'other' => 0, 'client' => 3200],
                ],
            ],
            'electrical.fixtures' => [
                'name_en' => 'Fixtures', 'name_ar' => 'التجهيزات',
                'items' => [
                    'electrical.fixtures.ceiling_light' => ['name_en' => 'Ceiling Light Fixture (Supply+Fit)', 'name_ar' => 'تركيب لمبة سقف', 'unit' => 'pcs', 'material' => 450, 'labor' => 100, 'other' => 0, 'client' => 900],
                    'electrical.fixtures.wall_light' => ['name_en' => 'Wall Light Fixture (Supply+Fit)', 'name_ar' => 'تركيب لمبة حائط', 'unit' => 'pcs', 'material' => 400, 'labor' => 90, 'other' => 0, 'client' => 800],
                    'electrical.fixtures.recessed_spotlight' => ['name_en' => 'Recessed LED Spotlight', 'name_ar' => 'سبوت لايت مدفون', 'unit' => 'pcs', 'material' => 180, 'labor' => 60, 'other' => 0, 'client' => 400],
                    'electrical.fixtures.chandelier' => ['name_en' => 'Chandelier Installation', 'name_ar' => 'تركيب نجفة', 'unit' => 'pcs', 'material' => 1500, 'labor' => 300, 'other' => 0, 'client' => 2800],
                    'electrical.fixtures.switch_standard' => ['name_en' => 'Standard Switch/Socket Plate', 'name_ar' => 'كبك عادي', 'unit' => 'pcs', 'material' => 40, 'labor' => 20, 'other' => 0, 'client' => 100],
                    'electrical.fixtures.switch_premium' => ['name_en' => 'Premium Switch/Socket Plate', 'name_ar' => 'كبك فاخر', 'unit' => 'pcs', 'material' => 120, 'labor' => 20, 'other' => 0, 'client' => 220],
                ],
            ],
            'electrical.wiring' => [
                'name_en' => 'Wiring', 'name_ar' => 'التوصيلات',
                'items' => [
                    'electrical.wiring.concealed' => ['name_en' => 'Concealed Wiring Conduit', 'name_ar' => 'مواسير كهرباء مدفونة', 'unit' => 'm', 'material' => 25, 'labor' => 20, 'other' => 0, 'client' => 65],
                    'electrical.wiring.cable_tray' => ['name_en' => 'Cable Tray Installation', 'name_ar' => 'تركيب مجرى كابلات', 'unit' => 'm', 'material' => 60, 'labor' => 35, 'other' => 0, 'client' => 140],
                ],
            ],
            'electrical.testing' => [
                'name_en' => 'Testing & Certification', 'name_ar' => 'الاختبار والاعتماد',
                'items' => [
                    'electrical.testing.certification' => ['name_en' => 'Electrical Testing & Certification', 'name_ar' => 'اختبار واعتماد كهربائي', 'unit' => 'ls', 'material' => 0, 'labor' => 1500, 'other' => 0, 'client' => 2800],
                ],
            ],
        ],
    ],

    'hvac' => [
        'name_en' => 'HVAC', 'name_ar' => 'التكييف والتهوية',
        'subcategories' => [
            'hvac.split_units' => [
                'name_en' => 'Split Units', 'name_ar' => 'وحدات سبليت',
                'items' => [
                    'hvac.split_units.wiring' => ['name_en' => 'Split AC Dedicated Wiring + Drain', 'name_ar' => 'تمديد كهرباء ومواسير تكييف سبليت', 'unit' => 'point', 'material' => 250, 'labor' => 150, 'other' => 0, 'client' => 550],
                    'hvac.split_units.unit_15hp' => ['name_en' => 'Split AC Unit - 1.5HP (Supply+Install)', 'name_ar' => 'تكييف سبليت ١.٥ حصان', 'unit' => 'pcs', 'material' => 9500, 'labor' => 800, 'other' => 0, 'client' => 13500],
                    'hvac.split_units.unit_2hp' => ['name_en' => 'Split AC Unit - 2.25HP (Supply+Install)', 'name_ar' => 'تكييف سبليت ٢.٢٥ حصان', 'unit' => 'pcs', 'material' => 13500, 'labor' => 1000, 'other' => 0, 'client' => 18500],
                ],
            ],
            'hvac.ducting' => [
                'name_en' => 'Ducting', 'name_ar' => 'الدكتات',
                'items' => [
                    'hvac.ducting.central_ac' => ['name_en' => 'Central AC Ducting per Outlet', 'name_ar' => 'دكت تكييف مركزي لكل مخرج', 'unit' => 'point', 'material' => 600, 'labor' => 300, 'other' => 0, 'client' => 1300],
                    'hvac.ducting.diffuser' => ['name_en' => 'Diffuser/Grille Installation', 'name_ar' => 'تركيب فتحة تكييف', 'unit' => 'pcs', 'material' => 250, 'labor' => 80, 'other' => 0, 'client' => 500],
                ],
            ],
            'hvac.ventilation' => [
                'name_en' => 'Ventilation', 'name_ar' => 'التهوية',
                'items' => [
                    'hvac.ventilation.kitchen_hood' => ['name_en' => 'Kitchen Exhaust Hood Ducting', 'name_ar' => 'دكت شفاط المطبخ', 'unit' => 'ls', 'material' => 800, 'labor' => 400, 'other' => 0, 'client' => 1800],
                    'hvac.ventilation.bathroom_fan' => ['name_en' => 'Bathroom Exhaust Fan (Supply+Install)', 'name_ar' => 'شفاط حمام', 'unit' => 'pcs', 'material' => 450, 'labor' => 150, 'other' => 0, 'client' => 900],
                    'hvac.ventilation.fresh_air' => ['name_en' => 'Fresh Air Ventilation Unit', 'name_ar' => 'وحدة تهوية هواء نقي', 'unit' => 'pcs', 'material' => 2200, 'labor' => 400, 'other' => 0, 'client' => 3800],
                ],
            ],
        ],
    ],

    'flooring' => [
        'name_en' => 'Flooring', 'name_ar' => 'الأرضيات',
        'subcategories' => [
            'flooring.tiles' => [
                'name_en' => 'Ceramic & Porcelain Tiles', 'name_ar' => 'بلاط السيراميك والبورسلين',
                'items' => [
                    'flooring.tiles.porcelain_60' => ['name_en' => 'Porcelain Tile 60x60cm', 'name_ar' => 'بورسلين ٦٠×٦٠', 'unit' => 'm2', 'material' => 280, 'labor' => 110, 'other' => 0, 'client' => 580],
                    'flooring.tiles.porcelain_80' => ['name_en' => 'Porcelain Tile 80x80cm', 'name_ar' => 'بورسلين ٨٠×٨٠', 'unit' => 'm2', 'material' => 380, 'labor' => 130, 'other' => 0, 'client' => 750],
                    'flooring.tiles.ceramic_standard' => ['name_en' => 'Ceramic Floor Tile - Standard', 'name_ar' => 'سيراميك أرضيات عادي', 'unit' => 'm2', 'material' => 150, 'labor' => 90, 'other' => 0, 'client' => 380],
                    'flooring.tiles.anti_slip' => ['name_en' => 'Anti-Slip Bathroom Floor Tile', 'name_ar' => 'بلاط حمام مانع انزلاق', 'unit' => 'm2', 'material' => 220, 'labor' => 100, 'other' => 0, 'client' => 480],
                ],
            ],
            'flooring.natural_stone' => [
                'name_en' => 'Natural Stone', 'name_ar' => 'الأحجار الطبيعية',
                'items' => [
                    'flooring.natural_stone.marble' => ['name_en' => 'Natural Marble Flooring', 'name_ar' => 'أرضية رخام طبيعي', 'unit' => 'm2', 'material' => 650, 'labor' => 200, 'other' => 0, 'client' => 1300],
                    'flooring.natural_stone.granite' => ['name_en' => 'Granite Flooring', 'name_ar' => 'أرضية جرانيت', 'unit' => 'm2', 'material' => 750, 'labor' => 220, 'other' => 0, 'client' => 1500],
                ],
            ],
            'flooring.wood' => [
                'name_en' => 'Wood Flooring', 'name_ar' => 'أرضيات خشبية',
                'items' => [
                    'flooring.wood.laminate' => ['name_en' => 'Laminate Wood Flooring', 'name_ar' => 'أرضية لامينيت', 'unit' => 'm2', 'material' => 350, 'labor' => 120, 'other' => 0, 'client' => 700],
                    'flooring.wood.engineered' => ['name_en' => 'Engineered Wood Flooring', 'name_ar' => 'أرضية خشب هندسي', 'unit' => 'm2', 'material' => 650, 'labor' => 180, 'other' => 0, 'client' => 1250],
                    'flooring.wood.solid_parquet' => ['name_en' => 'Solid Wood Parquet', 'name_ar' => 'باركيه خشب طبيعي', 'unit' => 'm2', 'material' => 950, 'labor' => 250, 'other' => 0, 'client' => 1800],
                ],
            ],
            'flooring.skirting' => [
                'name_en' => 'Skirting', 'name_ar' => 'الوزرة',
                'items' => [
                    'flooring.skirting.ceramic' => ['name_en' => 'Ceramic Skirting', 'name_ar' => 'وزرة سيراميك', 'unit' => 'm', 'material' => 45, 'labor' => 25, 'other' => 0, 'client' => 110],
                    'flooring.skirting.marble' => ['name_en' => 'Marble Skirting', 'name_ar' => 'وزرة رخام', 'unit' => 'm', 'material' => 90, 'labor' => 35, 'other' => 0, 'client' => 190],
                    'flooring.skirting.wood' => ['name_en' => 'Wood Skirting', 'name_ar' => 'وزرة خشب', 'unit' => 'm', 'material' => 70, 'labor' => 30, 'other' => 0, 'client' => 160],
                ],
            ],
            'flooring.outdoor' => [
                'name_en' => 'Outdoor Flooring', 'name_ar' => 'الأرضيات الخارجية',
                'items' => [
                    'flooring.outdoor.tile' => ['name_en' => 'Outdoor Anti-Slip Tile', 'name_ar' => 'بلاط خارجي مانع انزلاق', 'unit' => 'm2', 'material' => 260, 'labor' => 110, 'other' => 0, 'client' => 540],
                ],
            ],
        ],
    ],

    'wall_finishes' => [
        'name_en' => 'Wall Finishes', 'name_ar' => 'تشطيبات الحوائط',
        'subcategories' => [
            'wall_finishes.tiles' => [
                'name_en' => 'Wall Tiles', 'name_ar' => 'بلاط الحوائط',
                'items' => [
                    'wall_finishes.tiles.bathroom' => ['name_en' => 'Bathroom Wall Tile', 'name_ar' => 'بلاط حائط حمام', 'unit' => 'm2', 'material' => 220, 'labor' => 110, 'other' => 0, 'client' => 500],
                    'wall_finishes.tiles.kitchen_backsplash' => ['name_en' => 'Kitchen Backsplash Tile', 'name_ar' => 'بلاط ظهر المطبخ', 'unit' => 'm2', 'material' => 260, 'labor' => 120, 'other' => 0, 'client' => 560],
                    'wall_finishes.tiles.feature_wall' => ['name_en' => 'Decorative Feature Wall Tile', 'name_ar' => 'بلاط حائط ديكوري', 'unit' => 'm2', 'material' => 450, 'labor' => 150, 'other' => 0, 'client' => 900],
                ],
            ],
            'wall_finishes.cladding' => [
                'name_en' => 'Cladding', 'name_ar' => 'الكسوة',
                'items' => [
                    'wall_finishes.cladding.stone' => ['name_en' => 'Natural Stone Wall Cladding', 'name_ar' => 'كسوة حائط حجر طبيعي', 'unit' => 'm2', 'material' => 650, 'labor' => 200, 'other' => 0, 'client' => 1300],
                    'wall_finishes.cladding.wood_panel' => ['name_en' => 'Wood Panel Wall Cladding', 'name_ar' => 'كسوة حائط ألواح خشبية', 'unit' => 'm2', 'material' => 550, 'labor' => 180, 'other' => 0, 'client' => 1100],
                    'wall_finishes.cladding.3d_panel' => ['name_en' => '3D Decorative Wall Panel', 'name_ar' => 'بانوه حائط ثلاثي الأبعاد', 'unit' => 'm2', 'material' => 380, 'labor' => 130, 'other' => 0, 'client' => 780],
                ],
            ],
            'wall_finishes.wallpaper' => [
                'name_en' => 'Wallpaper', 'name_ar' => 'ورق الحائط',
                'items' => [
                    'wall_finishes.wallpaper.standard' => ['name_en' => 'Wallpaper - Standard', 'name_ar' => 'ورق حائط عادي', 'unit' => 'm2', 'material' => 180, 'labor' => 60, 'other' => 0, 'client' => 380],
                    'wall_finishes.wallpaper.premium' => ['name_en' => 'Wallpaper - Premium/Textured', 'name_ar' => 'ورق حائط فاخر', 'unit' => 'm2', 'material' => 320, 'labor' => 80, 'other' => 0, 'client' => 600],
                ],
            ],
        ],
    ],

    'paint' => [
        'name_en' => 'Paint & Coatings', 'name_ar' => 'الدهانات',
        'subcategories' => [
            'paint.walls' => [
                'name_en' => 'Wall Paint', 'name_ar' => 'دهان الحوائط',
                'items' => [
                    'paint.walls.putty_primer' => ['name_en' => 'Wall Putty + Primer', 'name_ar' => 'معجون وبرايمر حوائط', 'unit' => 'm2', 'material' => 25, 'labor' => 20, 'other' => 0, 'client' => 65],
                    'paint.walls.standard' => ['name_en' => 'Wall Paint - Two Coats (Standard)', 'name_ar' => 'دهان حوائط وجهين عادي', 'unit' => 'm2', 'material' => 35, 'labor' => 30, 'other' => 0, 'client' => 90],
                    'paint.walls.premium' => ['name_en' => 'Wall Paint - Two Coats (Premium/Washable)', 'name_ar' => 'دهان حوائط وجهين فاخر', 'unit' => 'm2', 'material' => 55, 'labor' => 35, 'other' => 0, 'client' => 130],
                    'paint.walls.decorative' => ['name_en' => 'Decorative Paint Finish (Venetian/Texture)', 'name_ar' => 'دهان ديكوري', 'unit' => 'm2', 'material' => 90, 'labor' => 60, 'other' => 0, 'client' => 220],
                ],
            ],
            'paint.ceiling' => [
                'name_en' => 'Ceiling Paint', 'name_ar' => 'دهان الأسقف',
                'items' => [
                    'paint.ceiling.standard' => ['name_en' => 'Ceiling Paint - Two Coats', 'name_ar' => 'دهان سقف وجهين', 'unit' => 'm2', 'material' => 35, 'labor' => 30, 'other' => 0, 'client' => 90],
                ],
            ],
            'paint.exterior' => [
                'name_en' => 'Exterior Paint', 'name_ar' => 'الدهانات الخارجية',
                'items' => [
                    'paint.exterior.weatherproof' => ['name_en' => 'Exterior Wall Paint (Weatherproof)', 'name_ar' => 'دهان خارجي مقاوم للعوامل الجوية', 'unit' => 'm2', 'material' => 60, 'labor' => 40, 'other' => 0, 'client' => 150],
                ],
            ],
            'paint.metal' => [
                'name_en' => 'Metal Paint', 'name_ar' => 'دهان المعادن',
                'items' => [
                    'paint.metal.door_window' => ['name_en' => 'Metal Door/Window Paint', 'name_ar' => 'دهان أبواب وشبابيك معدنية', 'unit' => 'm2', 'material' => 40, 'labor' => 35, 'other' => 0, 'client' => 100],
                ],
            ],
        ],
    ],

    'gypsum' => [
        'name_en' => 'Gypsum & False Ceiling', 'name_ar' => 'الجبس والأسقف المستعارة',
        'subcategories' => [
            'gypsum.flat_ceiling' => [
                'name_en' => 'False Ceiling', 'name_ar' => 'السقف المستعار',
                'items' => [
                    'gypsum.flat_ceiling.flat' => ['name_en' => 'Flat Gypsum False Ceiling', 'name_ar' => 'سقف جبسي مستوي', 'unit' => 'm2', 'material' => 180, 'labor' => 90, 'other' => 0, 'client' => 420],
                    'gypsum.flat_ceiling.cove_lighting' => ['name_en' => 'Ceiling Cove for Hidden Lighting', 'name_ar' => 'كرنيش سقف لإضاءة مخفية', 'unit' => 'm', 'material' => 90, 'labor' => 60, 'other' => 0, 'client' => 220],
                    'gypsum.flat_ceiling.bulkhead' => ['name_en' => 'Decorative Ceiling Bulkhead', 'name_ar' => 'بوكس سقف ديكوري', 'unit' => 'm', 'material' => 120, 'labor' => 70, 'other' => 0, 'client' => 280],
                ],
            ],
            'gypsum.walls' => [
                'name_en' => 'Gypsum Walls', 'name_ar' => 'حوائط الجبس',
                'items' => [
                    'gypsum.walls.cladding' => ['name_en' => 'Gypsum Board Wall Cladding', 'name_ar' => 'كسوة حائط جبس بورد', 'unit' => 'm2', 'material' => 150, 'labor' => 80, 'other' => 0, 'client' => 360],
                    'gypsum.walls.partition' => ['name_en' => 'Gypsum Partition Wall', 'name_ar' => 'قاطع جبس بورد', 'unit' => 'm2', 'material' => 200, 'labor' => 100, 'other' => 0, 'client' => 450],
                ],
            ],
        ],
    ],

    'doors_joinery' => [
        'name_en' => 'Doors & Joinery', 'name_ar' => 'الأبواب والنجارة',
        'subcategories' => [
            'doors_joinery.internal_doors' => [
                'name_en' => 'Internal Doors', 'name_ar' => 'الأبواب الداخلية',
                'items' => [
                    'doors_joinery.internal_doors.standard' => ['name_en' => 'Internal Door - Standard (Supply+Fit)', 'name_ar' => 'باب داخلي عادي', 'unit' => 'leaf', 'material' => 2200, 'labor' => 400, 'other' => 0, 'client' => 3800],
                    'doors_joinery.internal_doors.premium' => ['name_en' => 'Internal Door - Premium Solid Wood', 'name_ar' => 'باب داخلي خشب طبيعي فاخر', 'unit' => 'leaf', 'material' => 4500, 'labor' => 500, 'other' => 0, 'client' => 7200],
                    'doors_joinery.internal_doors.main_entrance' => ['name_en' => 'Main Entrance Security Door', 'name_ar' => 'باب شقة أمان', 'unit' => 'pcs', 'material' => 6500, 'labor' => 800, 'other' => 0, 'client' => 10500],
                    'doors_joinery.internal_doors.frame' => ['name_en' => 'Door Frame & Architrave', 'name_ar' => 'كاسة باب وإطار', 'unit' => 'pcs', 'material' => 900, 'labor' => 300, 'other' => 0, 'client' => 1800],
                    'doors_joinery.internal_doors.hardware' => ['name_en' => 'Door Hardware Set (Handle+Lock+Hinges)', 'name_ar' => 'طقم أكسسوار باب', 'unit' => 'set', 'material' => 450, 'labor' => 100, 'other' => 0, 'client' => 850],
                ],
            ],
            'doors_joinery.wardrobes' => [
                'name_en' => 'Wardrobes', 'name_ar' => 'الدواليب',
                'items' => [
                    'doors_joinery.wardrobes.built_in' => ['name_en' => 'Built-in Wardrobe (per linear meter)', 'name_ar' => 'دولاب حائط لكل متر طولي', 'unit' => 'm', 'material' => 3500, 'labor' => 600, 'other' => 0, 'client' => 6000],
                    'doors_joinery.wardrobes.walk_in_closet' => ['name_en' => 'Walk-in Closet System', 'name_ar' => 'غرفة ملابس كاملة', 'unit' => 'ls', 'material' => 15000, 'labor' => 2500, 'other' => 0, 'client' => 25000],
                ],
            ],
            'doors_joinery.carpentry' => [
                'name_en' => 'Custom Carpentry', 'name_ar' => 'نجارة حسب الطلب',
                'items' => [
                    'doors_joinery.carpentry.tv_unit' => ['name_en' => 'Custom TV Unit', 'name_ar' => 'وحدة تليفزيون مفصلة', 'unit' => 'm', 'material' => 3200, 'labor' => 500, 'other' => 0, 'client' => 5500],
                    'doors_joinery.carpentry.study_table' => ['name_en' => 'Built-in Study Table', 'name_ar' => 'مكتب مدمج', 'unit' => 'm', 'material' => 2800, 'labor' => 450, 'other' => 0, 'client' => 4800],
                    'doors_joinery.carpentry.window_sill' => ['name_en' => 'Wood Window Sill', 'name_ar' => 'حرف شباك خشب', 'unit' => 'm', 'material' => 350, 'labor' => 100, 'other' => 0, 'client' => 650],
                ],
            ],
        ],
    ],

    'kitchen' => [
        'name_en' => 'Kitchen', 'name_ar' => 'المطبخ',
        'subcategories' => [
            'kitchen.cabinets' => [
                'name_en' => 'Cabinets', 'name_ar' => 'الوحدات',
                'items' => [
                    'kitchen.cabinets.base' => ['name_en' => 'Kitchen Base Cabinet (per linear meter)', 'name_ar' => 'وحدة مطبخ سفلية لكل متر طولي', 'unit' => 'm', 'material' => 3800, 'labor' => 500, 'other' => 0, 'client' => 6500],
                    'kitchen.cabinets.wall' => ['name_en' => 'Kitchen Wall Cabinet (per linear meter)', 'name_ar' => 'وحدة مطبخ علوية لكل متر طولي', 'unit' => 'm', 'material' => 2800, 'labor' => 400, 'other' => 0, 'client' => 4800],
                    'kitchen.cabinets.tall_unit' => ['name_en' => 'Kitchen Tall Unit (Pantry)', 'name_ar' => 'وحدة مطبخ عالية', 'unit' => 'pcs', 'material' => 6500, 'labor' => 700, 'other' => 0, 'client' => 10500],
                ],
            ],
            'kitchen.countertop' => [
                'name_en' => 'Countertop', 'name_ar' => 'الرخامة',
                'items' => [
                    'kitchen.countertop.granite' => ['name_en' => 'Kitchen Countertop - Granite', 'name_ar' => 'رخامة مطبخ جرانيت', 'unit' => 'm', 'material' => 1800, 'labor' => 300, 'other' => 0, 'client' => 3000],
                    'kitchen.countertop.quartz' => ['name_en' => 'Kitchen Countertop - Quartz', 'name_ar' => 'رخامة مطبخ كوارتز', 'unit' => 'm', 'material' => 2800, 'labor' => 350, 'other' => 0, 'client' => 4500],
                ],
            ],
            'kitchen.accessories' => [
                'name_en' => 'Accessories', 'name_ar' => 'الإكسسوارات',
                'items' => [
                    'kitchen.accessories.drawer_organizer' => ['name_en' => 'Internal Drawer Organizer', 'name_ar' => 'منظم أدراج داخلي', 'unit' => 'pcs', 'material' => 600, 'labor' => 100, 'other' => 0, 'client' => 1100],
                    'kitchen.accessories.pull_out_basket' => ['name_en' => 'Pull-Out Wire Basket', 'name_ar' => 'سلة سحب', 'unit' => 'pcs', 'material' => 450, 'labor' => 80, 'other' => 0, 'client' => 800],
                    'kitchen.accessories.handles' => ['name_en' => 'Kitchen Cabinet Handles (per unit)', 'name_ar' => 'يد وحدة مطبخ', 'unit' => 'pcs', 'material' => 60, 'labor' => 15, 'other' => 0, 'client' => 130],
                ],
            ],
        ],
    ],

    'aluminum_glass' => [
        'name_en' => 'Aluminum & Glass', 'name_ar' => 'الألومنيوم والزجاج',
        'subcategories' => [
            'aluminum_glass.windows' => [
                'name_en' => 'Windows', 'name_ar' => 'الشبابيك',
                'items' => [
                    'aluminum_glass.windows.sliding' => ['name_en' => 'Aluminum Sliding Window', 'name_ar' => 'شباك ألومنيوم سحاب', 'unit' => 'm2', 'material' => 1800, 'labor' => 300, 'other' => 0, 'client' => 3200],
                    'aluminum_glass.windows.upvc' => ['name_en' => 'UPVC Window (Double Glazed)', 'name_ar' => 'شباك يو بي في سي مزدوج', 'unit' => 'm2', 'material' => 2500, 'labor' => 350, 'other' => 0, 'client' => 4200],
                ],
            ],
            'aluminum_glass.doors' => [
                'name_en' => 'Doors', 'name_ar' => 'الأبواب',
                'items' => [
                    'aluminum_glass.doors.sliding' => ['name_en' => 'Aluminum Sliding Door', 'name_ar' => 'باب ألومنيوم سحاب', 'unit' => 'm2', 'material' => 2200, 'labor' => 400, 'other' => 0, 'client' => 3800],
                    'aluminum_glass.doors.balcony' => ['name_en' => 'Glass Balcony Door', 'name_ar' => 'باب بلكونة زجاج', 'unit' => 'm2', 'material' => 2800, 'labor' => 450, 'other' => 0, 'client' => 4800],
                ],
            ],
            'aluminum_glass.shower' => [
                'name_en' => 'Shower Screens', 'name_ar' => 'شاشات الدش',
                'items' => [
                    'aluminum_glass.shower.screen' => ['name_en' => 'Shower Glass Screen', 'name_ar' => 'شاشة دش زجاج', 'unit' => 'm2', 'material' => 1500, 'labor' => 300, 'other' => 0, 'client' => 2800],
                    'aluminum_glass.shower.cabin' => ['name_en' => 'Shower Glass Cabin (Full)', 'name_ar' => 'كابينة دش زجاج كاملة', 'unit' => 'pcs', 'material' => 4500, 'labor' => 600, 'other' => 0, 'client' => 7500],
                ],
            ],
            'aluminum_glass.mirrors' => [
                'name_en' => 'Mirrors', 'name_ar' => 'المرايا',
                'items' => [
                    'aluminum_glass.mirrors.bathroom' => ['name_en' => 'Bathroom Mirror (Supply+Fit)', 'name_ar' => 'مراية حمام', 'unit' => 'pcs', 'material' => 600, 'labor' => 150, 'other' => 0, 'client' => 1200],
                    'aluminum_glass.mirrors.decorative' => ['name_en' => 'Decorative Wall Mirror', 'name_ar' => 'مراية حائط ديكورية', 'unit' => 'm2', 'material' => 900, 'labor' => 200, 'other' => 0, 'client' => 1700],
                ],
            ],
        ],
    ],

    'low_current' => [
        'name_en' => 'Low Current & Smart Home', 'name_ar' => 'التيار الخفيف والمنزل الذكي',
        'subcategories' => [
            'low_current.data' => [
                'name_en' => 'Data & Network', 'name_ar' => 'الشبكات',
                'items' => [
                    'low_current.data.point' => ['name_en' => 'Data/Network Point (Cat6)', 'name_ar' => 'نقطة شبكة', 'unit' => 'point', 'material' => 180, 'labor' => 100, 'other' => 0, 'client' => 400],
                    'low_current.data.wifi_ap' => ['name_en' => 'WiFi Access Point Installation', 'name_ar' => 'تركيب نقطة واي فاي', 'unit' => 'pcs', 'material' => 600, 'labor' => 150, 'other' => 0, 'client' => 1100],
                ],
            ],
            'low_current.security' => [
                'name_en' => 'Security', 'name_ar' => 'الأمن',
                'items' => [
                    'low_current.security.cctv_indoor' => ['name_en' => 'CCTV Camera Point (Indoor)', 'name_ar' => 'نقطة كاميرا داخلية', 'unit' => 'point', 'material' => 350, 'labor' => 150, 'other' => 0, 'client' => 700],
                    'low_current.security.cctv_outdoor' => ['name_en' => 'CCTV Camera Point (Outdoor)', 'name_ar' => 'نقطة كاميرا خارجية', 'unit' => 'point', 'material' => 450, 'labor' => 180, 'other' => 0, 'client' => 900],
                    'low_current.security.video_intercom' => ['name_en' => 'Video Intercom System', 'name_ar' => 'نظام إنتركم بالصورة', 'unit' => 'set', 'material' => 2200, 'labor' => 400, 'other' => 0, 'client' => 3800],
                    'low_current.security.access_control' => ['name_en' => 'Door Access Control System', 'name_ar' => 'نظام تحكم دخول الباب', 'unit' => 'pcs', 'material' => 3500, 'labor' => 600, 'other' => 0, 'client' => 6000],
                    'low_current.security.alarm_point' => ['name_en' => 'Alarm Sensor Point', 'name_ar' => 'نقطة حساس إنذار', 'unit' => 'point', 'material' => 280, 'labor' => 120, 'other' => 0, 'client' => 550],
                ],
            ],
            'low_current.smart_home' => [
                'name_en' => 'Smart Home', 'name_ar' => 'المنزل الذكي',
                'items' => [
                    'low_current.smart_home.smart_switch' => ['name_en' => 'Smart Lighting Switch', 'name_ar' => 'مفتاح إنارة ذكي', 'unit' => 'pcs', 'material' => 450, 'labor' => 80, 'other' => 0, 'client' => 850],
                    'low_current.smart_home.thermostat' => ['name_en' => 'Smart Thermostat', 'name_ar' => 'ترموستات ذكي', 'unit' => 'pcs', 'material' => 1200, 'labor' => 200, 'other' => 0, 'client' => 2000],
                    'low_current.smart_home.curtain_motor' => ['name_en' => 'Motorized Curtain System', 'name_ar' => 'ستارة كهربائية', 'unit' => 'm', 'material' => 1800, 'labor' => 300, 'other' => 0, 'client' => 3200],
                    'low_current.smart_home.automation_hub' => ['name_en' => 'Home Automation Hub', 'name_ar' => 'وحدة تحكم منزل ذكي', 'unit' => 'pcs', 'material' => 3500, 'labor' => 500, 'other' => 0, 'client' => 5800],
                ],
            ],
            'low_current.audio_visual' => [
                'name_en' => 'Audio Visual', 'name_ar' => 'الصوت والصورة',
                'items' => [
                    'low_current.audio_visual.home_theater_wiring' => ['name_en' => 'Home Theater Pre-Wiring', 'name_ar' => 'تمديد سينما منزلية', 'unit' => 'ls', 'material' => 2200, 'labor' => 600, 'other' => 0, 'client' => 4200],
                    'low_current.audio_visual.ceiling_speaker' => ['name_en' => 'Ceiling Speaker Installation', 'name_ar' => 'تركيب سماعة سقف', 'unit' => 'pcs', 'material' => 900, 'labor' => 200, 'other' => 0, 'client' => 1600],
                ],
            ],
        ],
    ],

    'finalization' => [
        'name_en' => 'Finalization', 'name_ar' => 'أعمال التسليم النهائي',
        'subcategories' => [
            'finalization.cleaning' => [
                'name_en' => 'Cleaning', 'name_ar' => 'التنظيف',
                'items' => [
                    'finalization.cleaning.deep_clean' => ['name_en' => 'Post-Construction Deep Cleaning', 'name_ar' => 'تنظيف عميق بعد التشطيب', 'unit' => 'm2', 'material' => 5, 'labor' => 15, 'other' => 0, 'client' => 35],
                    'finalization.cleaning.glass' => ['name_en' => 'Window & Glass Cleaning', 'name_ar' => 'تنظيف الشبابيك والزجاج', 'unit' => 'm2', 'material' => 3, 'labor' => 8, 'other' => 0, 'client' => 20],
                ],
            ],
            'finalization.snagging' => [
                'name_en' => 'Snagging', 'name_ar' => 'الملاحظات الختامية',
                'items' => [
                    'finalization.snagging.rectification' => ['name_en' => 'Snag List Rectification', 'name_ar' => 'معالجة الملاحظات', 'unit' => 'ls', 'material' => 500, 'labor' => 3000, 'other' => 0, 'client' => 5000],
                ],
            ],
            'finalization.handover' => [
                'name_en' => 'Handover', 'name_ar' => 'التسليم',
                'items' => [
                    'finalization.handover.inspection' => ['name_en' => 'Final Inspection & Handover', 'name_ar' => 'المعاينة النهائية والتسليم', 'unit' => 'ls', 'material' => 0, 'labor' => 1500, 'other' => 0, 'client' => 2500],
                    'finalization.handover.as_built' => ['name_en' => 'As-Built Documentation', 'name_ar' => 'توثيق الرسومات التنفيذية', 'unit' => 'ls', 'material' => 0, 'labor' => 1000, 'other' => 0, 'client' => 1800],
                ],
            ],
            'finalization.protection_removal' => [
                'name_en' => 'Protection Removal', 'name_ar' => 'إزالة أعمال الحماية',
                'items' => [
                    'finalization.protection_removal.removal' => ['name_en' => 'Protection Material Removal', 'name_ar' => 'إزالة مواد الحماية', 'unit' => 'ls', 'material' => 0, 'labor' => 800, 'other' => 0, 'client' => 1500],
                ],
            ],
        ],
    ],
];
