import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormField from '@/Components/FormField';
import { Head, Link, useForm } from '@inertiajs/react';

export default function OrderEdit({ order, statuses }) {
    const { data, setData, put, processing, errors } = useForm({
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
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('orders.update', order.id));
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

                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Adjustments</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <FormField label="Discount" name="discount_amount" type="number" value={data.discount_amount} onChange={(v) => setData('discount_amount', v)} error={errors.discount_amount} />
                        <FormField label="Shipping" name="shipping_amount" type="number" value={data.shipping_amount} onChange={(v) => setData('shipping_amount', v)} error={errors.shipping_amount} />
                        <FormField label="Extra fees" name="extra_fees" type="number" value={data.extra_fees} onChange={(v) => setData('extra_fees', v)} error={errors.extra_fees} />
                    </div>
                    <p className="mt-2 text-xs text-slate-500">Item-level edits are blocked once an order is created — recreate the order or contact a manager. Phase 4 adds a finer-grained item-edit flow with approval.</p>
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
                                {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Status note" name="status_note" value={data.status_note} onChange={(v) => setData('status_note', v)} error={errors.status_note} hint="Stored in the status history" />
                    </div>
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
