import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, useForm } from '@inertiajs/react';

function fmtAmount(value, currency = 'EGP') {
    const n = Number(value ?? 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    return currency === 'EGP' ? `${n} جنيه` : `${currency} ${n}`;
}

function refundStatusChip(status) {
    const tone = {
        requested: 'bg-amber-50 text-amber-700',
        approved: 'bg-emerald-50 text-emerald-700',
        rejected: 'bg-slate-100 text-slate-600',
        paid: 'bg-indigo-50 text-indigo-700',
    }[status] ?? 'bg-slate-100 text-slate-600';
    return (
        <span className={'inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium ' + tone}>
            {status}
        </span>
    );
}

export default function ReturnShow({ return: ret, reasons, refund_context, order_context, edit_context }) {
    const can = useCan();

    const inspect = useForm({
        product_condition: ret.product_condition === 'Unknown' ? 'Good' : ret.product_condition,
        restockable: ret.restockable ?? true,
        refund_amount: ret.refund_amount,
        notes: ret.notes ?? '',
    });

    const requestRefund = useForm({
        amount: refund_context?.refundable_amount ?? ret.refund_amount,
        reason: `Refund from return #${ret.id}`,
    });

    // Professional Return Management — limited details edit form.
    // Only refund_amount, shipping_loss_amount, notes are mutable
    // post-creation. State-machine fields are NOT editable here.
    const editDetails = useForm({
        refund_amount: ret.refund_amount,
        shipping_loss_amount: ret.shipping_loss_amount ?? 0,
        notes: ret.notes ?? '',
    });

    const submitInspect = (e) => {
        e.preventDefault();
        inspect.post(route('returns.inspect', ret.id));
    };

    const submitRequestRefund = (e) => {
        e.preventDefault();
        requestRefund.post(route('returns.request-refund', ret.id));
    };

    const submitEditDetails = (e) => {
        e.preventDefault();
        editDetails.put(route('returns.update', ret.id), { preserveScroll: true });
    };

    const closeReturn = () => {
        if (!confirm('Close this return? Closed returns cannot be edited.')) return;
        router.post(route('returns.close', ret.id));
    };

    // Phase 3 — optional Received checkpoint. Pure lifecycle marker:
    // posting this endpoint flips Pending → Received without touching
    // inventory, refunds, or cashbox. The fast-path (Pending → inspect()
    // directly) remains supported; this button lets warehouses with a
    // batch-inspection workflow record "parcel received, inspection
    // pending" as a separate step.
    const markReceived = () => {
        router.post(route('returns.receive', ret.id), {}, { preserveScroll: true });
    };

    // Phase 2 — RMA display reference (RET-000006). Provided by the
    // OrderReturn model's `display_reference` accessor; we fall back to
    // a bare `#id` only if an older payload (or test fixture) doesn't
    // include it.
    const ref = ret.display_reference ?? `Return #${ret.id}`;

    return (
        <AuthenticatedLayout header={ref}>
            <Head title={ref} />
            <PageHeader
                title={ref}
                subtitle={`Order ${ret.order?.order_number} · ${ret.order?.customer_name}`}
                actions={<Link href={route('returns.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Returns</Link>}
            />

            <div className="mb-4 flex items-center gap-2">
                <StatusBadge value={ret.return_status} />
                <StatusBadge value={ret.product_condition} />
                <span className="text-xs text-slate-500">Reason: {ret.return_reason?.name}</span>
            </div>

            {/* Professional Return Management — Order Summary card. Always
                surfaces the linked order's status so operators can spot a
                mismatch (e.g. a legacy return whose order is still in
                Shipped). The amber warning fires only when the mismatch
                is real (i.e. not during the legitimate Pending mid-flow
                state). */}
            {order_context && (
                <div className="mb-4 rounded-lg border border-slate-200 bg-white p-4">
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="text-xs font-medium uppercase tracking-wide text-slate-500">Linked order</div>
                        <Link href={route('orders.show', order_context.id)} className="font-mono text-sm text-indigo-600 hover:underline">
                            {order_context.order_number}
                        </Link>
                        <StatusBadge value={order_context.status} />
                        <span className="text-sm text-slate-700">{order_context.customer_name}</span>
                        {order_context.customer_phone && (
                            <span className="text-xs text-slate-500">· {order_context.customer_phone}</span>
                        )}
                    </div>
                    {order_context.mismatch && (
                        <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                            This return exists, but the linked order status is still
                            <strong className="mx-1">"{order_context.status}"</strong>.
                            Consider changing the order status to <strong>Returned</strong> from the order page so inventory and audit history stay aligned.
                        </div>
                    )}
                </div>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="lg:col-span-2 rounded-lg border border-slate-200 bg-white p-5 space-y-3">
                    <h2 className="text-sm font-semibold text-slate-700">Order items</h2>
                    <ul className="space-y-1 text-sm">
                        {(ret.order?.items ?? []).map((it) => (
                            <li key={it.id} className="flex justify-between border-b border-slate-100 py-1">
                                <span><span className="font-mono text-xs text-slate-500">{it.sku}</span> {it.product_name}</span>
                                <span className="tabular-nums text-slate-600">×{it.quantity}</span>
                            </li>
                        ))}
                    </ul>

                    {ret.notes && (
                        <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 whitespace-pre-line">
                            {ret.notes}
                        </div>
                    )}
                </div>

                <div className="space-y-4">
                    {/* Phase 3 — optional Received checkpoint. Shown only
                        while the return is Pending AND the user has the
                        new `returns.receive` slug (warehouse-agent +
                        manager + admin). Inspecting directly remains
                        supported via the form below — Received is a
                        convenience, not a prerequisite. */}
                    {can('returns.receive') && ret.return_status === 'Pending' && (
                        <div className="rounded-lg border border-slate-200 bg-white p-4 space-y-2">
                            <h2 className="text-sm font-semibold text-slate-700">Receive</h2>
                            <p className="text-[11px] text-slate-500">
                                Mark the parcel as physically received in the warehouse. Inventory and refunds are unaffected — inspection still decides the verdict.
                            </p>
                            <button
                                type="button"
                                onClick={markReceived}
                                className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                            >
                                Mark received
                            </button>
                        </div>
                    )}

                    {can('returns.inspect') && ['Pending', 'Received'].includes(ret.return_status) && (
                        <form onSubmit={submitInspect} className="rounded-lg border border-slate-200 bg-white p-5 space-y-3">
                            <h2 className="text-sm font-semibold text-slate-700">Inspect</h2>
                            <select value={inspect.data.product_condition} onChange={(e) => inspect.setData('product_condition', e.target.value)} className="block w-full rounded-md border-slate-300 text-sm">
                                <option>Good</option>
                                <option>Damaged</option>
                                <option>Missing Parts</option>
                                <option>Unknown</option>
                            </select>
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={inspect.data.restockable} onChange={(e) => inspect.setData('restockable', e.target.checked)} className="rounded border-slate-300" />
                                Restock to inventory
                            </label>
                            <input type="number" step="0.01" min={0} value={inspect.data.refund_amount} onChange={(e) => inspect.setData('refund_amount', e.target.value)} placeholder="Refund amount" className="block w-full rounded-md border-slate-300 text-sm" />
                            <textarea value={inspect.data.notes} onChange={(e) => inspect.setData('notes', e.target.value)} placeholder="Inspection notes" rows={2} className="block w-full rounded-md border-slate-300 text-sm" />
                            <button type="submit" disabled={inspect.processing} className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                                {inspect.processing ? 'Saving…' : 'Save inspection'}
                            </button>
                            <p className="text-[11px] text-slate-500">If condition is anything other than Good + restockable, an inventory write-off (Return Damaged) is recorded automatically.</p>
                        </form>
                    )}

                    {ret.return_status !== 'Closed' && can('returns.approve') && (
                        <button onClick={closeReturn} className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">
                            Close return
                        </button>
                    )}

                    {/* Professional Return Management — limited details edit.
                        Lifecycle state (return_status, product_condition,
                        restockable) is NEVER editable here — those mutate
                        only via inspect/close. refund_amount cannot drop
                        below the cumulative active linked refunds. */}
                    {can('returns.create') && edit_context?.can_edit && (
                        <form onSubmit={submitEditDetails} className="rounded-lg border border-slate-200 bg-white p-5 space-y-3">
                            <h2 className="text-sm font-semibold text-slate-700">Edit details</h2>
                            <p className="text-[11px] text-slate-500">
                                Only refund amount, shipping loss, and notes can be edited here.
                                Status, condition, and restockable are managed by Inspect / Close.
                            </p>

                            <label className="block text-xs font-medium text-slate-600">
                                Refund amount
                                <input
                                    type="number"
                                    step="0.01"
                                    min={edit_context?.min_refund_amount ?? 0}
                                    value={editDetails.data.refund_amount}
                                    onChange={(e) => editDetails.setData('refund_amount', e.target.value)}
                                    className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                                />
                                {edit_context?.active_refund_total > 0 && (
                                    <p className="mt-1 text-[11px] text-slate-500">
                                        Cannot reduce below {fmtAmount(edit_context.active_refund_total)} (active linked refunds).
                                    </p>
                                )}
                                {editDetails.errors.refund_amount && (
                                    <p className="mt-1 text-xs text-rose-600">{editDetails.errors.refund_amount}</p>
                                )}
                            </label>

                            <label className="block text-xs font-medium text-slate-600">
                                Shipping loss
                                <input
                                    type="number"
                                    step="0.01"
                                    min={0}
                                    value={editDetails.data.shipping_loss_amount}
                                    onChange={(e) => editDetails.setData('shipping_loss_amount', e.target.value)}
                                    className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                                />
                                {editDetails.errors.shipping_loss_amount && (
                                    <p className="mt-1 text-xs text-rose-600">{editDetails.errors.shipping_loss_amount}</p>
                                )}
                            </label>

                            <label className="block text-xs font-medium text-slate-600">
                                Notes
                                <textarea
                                    rows={3}
                                    value={editDetails.data.notes}
                                    onChange={(e) => editDetails.setData('notes', e.target.value)}
                                    placeholder="Free-text notes about the return"
                                    className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                                />
                                {editDetails.errors.notes && (
                                    <p className="mt-1 text-xs text-rose-600">{editDetails.errors.notes}</p>
                                )}
                            </label>

                            <button
                                type="submit"
                                disabled={editDetails.processing}
                                className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                            >
                                {editDetails.processing ? 'Saving…' : 'Save details'}
                            </button>
                        </form>
                    )}

                    {ret.return_status === 'Closed' && (
                        <p className="rounded-md border border-slate-200 bg-slate-50 px-2 py-1.5 text-[11px] text-slate-500">
                            This return is closed. Details are locked.
                        </p>
                    )}

                    <div className="rounded-lg border border-slate-200 bg-white p-5 text-sm">
                        <h2 className="text-sm font-semibold text-slate-700">Refund</h2>
                        <div className="mt-1 text-2xl font-semibold tabular-nums text-slate-800">{fmtAmount(ret.refund_amount)}</div>
                        <div className="mt-1 text-xs text-slate-500">Shipping loss: {fmtAmount(ret.shipping_loss_amount)}</div>
                        {ret.inspected_by && (
                            <div className="mt-2 text-xs text-slate-500">Inspected by {ret.inspected_by.name} on {ret.inspected_at?.split('T')[0]}</div>
                        )}

                        {/* Phase 5C — linked refunds + request action */}
                        {refund_context && (
                            <div className="mt-4 border-t border-slate-100 pt-3">
                                <div className="flex items-center justify-between text-xs text-slate-500">
                                    <span>Active refunds total</span>
                                    <span className="tabular-nums">{fmtAmount(refund_context.active_refund_total)}</span>
                                </div>
                                <div className="flex items-center justify-between text-xs text-slate-500">
                                    <span>Refundable remaining</span>
                                    <span className={'tabular-nums ' + (refund_context.refundable_amount > 0 ? 'text-slate-700 font-medium' : 'text-slate-400')}>
                                        {fmtAmount(refund_context.refundable_amount)}
                                    </span>
                                </div>

                                {(ret.refunds ?? []).length > 0 && (
                                    <ul className="mt-3 space-y-1.5">
                                        {ret.refunds.map((r) => (
                                            <li key={r.id} className="flex items-center justify-between rounded border border-slate-100 bg-slate-50 px-2 py-1 text-xs">
                                                <span>
                                                    <Link href={route('refunds.index')} className="font-mono text-slate-600 hover:underline">#{r.id}</Link>
                                                    <span className="ml-1.5">{refundStatusChip(r.status)}</span>
                                                </span>
                                                <span className="tabular-nums font-medium text-slate-700">{fmtAmount(r.amount)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                {can('refunds.create') && refund_context.can_request_refund && (
                                    <form onSubmit={submitRequestRefund} className="mt-3 space-y-2 rounded-md border border-indigo-200 bg-indigo-50 p-3">
                                        <label className="text-[11px] uppercase tracking-wide text-indigo-800">
                                            Request refund
                                        </label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            max={refund_context.refundable_amount}
                                            value={requestRefund.data.amount ?? ''}
                                            onChange={(e) => requestRefund.setData('amount', e.target.value)}
                                            placeholder="Amount"
                                            className="block w-full rounded-md border-indigo-200 text-xs"
                                        />
                                        <textarea
                                            value={requestRefund.data.reason ?? ''}
                                            onChange={(e) => requestRefund.setData('reason', e.target.value)}
                                            placeholder="Reason (optional)"
                                            rows={2}
                                            className="block w-full rounded-md border-indigo-200 text-xs"
                                        />
                                        <button
                                            type="submit"
                                            disabled={requestRefund.processing}
                                            className="w-full rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                                        >
                                            {requestRefund.processing ? 'Requesting…' : 'Request refund'}
                                        </button>
                                        <p className="text-[10px] text-indigo-700">
                                            Creates a <strong>requested</strong> refund. Approval and payment continue in the Refunds module.
                                        </p>
                                    </form>
                                )}

                                {refund_context.can_request_refund === false && refund_context.refund_base_amount > 0 && (
                                    <p className="mt-3 rounded-md border border-slate-200 bg-slate-50 px-2 py-1.5 text-[11px] text-slate-500">
                                        {refund_context.refundable_amount <= 0
                                            ? 'No refundable amount remaining — all has been covered by active refunds.'
                                            : `Refund requests are only available after inspection (eligible statuses: ${refund_context.eligible_statuses.join(', ')}).`}
                                    </p>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
