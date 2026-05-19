<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent seeder for Egypt + Saudi Arabia administrative tree.
 *
 *   country → state (governorate / region) → city → district
 *
 * Re-runnable safely: every level uses `updateOrInsert()` keyed on a
 * natural unique identifier (country code; state composite key; city
 * composite key; district composite key).
 *
 * Coverage:
 *   - Egypt: ALL 27 governorates, major + secondary cities,
 *     comprehensive districts for Cairo / Giza / Alexandria, common
 *     districts for delta + sahel cities.
 *   - Saudi Arabia: ALL 13 regions, major cities, comprehensive
 *     districts for Riyadh / Jeddah / Mecca / Medina / Dammam.
 *
 *   Operationally sufficient for e-commerce address pickers. Operators
 *   add new districts on demand via the (future) admin UI.
 */
class LocationsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->data() as $countryDef) {
            $this->seedCountry($countryDef);
        }
        $this->command->info('✅ Locations seeded successfully.');
    }

    private function seedCountry(array $def): void
    {
        $this->command->line('');
        $this->command->info("→ Country: {$def['name_en']} ({$def['code']})");

        DB::table('countries')->updateOrInsert(
            ['code' => $def['code']],
            [
                'name_ar' => $def['name_ar'],
                'name_en' => $def['name_en'],
                'is_active' => true,
                'sort_order' => $def['sort_order'] ?? 0,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
        $countryId = DB::table('countries')->where('code', $def['code'])->value('id');

        foreach ($def['states'] as $i => $state) {
            $this->seedState($countryId, $state, $i);
        }
    }

    private function seedState(int $countryId, array $state, int $sortOrder): void
    {
        DB::table('states')->updateOrInsert(
            ['country_id' => $countryId, 'name_en' => $state['name_en']],
            [
                'name_ar' => $state['name_ar'],
                'type' => $state['type'] ?? 'governorate',
                'is_active' => true,
                'sort_order' => $sortOrder,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
        $stateId = DB::table('states')
            ->where('country_id', $countryId)
            ->where('name_en', $state['name_en'])
            ->value('id');

        $cityCount = count($state['cities'] ?? []);
        $this->command->line("   • State: {$state['name_en']} ({$cityCount} cities)");

        foreach (($state['cities'] ?? []) as $j => $city) {
            $this->seedCity($stateId, $city, $j);
        }
    }

    private function seedCity(int $stateId, array $city, int $sortOrder): void
    {
        DB::table('cities')->updateOrInsert(
            ['state_id' => $stateId, 'name_en' => $city['name_en']],
            [
                'name_ar' => $city['name_ar'],
                'is_active' => true,
                'sort_order' => $sortOrder,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
        $cityId = DB::table('cities')
            ->where('state_id', $stateId)
            ->where('name_en', $city['name_en'])
            ->value('id');

        foreach (($city['districts'] ?? []) as $k => $district) {
            // Districts arrive as [name_en, name_ar] pairs.
            [$en, $ar] = $district;
            DB::table('districts')->updateOrInsert(
                ['city_id' => $cityId, 'name_en' => $en],
                [
                    'name_ar' => $ar,
                    'is_active' => true,
                    'sort_order' => $k,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        $dCount = count($city['districts'] ?? []);
        if ($dCount > 0) {
            $this->command->line("       └ City: {$city['name_en']} → {$dCount} districts");
        }
    }

    /**
     * Source-of-truth data tree. Keep districts as [en, ar] pairs to
     * stay compact; everything else is associative for readability.
     *
     * @return array<int, array<string, mixed>>
     */
    private function data(): array
    {
        return [
            $this->egypt(),
            $this->saudiArabia(),
        ];
    }

    /* ─────────────────────────── EGYPT ─────────────────────────── */

    private function egypt(): array
    {
        return [
            'code' => 'EG',
            'name_ar' => 'مصر',
            'name_en' => 'Egypt',
            'sort_order' => 10,
            'states' => [
                [
                    'name_en' => 'Cairo', 'name_ar' => 'القاهرة', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Cairo', 'name_ar' => 'القاهرة', 'districts' => [
                            ['Downtown',           'وسط البلد'],
                            ['Garden City',        'جاردن سيتي'],
                            ['Zamalek',            'الزمالك'],
                            ['Sayyida Zeinab',     'السيدة زينب'],
                            ['Manyal',             'المنيل'],
                            ['Old Cairo',          'مصر القديمة'],
                            ['Khalifa',            'الخليفة'],
                            ['Mokattam',           'المقطم'],
                            ['Manshiyat Naser',    'منشية ناصر'],
                            ['Maadi',              'المعادي'],
                            ['New Maadi',          'المعادي الجديدة'],
                            ['Basateen',           'البساتين'],
                            ['Dar el Salam',       'دار السلام'],
                            ['Heliopolis',         'مصر الجديدة'],
                            ['Nasr City',          'مدينة نصر'],
                            ['Ain Shams',          'عين شمس'],
                            ['El Matareya',        'المطرية'],
                            ['El Marg',            'المرج'],
                            ['El Salam',           'السلام'],
                            ['Shubra',             'شبرا'],
                            ['Rod El Farag',       'روض الفرج'],
                            ['Sahel',              'الساحل'],
                            ['Hadayek El Qobba',   'حدائق القبة'],
                            ['Zaytoun',            'الزيتون'],
                            ['Boulaq',             'بولاق'],
                            ['Abdeen',             'عابدين'],
                            ['Azbakeya',           'الأزبكية'],
                            ['Bab El Shaareya',    'باب الشعرية'],
                            ['Gamaleya',           'الجمالية'],
                            ['Mosky',              'الموسكي'],
                            ['Darb El Ahmar',      'الدرب الأحمر'],
                            ['Helwan',             'حلوان'],
                            ['Maasara',            'المعصرة'],
                            ['Tora',               'طرة'],
                            ['15 May City',        'مدينة 15 مايو'],
                            ['New Cairo',          'القاهرة الجديدة'],
                            ['Fifth Settlement',   'التجمع الخامس'],
                            ['First Settlement',   'التجمع الأول'],
                            ['Third Settlement',   'التجمع الثالث'],
                            ['Rehab City',         'مدينة الرحاب'],
                            ['Madinaty',           'مدينتي'],
                            ['Shorouk City',       'مدينة الشروق'],
                            ['Badr City',          'مدينة بدر'],
                            ['Obour City',         'مدينة العبور'],
                            ['Mostakbal City',     'مدينة المستقبل'],
                            ['New Administrative Capital', 'العاصمة الإدارية الجديدة'],
                        ]],
                    ],
                ],
                [
                    'name_en' => 'Giza', 'name_ar' => 'الجيزة', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Giza', 'name_ar' => 'الجيزة', 'districts' => [
                            ['Dokki',              'الدقي'],
                            ['Agouza',             'العجوزة'],
                            ['Mohandessin',        'المهندسين'],
                            ['Imbaba',             'إمبابة'],
                            ['Boulaq Dakrour',     'بولاق الدكرور'],
                            ['Warraq',             'الوراق'],
                            ['Haram',              'الهرم'],
                            ['Faisal',             'فيصل'],
                            ['Omraniya',           'العمرانية'],
                            ['Saft El Laban',      'صفط اللبن'],
                            ['Talbiya',            'الطالبية'],
                            ['Manial Sheha',       'منيل شيحة'],
                            ['Pyramids Gardens',   'حدائق الأهرام'],
                            ['Maryouteya',         'المريوطية'],
                            ['Konayyesa',          'الكنيسة'],
                        ]],
                        ['name_en' => '6th of October City', 'name_ar' => 'مدينة السادس من أكتوبر', 'districts' => [
                            ['1st District',       'الحي الأول'],
                            ['2nd District',       'الحي الثاني'],
                            ['3rd District',       'الحي الثالث'],
                            ['4th District',       'الحي الرابع'],
                            ['5th District',       'الحي الخامس'],
                            ['6th District',       'الحي السادس'],
                            ['7th District',       'الحي السابع'],
                            ['8th District',       'الحي الثامن'],
                            ['9th District',       'الحي التاسع'],
                            ['10th District',      'الحي العاشر'],
                            ['11th District',      'الحي الحادي عشر'],
                            ['12th District',      'الحي الثاني عشر'],
                            ['Hadayek October',    'حدائق أكتوبر'],
                            ['Touristic',          'الحي السياحي'],
                            ['Dreamland',          'دريم لاند'],
                            ['Mountain View',      'ماونتن فيو'],
                        ]],
                        ['name_en' => 'Sheikh Zayed', 'name_ar' => 'الشيخ زايد', 'districts' => [
                            ['1st District',       'الحي الأول'],
                            ['2nd District',       'الحي الثاني'],
                            ['3rd District',       'الحي الثالث'],
                            ['4th District',       'الحي الرابع'],
                            ['5th District',       'الحي الخامس'],
                            ['6th District',       'الحي السادس'],
                            ['7th District',       'الحي السابع'],
                            ['8th District',       'الحي الثامن'],
                            ['9th District',       'الحي التاسع'],
                            ['10th District',      'الحي العاشر'],
                            ['11th District',      'الحي الحادي عشر'],
                            ['12th District',      'الحي الثاني عشر'],
                            ['13th District',      'الحي الثالث عشر'],
                            ['14th District',      'الحي الرابع عشر'],
                            ['15th District',      'الحي الخامس عشر'],
                            ['16th District',      'الحي السادس عشر'],
                            ['Beverly Hills',      'بيفرلي هيلز'],
                            ['Allegria',           'اليجريا'],
                        ]],
                        ['name_en' => 'Hadayek El Ahram', 'name_ar' => 'حدائق الأهرام', 'districts' => []],
                        ['name_en' => 'Bahariya Oasis',   'name_ar' => 'الواحات البحرية', 'districts' => []],
                        ['name_en' => 'Saqqara',          'name_ar' => 'سقارة', 'districts' => []],
                        ['name_en' => 'Atfih',            'name_ar' => 'أطفيح', 'districts' => []],
                        ['name_en' => 'El Badrasheen',    'name_ar' => 'البدرشين', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Alexandria', 'name_ar' => 'الإسكندرية', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Alexandria', 'name_ar' => 'الإسكندرية', 'districts' => [
                            ['Stanley',            'ستانلي'],
                            ['San Stefano',        'سان ستيفانو'],
                            ['Smouha',             'سموحة'],
                            ['Sidi Gaber',         'سيدي جابر'],
                            ['Sporting',           'سبورتنج'],
                            ['Cleopatra',          'كليوباترا'],
                            ['Roushdy',            'رشدي'],
                            ['Glym',               'جليم'],
                            ['Sidi Bishr',         'سيدي بشر'],
                            ['Mandara',            'المندرة'],
                            ['Montaza',            'المنتزه'],
                            ['Asafra',             'العصافرة'],
                            ['Miami',              'ميامي'],
                            ['Bakos',              'باكوس'],
                            ['Raml Station',       'محطة الرمل'],
                            ['Camp Cesar',         'كامب شيزار'],
                            ['Wabour El Mayah',    'وابور المياه'],
                            ['Karmouz',            'كرموز'],
                            ['Moharam Bek',        'محرم بك'],
                            ['Bahary',             'بحري'],
                            ['Anfoushi',           'الأنفوشي'],
                            ['Manshiya',           'المنشية'],
                            ['El Attareen',        'العطارين'],
                            ['Gomrok',             'الجمرك'],
                            ['Borg El Arab',       'برج العرب'],
                            ['Agami',              'العجمي'],
                            ['Bitash',             'البيطاش'],
                            ['King Mariout',       'كنج مريوط'],
                            ['Sidi Kerir',         'سيدي كرير'],
                            ['Abu Talat',          'أبو تلات'],
                            ['Hannoville',         'هانوفيل'],
                            ['Maamoura',           'المعمورة'],
                        ]],
                        ['name_en' => 'Borg El Arab', 'name_ar' => 'برج العرب', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Qalyubia', 'name_ar' => 'القليوبية', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Banha',          'name_ar' => 'بنها', 'districts' => []],
                        ['name_en' => 'Shubra El Kheima','name_ar' => 'شبرا الخيمة', 'districts' => []],
                        ['name_en' => 'Qaha',           'name_ar' => 'قها', 'districts' => []],
                        ['name_en' => 'Qalyub',         'name_ar' => 'قليوب', 'districts' => []],
                        ['name_en' => 'El Khanka',      'name_ar' => 'الخانكة', 'districts' => []],
                        ['name_en' => 'Kafr Shukr',     'name_ar' => 'كفر شكر', 'districts' => []],
                        ['name_en' => 'Tukh',           'name_ar' => 'طوخ', 'districts' => []],
                        ['name_en' => 'El Qanater El Khayreya', 'name_ar' => 'القناطر الخيرية', 'districts' => []],
                        ['name_en' => 'Obour City',     'name_ar' => 'مدينة العبور', 'districts' => []],
                        ['name_en' => '10th of Ramadan','name_ar' => 'العاشر من رمضان', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Sharqia', 'name_ar' => 'الشرقية', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Zagazig',     'name_ar' => 'الزقازيق', 'districts' => []],
                        ['name_en' => 'Bilbeis',     'name_ar' => 'بلبيس',   'districts' => []],
                        ['name_en' => 'Minya El Qamh','name_ar' => 'منيا القمح','districts' => []],
                        ['name_en' => 'Mashtoul El Souq','name_ar' => 'مشتول السوق','districts' => []],
                        ['name_en' => 'El Ibrahimiya','name_ar' => 'الإبراهيمية','districts' => []],
                        ['name_en' => 'Abu Hammad',  'name_ar' => 'أبو حماد', 'districts' => []],
                        ['name_en' => 'Faqous',      'name_ar' => 'فاقوس', 'districts' => []],
                        ['name_en' => 'Hihya',       'name_ar' => 'ههيا', 'districts' => []],
                        ['name_en' => 'Awlad Saqr',  'name_ar' => 'أولاد صقر', 'districts' => []],
                        ['name_en' => 'San El Hagar','name_ar' => 'صان الحجر', 'districts' => []],
                        ['name_en' => 'El Husseiniya','name_ar' => 'الحسينية','districts' => []],
                        ['name_en' => 'Diarb Negm',  'name_ar' => 'ديرب نجم', 'districts' => []],
                        ['name_en' => 'Kafr Saqr',   'name_ar' => 'كفر صقر', 'districts' => []],
                        ['name_en' => 'El Salhia El Gedida','name_ar' => 'الصالحية الجديدة','districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Dakahlia', 'name_ar' => 'الدقهلية', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Mansoura',     'name_ar' => 'المنصورة', 'districts' => []],
                        ['name_en' => 'Talkha',       'name_ar' => 'طلخا', 'districts' => []],
                        ['name_en' => 'Mit Ghamr',    'name_ar' => 'ميت غمر', 'districts' => []],
                        ['name_en' => 'Aga',          'name_ar' => 'أجا', 'districts' => []],
                        ['name_en' => 'Senbellawein', 'name_ar' => 'السنبلاوين', 'districts' => []],
                        ['name_en' => 'Belqas',       'name_ar' => 'بلقاس', 'districts' => []],
                        ['name_en' => 'Sherbin',      'name_ar' => 'شربين', 'districts' => []],
                        ['name_en' => 'Dikirnis',     'name_ar' => 'دكرنس', 'districts' => []],
                        ['name_en' => 'Manzala',      'name_ar' => 'المنزلة', 'districts' => []],
                        ['name_en' => 'Gamasa',       'name_ar' => 'جمصة', 'districts' => []],
                        ['name_en' => 'Nabaroh',      'name_ar' => 'نبروه', 'districts' => []],
                        ['name_en' => 'Mahalat Damana','name_ar' => 'محلة دمنة', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Beheira', 'name_ar' => 'البحيرة', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Damanhour',    'name_ar' => 'دمنهور', 'districts' => []],
                        ['name_en' => 'Kafr El Dawwar','name_ar' => 'كفر الدوار', 'districts' => []],
                        ['name_en' => 'Rashid (Rosetta)','name_ar' => 'رشيد', 'districts' => []],
                        ['name_en' => 'Edku',         'name_ar' => 'إدكو', 'districts' => []],
                        ['name_en' => 'Abu Hommos',   'name_ar' => 'أبو حمص', 'districts' => []],
                        ['name_en' => 'Hosh Issa',    'name_ar' => 'حوش عيسى', 'districts' => []],
                        ['name_en' => 'Shubra Khit',  'name_ar' => 'شبراخيت', 'districts' => []],
                        ['name_en' => 'Mahmoudiya',   'name_ar' => 'المحمودية', 'districts' => []],
                        ['name_en' => 'Rahmaniya',    'name_ar' => 'الرحمانية', 'districts' => []],
                        ['name_en' => 'Itay El Barud','name_ar' => 'إيتاي البارود', 'districts' => []],
                        ['name_en' => 'Wadi El Natrun','name_ar' => 'وادي النطرون','districts' => []],
                        ['name_en' => 'New Nubaria',  'name_ar' => 'النوبارية الجديدة', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Gharbia', 'name_ar' => 'الغربية', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Tanta',        'name_ar' => 'طنطا', 'districts' => []],
                        ['name_en' => 'Mahalla El Kubra','name_ar' => 'المحلة الكبرى', 'districts' => []],
                        ['name_en' => 'Kafr El Zayat','name_ar' => 'كفر الزيات', 'districts' => []],
                        ['name_en' => 'Zifta',        'name_ar' => 'زفتى', 'districts' => []],
                        ['name_en' => 'El Santa',     'name_ar' => 'السنطة', 'districts' => []],
                        ['name_en' => 'Qotour',       'name_ar' => 'قطور', 'districts' => []],
                        ['name_en' => 'Samannoud',    'name_ar' => 'سمنود', 'districts' => []],
                        ['name_en' => 'Basyoun',      'name_ar' => 'بسيون', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Monufia', 'name_ar' => 'المنوفية', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Shibin El Kom','name_ar' => 'شبين الكوم', 'districts' => []],
                        ['name_en' => 'Sadat City',   'name_ar' => 'مدينة السادات', 'districts' => []],
                        ['name_en' => 'Menouf',       'name_ar' => 'منوف', 'districts' => []],
                        ['name_en' => 'Quesna',       'name_ar' => 'قويسنا', 'districts' => []],
                        ['name_en' => 'Berkat El Sabaa','name_ar' => 'بركة السبع', 'districts' => []],
                        ['name_en' => 'Tala',         'name_ar' => 'تلا', 'districts' => []],
                        ['name_en' => 'Ashmoun',      'name_ar' => 'أشمون', 'districts' => []],
                        ['name_en' => 'El Bagour',    'name_ar' => 'الباجور', 'districts' => []],
                        ['name_en' => 'El Shohada',   'name_ar' => 'الشهداء', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Kafr El Sheikh', 'name_ar' => 'كفر الشيخ', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Kafr El Sheikh','name_ar' => 'كفر الشيخ', 'districts' => []],
                        ['name_en' => 'Desouk',       'name_ar' => 'دسوق', 'districts' => []],
                        ['name_en' => 'Foua',         'name_ar' => 'فوة', 'districts' => []],
                        ['name_en' => 'Metoubes',     'name_ar' => 'مطوبس', 'districts' => []],
                        ['name_en' => 'Burullus',     'name_ar' => 'البرلس', 'districts' => []],
                        ['name_en' => 'Baltim',       'name_ar' => 'بلطيم', 'districts' => []],
                        ['name_en' => 'Hamoul',       'name_ar' => 'الحامول', 'districts' => []],
                        ['name_en' => 'Bila',         'name_ar' => 'بيلا', 'districts' => []],
                        ['name_en' => 'Sidi Salem',   'name_ar' => 'سيدي سالم', 'districts' => []],
                        ['name_en' => 'Qellin',       'name_ar' => 'قلين', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Damietta', 'name_ar' => 'دمياط', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Damietta',     'name_ar' => 'دمياط', 'districts' => []],
                        ['name_en' => 'New Damietta', 'name_ar' => 'دمياط الجديدة', 'districts' => []],
                        ['name_en' => 'Ras El Bar',   'name_ar' => 'رأس البر', 'districts' => []],
                        ['name_en' => 'Faraskour',    'name_ar' => 'فارسكور', 'districts' => []],
                        ['name_en' => 'Zarqa',        'name_ar' => 'الزرقا', 'districts' => []],
                        ['name_en' => 'Kafr El Battikh','name_ar' => 'كفر البطيخ', 'districts' => []],
                        ['name_en' => 'Kafr Saad',    'name_ar' => 'كفر سعد', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Port Said', 'name_ar' => 'بورسعيد', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Port Said',    'name_ar' => 'بورسعيد', 'districts' => [
                            ['El Manakh',         'المناخ'],
                            ['El Arab',           'العرب'],
                            ['El Sharq',          'الشرق'],
                            ['El Zohour',         'الزهور'],
                            ['Port Fouad',        'بورفؤاد'],
                            ['El Dawahy',         'الضواحي'],
                        ]],
                    ],
                ],
                [
                    'name_en' => 'Ismailia', 'name_ar' => 'الإسماعيلية', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Ismailia',     'name_ar' => 'الإسماعيلية', 'districts' => []],
                        ['name_en' => 'Fayed',        'name_ar' => 'فايد', 'districts' => []],
                        ['name_en' => 'Qantara West', 'name_ar' => 'القنطرة غرب', 'districts' => []],
                        ['name_en' => 'Qantara East', 'name_ar' => 'القنطرة شرق', 'districts' => []],
                        ['name_en' => 'Tell El Kebir','name_ar' => 'التل الكبير', 'districts' => []],
                        ['name_en' => 'Abu Sweir',    'name_ar' => 'أبو صوير', 'districts' => []],
                        ['name_en' => 'Qassasin',     'name_ar' => 'القصاصين', 'districts' => []],
                        ['name_en' => 'Nefisha',      'name_ar' => 'نفيشة', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Suez', 'name_ar' => 'السويس', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Suez',         'name_ar' => 'السويس', 'districts' => [
                            ['El Arbaeen',        'الأربعين'],
                            ['Faisal',            'فيصل'],
                            ['Ataqa',             'عتاقة'],
                            ['El Ganayen',        'الجنايين'],
                            ['Suez',              'السويس'],
                        ]],
                        ['name_en' => 'Ain Sokhna',   'name_ar' => 'العين السخنة', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'North Sinai', 'name_ar' => 'شمال سيناء', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Arish',        'name_ar' => 'العريش', 'districts' => []],
                        ['name_en' => 'Sheikh Zuweid','name_ar' => 'الشيخ زويد', 'districts' => []],
                        ['name_en' => 'Rafah',        'name_ar' => 'رفح', 'districts' => []],
                        ['name_en' => 'Bir El Abd',   'name_ar' => 'بئر العبد', 'districts' => []],
                        ['name_en' => 'El Hasana',    'name_ar' => 'الحسنة', 'districts' => []],
                        ['name_en' => 'Nakhl',        'name_ar' => 'نخل', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'South Sinai', 'name_ar' => 'جنوب سيناء', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'El Tor',       'name_ar' => 'الطور', 'districts' => []],
                        ['name_en' => 'Sharm El Sheikh','name_ar' => 'شرم الشيخ', 'districts' => [
                            ['Naama Bay',         'خليج نعمة'],
                            ['Hadaba',            'الهضبة'],
                            ['Old Market',        'السوق القديم'],
                            ['Nabq',              'نبق'],
                            ['Shark Bay',         'خليج القرش'],
                        ]],
                        ['name_en' => 'Dahab',        'name_ar' => 'دهب', 'districts' => []],
                        ['name_en' => 'Nuweiba',      'name_ar' => 'نويبع', 'districts' => []],
                        ['name_en' => 'Taba',         'name_ar' => 'طابا', 'districts' => []],
                        ['name_en' => 'Saint Catherine','name_ar' => 'سانت كاترين', 'districts' => []],
                        ['name_en' => 'Abu Rudeis',   'name_ar' => 'أبو رديس', 'districts' => []],
                        ['name_en' => 'Ras Sedr',     'name_ar' => 'رأس سدر', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Faiyum', 'name_ar' => 'الفيوم', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Faiyum',       'name_ar' => 'الفيوم', 'districts' => []],
                        ['name_en' => 'Tamiya',       'name_ar' => 'طامية', 'districts' => []],
                        ['name_en' => 'Sinnuris',     'name_ar' => 'سنورس', 'districts' => []],
                        ['name_en' => 'Ibshaway',     'name_ar' => 'إبشواي', 'districts' => []],
                        ['name_en' => 'Etsa',         'name_ar' => 'إطسا', 'districts' => []],
                        ['name_en' => 'Yousef El Seddik','name_ar' => 'يوسف الصديق', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Beni Suef', 'name_ar' => 'بني سويف', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Beni Suef',    'name_ar' => 'بني سويف', 'districts' => []],
                        ['name_en' => 'New Beni Suef','name_ar' => 'بني سويف الجديدة', 'districts' => []],
                        ['name_en' => 'Beba',         'name_ar' => 'ببا', 'districts' => []],
                        ['name_en' => 'El Wasta',     'name_ar' => 'الواسطى', 'districts' => []],
                        ['name_en' => 'Naser',        'name_ar' => 'ناصر', 'districts' => []],
                        ['name_en' => 'Ihnasia',      'name_ar' => 'إهناسيا', 'districts' => []],
                        ['name_en' => 'El Fashn',     'name_ar' => 'الفشن', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Minya', 'name_ar' => 'المنيا', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Minya',        'name_ar' => 'المنيا', 'districts' => []],
                        ['name_en' => 'New Minya',    'name_ar' => 'المنيا الجديدة', 'districts' => []],
                        ['name_en' => 'Mallawi',      'name_ar' => 'ملوي', 'districts' => []],
                        ['name_en' => 'Beni Mazar',   'name_ar' => 'بني مزار', 'districts' => []],
                        ['name_en' => 'Matay',        'name_ar' => 'مطاي', 'districts' => []],
                        ['name_en' => 'Samalut',      'name_ar' => 'سمالوط', 'districts' => []],
                        ['name_en' => 'Maghagha',     'name_ar' => 'مغاغة', 'districts' => []],
                        ['name_en' => 'Abu Qurqas',   'name_ar' => 'أبو قرقاص', 'districts' => []],
                        ['name_en' => 'Deir Mawas',   'name_ar' => 'دير مواس', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Asyut', 'name_ar' => 'أسيوط', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Asyut',        'name_ar' => 'أسيوط', 'districts' => []],
                        ['name_en' => 'New Asyut',    'name_ar' => 'أسيوط الجديدة', 'districts' => []],
                        ['name_en' => 'Dairut',       'name_ar' => 'ديروط', 'districts' => []],
                        ['name_en' => 'Manfalut',     'name_ar' => 'منفلوط', 'districts' => []],
                        ['name_en' => 'El Qusiya',    'name_ar' => 'القوصية', 'districts' => []],
                        ['name_en' => 'Abnoub',       'name_ar' => 'أبنوب', 'districts' => []],
                        ['name_en' => 'El Badari',    'name_ar' => 'البداري', 'districts' => []],
                        ['name_en' => 'Sahel Selim',  'name_ar' => 'ساحل سليم', 'districts' => []],
                        ['name_en' => 'Abu Tig',      'name_ar' => 'أبو تيج', 'districts' => []],
                        ['name_en' => 'El Ghanayem',  'name_ar' => 'الغنايم', 'districts' => []],
                        ['name_en' => 'El Fath',      'name_ar' => 'الفتح', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Sohag', 'name_ar' => 'سوهاج', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Sohag',        'name_ar' => 'سوهاج', 'districts' => []],
                        ['name_en' => 'New Sohag',    'name_ar' => 'سوهاج الجديدة', 'districts' => []],
                        ['name_en' => 'Akhmim',       'name_ar' => 'أخميم', 'districts' => []],
                        ['name_en' => 'Tahta',        'name_ar' => 'طهطا', 'districts' => []],
                        ['name_en' => 'El Maragha',   'name_ar' => 'المراغة', 'districts' => []],
                        ['name_en' => 'El Balyana',   'name_ar' => 'البلينا', 'districts' => []],
                        ['name_en' => 'Girga',        'name_ar' => 'جرجا', 'districts' => []],
                        ['name_en' => 'Juhaynah',     'name_ar' => 'جهينة', 'districts' => []],
                        ['name_en' => 'Saqulta',      'name_ar' => 'ساقلتة', 'districts' => []],
                        ['name_en' => 'El Monsha',    'name_ar' => 'المنشاة', 'districts' => []],
                        ['name_en' => 'Dar El Salam', 'name_ar' => 'دار السلام', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Qena', 'name_ar' => 'قنا', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Qena',         'name_ar' => 'قنا', 'districts' => []],
                        ['name_en' => 'New Qena',     'name_ar' => 'قنا الجديدة', 'districts' => []],
                        ['name_en' => 'Nag Hammadi', 'name_ar' => 'نجع حمادي', 'districts' => []],
                        ['name_en' => 'Dishna',       'name_ar' => 'دشنا', 'districts' => []],
                        ['name_en' => 'Qus',          'name_ar' => 'قوص', 'districts' => []],
                        ['name_en' => 'Naqada',       'name_ar' => 'نقادة', 'districts' => []],
                        ['name_en' => 'Farshut',      'name_ar' => 'فرشوط', 'districts' => []],
                        ['name_en' => 'Abu Tesht',    'name_ar' => 'أبو تشت', 'districts' => []],
                        ['name_en' => 'El Waqf',      'name_ar' => 'الوقف', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Luxor', 'name_ar' => 'الأقصر', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Luxor',        'name_ar' => 'الأقصر', 'districts' => [
                            ['Karnak',            'الكرنك'],
                            ['East Bank',         'البر الشرقي'],
                            ['West Bank',         'البر الغربي'],
                            ['Luxor City',        'مدينة الأقصر'],
                        ]],
                        ['name_en' => 'New Luxor',    'name_ar' => 'الأقصر الجديدة', 'districts' => []],
                        ['name_en' => 'Esna',         'name_ar' => 'إسنا', 'districts' => []],
                        ['name_en' => 'Armant',       'name_ar' => 'أرمنت', 'districts' => []],
                        ['name_en' => 'El Toud',      'name_ar' => 'الطود', 'districts' => []],
                        ['name_en' => 'El Zeiniya',   'name_ar' => 'الزينية', 'districts' => []],
                        ['name_en' => 'El Bayadeya',  'name_ar' => 'البياضية', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Aswan', 'name_ar' => 'أسوان', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Aswan',        'name_ar' => 'أسوان', 'districts' => [
                            ['Aswan City',        'مدينة أسوان'],
                            ['Sahari',            'سحاري'],
                            ['Elephantine',       'إلفنتين'],
                            ['Sehel',             'سهيل'],
                        ]],
                        ['name_en' => 'New Aswan',    'name_ar' => 'أسوان الجديدة', 'districts' => []],
                        ['name_en' => 'Edfu',         'name_ar' => 'إدفو', 'districts' => []],
                        ['name_en' => 'Daraw',        'name_ar' => 'دراو', 'districts' => []],
                        ['name_en' => 'Kom Ombo',     'name_ar' => 'كوم أمبو', 'districts' => []],
                        ['name_en' => 'Nasr El Nuba', 'name_ar' => 'نصر النوبة', 'districts' => []],
                        ['name_en' => 'Abu Simbel',   'name_ar' => 'أبو سمبل', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Red Sea', 'name_ar' => 'البحر الأحمر', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Hurghada',     'name_ar' => 'الغردقة', 'districts' => [
                            ['Sakkala',           'السقالة'],
                            ['El Dahar',          'الدهار'],
                            ['Mubarak 5',         'مبارك 5'],
                            ['Mubarak 6',         'مبارك 6'],
                            ['Mubarak 7',         'مبارك 7'],
                            ['El Memsha',         'الممشى'],
                            ['Magawish',          'المجاويش'],
                            ['Sahl Hasheesh',     'سهل حشيش'],
                            ['Makadi Bay',        'خليج مكادي'],
                        ]],
                        ['name_en' => 'Safaga',       'name_ar' => 'سفاجا', 'districts' => []],
                        ['name_en' => 'El Qoseir',    'name_ar' => 'القصير', 'districts' => []],
                        ['name_en' => 'Marsa Alam',   'name_ar' => 'مرسى علم', 'districts' => []],
                        ['name_en' => 'Shalateen',    'name_ar' => 'شلاتين', 'districts' => []],
                        ['name_en' => 'Halayeb',      'name_ar' => 'حلايب', 'districts' => []],
                        ['name_en' => 'Ras Gharib',   'name_ar' => 'رأس غارب', 'districts' => []],
                        ['name_en' => 'El Gouna',     'name_ar' => 'الجونة', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'New Valley', 'name_ar' => 'الوادي الجديد', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Kharga',       'name_ar' => 'الخارجة', 'districts' => []],
                        ['name_en' => 'Dakhla',       'name_ar' => 'الداخلة', 'districts' => []],
                        ['name_en' => 'Farafra',      'name_ar' => 'الفرافرة', 'districts' => []],
                        ['name_en' => 'Balat',        'name_ar' => 'بلاط', 'districts' => []],
                        ['name_en' => 'Paris',        'name_ar' => 'باريس', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Matrouh', 'name_ar' => 'مطروح', 'type' => 'governorate',
                    'cities' => [
                        ['name_en' => 'Marsa Matrouh','name_ar' => 'مرسى مطروح', 'districts' => []],
                        ['name_en' => 'El Hammam',    'name_ar' => 'الحمام', 'districts' => []],
                        ['name_en' => 'El Alamein',   'name_ar' => 'العلمين', 'districts' => []],
                        ['name_en' => 'New Alamein',  'name_ar' => 'العلمين الجديدة', 'districts' => []],
                        ['name_en' => 'Sidi Barani',  'name_ar' => 'سيدي براني', 'districts' => []],
                        ['name_en' => 'Salloum',      'name_ar' => 'السلوم', 'districts' => []],
                        ['name_en' => 'Siwa',         'name_ar' => 'سيوة', 'districts' => []],
                        ['name_en' => 'El Negeila',   'name_ar' => 'النجيلة', 'districts' => []],
                        ['name_en' => 'El Dabaa',     'name_ar' => 'الضبعة', 'districts' => []],
                        ['name_en' => 'North Coast',  'name_ar' => 'الساحل الشمالي', 'districts' => []],
                    ],
                ],
            ],
        ];
    }

    /* ─────────────────────── SAUDI ARABIA ─────────────────────── */

    private function saudiArabia(): array
    {
        return [
            'code' => 'SA',
            'name_ar' => 'المملكة العربية السعودية',
            'name_en' => 'Saudi Arabia',
            'sort_order' => 20,
            'states' => [
                [
                    'name_en' => 'Riyadh', 'name_ar' => 'منطقة الرياض', 'type' => 'region',
                    'cities' => [
                        ['name_en' => 'Riyadh', 'name_ar' => 'الرياض', 'districts' => [
                            ['Olaya',             'العليا'],
                            ['Sulaimaniyah',      'السليمانية'],
                            ['Malaz',             'الملز'],
                            ['Murabba',           'المربع'],
                            ['Diplomatic Quarter','حي السفارات'],
                            ['Hittin',            'حطين'],
                            ['Yasmin',            'الياسمين'],
                            ['Narjis',            'النرجس'],
                            ['Rabwa',             'الربوة'],
                            ['Rabi',              'الربيع'],
                            ['Rawda',             'الروضة'],
                            ['Wurud',             'الورود'],
                            ['Roabi',             'الروابي'],
                            ['Ghadeer',           'الغدير'],
                            ['Falah',             'الفلاح'],
                            ['Aqiq',              'العقيق'],
                            ['Andalus',           'الأندلس'],
                            ['Wadi Laban',        'وادي لبن'],
                            ['Suwaidi',           'السويدي'],
                            ['King Fahd District','حي الملك فهد'],
                            ['King Abdulaziz District','حي الملك عبدالعزيز'],
                            ['Salhiya',           'الصالحية'],
                            ['Naseem',            'النسيم'],
                            ['Khuzama',           'الخزامى'],
                            ['Murouj',            'المروج'],
                            ['Yarmouk',           'اليرموك'],
                            ['Sahafa',            'الصحافة'],
                            ['Manakh',            'المناخ'],
                            ['Diraiyah',          'الدرعية'],
                            ['Olaisha',           'العليشة'],
                            ['Manfouha',          'منفوحة'],
                            ['Shifa',             'الشفاء'],
                            ['Quds',              'القدس'],
                            ['Khalidiya',         'الخالدية'],
                            ['Nakheel',           'النخيل'],
                            ['Munisiyah',         'المونسية'],
                            ['Nuzhah',            'النزهة'],
                            ['Izdihar',           'الازدهار'],
                            ['Worood',            'الورود'],
                            ['Batha',             'البطحاء'],
                            ['Deerah',            'الديرة'],
                        ]],
                        ['name_en' => 'Diriyah',    'name_ar' => 'الدرعية', 'districts' => []],
                        ['name_en' => 'Al Kharj',   'name_ar' => 'الخرج', 'districts' => []],
                        ['name_en' => 'Al Majma\'ah','name_ar' => 'المجمعة', 'districts' => []],
                        ['name_en' => 'Al Zulfi',   'name_ar' => 'الزلفي', 'districts' => []],
                        ['name_en' => 'Wadi El Dawasir','name_ar' => 'وادي الدواسر', 'districts' => []],
                        ['name_en' => 'Afif',       'name_ar' => 'عفيف', 'districts' => []],
                        ['name_en' => 'Quwaiyah',   'name_ar' => 'القويعية', 'districts' => []],
                        ['name_en' => 'Dawadmi',    'name_ar' => 'الدوادمي', 'districts' => []],
                        ['name_en' => 'Hawtat Bani Tamim','name_ar' => 'حوطة بني تميم', 'districts' => []],
                        ['name_en' => 'Hareed',     'name_ar' => 'الحريق', 'districts' => []],
                        ['name_en' => 'Aflaj',      'name_ar' => 'الأفلاج', 'districts' => []],
                        ['name_en' => 'Sulayyil',   'name_ar' => 'السليل', 'districts' => []],
                        ['name_en' => 'Shaqra',     'name_ar' => 'شقراء', 'districts' => []],
                        ['name_en' => 'Thadiq',     'name_ar' => 'ثادق', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Makkah', 'name_ar' => 'منطقة مكة المكرمة', 'type' => 'region',
                    'cities' => [
                        ['name_en' => 'Mecca', 'name_ar' => 'مكة المكرمة', 'districts' => [
                            ['Aziziyah',          'العزيزية'],
                            ['Awali',             'العوالي'],
                            ['Mansour',           'المنصور'],
                            ['Naseem',            'النسيم'],
                            ['Rusaifa',           'الرصيفة'],
                            ['Misfalah',          'المسفلة'],
                            ['Hijra',             'الهجرة'],
                            ['Zahra',             'الزهراء'],
                            ['Shesha',            'الشيشة'],
                            ['Ka\'akiyah',        'الكعكية'],
                            ['Sharaie',           'الشرائع'],
                            ['Hindawiya',         'الهنداوية'],
                            ['Adel',              'العدل'],
                            ['Jamoum',            'الجموم'],
                            ['Rehab',             'الرحاب'],
                            ['Tanaim',            'التنعيم'],
                        ]],
                        ['name_en' => 'Jeddah', 'name_ar' => 'جدة', 'districts' => [
                            ['Al-Hamra',          'الحمراء'],
                            ['Andalus',           'الأندلس'],
                            ['Salamah',           'السلامة'],
                            ['Faisaliyah',        'الفيصلية'],
                            ['Naseem',            'النسيم'],
                            ['Rawda',             'الروضة'],
                            ['Khalidiyah',        'الخالدية'],
                            ['Zahra',             'الزهراء'],
                            ['Murjan',            'المرجان'],
                            ['Shati',             'الشاطئ'],
                            ['Obhur Al-Shamaliyah','أبحر الشمالية'],
                            ['Obhur Al-Janubiyah','أبحر الجنوبية'],
                            ['Bani Malik',        'بني مالك'],
                            ['Sharafiyah',        'الشرفية'],
                            ['Balad',             'البلد'],
                            ['Kandara',           'الكندرة'],
                            ['Aziziyah',          'العزيزية'],
                            ['Naeem',             'النعيم'],
                            ['Safa',              'الصفا'],
                            ['Marwa',             'المروة'],
                            ['Rayyan',            'الريان'],
                            ['Jamia',             'الجامعة'],
                            ['Khomra',            'الخمرة'],
                            ['Mushrifah',         'المشرفة'],
                            ['Petromin',          'البترومين'],
                        ]],
                        ['name_en' => 'Taif',       'name_ar' => 'الطائف', 'districts' => [
                            ['Shafa',             'الشفا'],
                            ['Hada',              'الهدا'],
                            ['Wadi Mehrm',        'وادي محرم'],
                            ['Quwa',              'القوة'],
                            ['Tabari',            'الطبري'],
                            ['Shihar',            'شهار'],
                        ]],
                        ['name_en' => 'Rabigh',     'name_ar' => 'رابغ', 'districts' => []],
                        ['name_en' => 'Khulays',    'name_ar' => 'خليص', 'districts' => []],
                        ['name_en' => 'Al Lith',    'name_ar' => 'الليث', 'districts' => []],
                        ['name_en' => 'Qunfudah',   'name_ar' => 'القنفذة', 'districts' => []],
                        ['name_en' => 'Khulayyah',  'name_ar' => 'الخليفة', 'districts' => []],
                        ['name_en' => 'Adham',      'name_ar' => 'أضم', 'districts' => []],
                        ['name_en' => 'Al Kamil',   'name_ar' => 'الكامل', 'districts' => []],
                        ['name_en' => 'Turbah',     'name_ar' => 'تربة', 'districts' => []],
                        ['name_en' => 'Mizalif',    'name_ar' => 'مزاحمية', 'districts' => []],
                        ['name_en' => 'Ranyah',     'name_ar' => 'رنية', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Madinah', 'name_ar' => 'منطقة المدينة المنورة', 'type' => 'region',
                    'cities' => [
                        ['name_en' => 'Medina', 'name_ar' => 'المدينة المنورة', 'districts' => [
                            ['Quba',              'قباء'],
                            ['Aziziyah',          'العزيزية'],
                            ['Sayh',              'السيح'],
                            ['Khalidiyah',        'الخالدية'],
                            ['Iskan',             'الإسكان'],
                            ['Shoraibat',         'الشريبات'],
                            ['Bani Bayadah',      'بني بياضة'],
                            ['Aridh',             'العريض'],
                            ['Sayyid al-Shuhada', 'سيد الشهداء'],
                            ['Awali',             'العوالي'],
                            ['Hafia',             'الحفيا'],
                            ['Sultanah',          'السلطانة'],
                            ['Naqa',              'النقا'],
                            ['Ihn',               'إحنة'],
                        ]],
                        ['name_en' => 'Yanbu',      'name_ar' => 'ينبع', 'districts' => [
                            ['Yanbu Industrial',  'ينبع الصناعية'],
                            ['Yanbu Bahr',        'ينبع البحر'],
                            ['Yanbu Nakhl',       'ينبع النخل'],
                        ]],
                        ['name_en' => 'Al Ula',     'name_ar' => 'العلا', 'districts' => []],
                        ['name_en' => 'Badr',       'name_ar' => 'بدر', 'districts' => []],
                        ['name_en' => 'Khaybar',    'name_ar' => 'خيبر', 'districts' => []],
                        ['name_en' => 'Al Henakiyah','name_ar' => 'الحناكية', 'districts' => []],
                        ['name_en' => 'Al Mahd',    'name_ar' => 'المهد', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Eastern Province', 'name_ar' => 'المنطقة الشرقية', 'type' => 'region',
                    'cities' => [
                        ['name_en' => 'Dammam', 'name_ar' => 'الدمام', 'districts' => [
                            ['Faisaliyah',        'الفيصلية'],
                            ['Shati Al-Sharqi',   'الشاطئ الشرقي'],
                            ['Shati Al-Gharbi',   'الشاطئ الغربي'],
                            ['Naseem',            'النسيم'],
                            ['Adamah',            'الأدامة'],
                            ['Uhud',              'أحد'],
                            ['Manar',             'المنار'],
                            ['Aziziyah',          'العزيزية'],
                            ['Mazrouiyah',        'المزروعية'],
                            ['Rabie',             'الربيع'],
                            ['Mintazh',           'المنتزه'],
                            ['Andalus',           'الأندلس'],
                            ['Tubaishi',          'الطبيشي'],
                            ['Anwar',             'الأنوار'],
                            ['Saif',              'الصيف'],
                            ['Salam',             'السلام'],
                            ['Jalawiyah',         'الجلوية'],
                        ]],
                        ['name_en' => 'Al Khobar', 'name_ar' => 'الخبر', 'districts' => [
                            ['Bandariyah',        'البندرية'],
                            ['Thuqbah',           'الثقبة'],
                            ['Khuzama',           'الخزامى'],
                            ['Aqrabiyah',         'العقربية'],
                            ['Bawadi',            'البوادي'],
                            ['Rakah',             'الراكة'],
                            ['Hada',              'الهدا'],
                            ['Olaya',             'العليا'],
                            ['Lulu',              'اللؤلؤ'],
                            ['Khobar Janoubiyah', 'الخبر الجنوبية'],
                            ['Khobar Shamaliyah', 'الخبر الشمالية'],
                        ]],
                        ['name_en' => 'Dhahran',    'name_ar' => 'الظهران', 'districts' => [
                            ['ARAMCO Camp',       'مخيم أرامكو'],
                            ['Doha',              'الدوحة'],
                            ['Hizam',             'الحزام'],
                            ['Tahliah',           'التحلية'],
                        ]],
                        ['name_en' => 'Jubail',     'name_ar' => 'الجبيل', 'districts' => [
                            ['Jubail Industrial', 'الجبيل الصناعية'],
                            ['Jubail Balad',      'الجبيل البلد'],
                            ['Fanateer',          'الفناتير'],
                            ['Deffi',             'الدفي'],
                        ]],
                        ['name_en' => 'Qatif',      'name_ar' => 'القطيف', 'districts' => []],
                        ['name_en' => 'Hofuf (Al Ahsa)','name_ar' => 'الهفوف (الأحساء)', 'districts' => [
                            ['Mubarraz',          'المبرز'],
                            ['Hofuf',             'الهفوف'],
                            ['Oyoun',             'العيون'],
                            ['Mansoura',          'المنصورة'],
                            ['Salmaniyah',        'السلمانية'],
                        ]],
                        ['name_en' => 'Khafji',     'name_ar' => 'الخفجي', 'districts' => []],
                        ['name_en' => 'Ras Tanura', 'name_ar' => 'رأس تنورة', 'districts' => []],
                        ['name_en' => 'Saihat',     'name_ar' => 'سيهات', 'districts' => []],
                        ['name_en' => 'Safwa',      'name_ar' => 'صفوى', 'districts' => []],
                        ['name_en' => 'Tarout',     'name_ar' => 'تاروت', 'districts' => []],
                        ['name_en' => 'Nairyah',    'name_ar' => 'النعيرية', 'districts' => []],
                        ['name_en' => 'Qaryat al-Ulya','name_ar' => 'قرية العليا', 'districts' => []],
                        ['name_en' => 'Hafar Al Batin','name_ar' => 'حفر الباطن', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Asir', 'name_ar' => 'منطقة عسير', 'type' => 'region',
                    'cities' => [
                        ['name_en' => 'Abha',       'name_ar' => 'أبها', 'districts' => [
                            ['Khasha',            'الخشع'],
                            ['Manhal',            'المنهل'],
                            ['Muftaha',           'المفتاحة'],
                            ['Nasab',             'النصب'],
                            ['Shifa',             'الشفا'],
                        ]],
                        ['name_en' => 'Khamis Mushait','name_ar' => 'خميس مشيط', 'districts' => []],
                        ['name_en' => 'Bisha',      'name_ar' => 'بيشة', 'districts' => []],
                        ['name_en' => 'Mahayel Asir','name_ar' => 'محايل عسير', 'districts' => []],
                        ['name_en' => 'Tathleeth',  'name_ar' => 'تثليث', 'districts' => []],
                        ['name_en' => 'Sarat Abidah','name_ar' => 'سراة عبيدة', 'districts' => []],
                        ['name_en' => 'Rijal Almaa','name_ar' => 'رجال ألمع', 'districts' => []],
                        ['name_en' => 'Dhahran Al Janub','name_ar' => 'ظهران الجنوب', 'districts' => []],
                        ['name_en' => 'Ahad Rafidah','name_ar' => 'أحد رفيدة', 'districts' => []],
                        ['name_en' => 'Al Majardah','name_ar' => 'المجاردة', 'districts' => []],
                        ['name_en' => 'Balqarn',    'name_ar' => 'بلقرن', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Tabuk', 'name_ar' => 'منطقة تبوك', 'type' => 'region',
                    'cities' => [
                        ['name_en' => 'Tabuk',      'name_ar' => 'تبوك', 'districts' => [
                            ['Tubaishi',          'الطبيشي'],
                            ['Faisaliyah',        'الفيصلية'],
                            ['Mahrajan',          'المهرجان'],
                            ['Rawdah',            'الروضة'],
                            ['Salmaniyah',        'السلمانية'],
                            ['Aliyah',            'العلياء'],
                        ]],
                        ['name_en' => 'Duba',       'name_ar' => 'ضباء', 'districts' => []],
                        ['name_en' => 'Tayma',      'name_ar' => 'تيماء', 'districts' => []],
                        ['name_en' => 'Umluj',      'name_ar' => 'أملج', 'districts' => []],
                        ['name_en' => 'Al Wajh',    'name_ar' => 'الوجه', 'districts' => []],
                        ['name_en' => 'NEOM',       'name_ar' => 'نيوم', 'districts' => []],
                        ['name_en' => 'Haql',       'name_ar' => 'حقل', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Hail', 'name_ar' => 'منطقة حائل', 'type' => 'region',
                    'cities' => [
                        ['name_en' => 'Hail',       'name_ar' => 'حائل', 'districts' => []],
                        ['name_en' => 'Baqaa',      'name_ar' => 'بقعاء', 'districts' => []],
                        ['name_en' => 'Shinan',     'name_ar' => 'الشنان', 'districts' => []],
                        ['name_en' => 'Al Ghazalah','name_ar' => 'الغزالة', 'districts' => []],
                        ['name_en' => 'Al Sulaymi', 'name_ar' => 'السليمي', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Northern Borders', 'name_ar' => 'منطقة الحدود الشمالية', 'type' => 'region',
                    'cities' => [
                        ['name_en' => 'Arar',       'name_ar' => 'عرعر', 'districts' => []],
                        ['name_en' => 'Rafha',      'name_ar' => 'رفحاء', 'districts' => []],
                        ['name_en' => 'Turaif',     'name_ar' => 'طريف', 'districts' => []],
                        ['name_en' => 'Al Uwayqilah','name_ar' => 'العويقيلة', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Jazan', 'name_ar' => 'منطقة جازان', 'type' => 'region',
                    'cities' => [
                        ['name_en' => 'Jazan',      'name_ar' => 'جازان', 'districts' => []],
                        ['name_en' => 'Sabya',      'name_ar' => 'صبيا', 'districts' => []],
                        ['name_en' => 'Abu Arish',  'name_ar' => 'أبو عريش', 'districts' => []],
                        ['name_en' => 'Samtah',     'name_ar' => 'صامطة', 'districts' => []],
                        ['name_en' => 'Ahad Al Masarihah','name_ar' => 'أحد المسارحة', 'districts' => []],
                        ['name_en' => 'Bish',       'name_ar' => 'بيش', 'districts' => []],
                        ['name_en' => 'Damad',      'name_ar' => 'ضمد', 'districts' => []],
                        ['name_en' => 'Farasan',    'name_ar' => 'فرسان', 'districts' => []],
                        ['name_en' => 'Al Aridah',  'name_ar' => 'العارضة', 'districts' => []],
                        ['name_en' => 'Al Dair',    'name_ar' => 'الدائر', 'districts' => []],
                        ['name_en' => 'Al Reeth',   'name_ar' => 'الريث', 'districts' => []],
                        ['name_en' => 'Hareeb',     'name_ar' => 'حريب', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Najran', 'name_ar' => 'منطقة نجران', 'type' => 'region',
                    'cities' => [
                        ['name_en' => 'Najran',     'name_ar' => 'نجران', 'districts' => []],
                        ['name_en' => 'Sharurah',   'name_ar' => 'شرورة', 'districts' => []],
                        ['name_en' => 'Hubuna',     'name_ar' => 'حبونا', 'districts' => []],
                        ['name_en' => 'Badr Al Janoub','name_ar' => 'بدر الجنوب', 'districts' => []],
                        ['name_en' => 'Yadamah',    'name_ar' => 'يدمة', 'districts' => []],
                        ['name_en' => 'Khubash',    'name_ar' => 'خباش', 'districts' => []],
                        ['name_en' => 'Thar',       'name_ar' => 'ثار', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Al Bahah', 'name_ar' => 'منطقة الباحة', 'type' => 'region',
                    'cities' => [
                        ['name_en' => 'Al Bahah',   'name_ar' => 'الباحة', 'districts' => []],
                        ['name_en' => 'Baljurashi', 'name_ar' => 'بلجرشي', 'districts' => []],
                        ['name_en' => 'Al Mandaq',  'name_ar' => 'المندق', 'districts' => []],
                        ['name_en' => 'Al Mikhwah', 'name_ar' => 'المخواة', 'districts' => []],
                        ['name_en' => 'Qilwah',     'name_ar' => 'قلوة', 'districts' => []],
                        ['name_en' => 'Al Aqiq',    'name_ar' => 'العقيق', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Al Jouf', 'name_ar' => 'منطقة الجوف', 'type' => 'region',
                    'cities' => [
                        ['name_en' => 'Sakaka',     'name_ar' => 'سكاكا', 'districts' => []],
                        ['name_en' => 'Qurayyat',   'name_ar' => 'القريات', 'districts' => []],
                        ['name_en' => 'Dumat Al Jandal','name_ar' => 'دومة الجندل', 'districts' => []],
                        ['name_en' => 'Tubarjal',   'name_ar' => 'طبرجل', 'districts' => []],
                    ],
                ],
                [
                    'name_en' => 'Qassim', 'name_ar' => 'منطقة القصيم', 'type' => 'region',
                    'cities' => [
                        ['name_en' => 'Buraidah',   'name_ar' => 'بريدة', 'districts' => [
                            ['Faisaliyah',        'الفيصلية'],
                            ['Khabra',            'الخبراء'],
                            ['Rayyan',            'الريان'],
                            ['Murouj',            'المروج'],
                            ['Rawdah',            'الروضة'],
                            ['Iskan',             'الإسكان'],
                        ]],
                        ['name_en' => 'Unaizah',    'name_ar' => 'عنيزة', 'districts' => []],
                        ['name_en' => 'Al Rass',    'name_ar' => 'الرس', 'districts' => []],
                        ['name_en' => 'Al Mithnab', 'name_ar' => 'المذنب', 'districts' => []],
                        ['name_en' => 'Al Badaye',  'name_ar' => 'البدائع', 'districts' => []],
                        ['name_en' => 'Al Bukayriyah','name_ar' => 'البكيرية', 'districts' => []],
                        ['name_en' => 'Riyadh Al Khabra','name_ar' => 'رياض الخبراء', 'districts' => []],
                        ['name_en' => 'Uyun Al Jiwa','name_ar' => 'عيون الجواء', 'districts' => []],
                        ['name_en' => 'Al Asyah',   'name_ar' => 'الأسياح', 'districts' => []],
                        ['name_en' => 'Al Nabhaniyah','name_ar' => 'النبهانية', 'districts' => []],
                        ['name_en' => 'Dhariyah',   'name_ar' => 'ضرية', 'districts' => []],
                    ],
                ],
            ],
        ];
    }
}
