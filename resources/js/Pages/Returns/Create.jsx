import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormField from '@/Components/FormField';
import { Head, Link, useForm } from '@inertiajs/react';

/**
 * Direct return-creation form.
 *
 * Phase 2 — Return Intake & RMA Standards. The atomic flow
 * (Order → Change Status → Returned) is the preferred path because it
 * keeps the order's status, the return record, and the inventory
 * movements in a single transaction. This form is a back-office
 * correction tool — for cases where the order was already moved out of
 * the standard flow (legacy data, an operator who forgot to use the
 * modal, etc.).
 *
 * Important contract reminders the labels reflect:
 *   - Refund amount is *intent*, not a posted refund.
 *   - Shipping loss is *intent*, no finance row is written.
 *   - Notes are operations-internal — there's no customer-facing field.
 */
export default function ReturnCreate({
    preselected_order,
    recent_orders,
    reasons,
    already_returned_notice,
    existing_return_id,
}) {
    const { data, setData, post, processing, errors } = useForm({
        order_id: preselected_order?.id ?? '',
        return_reason_id: '',
        product_condition: 'Unknown',
        refund_amount: 0,
        shipping_loss_amount: 0,
        notes: '',
    });

    const submit = (e) => { e.preventDefault(); post(route('returns.store')); };

    return (
        <AuthenticatedLayout header="New return">
            <Head title="New return" />
            <PageHeader
                title="Open a return"
                subtitle="Records a return against an existing order. Inspection happens next."
            />

            {/* Phase 2 — preferred-path notice. The form still works, but
                this nudges back-office staff toward the atomic
                Order → Change Status → Returned flow whenever possible. */}
            <div className="mb-3 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                <strong className="text-slate-700">Preferred path:</strong> open <span className="font-mono">Orders → the order → Change status → Returned</span>.
                That flow updates the order status, creates the return, and adjusts inventory atomically. Use this form only for back-office corrections.
            </div>

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5 space-y-4">
                {already_returned_notice && (
                    <div className="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        <div>{already_returned_notice}</div>
                        {existing_return_id && (
                            <div className="mt-2">
                                <Link
                                    href={route('returns.show', existing_return_id)}
                                    className="font-medium text-amber-900 underline hover:text-amber-700"
                                >
                                    Open existing return →
                                </Link>
                            </div>
                        )}
                    </div>
                )}

                {preselected_order ? (
                    <div className="rounded-md border border-emerald-200 bg-emerald-50 p-3">
                        <div className="text-sm text-slate-700">Order: <span className="font-mono">{preselected_order.order_number}</span></div>
                        <div className="text-xs text-slate-500">{preselected_order.customer_name} · {preselected_order.customer?.primary_phone}</div>
                    </div>
                ) : (
                    <FormField label="Order" name="order_id" error={errors.order_id} required>
                        <select id="order_id" value={data.order_id} onChange={(e) => setData('order_id', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            <option value="">— Pick an order —</option>
                            {(recent_orders ?? []).map((o) => (
                                <option key={o.id} value={o.id}>
                                    {o.order_number} — {o.customer_name} ({o.status})
                                </option>
                            ))}
                        </select>
                        <p className="mt-1 text-[11px] text-slate-400">Only orders without an existing return are listed.</p>
                    </FormField>
                )}

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <FormField
                        label="Reason"
                        name="return_reason_id"
                        error={errors.return_reason_id}
                        required
                        hint="A reason is required for every return."
                    >
                        <select id="return_reason_id" value={data.return_reason_id} onChange={(e) => setData('return_reason_id', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            <option value="">— Pick reason —</option>
                            {reasons.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                        </select>
                    </FormField>

                    <FormField
                        label="Product condition (provisional)"
                        name="product_condition"
                        error={errors.product_condition}
                        hint="Use Unknown if the goods haven't arrived yet — the inspector locks the final condition later."
                    >
                        <select id="product_condition" value={data.product_condition} onChange={(e) => setData('product_condition', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            <option>Unknown</option>
                            <option>Good</option>
                            <option>Damaged</option>
                            <option>Missing Parts</option>
                        </select>
                    </FormField>

                    <FormField
                        label="Refund amount"
                        name="refund_amount"
                        type="number"
                        value={data.refund_amount}
                        onChange={(v) => setData('refund_amount', v)}
                        error={errors.refund_amount}
                        hint="Optional. Records the intended refund — no money moves until a separate Request refund action."
                    />
                    <FormField
                        label="Shipping loss"
                        name="shipping_loss_amount"
                        type="number"
                        value={data.shipping_loss_amount}
                        onChange={(v) => setData('shipping_loss_amount', v)}
                        error={errors.shipping_loss_amount}
                        hint="Optional. Records absorbed shipping cost — no finance row is posted."
                    />
                </div>

                <FormField
                    label="Notes (internal)"
                    name="notes"
                    error={errors.notes}
                    hint="Operations-internal notes for warehouse and order agents — not customer-facing."
                >
                    <textarea id="notes" rows={3} value={data.notes} onChange={(e) => setData('notes', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                </FormField>

                <div className="flex justify-end gap-2">
                    <Link href={route('returns.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {processing ? 'Opening…' : 'Open return'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
