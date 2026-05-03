import { useEffect, useMemo } from 'react';
import FormField from '@/Components/FormField';

/**
 * Cascading Country → State → City dropdown for Egypt + Saudi Arabia.
 *
 * Selecting a country resets state + city. Selecting a state resets city.
 * The component writes the Arabic display labels (or the country English
 * label, since that's what existing records use) into the parent form's
 * existing string columns — no schema change needed for backward compat.
 *
 * Props:
 *   - locations:    array<{id, code, name_ar, name_en, states: [...]}>
 *   - country:      string (currently-selected country, by name)
 *   - state:        string (currently-selected state/governorate, by name_ar)
 *   - city:         string (currently-selected city, by name_ar OR free text)
 *   - onChange({country, state, city, country_code}):
 *                    fires whenever any of the three change. country is the
 *                    English name (matches existing data), state/city are
 *                    the Arabic labels (what operators see and pick).
 *   - errors:       optional {country, state, city} — passed to FormField
 *   - required:     boolean — applies to country + city (governorate stays
 *                    optional since some Saudi cities map straight to a
 *                    region with the same name)
 */
export default function LocationSelect({
    locations = [],
    country = '',
    state = '',
    city = '',
    onChange,
    errors = {},
    required = false,
}) {
    // Resolve the current country object — match by English name first
    // (existing data uses "Egypt" / "Saudi Arabia"), then fall back to
    // Arabic name in case an admin manually flipped the value.
    const currentCountry = useMemo(() => {
        if (!country) return null;
        return (
            locations.find((c) => c.name_en === country) ||
            locations.find((c) => c.name_ar === country) ||
            null
        );
    }, [locations, country]);

    const currentState = useMemo(() => {
        if (!currentCountry || !state) return null;
        return currentCountry.states.find((s) => s.name_ar === state) || null;
    }, [currentCountry, state]);

    const cities = currentState?.cities ?? [];

    // If the parent props point at a country/state that no longer exists
    // in the active list (e.g. data migrated from a previous text value),
    // surface a hint rather than silently dropping the value.
    const orphanedState = country && state && !currentState;

    const handleCountryChange = (e) => {
        const newCountry = locations.find((c) => String(c.id) === e.target.value);
        onChange({
            country: newCountry?.name_en ?? '',
            country_code: newCountry?.code ?? '',
            state: '',
            city: '',
        });
    };

    const handleStateChange = (e) => {
        const newState = currentCountry?.states.find((s) => String(s.id) === e.target.value);
        onChange({
            country: currentCountry?.name_en ?? country,
            country_code: currentCountry?.code ?? '',
            state: newState?.name_ar ?? '',
            city: '',
        });
    };

    const handleCityChange = (e) => {
        onChange({
            country: currentCountry?.name_en ?? country,
            country_code: currentCountry?.code ?? '',
            state: currentState?.name_ar ?? state,
            city: e.target.value,
        });
    };

    return (
        <>
            <FormField label="Country" name="country" error={errors.country} required={required}>
                <select
                    id="country"
                    value={currentCountry?.id ?? ''}
                    onChange={handleCountryChange}
                    className="mt-1 block w-full rounded-md border-slate-300 shadow-sm sm:text-sm"
                >
                    <option value="">— Select country —</option>
                    {locations.map((c) => (
                        <option key={c.id} value={c.id}>{c.name_ar} — {c.name_en}</option>
                    ))}
                </select>
            </FormField>

            <FormField label="Governorate / Region" name="governorate" error={errors.state}>
                <select
                    id="governorate"
                    value={currentState?.id ?? ''}
                    onChange={handleStateChange}
                    disabled={!currentCountry}
                    className="mt-1 block w-full rounded-md border-slate-300 shadow-sm sm:text-sm disabled:bg-slate-50 disabled:text-slate-400"
                >
                    <option value="">{currentCountry ? '— Select governorate / region —' : '— Pick country first —'}</option>
                    {currentCountry?.states.map((s) => (
                        <option key={s.id} value={s.id}>{s.name_ar}</option>
                    ))}
                </select>
                {orphanedState && (
                    <p className="mt-1 text-xs text-amber-600">
                        Stored value &ldquo;{state}&rdquo; isn&apos;t in the list yet — pick a value to update.
                    </p>
                )}
            </FormField>

            <FormField label="City / Area" name="city" error={errors.city} required={required}>
                {cities.length > 0 ? (
                    <select
                        id="city"
                        value={cities.some((c) => c.name_ar === city) ? city : ''}
                        onChange={handleCityChange}
                        disabled={!currentState}
                        className="mt-1 block w-full rounded-md border-slate-300 shadow-sm sm:text-sm disabled:bg-slate-50 disabled:text-slate-400"
                    >
                        <option value="">— Select city / area —</option>
                        {cities.map((c) => (
                            <option key={c.id} value={c.name_ar}>{c.name_ar}</option>
                        ))}
                    </select>
                ) : (
                    <input
                        id="city"
                        type="text"
                        value={city}
                        onChange={handleCityChange}
                        disabled={!currentState}
                        placeholder={currentState ? 'Type the city / area' : 'Pick a governorate first'}
                        className="mt-1 block w-full rounded-md border-slate-300 shadow-sm sm:text-sm disabled:bg-slate-50 disabled:text-slate-400"
                    />
                )}
                {currentState && cities.length === 0 && (
                    <p className="mt-1 text-xs text-slate-400">No seeded cities for this region yet — type freely.</p>
                )}
            </FormField>
        </>
    );
}
