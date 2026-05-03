import FormField from '@/Components/FormField';
import LocationSelect from '@/Components/LocationSelect';
import { useState } from 'react';

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

            <FormField
                label="Primary phone"
                name="primary_phone"
                value={data.primary_phone}
                onChange={(v) => setData('primary_phone', v)}
                error={errors.primary_phone}
                required
            />

            <FormField
                label="Secondary phone"
                name="secondary_phone"
                value={data.secondary_phone}
                onChange={(v) => setData('secondary_phone', v)}
                error={errors.secondary_phone}
            />

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
