import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function fmt(n) {
    if (n === null || n === undefined) return '—';
    return Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function Field({ label, value }) {
    return (
        <div>
            <div className="text-[11px] font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-0.5 text-sm text-slate-800">{value || <span className="text-slate-400">—</span>}</div>
        </div>
    );
}

export default function OrderShow({ order, statuses }) {
    const can = useCan();
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const [statusOpen, setStatusOpen] = useState(false);
    const [pendingStatus, setPendingStatus] = useState(order.status);
    const [statusNote, setStatusNote] = useState('');

    const submitStatus = (e) => {
        e.preventDefault();
        router.post(
            route('orders.change-status', order.id),
            { status: pendingStatus, note: statusNote },
            { onSuccess: () => { setStatusOpen(false); setStatusNote(''); } },
        );
    };

    return (
        <AuthenticatedLayout header={order.display_order_number ?? order.order_number}>
            <Head title={order.display_order_number ?? order.order_number} />

            <PageHeader
                title={<span className="font-mono">{order.display_order_number ?? order.order_number}</span>}
                subtitle={`${order.customer_name} · ${order.customer_phone}${order.external_order_reference ? ` · Ext: ${order.external_order_reference}` : ''}`}
                actions={
                    <div className="flex gap-2">
                        <Link href={route('orders.timeline', order.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">
                            Timeline
                        </Link>
                        {can('orders.edit') && (
                            <Link href={route('orders.edit', order.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">
                                Edit
                            </Link>
                        )}
                        {can('orders.change_status') && (
                            <button onClick={() => setStatusOpen(true)} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">
                                Change status
                            </button>
                        )}
                        {props.auth?.user?.is_super_admin && (
                            <button
                                onClick={() => {
                                    if (confirm('Are you sure you want to delete this order? This action is restricted to Super Admin and is reversible (soft-delete).')) {
                                        router.delete(route('orders.destroy', order.id));
                                    }
                                }}
                                className="rounded-md border border-red-300 bg-white px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50"
                            >
                                Delete order
                            </button>
                        )}
                    </div>
                }
            />

            {/* Status row */}
            <div className="mb-5 flex flex-wrap items-center gap-2">
                <StatusBadge value={order.status} />
                <span className="text-xs text-slate-400">Shipping: <StatusBadge value={order.shipping_status} /></span>
                <span className="text-xs text-slate-400">Collection: <StatusBadge value={order.collection_status} /></span>
                {order.duplicate_score >= 50 && (
                    <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                        Duplicate score {order.duplicate_score}
                    </span>
                )}
                <StatusBadge value={order.customer_risk_level} />
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                {/* Items */}
                <div className="lg:col-span-2 rounded-lg border border-slate-200 bg-white">
                    <div className="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">
                        Items ({order.items.length})
                    </div>
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-5 py-2">SKU</th>
                                <th className="px-5 py-2">Product</th>
                                <th className="px-5 py-2 text-right">Qty</th>
                                <th className="px-5 py-2 text-right">Unit</th>
                                <th className="px-5 py-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {order.items.map((it) => (
                                <tr key={it.id}>
                                    <td className="px-5 py-2 font-mono text-xs">{it.sku}</td>
                                    <td className="px-5 py-2">{it.product_name}</td>
                                    <td className="px-5 py-2 text-right tabular-nums">{it.quantity}</td>
                                    <td className="px-5 py-2 text-right tabular-nums">{fmt(it.unit_price)}</td>
                                    <td className="px-5 py-2 text-right tabular-nums">{fmt(it.total_price)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* Totals + customer card */}
                <div className="space-y-4">
                    <div className="rounded-lg border border-slate-200 bg-white p-5">
                        <h2 className="mb-3 text-sm font-semibold text-slate-700">Totals</h2>
                        <dl className="space-y-1 text-sm">
                            <Row k="Subtotal" v={`${sym}${fmt(order.subtotal)}`} />
                            <Row k="Discount" v={`–${sym}${fmt(order.discount_amount)}`} />
                            <Row k="Shipping" v={`${sym}${fmt(order.shipping_amount)}`} />
                            <Row k="Tax" v={`${sym}${fmt(order.tax_amount)}`} />
                            <Row k="Extra fees" v={`${sym}${fmt(order.extra_fees)}`} />
                            <div className="border-t border-slate-200 pt-1.5">
                                <Row k={<span className="font-semibold">Total</span>} v={<span className="font-semibold">{sym}{fmt(order.total_amount)}</span>} />
                            </div>
                            {can('orders.view_profit') && (
                                <>
                                    <Row k="Product cost" v={`–${sym}${fmt(order.product_cost_total)}`} />
                                    <div className="border-t border-slate-200 pt-1.5">
                                        <Row k={<span className="font-medium">Net profit</span>} v={<span className={`font-semibold ${Number(order.net_profit) < 0 ? 'text-red-600' : 'text-emerald-600'}`}>{sym}{fmt(order.net_profit)}</span>} />
                                    </div>
                                </>
                            )}
                        </dl>
                    </div>

                    <div className="rounded-lg border border-slate-200 bg-white p-5 space-y-3">
                        <h2 className="text-sm font-semibold text-slate-700">References</h2>
                        <Field label="Internal order #" value={<span className="font-mono">{order.order_number}</span>} />
                        <Field label="Display #" value={<span className="font-mono">{order.display_order_number ?? order.order_number}</span>} />
                        <Field label="External reference" value={order.external_order_reference || <span className="text-slate-400">—</span>} />
                        <Field label="Entry code" value={order.entry_code ? <span className="font-mono">{order.entry_code}</span> : <span className="text-slate-400">—</span>} />
                        <Field label="Source" value={order.source || <span className="text-slate-400">—</span>} />
                        <Field label="Entered by" value={order.created_by?.name ?? order.createdBy?.name ?? <span className="text-slate-400">—</span>} />
                    </div>

                    <div className="rounded-lg border border-slate-200 bg-white p-5 space-y-3">
                        <h2 className="text-sm font-semibold text-slate-700">Customer</h2>
                        <Field label="Name" value={order.customer_name} />
                        <Field label="Phone" value={order.customer_phone} />
                        <Field label="Address" value={order.customer_address} />
                        <Field label="City" value={`${order.city}${order.governorate ? `, ${order.governorate}` : ''}`} />
                        <Field label="Country" value={order.country} />
                        {order.customer && (
                            <Link href={route('customers.show', order.customer.id)} className="inline-block text-xs text-indigo-600 hover:underline">
                                Open customer profile →
                            </Link>
                        )}
                    </div>
                </div>
            </div>

            {/* Notes */}
            {(order.notes || order.internal_notes) && (
                <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    {order.notes && (
                        <div className="rounded-md border border-slate-200 bg-white p-4 text-sm">
                            <div className="text-[11px] font-medium uppercase text-slate-500">Customer notes</div>
                            <p className="mt-1 whitespace-pre-line text-slate-700">{order.notes}</p>
                        </div>
                    )}
                    {order.internal_notes && (
                        <div className="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm">
                            <div className="text-[11px] font-medium uppercase text-amber-700">Internal notes</div>
                            <p className="mt-1 whitespace-pre-line text-amber-800">{order.internal_notes}</p>
                        </div>
                    )}
                </div>
            )}

            {/* Status modal */}
            {statusOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <form onSubmit={submitStatus} className="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
                        <h3 className="mb-3 text-sm font-semibold text-slate-800">Change order status</h3>
                        <select
                            value={pendingStatus}
                            onChange={(e) => setPendingStatus(e.target.value)}
                            className="block w-full rounded-md border-slate-300 text-sm"
                        >
                            {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                        </select>
                        <textarea
                            value={statusNote}
                            onChange={(e) => setStatusNote(e.target.value)}
                            placeholder="Optional note for the status history"
                            rows={2}
                            className="mt-3 block w-full rounded-md border-slate-300 text-sm"
                        />
                        <div className="mt-4 flex justify-end gap-2">
                            <button type="button" onClick={() => setStatusOpen(false)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</button>
                            <button type="submit" className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">Save</button>
                        </div>
                    </form>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

function Row({ k, v }) {
    return (
        <div className="flex justify-between">
            <span className="text-slate-500">{k}</span>
            <span className="tabular-nums text-slate-800">{v}</span>
        </div>
    );
}
