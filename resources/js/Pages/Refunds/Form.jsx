import FormField from '@/Components/FormField';

/**
 * Phase 5A — paperwork-only refund form.
 *
 * Linkage fields (order, collection, order_return, customer) accept
 * numeric IDs. Production UX would attach lookup widgets; Phase 5A
 * keeps it minimal so the foundation lands without re-doing the form
 * in Phase 5B.
 *
 * No cashbox or payment-method fields here — those are Phase 5B.
 */
export default function RefundForm({ data, setData, errors }) {
    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField
                label="Amount"
                name="amount"
                type="number"
                value={data.amount}
                onChange={(v) => setData('amount', v)}
                error={errors.amount}
                required
                hint="Positive number. Cumulative active refunds cannot exceed the collected amount."
            >
                <input
                    id="amount"
                    type="number"
                    step="0.01"
                    min="0.01"
                    value={data.amount ?? ''}
                    onChange={(e) => setData('amount', e.target.value)}
                    className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                />
            </FormField>

            <FormField
                label="Collection ID"
                name="collection_id"
                type="number"
                value={data.collection_id}
                onChange={(v) => setData('collection_id', v || null)}
                error={errors.collection_id}
                hint="Optional. Required for the over-refund guard."
            />

            <FormField
                label="Order ID"
                name="order_id"
                type="number"
                value={data.order_id}
                onChange={(v) => setData('order_id', v || null)}
                error={errors.order_id}
                hint="Optional linkage."
            />

            <FormField
                label="Customer ID"
                name="customer_id"
                type="number"
                value={data.customer_id}
                onChange={(v) => setData('customer_id', v || null)}
                error={errors.customer_id}
                hint="Optional linkage."
            />

            <FormField
                label="Order Return ID"
                name="order_return_id"
                type="number"
                value={data.order_return_id}
                onChange={(v) => setData('order_return_id', v || null)}
                error={errors.order_return_id}
                hint="Optional — refunds may be standalone goodwill."
                className="sm:col-span-2"
            />

            <FormField label="Reason" name="reason" error={errors.reason} className="sm:col-span-2">
                <textarea
                    id="reason"
                    rows={3}
                    value={data.reason ?? ''}
                    onChange={(e) => setData('reason', e.target.value)}
                    placeholder="Why this refund is being requested"
                    className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                />
            </FormField>

            <div className="sm:col-span-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                <strong>Note:</strong> refunds are paperwork-only.
                Approving a refund records the decision but does NOT
                move money. The cashbox payment is a separate step.
            </div>
        </div>
    );
}
