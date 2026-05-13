import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Refunds use the app's currency_code prefix (e.g. "EGP 100.00"),
 * matching the Cashbox/CashboxTransfer convention.
 */
function fmtAmount(value, currency = 'EGP') {
    const n = Number(value ?? 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    return currency === 'EGP' ? `${n} جنيه` : `${currency} ${n}`;
}

function statusChip(status) {
    const tone = {
        requested: 'bg-amber-50 text-amber-700',
        approved: 'bg-emerald-50 text-emerald-700',
        rejected: 'bg-slate-100 text-slate-600',
        paid: 'bg-indigo-50 text-indigo-700',
    }[status] ?? 'bg-slate-100 text-slate-600';
    return (
        <span className={'inline-flex rounded-full px-2 py-0.5 text-xs font-medium ' + tone}>
            {status}
        </span>
    );
}

export default function RefundsIndex({ refunds, filters, totals, statuses, cashboxes = [], payment_methods = [] }) {
    const can = useCan();
    const { props } = usePage();
    const currency = props.app?.currency_code ?? 'EGP';

    const [q, setQ] = useState(filters?.q ?? '');
    const [status, setStatus] = useState(filters?.status ?? '');
    const [payingId, setPayingId] = useState(null);

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('refunds.index'), {
            q: q || undefined,
            status: status || undefined,
        }, { preserveState: true, replace: true });
    };

    const onApprove = (r) => {
        if (!confirm(`Approve refund #${r.id} for ${fmtAmount(r.amount, currency)}?`)) return;
        router.post(route('refunds.approve', r.id), {}, { preserveScroll: true });
    };
    const onReject = (r) => {
        if (!confirm(`Reject refund #${r.id} for ${fmtAmount(r.amount, currency)}?`)) return;
        router.post(route('refunds.reject', r.id), {}, { preserveScroll: true });
    };
    const onDelete = (r) => {
        if (!confirm(`Delete requested refund #${r.id}? Only requested refunds can be deleted.`)) return;
        router.delete(route('refunds.destroy', r.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header="Refunds">
            <Head title="Refunds" />
            <PageHeader
                title="Refunds"
                subtitle={`${refunds.total} record${refunds.total === 1 ? '' : 's'}`}
                actions={
                    can('refunds.create') && (
                        <Link
                            href={route('refunds.create')}
                            className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
                        >
                            + New refund request
                        </Link>
                    )
                }
            />

            <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <div className="text-xs font-medium uppercase tracking-wide text-amber-700">Requested</div>
                    <div className="mt-1 text-2xl font-semibold tabular-nums text-amber-800">{totals.requested_count}</div>
                    <div className="mt-1 text-sm tabular-nums text-amber-700">{fmtAmount(totals.requested_amount, currency)}</div>
                </div>
                <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                    <div className="text-xs font-medium uppercase tracking-wide text-emerald-700">Approved (awaiting pay)</div>
                    <div className="mt-1 text-2xl font-semibold tabular-nums text-emerald-800">{totals.approved_count}</div>
                    <div className="mt-1 text-sm tabular-nums text-emerald-700">{fmtAmount(totals.approved_amount, currency)}</div>
                </div>
                <div className="rounded-lg border border-indigo-200 bg-indigo-50 p-4">
                    <div className="text-xs font-medium uppercase tracking-wide text-indigo-700">Paid (cashbox OUT)</div>
                    <div className="mt-1 text-2xl font-semibold tabular-nums text-indigo-800">{totals.paid_count ?? 0}</div>
                    <div className="mt-1 text-sm tabular-nums text-indigo-700">{fmtAmount(totals.paid_amount ?? 0, currency)}</div>
                </div>
                <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <div className="text-xs font-medium uppercase tracking-wide text-slate-600">Rejected</div>
                    <div className="mt-1 text-2xl font-semibold tabular-nums text-slate-700">{totals.rejected_count}</div>
                    <div className="mt-1 text-sm tabular-nums text-slate-600">{fmtAmount(totals.rejected_amount, currency)}</div>
                </div>
            </div>

            <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div className="flex-1 min-w-[200px]">
                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Reason or order #" className="block w-full rounded-md border-slate-300 text-sm" />
                </div>
                <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any status</option>
                    {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">#</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5 text-right">Amount</th>
                            <th className="px-4 py-2.5">Order</th>
                            <th className="px-4 py-2.5">Collection</th>
                            <th className="px-4 py-2.5">Reason</th>
                            <th className="px-4 py-2.5">Decided</th>
                            <th className="px-4 py-2.5">Paid</th>
                            <th className="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {refunds.data.length === 0 && (
                            <tr><td colSpan={9} className="px-4 py-12 text-center text-sm text-slate-400">No refunds match.</td></tr>
                        )}
                        {refunds.data.map((r) => (
                            <RefundRow
                                key={r.id}
                                refund={r}
                                currency={currency}
                                can={can}
                                cashboxes={cashboxes}
                                paymentMethods={payment_methods}
                                paying={payingId === r.id}
                                onPayOpen={() => setPayingId(r.id)}
                                onPayClose={() => setPayingId(null)}
                                onApprove={() => onApprove(r)}
                                onReject={() => onReject(r)}
                                onDelete={() => onDelete(r)}
                            />
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={refunds.links} />
        </AuthenticatedLayout>
    );
}

function RefundRow({ refund, currency, can, cashboxes, paymentMethods, paying, onPayOpen, onPayClose, onApprove, onReject, onDelete }) {
    const r = refund;
    const isApproved = r.status === 'approved';
    const isPaid = r.status === 'paid';

    /* Pay form per row (only when expanded). */
    const f = useForm({
        cashbox_id: '',
        payment_method_id: '',
        occurred_at: new Date().toISOString().slice(0, 10),
    });

    const submitPay = (e) => {
        e.preventDefault();
        f.post(route('refunds.pay', r.id), {
            preserveScroll: true,
            onSuccess: () => onPayClose(),
        });
    };

    const selectedCashbox = cashboxes.find((c) => String(c.id) === String(f.data.cashbox_id));
    const wouldOverdraw =
        selectedCashbox &&
        !selectedCashbox.allow_negative_balance &&
        Number(r.amount) > Number(selectedCashbox.balance ?? Infinity);

    return (
        <>
            <tr className="hover:bg-slate-50">
                <td className="px-4 py-2.5 font-mono text-xs text-slate-500">#{r.id}</td>
                <td className="px-4 py-2.5">{statusChip(r.status)}</td>
                <td className="px-4 py-2.5 text-right tabular-nums font-medium">{fmtAmount(r.amount, currency)}</td>
                <td className="px-4 py-2.5 text-xs">
                    {r.order ? <Link href={route('orders.show', r.order.id)} className="text-slate-700 hover:underline">{r.order.order_number}</Link> : <span className="text-slate-400">—</span>}
                </td>
                <td className="px-4 py-2.5 text-xs text-slate-500">
                    {r.collection ? <span>#{r.collection.id}</span> : <span className="text-slate-400">—</span>}
                </td>
                <td className="px-4 py-2.5 text-xs text-slate-600">{r.reason ? <span title={r.reason}>{r.reason.slice(0, 50)}{r.reason.length > 50 ? '…' : ''}</span> : <span className="text-slate-400">—</span>}</td>
                <td className="px-4 py-2.5 text-xs text-slate-500">
                    {r.approved_at && <span>approved {r.approved_at?.slice(0, 10)}<br /><span className="text-slate-400">by {r.approved_by?.name ?? '—'}</span></span>}
                    {r.rejected_at && <span>rejected {r.rejected_at?.slice(0, 10)}<br /><span className="text-slate-400">by {r.rejected_by?.name ?? '—'}</span></span>}
                    {!r.approved_at && !r.rejected_at && <span className="text-slate-400">—</span>}
                </td>
                <td className="px-4 py-2.5 text-xs text-slate-600">
                    {isPaid ? (
                        <span>
                            {r.paid_at?.slice(0, 10)}
                            <br />
                            <span className="text-slate-400">{r.cashbox?.name ?? '—'} · {r.payment_method?.name ?? '—'}</span>
                        </span>
                    ) : <span className="text-slate-400">—</span>}
                </td>
                <td className="px-4 py-2.5 text-right space-x-2 whitespace-nowrap">
                    {r.status === 'requested' && can('refunds.create') && (
                        <Link href={route('refunds.edit', r.id)} className="text-xs text-slate-600 hover:underline">Edit</Link>
                    )}
                    {r.status === 'requested' && can('refunds.approve') && (
                        <button type="button" onClick={onApprove} className="text-xs text-emerald-700 hover:underline">Approve</button>
                    )}
                    {r.status === 'requested' && can('refunds.reject') && (
                        <button type="button" onClick={onReject} className="text-xs text-rose-700 hover:underline">Reject</button>
                    )}
                    {r.status === 'requested' && can('refunds.create') && (
                        <button type="button" onClick={onDelete} className="text-xs text-red-600 hover:underline">Delete</button>
                    )}
                    {isApproved && can('refunds.pay') && !paying && (
                        <button type="button" onClick={onPayOpen} className="text-xs text-indigo-700 font-medium hover:underline">Pay…</button>
                    )}
                </td>
            </tr>

            {paying && isApproved && can('refunds.pay') && (
                <tr className="bg-indigo-50 align-top">
                    <td className="px-4 py-2.5" colSpan={9}>
                        <form onSubmit={submitPay} className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div>
                                <label className="text-[10px] uppercase text-slate-500">Cashbox *</label>
                                <select
                                    value={f.data.cashbox_id ?? ''}
                                    onChange={(e) => f.setData('cashbox_id', e.target.value)}
                                    required
                                    className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                                >
                                    <option value="">— Pick cashbox —</option>
                                    {cashboxes.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name} ({c.currency_code})
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="text-[10px] uppercase text-slate-500">Payment method *</label>
                                <select
                                    value={f.data.payment_method_id ?? ''}
                                    onChange={(e) => f.setData('payment_method_id', e.target.value)}
                                    required
                                    className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                                >
                                    <option value="">— Pick method —</option>
                                    {paymentMethods.map((m) => (
                                        <option key={m.id} value={m.id}>{m.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="text-[10px] uppercase text-slate-500">Occurred at</label>
                                <input
                                    type="date"
                                    value={f.data.occurred_at ?? ''}
                                    onChange={(e) => f.setData('occurred_at', e.target.value)}
                                    className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                                />
                            </div>
                            <div className="flex items-end justify-end gap-2">
                                <button type="button" onClick={onPayClose} className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs">Cancel</button>
                                <button
                                    type="submit"
                                    disabled={f.processing || wouldOverdraw}
                                    className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white disabled:opacity-60"
                                >
                                    {f.processing ? 'Paying…' : `Pay ${fmtAmount(r.amount, currency)}`}
                                </button>
                            </div>
                            <div className="col-span-2 sm:col-span-4 text-xs">
                                {wouldOverdraw && (
                                    <span className="rounded-md bg-amber-50 px-2 py-1 text-amber-800">
                                        Source cashbox does not permit a negative balance and the refund amount exceeds the current balance.
                                    </span>
                                )}
                                <span className="ml-2 text-slate-500">
                                    Records one cashbox OUT transaction. Cannot be undone in this phase.
                                </span>
                            </div>
                        </form>
                    </td>
                </tr>
            )}
        </>
    );
}
