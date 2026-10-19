<?php

/**
 * BOQ Master Catalog — English/Arabic scope descriptions for every item in catalog.php, keyed by
 * the same stable item key. Kept in its own file so catalog.php's one-line-per-item layout stays
 * scannable; BoqMasterCatalogSeeder merges the two, and
 * tests/Unit/Boq/CatalogDescriptionsTest.php fails if a catalog key has no description here (or
 * a description key no longer exists in the catalog).
 *
 * Shape: item key => [English, Arabic].
 */
return [
    // Demolition & Prep
    'demolition.removal.block_wall' => ['Demolition of existing brick or block walls, including stacking the debris on site.', 'هدم حوائط الطوب أو البلوك القائمة مع تجميع المخلفات في الموقع.'],
    'demolition.removal.partition' => ['Removal of light partitions such as gypsum board or wood, including frames and fixings.', 'إزالة القواطيع الخفيفة مثل الجبس بورد أو الخشب شاملة القطاعات والتثبيتات.'],
    'demolition.removal.flooring' => ['Breaking out existing floor tiles or marble together with the mortar bed beneath.', 'تكسير وإزالة البلاط أو الرخام الحالي مع طبقة المونة أسفله.'],
    'demolition.removal.wall_tiles' => ['Removing existing wall tiles and their adhesive back to the plaster or block surface.', 'إزالة بلاط الحوائط الحالي واللاصق حتى سطح البياض أو الطوب.'],
    'demolition.removal.false_ceiling' => ['Dismantling existing gypsum or panel false ceilings, including the suspension system.', 'فك الأسقف المستعارة الحالية من الجبس أو البلاطات شاملة شبكة التعليق.'],
    'demolition.removal.doors_windows' => ['Removing an existing door or window together with its frame, without damaging the opening.', 'فك باب أو شباك قائم مع الحلق دون الإضرار بالفتحة.'],
    'demolition.removal.sanitary_fixtures' => ['Disconnecting and removing an existing WC, basin, bathtub or similar fixture, and capping the points.', 'فصل وفك قاعدة أو حوض أو بانيو قائم وما شابه مع تقفيل النقاط.'],
    'demolition.removal.debris' => ['Loading debris and transporting it off site to an approved dump, priced per cubic meter.', 'تحميل المخلفات ونقلها خارج الموقع إلى مقلب معتمد، بالمتر المكعب.'],
    'demolition.protection.floor_protection' => ['Covering finished floors with corrugated sheets or plywood to protect them during works.', 'تغطية الأرضيات المشطبة بألواح كرتون مضلع أو خشب أبلكاش لحمايتها أثناء الأعمال.'],
    'demolition.protection.site_cleaning' => ['Clearing and cleaning the unit before works begin so the site is ready for setting out.', 'تنظيف الوحدة وإخلاؤها قبل بدء الأعمال لتجهيز الموقع للتوقيع.'],

    // Masonry & Block Work
    'masonry.walls.block_12' => ['Half-brick red brick wall, 12 cm thick, built in cement mortar.', 'حائط طوب أحمر نصف طوبة بسمك ١٢ سم بمونة أسمنتية.'],
    'masonry.walls.block_25' => ['Full-brick red brick wall, 25 cm thick, built in cement mortar for external or load-bearing walls.', 'حائط طوب أحمر طوبة كاملة بسمك ٢٥ سم بمونة أسمنتية للحوائط الخارجية أو الحاملة.'],
    'masonry.walls.lightweight_partition' => ['Internal partition in lightweight (AAC) blocks, faster to build and lighter on the slab.', 'قاطع داخلي من الطوب الخفيف، أسرع في التنفيذ وأخف على البلاطة.'],
    'masonry.walls.lintel' => ['Cast-in-place reinforced concrete lintel above a door or window opening.', 'كمرة خرسانية مسلحة تُصب في الموقع أعلى فتحة باب أو شباك.'],
    'masonry.openings.door_prep' => ['Cutting or adjusting a door opening to size and squaring its edges ready for the frame.', 'فتح أو تعديل فتحة الباب للمقاس وضبط حوافها لاستقبال الحلق.'],
    'masonry.openings.window_prep' => ['Cutting or adjusting a window opening to size, including levelling the sill.', 'فتح أو تعديل فتحة الشباك للمقاس شاملة ضبط منسوب الجلسة.'],

    // Plaster & Screed
    'plaster.walls.cement_plaster' => ['Cement and sand plaster on walls with level guides, finished true and ready for paint or tiles.', 'بياض أسمنتي على الحوائط بالبؤج والأوتار، مستوٍ وجاهز للدهان أو البلاط.'],
    'plaster.walls.gypsum_plaster' => ['Gypsum plaster on internal walls, giving a smooth finish that needs less putty.', 'بياض جبسي للحوائط الداخلية يعطي سطحًا ناعمًا يحتاج معجونًا أقل.'],
    'plaster.walls.corner_beads' => ['Galvanized corner beads fixed into the plaster to give straight, impact-resistant corners.', 'زوايا معدنية مجلفنة تُثبت داخل البياض لعمل أركان مستقيمة مقاومة للصدمات.'],
    'plaster.floors.screed' => ['Sand and cement screed laid to level as a base for floor tiles or other finishes.', 'محارة أرضية رمل وأسمنت بالمنسوب كطبقة أساس للبلاط أو التشطيبات الأخرى.'],
    'plaster.floors.sloped_screed' => ['Screed laid to falls toward the drains in bathrooms, kitchens and balconies.', 'محارة بميول ناحية الصفايات في الحمامات والمطابخ والبلكونات.'],

    // Waterproofing
    'waterproofing.wet_areas.bathroom_floor' => ['Waterproofing membrane on the bathroom floor, turned up the walls, with a flood test before tiling.', 'عزل مائي لأرضية الحمام مع رفعه على الحوائط واختبار غمر بالمياه قبل البلاط.'],
    'waterproofing.wet_areas.bathroom_wall' => ['Waterproofing coating on bathroom walls up to 1 m, and full height around the shower.', 'عزل مائي لحوائط الحمام حتى ارتفاع ١ متر، وبالارتفاع الكامل حول الدُش.'],
    'waterproofing.wet_areas.kitchen_floor' => ['Waterproofing membrane on the kitchen floor, turned up the walls, before the screed and tiles.', 'عزل مائي لأرضية المطبخ مع رفعه على الحوائط قبل المحارة والبلاط.'],
    'waterproofing.wet_areas.balcony' => ['Waterproofing for balcony or terrace floors exposed to rain and washing water.', 'عزل مائي لأرضيات البلكونات أو التراسات المعرضة للأمطار ومياه الغسيل.'],
    'waterproofing.roof.membrane' => ['Bituminous membrane on the roof slab with overlapping joints and turn-ups at parapets.', 'عزل رولات بيتومينية على بلاطة السطح بتراكب الوصلات والرفع على الدراوي.'],
    'waterproofing.roof.thermal' => ['Combined system of thermal insulation boards plus a waterproofing layer to reduce heat gain through the roof.', 'نظام متكامل من ألواح العزل الحراري وطبقة عزل مائي لتقليل الحرارة النافذة من السطح.'],

    // Plumbing
    'plumbing.rough_in.cold_water_point' => ['Concealed cold-water supply pipe from the main line to one outlet, pressure-tested.', 'تمديد مواسير تغذية مياه باردة مدفونة من الخط الرئيسي حتى مخرج واحد مع اختبار الضغط.'],
    'plumbing.rough_in.hot_water_point' => ['Concealed hot-water supply pipe from the heater to one outlet, pressure-tested.', 'تمديد مواسير مياه ساخنة مدفونة من السخان حتى مخرج واحد مع اختبار الضغط.'],
    'plumbing.rough_in.drainage_point' => ['Drainage pipe from one fixture to the main stack, laid to the correct fall.', 'تمديد ماسورة صرف من جهاز واحد حتى العمود الرئيسي بالميل الصحيح.'],
    'plumbing.rough_in.floor_drain' => ['Supplying and installing a floor drain with grating, connected to the drainage network.', 'توريد وتركيب صفاية أرضية بغطاء وتوصيلها بشبكة الصرف.'],
    'plumbing.rough_in.main_riser' => ['Connecting the unit’s internal supply and drainage to the building’s main risers.', 'توصيل التغذية والصرف الداخلي للوحدة بالأعمدة الرئيسية للعمارة.'],
    'plumbing.rough_in.water_meter' => ['Installing the water meter with its valves and fittings at the supply entry.', 'تركيب عداد المياه بالمحابس والوصلات عند مدخل التغذية.'],
    'plumbing.fixtures.wc' => ['Supplying and fitting a floor-mounted WC with its cistern, seat and connections.', 'توريد وتركيب قاعدة حمام أرضية بالصندوق والغطاء والوصلات.'],
    'plumbing.fixtures.wall_hung_wc' => ['Wall-hung WC on a concealed cistern frame, built into the wall, with flush plate.', 'قاعدة معلقة على شاسيه بصندوق طرد مخفي داخل الحائط مع لوحة الطرد.'],
    'plumbing.fixtures.wash_basin' => ['Supplying and fitting a ceramic wash basin on a pedestal, including the waste and trap.', 'توريد وتركيب حوض غسيل سيراميك على عمود شامل البلاعة والسيفون.'],
    'plumbing.fixtures.basin_mixer' => ['Supplying and fitting a single-lever basin mixer with flexible hoses and angle valves.', 'توريد وتركيب خلاط حوض بذراع واحد بالخراطيم والمحابس الزاوية.'],
    'plumbing.fixtures.shower_mixer' => ['Shower mixer set with hand shower, hose and wall bracket.', 'طقم خلاط دُش مع دُش يدوي وخرطوم وحامل حائط.'],
    'plumbing.fixtures.bathtub' => ['Installing a bathtub with its waste and overflow, levelled and sealed to the walls.', 'تركيب بانيو بالبلاعة والفائض مع ضبط الميزان والعزل مع الحوائط.'],
    'plumbing.fixtures.kitchen_sink' => ['Stainless steel kitchen sink fitted into the countertop, including waste and trap.', 'حوض مطبخ استانلس ستيل يُركب في الرخامة شامل البلاعة والسيفون.'],
    'plumbing.fixtures.kitchen_mixer' => ['Supplying and fitting a kitchen sink mixer tap with flexible hoses.', 'توريد وتركيب خلاط حوض مطبخ بالخراطيم.'],
    'plumbing.fixtures.bidet' => ['Supplying and fitting a bidet spray or bidet fixture with its valve.', 'توريد وتركيب شطاف بالمحبس.'],
    'plumbing.heating.electric_small' => ['30-litre electric water heater, wall-mounted and connected, for a kitchen or guest bathroom.', 'سخان كهربائي ٣٠ لتر معلق وموصل، لمطبخ أو حمام ضيوف.'],
    'plumbing.heating.electric_large' => ['100-litre electric water heater, wall-mounted and connected, serving the main bathrooms.', 'سخان كهربائي ١٠٠ لتر معلق وموصل لخدمة الحمامات الرئيسية.'],
    'plumbing.heating.gas' => ['Gas water heater installed and connected to the gas line and flue by a certified technician.', 'سخان غاز يُركب ويوصل بخط الغاز والمدخنة بواسطة فني معتمد.'],
    'plumbing.drainage.floor_trap' => ['Floor trap to collect drainage from several fixtures and stop odours returning.', 'جالي تجميع صرف عدة أجهزة ويمنع رجوع الروائح.'],
    'plumbing.drainage.vent_pipe' => ['Vent pipe connected to the drainage system so it drains freely without siphoning traps.', 'ماسورة تهوية متصلة بشبكة الصرف لتصريف سليم دون سحب مياه السيفونات.'],

    // Electrical
    'electrical.rough_in.lighting_point' => ['Concealed conduit and wiring from the board to one lighting outlet, including its switch.', 'تمديد مواسير وأسلاك مدفونة من اللوحة حتى مخرج إنارة واحد شامل المفتاح.'],
    'electrical.rough_in.socket_point' => ['Concealed conduit and wiring for one 13 A socket outlet.', 'تمديد مواسير وأسلاك مدفونة لمخرج فيشة واحد ١٣ أمبير.'],
    'electrical.rough_in.ac_point' => ['Dedicated 20 A circuit from the board to an air-conditioner outlet.', 'دائرة مستقلة ٢٠ أمبير من اللوحة حتى مخرج التكييف.'],
    'electrical.rough_in.cooker_point' => ['Dedicated heavy-duty circuit for an electric cooker or oven.', 'دائرة مستقلة بقطاع كبير للبوتاجاز أو الفرن الكهربائي.'],
    'electrical.rough_in.water_heater_point' => ['Dedicated circuit with isolator switch for an electric water heater.', 'دائرة مستقلة بمفتاح فصل للسخان الكهربائي.'],
    'electrical.rough_in.exhaust_fan_point' => ['Wiring point for a bathroom or kitchen exhaust fan, usually linked to the light switch.', 'نقطة كهرباء لشفاط الحمام أو المطبخ، تُربط عادة بمفتاح الإنارة.'],
    'electrical.rough_in.tv_point' => ['TV outlet with a power socket and coaxial/satellite cable run.', 'مخرج تليفزيون بفيشة كهرباء وتمديد كابل الدش.'],
    'electrical.rough_in.telephone_point' => ['Cable run and outlet for a telephone line or intercom handset.', 'تمديد كابل ومخرج لخط تليفون أو سماعة إنتركم.'],
    'electrical.distribution.board_small' => ['8-way distribution board with breakers, suitable for a small apartment.', 'لوحة توزيع ٨ خطوط بالقواطع، مناسبة لشقة صغيرة.'],
    'electrical.distribution.board_large' => ['18-way distribution board with breakers and an RCD, for a large apartment or villa floor.', 'لوحة توزيع ١٨ خط بالقواطع وقاطع تسريب أرضي، لشقة كبيرة أو دور فيلا.'],
    'electrical.distribution.main_breaker' => ['Main incoming breaker between the meter and the distribution board.', 'قاطع رئيسي بين العداد ولوحة التوزيع.'],
    'electrical.distribution.earthing' => ['Earthing system with electrode and earth conductor bonded to the board, tested for resistance.', 'منظومة تأريض بالقطب وسلك الأرضي الموصل باللوحة مع قياس المقاومة.'],
    'electrical.fixtures.ceiling_light' => ['Supplying and fitting a standard ceiling light fixture.', 'توريد وتركيب وحدة إضاءة سقف عادية.'],
    'electrical.fixtures.wall_light' => ['Supplying and fitting a wall light (sconce).', 'توريد وتركيب أبليك حائط.'],
    'electrical.fixtures.recessed_spotlight' => ['Recessed LED spotlight fitted into a gypsum ceiling, including its driver.', 'سبوت ليد مدفون يُركب في السقف الجبسي شامل الترانس.'],
    'electrical.fixtures.chandelier' => ['Assembling and hanging a client-supplied or specified chandelier, including the ceiling hook.', 'تجميع وتعليق نجفة يوردها العميل أو حسب المواصفة شامل خطاف السقف.'],
    'electrical.fixtures.switch_standard' => ['Standard-range switch or socket with cover plate, fitted on a rough-in point.', 'مفتاح أو فيشة من فئة عادية بالوش، يُركب على نقطة مؤسسة.'],
    'electrical.fixtures.switch_premium' => ['Premium-range switch or socket with designer cover plate, fitted on a rough-in point.', 'مفتاح أو فيشة من فئة فاخرة بوش ديكوري، يُركب على نقطة مؤسسة.'],
    'electrical.wiring.concealed' => ['PVC conduit chased into walls or laid in the slab to carry wiring, priced per meter.', 'مواسير بلاستيك تُدفن في الحوائط أو البلاطة لتمرير الأسلاك، بالمتر الطولي.'],
    'electrical.wiring.cable_tray' => ['Galvanized cable tray fixed above the false ceiling to carry cable runs.', 'مجرى كابلات مجلفن يُثبت أعلى السقف المستعار لحمل الكابلات.'],
    'electrical.testing.certification' => ['Insulation and continuity testing of all circuits, with a test report before handover.', 'اختبار العزل والاستمرارية لجميع الدوائر مع تقرير اختبار قبل التسليم.'],

    // HVAC
    'hvac.split_units.wiring' => ['Copper refrigerant pipes, power cable and condensate drain run for one split AC unit.', 'تمديد مواسير نحاس الفريون وكابل الكهرباء وماسورة الصرف لوحدة تكييف سبليت واحدة.'],
    'hvac.split_units.unit_15hp' => ['Supplying and installing a 1.5 HP split air conditioner, suitable for a bedroom.', 'توريد وتركيب تكييف سبليت ١.٥ حصان، مناسب لغرفة نوم.'],
    'hvac.split_units.unit_2hp' => ['Supplying and installing a 2.25 HP split air conditioner, suitable for a living room.', 'توريد وتركيب تكييف سبليت ٢.٢٥ حصان، مناسب لغرفة معيشة.'],
    'hvac.ducting.central_ac' => ['Insulated duct run from the central AC unit to one outlet, priced per outlet.', 'تمديد دكت معزول من وحدة التكييف المركزي حتى مخرج واحد، بالمخرج.'],
    'hvac.ducting.diffuser' => ['Supplying and fitting a supply diffuser or return grille in the ceiling.', 'توريد وتركيب فتحة تكييف تغذية أو راجع في السقف.'],
    'hvac.ventilation.kitchen_hood' => ['Duct from the kitchen hood to the outside, with a wall cap and back-draft flap.', 'دكت من شفاط المطبخ إلى الخارج بغطاء حائط وبوابة منع رجوع الهواء.'],
    'hvac.ventilation.bathroom_fan' => ['Supplying and installing a bathroom exhaust fan, ducted to the outside or a shaft.', 'توريد وتركيب شفاط حمام موصل بالخارج أو المنور.'],
    'hvac.ventilation.fresh_air' => ['Fresh-air unit that brings in filtered outdoor air, for sealed or poorly ventilated spaces.', 'وحدة تهوية تدخل هواء خارجي مفلتر، للمساحات المغلقة أو ضعيفة التهوية.'],

    // Flooring
    'flooring.tiles.porcelain_60' => ['60×60 cm porcelain floor tiles laid with adhesive and spacers, grouted.', 'بلاط بورسلين ٦٠×٦٠ سم يُركب بالغراء والفواصل مع ترويب العراميس.'],
    'flooring.tiles.porcelain_80' => ['80×80 cm large-format porcelain floor tiles, fewer joints for a cleaner look.', 'بلاط بورسلين مقاس كبير ٨٠×٨٠ سم بعراميس أقل لمظهر أنظف.'],
    'flooring.tiles.ceramic_standard' => ['Standard ceramic floor tiles, an economical option for service areas.', 'بلاط سيراميك أرضيات عادي، خيار اقتصادي لأماكن الخدمات.'],
    'flooring.tiles.anti_slip' => ['Anti-slip matt floor tiles for bathrooms and other wet areas.', 'بلاط أرضيات مطفي مانع للانزلاق للحمامات والأماكن المبتلة.'],
    'flooring.natural_stone.marble' => ['Natural marble flooring laid, then ground and polished on site.', 'أرضيات رخام طبيعي تُركب ثم تُجلى وتُلمع في الموقع.'],
    'flooring.natural_stone.granite' => ['Granite flooring, very hard-wearing, for entrances and high-traffic areas.', 'أرضيات جرانيت شديدة التحمل للمداخل والأماكن كثيفة الحركة.'],
    'flooring.wood.laminate' => ['Click-fit laminate flooring over an underlay, an affordable wood-look finish.', 'أرضيات لامينيت بنظام التعشيق فوق طبقة فوم، تشطيب بمظهر الخشب بتكلفة معقولة.'],
    'flooring.wood.engineered' => ['Engineered wood flooring with a real wood top layer, more stable than solid wood.', 'أرضيات خشب هندسي بطبقة علوية خشب طبيعي، أكثر ثباتًا من الخشب المصمت.'],
    'flooring.wood.solid_parquet' => ['Solid hardwood parquet glued down, then sanded and varnished on site.', 'باركيه خشب طبيعي مصمت يُلصق ثم يُصنفر ويُدهن ورنيش في الموقع.'],
    'flooring.skirting.ceramic' => ['Ceramic skirting to match the floor tiles, fixed along the base of walls.', 'وزرة سيراميك مطابقة لبلاط الأرضيات تُركب أسفل الحوائط.'],
    'flooring.skirting.marble' => ['Marble skirting strip with a polished edge, to match marble floors.', 'وزرة رخام بحافة ملمعة مطابقة لأرضيات الرخام.'],
    'flooring.skirting.wood' => ['Wood or MDF skirting board, for laminate and wood floors.', 'وزرة خشب أو إم دي إف لأرضيات اللامينيت والخشب.'],
    'flooring.outdoor.tile' => ['Frost- and slip-resistant tiles for balconies, terraces and roofs.', 'بلاط مقاوم للانزلاق والعوامل الجوية للبلكونات والتراسات والأسطح.'],

    // Wall Finishes
    'wall_finishes.tiles.bathroom' => ['Ceramic or porcelain wall tiles in bathrooms, to full or partial height.', 'بلاط سيراميك أو بورسلين لحوائط الحمامات بالارتفاع الكامل أو الجزئي.'],
    'wall_finishes.tiles.kitchen_backsplash' => ['Tiles on the wall between the base and wall cabinets, behind the sink and cooker.', 'بلاط الحائط بين الوحدات السفلية والعلوية خلف الحوض والبوتاجاز.'],
    'wall_finishes.tiles.feature_wall' => ['Decorative tiles on a single accent wall, such as behind the TV or in the entrance.', 'بلاط ديكوري لحائط مميز واحد مثل خلف التليفزيون أو في المدخل.'],
    'wall_finishes.cladding.stone' => ['Natural stone cladding fixed to the wall with adhesive or mechanical anchors.', 'تكسية حجر طبيعي تُثبت على الحائط بالغراء أو بالكانات المعدنية.'],
    'wall_finishes.cladding.wood_panel' => ['Wood or veneered MDF panels fixed on a timber frame for a warm accent wall.', 'ألواح خشب أو إم دي إف قشرة تُثبت على شاسيه خشبي لحائط دافئ مميز.'],
    'wall_finishes.cladding.3d_panel' => ['3D-relief decorative wall panels, jointed and painted on site.', 'بانوهات حائط ديكورية بارزة ثلاثية الأبعاد تُركب وتُدهن في الموقع.'],
    'wall_finishes.wallpaper.standard' => ['Standard vinyl wallpaper hung on primed walls.', 'ورق حائط فينيل عادي يُركب على حوائط مجهزة بالبرايمر.'],
    'wall_finishes.wallpaper.premium' => ['Premium textured or fabric-backed wallpaper, hung on primed walls.', 'ورق حائط فاخر بملمس أو بظهر قماش يُركب على حوائط مجهزة.'],

    // Paint
    'paint.walls.putty_primer' => ['Two coats of putty sanded smooth, plus a primer coat, to prepare walls for paint.', 'وجهان معجون مع الصنفرة ووجه برايمر لتجهيز الحوائط للدهان.'],
    'paint.walls.standard' => ['Two coats of standard plastic emulsion paint on prepared walls.', 'وجهان دهان بلاستيك عادي على حوائط مجهزة.'],
    'paint.walls.premium' => ['Two coats of premium washable emulsion paint on prepared walls.', 'وجهان دهان فاخر قابل للغسيل على حوائط مجهزة.'],
    'paint.walls.decorative' => ['Decorative finish such as Venetian plaster or textured paint, applied by a specialist.', 'تشطيب ديكوري مثل الفينيسيان أو الدهانات ذات الملمس، يُنفذ بواسطة فني متخصص.'],
    'paint.ceiling.standard' => ['Two coats of emulsion paint on ceilings, including putty and primer.', 'وجهان دهان للأسقف شاملة المعجون والبرايمر.'],
    'paint.exterior.weatherproof' => ['Weather-resistant exterior paint on facades and balcony walls.', 'دهان خارجي مقاوم للعوامل الجوية للواجهات وحوائط البلكونات.'],
    'paint.metal.door_window' => ['Rust-proof primer plus two coats of enamel on metal doors and window frames.', 'وجه برايمر مانع للصدأ ووجهان دهان زيتي للأبواب والشبابيك المعدنية.'],

    // Gypsum
    'gypsum.flat_ceiling.flat' => ['Flat gypsum board false ceiling on a galvanized frame, jointed and ready for paint.', 'سقف مستعار جبس بورد مستوٍ على قطاعات مجلفنة، معالج الوصلات وجاهز للدهان.'],
    'gypsum.flat_ceiling.cove_lighting' => ['Gypsum cove along the ceiling edge to conceal LED strip lighting.', 'كرنيش جبسي على حافة السقف لإخفاء شريط إضاءة ليد.'],
    'gypsum.flat_ceiling.bulkhead' => ['Dropped gypsum box (bulkhead) to hide ducts or pipes, or as a decorative feature.', 'بوكس جبسي ساقط لإخفاء الدكت أو المواسير أو كعنصر ديكوري.'],
    'gypsum.walls.cladding' => ['Gypsum board lining fixed over existing walls to level them or hide services.', 'تكسية جبس بورد على الحوائط القائمة لضبط استوائها أو إخفاء التأسيسات.'],
    'gypsum.walls.partition' => ['Drywall partition of gypsum boards on both sides of a metal stud frame.', 'قاطع جاف من ألواح الجبس بورد على الوجهين فوق قطاعات معدنية.'],

    // Doors & Joinery
    'doors_joinery.internal_doors.standard' => ['Standard internal door leaf (HDF or flush) supplied, hung and finished.', 'ضلفة باب داخلي عادي (إتش دي إف أو حشو) توريد وتركيب وتشطيب.'],
    'doors_joinery.internal_doors.premium' => ['Premium solid-wood internal door leaf, supplied, hung and finished.', 'ضلفة باب داخلي فاخر من الخشب الطبيعي المصمت توريد وتركيب وتشطيب.'],
    'doors_joinery.internal_doors.main_entrance' => ['Steel-core security entrance door with multi-point lock and frame.', 'باب مدخل أمان بقلب صلب وكالون متعدد النقاط شامل الحلق.'],
    'doors_joinery.internal_doors.frame' => ['Wooden door frame (casing) and architraves fixed into the opening.', 'حلق باب خشبي (كاسة) مع البرواز يُثبت في الفتحة.'],
    'doors_joinery.internal_doors.hardware' => ['Door hardware set: handle, lock with keys, and hinges.', 'طقم أكسسوار باب: أوكرة وكالون بالمفاتيح ومفصلات.'],
    'doors_joinery.wardrobes.built_in' => ['Floor-to-ceiling built-in wardrobe with doors and internal shelving, per linear meter.', 'دولاب حائط من الأرض للسقف بالضلف والأرفف الداخلية، بالمتر الطولي.'],
    'doors_joinery.wardrobes.walk_in_closet' => ['Complete walk-in closet system with hanging rails, drawers, shelves and lighting.', 'نظام غرفة ملابس متكامل بشماعات وأدراج وأرفف وإضاءة.'],
    'doors_joinery.carpentry.tv_unit' => ['Custom-made TV wall unit with storage, per linear meter.', 'وحدة تليفزيون مفصلة حسب الطلب بأماكن تخزين، بالمتر الطولي.'],
    'doors_joinery.carpentry.study_table' => ['Built-in study desk with shelving, per linear meter.', 'مكتب مدمج مع أرفف، بالمتر الطولي.'],
    'doors_joinery.carpentry.window_sill' => ['Hardwood window sill board fixed on the internal side of the window.', 'جلسة شباك من الخشب الصلب تُثبت على الجانب الداخلي للشباك.'],

    // Kitchen
    'kitchen.cabinets.base' => ['Kitchen base cabinets with doors and drawers, per linear meter.', 'وحدات مطبخ سفلية بالضلف والأدراج، بالمتر الطولي.'],
    'kitchen.cabinets.wall' => ['Kitchen wall-hung upper cabinets with doors, per linear meter.', 'وحدات مطبخ علوية معلقة بالضلف، بالمتر الطولي.'],
    'kitchen.cabinets.tall_unit' => ['Floor-to-ceiling tall unit for pantry storage or a built-in oven.', 'وحدة عالية من الأرض للسقف للتخزين أو لفرن بيلت إن.'],
    'kitchen.countertop.granite' => ['Granite countertop with polished edge and sink cut-out, per linear meter.', 'رخامة مطبخ جرانيت بحافة ملمعة وفتحة الحوض، بالمتر الطولي.'],
    'kitchen.countertop.quartz' => ['Engineered quartz countertop, non-porous and stain resistant, per linear meter.', 'رخامة مطبخ كوارتز صناعي غير مسامي ومقاوم للبقع، بالمتر الطولي.'],
    'kitchen.accessories.drawer_organizer' => ['Internal organizer insert for cutlery or utensil drawers.', 'منظم داخلي لأدراج المعالق والأدوات.'],
    'kitchen.accessories.pull_out_basket' => ['Pull-out wire basket fitted inside a base cabinet.', 'سلة سلك منزلقة تُركب داخل وحدة سفلية.'],
    'kitchen.accessories.handles' => ['Cabinet door or drawer handle, supplied and fitted.', 'مقبض ضلفة أو درج توريد وتركيب.'],

    // Aluminum & Glass
    'aluminum_glass.windows.sliding' => ['Aluminum sliding window with glazing, rubber seals and fly screen.', 'شباك ألومنيوم سحاب بالزجاج وجوانات الكاوتش والسلك.'],
    'aluminum_glass.windows.upvc' => ['uPVC window with double glazing for better thermal and sound insulation.', 'شباك يو بي في سي بزجاج مزدوج لعزل حراري وصوتي أفضل.'],
    'aluminum_glass.doors.sliding' => ['Aluminum sliding door with glazing, for balconies or room dividers.', 'باب ألومنيوم سحاب بالزجاج للبلكونات أو الفواصل بين الغرف.'],
    'aluminum_glass.doors.balcony' => ['Glazed balcony door in an aluminum frame, hinged or sliding.', 'باب بلكونة زجاج بإطار ألومنيوم مفصلي أو سحاب.'],
    'aluminum_glass.shower.screen' => ['Fixed or hinged tempered-glass shower screen with fittings.', 'فاصل دُش زجاج سيكوريت ثابت أو مفصلي بالإكسسوارات.'],
    'aluminum_glass.shower.cabin' => ['Full tempered-glass shower enclosure with door and fittings.', 'كابينة دُش كاملة من الزجاج السيكوريت بالباب والإكسسوارات.'],
    'aluminum_glass.mirrors.bathroom' => ['Bathroom mirror above the basin, supplied and fixed.', 'مرآة حمام أعلى الحوض توريد وتركيب.'],
    'aluminum_glass.mirrors.decorative' => ['Decorative wall mirror, cut to size and fixed, priced per square meter.', 'مرآة حائط ديكورية تُقص بالمقاس وتُثبت، بالمتر المربع.'],

    // Low Current
    'low_current.data.point' => ['Cat6 network cable from the network cabinet to one data outlet.', 'تمديد كابل شبكة كات ٦ من دولاب الشبكة حتى مخرج واحد.'],
    'low_current.data.wifi_ap' => ['Installing and configuring a ceiling- or wall-mounted WiFi access point.', 'تركيب وضبط نقطة واي فاي على السقف أو الحائط.'],
    'low_current.security.cctv_indoor' => ['Cable run and mounting point for one indoor CCTV camera.', 'تمديد كابل ونقطة تثبيت لكاميرا مراقبة داخلية واحدة.'],
    'low_current.security.cctv_outdoor' => ['Cable run and weatherproof mounting point for one outdoor CCTV camera.', 'تمديد كابل ونقطة تثبيت مقاومة للعوامل الجوية لكاميرا مراقبة خارجية واحدة.'],
    'low_current.security.video_intercom' => ['Video intercom with an outdoor panel and an indoor monitor.', 'إنتركم بالصورة بوحدة خارجية وشاشة داخلية.'],
    'low_current.security.access_control' => ['Door access control with keypad or card reader and electric lock.', 'نظام تحكم دخول للباب بلوحة أرقام أو قارئ كروت وقفل كهربائي.'],
    'low_current.security.alarm_point' => ['Wiring and mounting point for one alarm sensor (motion or door contact).', 'تمديد ونقطة تركيب لحساس إنذار واحد (حركة أو فتح باب).'],
    'low_current.smart_home.smart_switch' => ['Smart lighting switch controllable from an app or voice assistant.', 'مفتاح إنارة ذكي يُتحكم فيه من تطبيق أو مساعد صوتي.'],
    'low_current.smart_home.thermostat' => ['Smart thermostat to schedule and control the air conditioning.', 'ترموستات ذكي لجدولة التكييف والتحكم فيه.'],
    'low_current.smart_home.curtain_motor' => ['Motorized curtain track with motor and remote/app control, per linear meter.', 'مجرى ستارة بموتور كهربائي وتحكم بالريموت أو التطبيق، بالمتر الطولي.'],
    'low_current.smart_home.automation_hub' => ['Central hub that links and automates the smart devices in the home.', 'وحدة تحكم مركزية تربط الأجهزة الذكية بالمنزل وتشغلها تلقائيًا.'],
    'low_current.audio_visual.home_theater_wiring' => ['Pre-wiring speaker, HDMI and power cables for a home theater before finishes.', 'تمديد كابلات السماعات والـ HDMI والكهرباء للسينما المنزلية قبل التشطيب.'],
    'low_current.audio_visual.ceiling_speaker' => ['Recessed ceiling speaker fitted and connected to the audio system.', 'سماعة سقف مدفونة تُركب وتوصل بنظام الصوت.'],

    // Finalization
    'finalization.cleaning.deep_clean' => ['Professional post-construction cleaning of floors, walls, fixtures and joinery.', 'تنظيف احترافي بعد التشطيب للأرضيات والحوائط والأدوات الصحية والنجارة.'],
    'finalization.cleaning.glass' => ['Cleaning windows, glass and mirrors and removing paint and cement stains.', 'تنظيف الشبابيك والزجاج والمرايا وإزالة بقع الدهان والأسمنت.'],
    'finalization.snagging.rectification' => ['Fixing the defects recorded on the snag list before handover.', 'معالجة العيوب المسجلة في قائمة الملاحظات قبل التسليم.'],
    'finalization.handover.inspection' => ['Final walk-through inspection with the client and formal handover of the unit.', 'المعاينة النهائية مع العميل والتسليم الرسمي للوحدة.'],
    'finalization.handover.as_built' => ['As-built drawings of electrical, plumbing and other services as actually installed.', 'رسومات تنفيذية لما تم تنفيذه فعليًا من كهرباء وسباكة وتأسيسات أخرى.'],
    'finalization.protection_removal.removal' => ['Removing all protective coverings and films at the end of works.', 'إزالة جميع أغطية ومواد الحماية والأفلام في نهاية الأعمال.'],
];
