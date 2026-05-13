import FormField from '@/Components/FormField';

/**
 * Shared form for create + edit. The default_cashbox_id is optional —
 * admins can leave it null and assign later. Code is lower_snake_case
 * (server-validated by regex).
 */
export default function PaymentMethodForm({ data, setData, errors, types, cashboxes }) {
    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField
                label="Name"
                name="name"
                value={data.name}
                onChange={(v) => setData('name', v)}
                error={errors.name}
                required
                hint="Display name. E.g. 'Cash', 'Visa / POS', 'Vodafone Cash'."
            />

            <FormField
                label="Code"
                name="code"
                value={data.code}
                onChange={(v) => setData('code', v)}
                error={errors.code}
                required
                hint="Slug. lower_snake_case. Unique."
            />

            <FormField label="Type" name="type" error={errors.type} required>
                <select
                    id="type"
                    value={data.type ?? ''}
                    onChange={(e) => setData('type', e.target.value)}
                    className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                >
                    <option value="">— Pick type —</option>
                    {types.map((t) => (
                        <option key={t} value={t}>{t.replaceAll('_', ' ')}</option>
                    ))}
                </select>
            </FormField>

            <FormField label="Default cashbox (optional)" name="default_cashbox_id" error={errors.default_cashbox_id}>
                <select
                    id="default_cashbox_id"
                    value={data.default_cashbox_id ?? ''}
                    onChange={(e) => setData('default_cashbox_id', e.target.value || null)}
                    className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                >
                    <option value="">— None —</option>
                    {cashboxes.map((c) => (
                        <option key={c.id} value={c.id} disabled={!c.is_active}>
                            {c.name} ({c.currency_code}){!c.is_active ? ' — inactive' : ''}
                        </option>
                    ))}
                </select>
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
