import FormField from '@/Components/FormField';

export default function ExpenseForm({ data, setData, errors, categories, campaigns }) {
    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField label="Title" name="title" value={data.title} onChange={(v) => setData('title', v)} error={errors.title} required />
            <FormField label="Category" name="expense_category_id" error={errors.expense_category_id} required>
                <select id="expense_category_id" value={data.expense_category_id ?? ''} onChange={(e) => setData('expense_category_id', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                    <option value="">— Pick category —</option>
                    {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
            </FormField>

            <FormField label="Amount" name="amount" type="number" value={data.amount} onChange={(v) => setData('amount', v)} error={errors.amount} required />
            <FormField label="Currency" name="currency_code" value={data.currency_code} onChange={(v) => setData('currency_code', v)} error={errors.currency_code} required />

            <FormField label="Date" name="expense_date" type="date" value={data.expense_date} onChange={(v) => setData('expense_date', v)} error={errors.expense_date} required />
            <FormField label="Payment method" name="payment_method" value={data.payment_method} onChange={(v) => setData('payment_method', v)} error={errors.payment_method} />

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
        </div>
    );
}
