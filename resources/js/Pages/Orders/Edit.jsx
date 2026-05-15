import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormField from '@/Components/FormField';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useMemo } from 'react';

export default function OrderEdit({
    order,
    statuses,
    return_reasons = [],
    return_conditions = ['Good', 'Damaged', 'Missing Parts', 'Unknown'],
    can_create_return = false,
    can_view_profit = false,
    has_return = false,
    existing_return = null,
}) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';
    const form = useForm({
        customer_address: order.customer_address ?? '',
        city: order.city ?? '',
        governorate: order.governorate ?? '',
        country: order.country ?? '',
        // Phase 5.8: per-order phone snapshot (secondary phone +
        // WhatsApp reachability flag for the primary phone).
        customer_phone_secondary: order.customer_phone_secondary ?? '',
        customer_phone_whatsapp: order.customer_phone_whatsapp ?? true,
        source: order.source ?? '',
        notes: order.notes ?? '',
        internal_notes: order.internal_notes ?? '',
        discount_amount: order.discount_amount ?? 0,
        shipping_amount: order.shipping_amount ?? 0,
        extra_fees: order.extra_fees ?? 0,
        status: order.status,
        status_note: '',
        // Professional Return Management — payload only used when
        // status=Returned. Same field shape as the Orders/Show modal.
        return: {
            return_reason_id: '',
            product_condition: 'Unknown',
            refund_amount: 0,
            shipping_loss_amount: 0,
            notes: '',
        },
    });
    const { data, setData, processing, errors } = form;

    // Returned is a transition that REQUIRES creating a return record.
    // Hide it from the dropdown when the operator can't do that (no
    // permission, or the order already has a return). The current
    // value of Returned stays visible so an already-Returned order
    // still shows correctly.
    const availableStatuses = useMemo(() => {
        return statuses.filter((s) => {
            if (s !== 'Returned') return true;
            if (order.status === 'Returned') return true;
            return can_create_return && !has_return;
        });
    }, [statuses, order.status, can_create_return, has_return]);

    const isNewReturned = data.status === 'Returned' && order.status !== 'Returned';

    const submit = (e) => {
        e.preventDefault();
        // Only carry the `return` payload when this save is a genuine NEW
        // transition into Returned. For every other case (no status
        // change, non-Returned status, or an already-Returned order being
        // edited) strip it so the backend never validates a half-empty
        // return object.
        //
        // NOTE: `form.transform()` returns undefined in @inertiajs/react —
        // it must be called as its own statement, NOT chained before
        // `.put()`. Chaining throws a TypeError and the request never
        // fires (Save appears to do nothing).
        form.transform((payload) => {
            const newReturned = payload.status === 'Returned' && order.status !== 'Returned';
            if (!newReturned) {
                const { return: _unused, ...rest } = payload;
                return rest;
            }
            return payload;
        });
        form.put(route('orders.update', order.id));
    };

    return (
        <AuthenticatedLayout header={`Edit ${order.order_number}`}>
            <Head title={`Edit ${order.order_number}`} />
            <PageHeader title={`Edit ${order.order_number}`} subtitle={order.customer_name} />

            <form onSubmit={submit} className="space-y-5">
                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Customer address &amp; contact</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <FormField label="Address" name="customer_address" error={errors.customer_address} className="sm:col-span-2" required hint="Used for both billing and shipping">
                            <textarea
                                id="customer_address"
                                rows={2}
                                value={data.customer_address}
                                onChange={(e) => setData('customer_address', e.target.value)}
                                className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                            />
                        </FormField>
                        <FormField label="City" name="city" value={data.city} onChange={(v) => setData('city', v)} error={errors.city} required />
                        <FormField label="Governorate" name="governorate" value={data.governorate} onChange={(v) => setData('governorate', v)} error={errors.governorate} />
                        <FormField label="Country" name="country" value={data.country} onChange={(v) => setData('country', v)} error={errors.country} required />
                        <FormField label="Source" name="source" value={data.source} onChange={(v) => setData('source', v)} error={errors.source} />
                        {/* Phase 5.8 — secondary phone + WhatsApp reachability for the primary phone. */}
                        <FormField label="Secondary phone" name="customer_phone_secondary" value={data.customer_phone_secondary} onChange={(v) => setData('customer_phone_secondary', v)} error={errors.customer_phone_secondary} hint="Optional · alternate contact for this order" />
                        <label className="mt-6 flex items-center gap-2 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                checked={!!data.customer_phone_whatsapp}
                                onChange={(e) => setData('customer_phone_whatsapp', e.target.checked)}
                                className="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                            />
                            <span aria-hidden="true" className="text-base">🟢</span>
                            <span>Primary phone reachable on WhatsApp</span>
                        </label>
                    </div>
                </section>

                {/* Cost/profit gate: render the marketer profit snapshot
                    only when the operator has `orders.view_profit`. The
                    backend also strips the `marketer_profit` column from
                    the page props for users without this permission. */}
                {can_view_profit && order.marketer_id && order.marketer_profit !== null && order.marketer_profit !== undefined && (
                    <section className="rounded-lg border border-emerald-200 bg-emerald-50 p-5">
                        <div className="flex items-center justify-between">
                            <h2 className="text-sm font-semibold text-emerald-800">Marketer profit (snapshot)</h2>
                            <span className="font-mono text-base font-semibold text-emerald-900">
                                {sym}{Number(order.marketer_profit).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                            </span>
                        </div>
                        <p className="mt-1 text-[11px] text-emerald-700">
                            Calculated at create-time from selling price minus VAT, marketer cost, and shipping (per line). Stored snapshot — does not recalculate when product tier prices later change.
                        </p>
                    </section>
                )}

                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Adjustments</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <FormField label="Discount" name="discount_amount" type="number" value={data.discount_amount} onChange={(v) => setData('discount_amount', v)} error={errors.discount_amount} />
                        <FormField label="Shipping" name="shipping_amount" type="number" value={data.shipping_amount} onChange={(v) => setData('shipping_amount', v)} error={errors.shipping_amount} />
                        <FormField label="Extra fees" name="extra_fees" type="number" value={data.extra_fees} onChange={(v) => setData('extra_fees', v)} error={errors.extra_fees} />
                    </div>
                    <p className="mt-2 text-xs text-slate-500">Item-level edits are blocked once an order is created — recreate the order or contact a manager.</p>
                </section>

                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Status</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <FormField label="Status" name="status" error={errors.status}>
                            <select
                                id="status"
                                value={data.status}
                                onChange={(e) => setData('status', e.target.value)}
                                className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                            >
                                {availableStatuses.map((s) => <option key={s} value={s}>{s}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Status note" name="status_note" value={data.status_note} onChange={(v) => setData('status_note', v)} error={errors.status_note} hint="Stored in the status history" />
                    </div>

                    {/* Helper hint when the operator cannot select Returned. */}
                    {has_return && order.status !== 'Returned' && existing_return && (
                        <p className="mt-3 rounded-md border border-slate-200 bg-slate-50 p-2 text-[11px] text-slate-600">
                            This order already has a return record —
                            <Link href={route('returns.show', existing_return.id)} className="ml-1 underline">
                                open the return
                            </Link>
                            to manage it. The Returned status option is hidden here to prevent duplicates.
                        </p>
                    )}
                    {!can_create_return && !has_return && order.status !== 'Returned' && (
                        <p className="mt-3 rounded-md border border-slate-200 bg-slate-50 p-2 text-[11px] text-slate-600">
                            You do not have permission to create return records, so the <strong>Returned</strong> status option is hidden here.
                        </p>
                    )}

                    {/* Professional Return Management — Return Details
                        section expands ONLY when the operator is moving
                        the order INTO Returned from this edit page.
                        Same field shape as the Orders/Show modal. */}
                    {isNewReturned && (
                        <div className="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 space-y-3">
                            <div className="text-xs font-semibold uppercase tracking-wide text-amber-800">
                                Return details
                            </div>
                            <p className="text-[11px] text-amber-700">
                                A return record will be created automatically when you save. After saving you'll land on the return page so you can record the inspection.
                            </p>

                            <FormField
                                label={<>Return reason <span className="text-rose-600">*</span></>}
                                name="return.return_reason_id"
                                error={errors['return.return_reason_id']}
                            >
                                <select
                                    value={data.return.return_reason_id}
                                    onChange={(e) => setData('return', { ...data.return, return_reason_id: e.target.value })}
                                    className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                                >
                                    <option value="">— select a reason —</option>
                                    {return_reasons.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                                </select>
                            </FormField>

                            <FormField
                                label="Product condition"
                                name="return.product_condition"
                                error={errors['return.product_condition']}
                            >
                                <select
                                    value={data.return.product_condition}
                                    onChange={(e) => setData('return', { ...data.return, product_condition: e.target.value })}
                                    className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                                >
                                    {return_conditions.map((c) => <option key={c} value={c}>{c}</option>)}
                                </select>
                            </FormField>

                            <div className="grid grid-cols-2 gap-3">
                                <FormField
                                    label={<>Refund amount {sym && <span className="text-slate-400 font-normal">({sym})</span>}</>}
                                    name="return.refund_amount"
                                    error={errors['return.refund_amount']}
                                >
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={data.return.refund_amount}
                                        onChange={(e) => setData('return', { ...data.return, refund_amount: e.target.value })}
                                        className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                                    />
                                </FormField>
                                <FormField
                                    label={<>Shipping loss {sym && <span className="text-slate-400 font-normal">({sym})</span>}</>}
                                    name="return.shipping_loss_amount"
                                    error={errors['return.shipping_loss_amount']}
                                >
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={data.return.shipping_loss_amount}
                                        onChange={(e) => setData('return', { ...data.return, shipping_loss_amount: e.target.value })}
                                        className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                                    />
                                </FormField>
                            </div>

                            <FormField
                                label="Return notes"
                                name="return.notes"
                                error={errors['return.notes']}
                            >
                                <textarea
                                    rows={2}
                                    value={data.return.notes}
                                    onChange={(e) => setData('return', { ...data.return, notes: e.target.value })}
                                    placeholder="Optional notes for the return record"
                                    className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                                />
                            </FormField>
                        </div>
                    )}
                </section>

                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Notes</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <FormField label="Customer-facing notes" name="notes" error={errors.notes}>
                            <textarea
                                id="notes"
                                rows={2}
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                            />
                        </FormField>
                        <FormField label="Internal notes" name="internal_notes" error={errors.internal_notes}>
                            <textarea
                                id="internal_notes"
                                rows={2}
                                value={data.internal_notes}
                                onChange={(e) => setData('internal_notes', e.target.value)}
                                className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                            />
                        </FormField>
                    </div>
                </section>

                <div className="flex items-center justify-end gap-2">
                    <Link href={route('orders.show', order.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {processing ? 'Saving…' : 'Save changes'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
