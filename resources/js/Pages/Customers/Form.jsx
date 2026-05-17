import FormField from '@/Components/FormField';
import LocationSelect from '@/Components/LocationSelect';
import { useState } from 'react';

/**
 * O-2: supported country dial codes mirror
 * `App\Services\PhoneNormalizationService::COUNTRY_RULES`. Adding a
 * country requires updating BOTH this list AND the PHP constants —
 * promoting to a Backend-provided prop is a future enhancement.
 */
const COUNTRY_CODES = [
    { code: '+20', label: '🇪🇬 +20 Egypt' },
    { code: '+966', label: '🇸🇦 +966 Saudi Arabia' },
    { code: '+971', label: '🇦🇪 +971 UAE' },
    { code: '+964', label: '🇮🇶 +964 Iraq' },
];

/**
 * Shared form for create + edit. The parent page wires `useForm` and
 * passes `data`, `setData`, `errors`, and the location tree (Phase 2).
 * Tags are managed locally here because they're an array string field.
 */
export default function CustomerForm({ data, setData, errors, initialTags = [], locations = [] }) {
    const [tagInput, setTagInput] = useState('');
    const tags = data.tags ?? initialTags;

    const addTag = () => {
        const t = tagInput.trim();
        if (!t) return;
        if (tags.includes(t)) {
            setTagInput('');
            return;
        }
        setData('tags', [...tags, t]);
        setTagInput('');
    };

    const removeTag = (tag) => {
        setData('tags', tags.filter((t) => t !== tag));
    };

    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField
                label="Name"
                name="name"
                value={data.name}
                onChange={(v) => setData('name', v)}
                error={errors.name}
                required
            />

            <FormField
                label="Customer type"
                name="customer_type"
                error={errors.customer_type}
            >
                <select
                    id="customer_type"
                    value={data.customer_type ?? 'Normal'}
                    onChange={(e) => setData('customer_type', e.target.value)}
                    className="mt-1 block w-full rounded-md border-slate-300 shadow-sm sm:text-sm"
                >
                    <option>Normal</option>
                    <option>VIP</option>
                    <option>Watchlist</option>
                    <option>Blacklist</option>
                </select>
            </FormField>

            {/* O-2: Primary phone with country picker. The operator
                picks a country code; the existing `primary_phone` input
                stays as the local-number entry. The backend computes
                `normalized_phone` from the pair. */}
            <FormField label="Primary phone" name="primary_phone" error={errors.primary_phone} required>
                <div className="mt-1 flex gap-2">
                    <select
                        value={data.country_code ?? '+20'}
                        onChange={(e) => setData('country_code', e.target.value)}
                        className="rounded-md border-slate-300 text-sm"
                        aria-label="Primary phone country code"
                    >
                        {COUNTRY_CODES.map((c) => (
                            <option key={c.code} value={c.code}>{c.label}</option>
                        ))}
                    </select>
                    <input
                        id="primary_phone"
                        type="tel"
                        value={data.primary_phone ?? ''}
                        onChange={(e) => setData('primary_phone', e.target.value)}
                        placeholder="e.g. 01012345678"
                        className="block w-full rounded-md border-slate-300 text-sm"
                    />
                </div>
                {errors.primary_phone && <p className="mt-1 text-xs text-red-600">{errors.primary_phone}</p>}
                {data.normalized_phone && (
                    <p className="mt-1 text-[11px] text-slate-400">Saved as <span className="font-mono">{data.normalized_phone}</span></p>
                )}
            </FormField>

            <FormField label="Secondary phone" name="secondary_phone" error={errors.secondary_phone}>
                <div className="mt-1 flex gap-2">
                    <select
                        value={data.secondary_country_code ?? '+20'}
                        onChange={(e) => setData('secondary_country_code', e.target.value)}
                        className="rounded-md border-slate-300 text-sm"
                        aria-label="Secondary phone country code"
                    >
                        {COUNTRY_CODES.map((c) => (
                            <option key={c.code} value={c.code}>{c.label}</option>
                        ))}
                    </select>
                    <input
                        id="secondary_phone"
                        type="tel"
                        value={data.secondary_phone ?? ''}
                        onChange={(e) => setData('secondary_phone', e.target.value)}
                        placeholder="Optional"
                        className="block w-full rounded-md border-slate-300 text-sm"
                    />
                </div>
                {errors.secondary_phone && <p className="mt-1 text-xs text-red-600">{errors.secondary_phone}</p>}
            </FormField>

            {/* O-2: explicit WhatsApp opt-in (defaults to true). Phase 5.8
                shipped the column; we surface the toggle here for parity
                with Order Create. */}
            <FormField label="WhatsApp" name="primary_phone_whatsapp" error={errors.primary_phone_whatsapp}>
                <label className="mt-2 flex items-center gap-2 text-sm text-slate-700">
                    <input
                        type="checkbox"
                        checked={data.primary_phone_whatsapp !== false}
                        onChange={(e) => setData('primary_phone_whatsapp', e.target.checked)}
                        className="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                    />
                    <span aria-hidden="true" className="text-base">🟢</span>
                    <span>Primary phone reachable on WhatsApp</span>
                </label>
            </FormField>

            <FormField
                label="Email"
                name="email"
                type="email"
                value={data.email}
                onChange={(v) => setData('email', v)}
                error={errors.email}
            />

            <LocationSelect
                locations={locations}
                country={data.country}
                state={data.governorate}
                city={data.city}
                onChange={({ country, state, city }) => {
                    setData('country', country);
                    setData('governorate', state);
                    setData('city', city);
                }}
                errors={{ country: errors.country, state: errors.governorate, city: errors.city }}
                required
            />

            <FormField
                label="Default address"
                name="default_address"
                error={errors.default_address}
                className="sm:col-span-2"
                required
            >
                <textarea
                    id="default_address"
                    rows={2}
                    value={data.default_address ?? ''}
                    onChange={(e) => setData('default_address', e.target.value)}
                    className="mt-1 block w-full rounded-md border-slate-300 shadow-sm sm:text-sm"
                />
            </FormField>

            <FormField
                label="Internal notes"
                name="notes"
                error={errors.notes}
                className="sm:col-span-2"
            >
                <textarea
                    id="notes"
                    rows={2}
                    value={data.notes ?? ''}
                    onChange={(e) => setData('notes', e.target.value)}
                    className="mt-1 block w-full rounded-md border-slate-300 shadow-sm sm:text-sm"
                />
            </FormField>

            {/* Tags */}
            <div className="sm:col-span-2">
                <label className="block text-sm font-medium text-slate-700">Tags</label>
                <div className="mt-1 flex flex-wrap gap-1.5">
                    {tags.map((tag) => (
                        <span key={tag} className="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">
                            {tag}
                            <button
                                type="button"
                                onClick={() => removeTag(tag)}
                                className="ml-1 text-slate-400 hover:text-red-500"
                                aria-label={`Remove ${tag}`}
                            >
                                ×
                            </button>
                        </span>
                    ))}
                    <input
                        type="text"
                        value={tagInput}
                        onChange={(e) => setTagInput(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' || e.key === ',') {
                                e.preventDefault();
                                addTag();
                            }
                        }}
                        placeholder="Type a tag and press Enter"
                        className="rounded-md border-slate-300 text-sm"
                    />
                </div>
                <p className="mt-1 text-xs text-slate-500">e.g. VIP, Address Issue, Repeated Return</p>
            </div>
        </div>
    );
}
