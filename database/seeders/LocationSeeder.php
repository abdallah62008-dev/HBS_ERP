<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Database\Seeder;

/**
 * Phase 2 — Egypt & Saudi Arabia location seed.
 *
 * Idempotent via updateOrCreate keyed on natural identifiers:
 *   - Country by `code`
 *   - State by (country_id, name_ar)
 *   - City by (state_id, name_ar)
 *
 * Re-running this seeder updates labels but never inserts duplicates.
 */
class LocationSeeder extends Seeder
{
    public function run(): void
    {
        // ---- Countries ----
        $eg = Country::updateOrCreate(
            ['code' => 'EG'],
            ['name_ar' => 'مصر', 'name_en' => 'Egypt', 'is_active' => true, 'sort_order' => 1],
        );
        $sa = Country::updateOrCreate(
            ['code' => 'SA'],
            ['name_ar' => 'السعودية', 'name_en' => 'Saudi Arabia', 'is_active' => true, 'sort_order' => 2],
        );

        // ---- Egypt: governorates ----
        $egGovernorates = [
            'القاهرة' => ['Cairo'],
            'الجيزة' => ['Giza'],
            'الإسكندرية' => ['Alexandria'],
            'البحيرة' => ['Beheira'],
            'القليوبية' => ['Qalyubia'],
            'الدقهلية' => ['Dakahlia'],
            'الشرقية' => ['Sharqia'],
            'الغربية' => ['Gharbia'],
            'المنوفية' => ['Monufia'],
            'كفر الشيخ' => ['Kafr el-Sheikh'],
            'دمياط' => ['Damietta'],
            'بورسعيد' => ['Port Said'],
            'الإسماعيلية' => ['Ismailia'],
            'السويس' => ['Suez'],
            'الفيوم' => ['Faiyum'],
            'بني سويف' => ['Beni Suef'],
            'المنيا' => ['Minya'],
            'أسيوط' => ['Asyut'],
            'سوهاج' => ['Sohag'],
            'قنا' => ['Qena'],
            'الأقصر' => ['Luxor'],
            'أسوان' => ['Aswan'],
            'البحر الأحمر' => ['Red Sea'],
            'الوادي الجديد' => ['New Valley'],
            'مطروح' => ['Matrouh'],
            'شمال سيناء' => ['North Sinai'],
            'جنوب سيناء' => ['South Sinai'],
        ];

        $sortOrder = 0;
        foreach ($egGovernorates as $arName => [$enName]) {
            $sortOrder++;
            State::updateOrCreate(
                ['country_id' => $eg->id, 'name_ar' => $arName],
                [
                    'name_en' => $enName,
                    'type' => 'governorate',
                    'is_active' => true,
                    'sort_order' => $sortOrder,
                ],
            );
        }

        // ---- Saudi: regions ----
        $saRegions = [
            'الرياض' => ['Riyadh'],
            'مكة المكرمة' => ['Makkah'],
            'المدينة المنورة' => ['Madinah'],
            'القصيم' => ['Qassim'],
            'المنطقة الشرقية' => ['Eastern Province'],
            'عسير' => ['Asir'],
            'تبوك' => ['Tabuk'],
            'حائل' => ['Hail'],
            'الحدود الشمالية' => ['Northern Borders'],
            'جازان' => ['Jazan'],
            'نجران' => ['Najran'],
            'الباحة' => ['Al Bahah'],
            'الجوف' => ['Al Jouf'],
        ];

        $sortOrder = 0;
        foreach ($saRegions as $arName => [$enName]) {
            $sortOrder++;
            State::updateOrCreate(
                ['country_id' => $sa->id, 'name_ar' => $arName],
                [
                    'name_en' => $enName,
                    'type' => 'region',
                    'is_active' => true,
                    'sort_order' => $sortOrder,
                ],
            );
        }

        // ---- Egypt: starter cities ----
        $egCities = [
            'القاهرة' => ['مدينة نصر', 'مصر الجديدة', 'المعادي', 'التجمع الخامس', 'شبرا', 'وسط البلد'],
            'الجيزة' => ['الدقي', 'المهندسين', 'الهرم', 'فيصل', 'السادس من أكتوبر', 'الشيخ زايد'],
            'الإسكندرية' => ['سموحة', 'سيدي جابر', 'محرم بك', 'العجمي', 'ميامي'],
            'البحيرة' => ['دمنهور', 'كفر الدوار', 'رشيد', 'إيتاي البارود', 'أبو حمص'],
            'الشرقية' => ['الزقازيق', 'العاشر من رمضان', 'بلبيس'],
            'الدقهلية' => ['المنصورة', 'طلخا', 'ميت غمر'],
            'القليوبية' => ['بنها', 'شبرا الخيمة', 'قليوب'],
        ];

        // ---- Saudi: starter cities ----
        $saCities = [
            'الرياض' => ['الرياض', 'الدرعية', 'الخرج'],
            'مكة المكرمة' => ['مكة', 'جدة', 'الطائف'],
            'المدينة المنورة' => ['المدينة المنورة', 'ينبع'],
            'المنطقة الشرقية' => ['الدمام', 'الخبر', 'الظهران', 'الأحساء', 'الجبيل'],
            'القصيم' => ['بريدة', 'عنيزة', 'الرس'],
            'عسير' => ['أبها', 'خميس مشيط'],
            'تبوك' => ['تبوك'],
            'حائل' => ['حائل'],
            'جازان' => ['جازان'],
            'نجران' => ['نجران'],
            'الباحة' => ['الباحة'],
            'الجوف' => ['سكاكا'],
            'الحدود الشمالية' => ['عرعر'],
        ];

        $this->seedCities($eg->id, $egCities);
        $this->seedCities($sa->id, $saCities);
    }

    /**
     * @param  array<string, array<int,string>>  $cityMap
     */
    private function seedCities(int $countryId, array $cityMap): void
    {
        foreach ($cityMap as $stateNameAr => $cities) {
            $state = State::where('country_id', $countryId)
                ->where('name_ar', $stateNameAr)
                ->first();
            if (! $state) {
                continue;
            }

            $sortOrder = 0;
            foreach ($cities as $cityNameAr) {
                $sortOrder++;
                City::updateOrCreate(
                    ['state_id' => $state->id, 'name_ar' => $cityNameAr],
                    [
                        'is_active' => true,
                        'sort_order' => $sortOrder,
                    ],
                );
            }
        }
    }
}
