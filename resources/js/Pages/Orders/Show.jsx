import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

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

export default function OrderShow({
    order,
    statuses,
    return_reasons = [],
    return_conditions = ['Good', 'Damaged', 'Missing Parts', 'Unknown'],
    can_create_return = false,
    has_return = false,
    existing_return = null,
}) {
    const can = useCan();
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const [statusOpen, setStatusOpen] = useState(false);

    // Inertia form so we get inline validation errors on the return.* fields.
    const form = useForm({
        status: order.status,
        note: '',
        return: {
            return_reason_id: '',
            product_condition: 'Unknown',
            refund_amount: 0,
            shipping_loss_amount: 0,
            notes: '',
        },
    });

    // Filter the status dropdown:
    //   - Hide "Returned" when the user lacks returns.create OR the
    //     order already has a return (the one-return-per-order rule).
    //   - Everything else stays.
    const availableStatuses = useMemo(() => {
        return statuses.filter((s) => {
            if (s !== 'Returned') return true;
            return can_create_return && !has_return;
        });
    }, [statuses, can_create_return, has_return]);

    const isReturning = form.data.status === 'Returned';

    const submitStatus = (e) => {
        e.preventDefault();
        // Only send the `return` payload when actually transitioning to
        // Returned. For every other status we strip it entirely so the
        // backend never validates a half-empty return object — the
        // controller's `required_if:status,Returned` rule still enforces
        // a real reason on the Returned path.
        //
        // NOTE: `form.transform()` returns undefined in @inertiajs/react —
        // it must be called as its own statement, NOT chained before
        // `.post()`. Chaining throws a TypeError and the request never
        // fires (the modal appears to do nothing).
        form.transform((payload) => {
            if (payload.status !== 'Returned') {
                const { return: _unused, ...rest } = payload;
                return rest;
            }
            return payload;
        });
        form.post(route('orders.change-status', order.id), {
            preserveScroll: true,
            onSuccess: () => {
                setStatusOpen(false);
                form.reset('note');
                form.setData('return', {
                    return_reason_id: '',
                    product_condition: 'Unknown',
                    refund_amount: 0,
                    shipping_loss_amount: 0,
                    notes: '',
                });
            },
        });
    };

    // Defence-in-depth: surface any `return.*` validation error even when
    // the Return Details block is collapsed, so a status change can never
    // fail "silently" again.
    const hiddenReturnErrors = Object.entries(form.errors)
        .filter(([key]) => key.startsWith('return.'))
        .map(([, message]) => message);

    const openStatusModal = () => {
        form.setData('status', order.status);
        setStatusOpen(true);
    };

    return (
        <AuthenticatedLayout header={order.display_order_number ?? order.order_number}>
            <Head title={order.display_order_number ?? order.order_number} />

            <PageHeader
                title={<span className="font-mono">{order.display_order_number ?? order.order_number}</span>}
                subtitle={`${order.customer_name} · ${order.customer_phone}${order.external_order_reference ? ` · Ext: ${order.external_order_reference}` : ''}`}
                actions={
                    <div className="flex gap-2">
                        {/* Professional Return Management — when this order
                            already has a return, surface a direct link to
                            the return page. Without it the operator's only
                            path is via Edit, where it's easy to start
                            editing order fields instead of managing the
                            return. */}
                        {existing_return && can('returns.view') && (
                            <Link
                                href={route('returns.show', existing_return.id)}
                                className="rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-700"
                                title={`Open return ${existing_return.display_reference ?? `#${existing_return.id}`} (status: ${existing_return.return_status})`}
                            >
                                Manage return
                            </Link>
                        )}
                        <Link href={route('orders.timeline', order.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">
                            Timeline
                        </Link>
                        {can('orders.edit') && (
                            <Link href={route('orders.edit', order.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">
                                Edit order
                            </Link>
                        )}
                        {can('orders.change_status') && (
                            <button onClick={openStatusModal} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">
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
            <div className="mb-3 flex flex-wrap items-center gap-2">
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

            {/* Return-record banner — surfaces the linked return so the
                operator manages it on the Return page (where reason,
                condition, inspection, refund, and close actions live)
                instead of accidentally editing order fields. */}
            {existing_return && (
                <div className="mb-5 flex flex-wrap items-center justify-between gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    <span>
                        This order has a return record <span className="font-mono">{existing_return.display_reference ?? `#${existing_return.id}`}</span>
                        {' '}— status <strong>{existing_return.return_status}</strong>
                        {existing_return.product_condition ? <> · condition <strong>{existing_return.product_condition}</strong></> : null}.
                        {' '}Manage reason, condition, refund and close from the return page.
                    </span>
                    {can('returns.view') && (
                        <Link
                            href={route('returns.show', existing_return.id)}
                            className="font-medium text-amber-900 underline hover:text-amber-700"
                        >
                            Open return →
                        </Link>
                    )}
                </div>
            )}

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
                    <form onSubmit={submitStatus} className="w-full max-w-md rounded-lg bg-white p-5 shadow-xl max-h-[90vh] overflow-y-auto">
                        <h3 className="mb-3 text-sm font-semibold text-slate-800">Change order status</h3>

                        <label className="block text-xs font-medium text-slate-600">
                            Status
                            <select
                                value={form.data.status}
                                onChange={(e) => form.setData('status', e.target.value)}
                                className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                            >
                                {availableStatuses.map((s) => <option key={s} value={s}>{s}</option>)}
                            </select>
                            {form.errors.status && <p className="mt-1 text-xs text-rose-600">{form.errors.status}</p>}
                        </label>

                        <label className="mt-3 block text-xs font-medium text-slate-600">
                            Status note <span className="font-normal text-slate-400">(optional, history record)</span>
                            <textarea
                                value={form.data.note}
                                onChange={(e) => form.setData('note', e.target.value)}
                                placeholder="Optional note for the status history"
                                rows={2}
                                className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                            />
                            {form.errors.note && <p className="mt-1 text-xs text-rose-600">{form.errors.note}</p>}
                        </label>

                        {/* Return details — surfaced only when target status is Returned. */}
                        {isReturning && (
                            <div className="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 space-y-3">
                                <div className="text-xs font-semibold uppercase tracking-wide text-amber-800">
                                    Return details
                                </div>
                                <p className="text-[11px] text-amber-700">
                                    A return record will be created automatically when you save. After saving you'll land on the return page so you can record the inspection.
                                </p>

                                <label className="block text-xs font-medium text-slate-700">
                                    Return reason <span className="text-rose-600">*</span>
                                    <select
                                        value={form.data.return.return_reason_id}
                                        onChange={(e) => form.setData('return', { ...form.data.return, return_reason_id: e.target.value })}
                                        className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                                    >
                                        <option value="">— select a reason —</option>
                                        {return_reasons.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                                    </select>
                                    {form.errors['return.return_reason_id'] && (
                                        <p className="mt-1 text-xs text-rose-600">{form.errors['return.return_reason_id']}</p>
                                    )}
                                </label>

                                <label className="block text-xs font-medium text-slate-700">
                                    Product condition
                                    <select
                                        value={form.data.return.product_condition}
                                        onChange={(e) => form.setData('return', { ...form.data.return, product_condition: e.target.value })}
                                        className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                                    >
                                        {return_conditions.map((c) => <option key={c} value={c}>{c}</option>)}
                                    </select>
                                    {form.errors['return.product_condition'] && (
                                        <p className="mt-1 text-xs text-rose-600">{form.errors['return.product_condition']}</p>
                                    )}
                                </label>

                                <div className="grid grid-cols-2 gap-2">
                                    <label className="block text-xs font-medium text-slate-700">
                                        Refund amount {sym && <span className="text-slate-400">({sym})</span>}
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            value={form.data.return.refund_amount}
                                            onChange={(e) => form.setData('return', { ...form.data.return, refund_amount: e.target.value })}
                                            className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                                        />
                                        {form.errors['return.refund_amount'] && (
                                            <p className="mt-1 text-xs text-rose-600">{form.errors['return.refund_amount']}</p>
                                        )}
                                    </label>
                                    <label className="block text-xs font-medium text-slate-700">
                                        Shipping loss {sym && <span className="text-slate-400">({sym})</span>}
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            value={form.data.return.shipping_loss_amount}
                                            onChange={(e) => form.setData('return', { ...form.data.return, shipping_loss_amount: e.target.value })}
                                            className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                                        />
                                        {form.errors['return.shipping_loss_amount'] && (
                                            <p className="mt-1 text-xs text-rose-600">{form.errors['return.shipping_loss_amount']}</p>
                                        )}
                                    </label>
                                </div>

                                <label className="block text-xs font-medium text-slate-700">
                                    Return notes
                                    <textarea
                                        value={form.data.return.notes}
                                        onChange={(e) => form.setData('return', { ...form.data.return, notes: e.target.value })}
                                        placeholder="Optional notes for the return record"
                                        rows={2}
                                        className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                                    />
                                    {form.errors['return.notes'] && (
                                        <p className="mt-1 text-xs text-rose-600">{form.errors['return.notes']}</p>
                                    )}
                                </label>
                            </div>
                        )}

                        {has_return && (
                            <p className="mt-3 rounded-md border border-slate-200 bg-slate-50 p-2 text-[11px] text-slate-500">
                                This order already has a return record — the "Returned" status is no longer available from this menu.
                            </p>
                        )}

                        {/* Explain the missing "Returned" option when the
                            operator lacks returns.create — mirrors the hint
                            on Orders/Edit so the omission is never silent. */}
                        {!can_create_return && !has_return && (
                            <p className="mt-3 rounded-md border border-slate-200 bg-slate-50 p-2 text-[11px] text-slate-600">
                                You do not have permission to create return records, so the <strong>Returned</strong> status option is hidden here.
                            </p>
                        )}

                        {/* Defence-in-depth: if a return.* error ever comes back
                            while the Return Details block is collapsed, show it
                            here so the modal can never fail silently. */}
                        {!isReturning && hiddenReturnErrors.length > 0 && (
                            <p className="mt-3 rounded-md border border-rose-200 bg-rose-50 p-2 text-xs text-rose-700">
                                {hiddenReturnErrors.join(' ')}
                            </p>
                        )}

                        <div className="mt-4 flex justify-end gap-2">
                            <button type="button" onClick={() => setStatusOpen(false)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</button>
                            <button type="submit" disabled={form.processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                                {form.processing ? 'Saving…' : 'Save'}
                            </button>
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
