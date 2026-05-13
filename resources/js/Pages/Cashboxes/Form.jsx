import FormField from '@/Components/FormField';

/**
 * Shared form for create + edit. The `isEdit` flag hides the
 * opening_balance and currency_code fields after creation (they are
 * immutable; the server-side service strips them defensively too).
 */
export default function CashboxForm({ data, setData, errors, types, isEdit = false, hasTransactions = false }) {
    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField
                label="Name"
                name="name"
                value={data.name}
                onChange={(v) => setData('name', v)}
                error={errors.name}
                required
                hint="Unique. E.g. 'Main Cash', 'Visa POS', 'Vodafone Cash'."
            />

            <FormField label="Type" name="type" error={errors.type} required>
                <select
                    id="type"
                    value={data.type ?? ''}
                    onChange={(e) => setData('type', e.target.value)}
                    disabled={isEdit && hasTransactions}
                    className="mt-1 block w-full rounded-md border-slate-300 text-sm disabled:bg-slate-50 disabled:text-slate-500"
                >
                    <option value="">— Pick type —</option>
                    {types.map((t) => (
                        <option key={t} value={t}>{t.replaceAll('_', ' ')}</option>
                    ))}
                </select>
            </FormField>

            {!isEdit && (
                <FormField
                    label="Currency"
                    name="currency_code"
                    value={data.currency_code}
                    onChange={(v) => setData('currency_code', v)}
                    error={errors.currency_code}
                    required
                    hint="Fixed at creation. Cannot be changed later."
                />
            )}

            {!isEdit && (
                <FormField
                    label="Opening balance"
                    name="opening_balance"
                    type="number"
                    value={data.opening_balance}
                    onChange={(v) => setData('opening_balance', v)}
                    error={errors.opening_balance}
                    hint="Writes one transaction. Editable only at creation."
                />
            )}

            <FormField label="Allow negative balance" name="allow_negative_balance" error={errors.allow_negative_balance}>
                <div className="mt-2">
                    <label className="inline-flex items-center gap-2 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            checked={!!data.allow_negative_balance}
                            onChange={(e) => setData('allow_negative_balance', e.target.checked)}
                            className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                        />
                        Permit balance below zero
                    </label>
                </div>
            </FormField>

            <FormField label="Active" name="is_active" error={errors.is_active}>
                <div className="mt-2">
                    <label className="inline-flex items-center gap-2 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            checked={!!data.is_active}
                            onChange={(e) => setData('is_active', e.target.checked)}
                            className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                        />
                        Active (uncheck to retire)
                    </label>
                </div>
            </FormField>

            <FormField label="Description" name="description" error={errors.description} className="sm:col-span-2">
                <textarea
                    id="description"
                    rows={2}
                    value={data.description ?? ''}
                    onChange={(e) => setData('description', e.target.value)}
                    className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                />
            </FormField>
        </div>
    );
}
