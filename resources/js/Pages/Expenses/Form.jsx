import FormField from '@/Components/FormField';

/**
 * Phase 4: cashbox + structured payment_method are required on
 * create. On edit, if the expense is posted, those fields are
 * disabled (and the server strips them defensively). The legacy
 * free-text `payment_method` column is no longer surfaced in the UI
 * — existing rows keep their value in the DB.
 */
export default function ExpenseForm({
    data,
    setData,
    errors,
    categories,
    campaigns,
    cashboxes = [],
    payment_methods = [],
    isPosted = false,
    isEdit = false,
}) {
    const lockFinancial = isEdit && isPosted;

    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField label="Title" name="title" value={data.title} onChange={(v) => setData('title', v)} error={errors.title} required />

            <FormField label="Category" name="expense_category_id" error={errors.expense_category_id} required>
                <select
                    id="expense_category_id"
                    value={data.expense_category_id ?? ''}
                    onChange={(e) => setData('expense_category_id', e.target.value)}
                    className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                >
                    <option value="">— Pick category —</option>
                    {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
            </FormField>

            <FormField
                label="Amount"
                name="amount"
                type="number"
                value={data.amount}
                onChange={(v) => setData('amount', v)}
                error={errors.amount}
                required
                hint={lockFinancial ? 'Locked — expense is posted.' : undefined}
            >
                {/* render the input with an explicit disabled state when posted */}
                <input
                    id="amount"
                    name="amount"
                    type="number"
                    step="0.01"
                    min="0"
                    value={data.amount ?? ''}
                    onChange={(e) => setData('amount', e.target.value)}
                    disabled={lockFinancial}
                    className="mt-1 block w-full rounded-md border-slate-300 text-sm disabled:bg-slate-100 disabled:text-slate-500"
                />
            </FormField>

            <FormField
                label="Currency"
                name="currency_code"
                value={data.currency_code}
                onChange={(v) => setData('currency_code', v)}
                error={errors.currency_code}
                required
            >
                <input
                    id="currency_code"
                    name="currency_code"
                    type="text"
                    value={data.currency_code ?? ''}
                    onChange={(e) => setData('currency_code', e.target.value)}
                    disabled={lockFinancial}
                    className="mt-1 block w-full rounded-md border-slate-300 text-sm disabled:bg-slate-100 disabled:text-slate-500"
                />
            </FormField>

            <FormField label="Date" name="expense_date" error={errors.expense_date} required>
                <input
                    id="expense_date"
                    name="expense_date"
                    type="date"
                    value={data.expense_date ?? ''}
                    onChange={(e) => setData('expense_date', e.target.value)}
                    disabled={lockFinancial}
                    className="mt-1 block w-full rounded-md border-slate-300 text-sm disabled:bg-slate-100 disabled:text-slate-500"
                />
            </FormField>

            <FormField label="Payment method" name="payment_method_id" error={errors.payment_method_id} required={!isEdit}>
                <select
                    id="payment_method_id"
                    value={data.payment_method_id ?? ''}
                    onChange={(e) => setData('payment_method_id', e.target.value || null)}
                    disabled={lockFinancial}
                    className="mt-1 block w-full rounded-md border-slate-300 text-sm disabled:bg-slate-100 disabled:text-slate-500"
                >
                    <option value="">— Pick method —</option>
                    {payment_methods.map((m) => (
                        <option key={m.id} value={m.id} disabled={m.is_active === false}>
                            {m.name}{m.is_active === false ? ' (inactive)' : ''}
                        </option>
                    ))}
                </select>
            </FormField>

            <FormField label="Cashbox" name="cashbox_id" error={errors.cashbox_id} required={!isEdit} className="sm:col-span-2">
                <select
                    id="cashbox_id"
                    value={data.cashbox_id ?? ''}
                    onChange={(e) => setData('cashbox_id', e.target.value || null)}
                    disabled={lockFinancial}
                    className="mt-1 block w-full rounded-md border-slate-300 text-sm disabled:bg-slate-100 disabled:text-slate-500"
                >
                    <option value="">— Pick cashbox —</option>
                    {cashboxes.map((c) => (
                        <option key={c.id} value={c.id} disabled={c.is_active === false}>
                            {c.name} ({c.currency_code}){c.is_active === false ? ' — inactive' : ''}{c.allow_negative_balance === false ? ' · no negative' : ''}
                        </option>
                    ))}
                </select>
            </FormField>

            <FormField label="Related campaign" name="related_campaign_id" error={errors.related_campaign_id}>
                <select id="related_campaign_id" value={data.related_campaign_id ?? ''} onChange={(e) => setData('related_campaign_id', e.target.value || null)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                    <option value="">— None —</option>
                    {campaigns.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
            </FormField>
            <FormField label="Related order ID (optional)" name="related_order_id" type="number" value={data.related_order_id} onChange={(v) => setData('related_order_id', v)} error={errors.related_order_id} />

            <FormField label="Notes" name="notes" error={errors.notes} className="sm:col-span-2">
                <textarea id="notes" rows={2} value={data.notes ?? ''} onChange={(e) => setData('notes', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm" />
            </FormField>

            {lockFinancial && (
                <div className="sm:col-span-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    This expense is already posted to a cashbox. Amount, date, currency, payment method, and cashbox are locked. Use a reversal flow (future phase) to correct it.
                </div>
            )}
        </div>
    );
}
