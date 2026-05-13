import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, usePage } from '@inertiajs/react';
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
    return `${currency} ${n}`;
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

export default function RefundsIndex({ refunds, filters, totals, statuses }) {
    const can = useCan();
    const { props } = usePage();
    const currency = props.app?.currency_code ?? 'EGP';

    const [q, setQ] = useState(filters?.q ?? '');
    const [status, setStatus] = useState(filters?.status ?? '');

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
                subtitle={`${refunds.total} record${refunds.total === 1 ? '' : 's'} · paperwork-only (Phase 5A)`}
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

            <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <div className="text-xs font-medium uppercase tracking-wide text-amber-700">Requested</div>
                    <div className="mt-1 text-2xl font-semibold tabular-nums text-amber-800">{totals.requested_count}</div>
                    <div className="mt-1 text-sm tabular-nums text-amber-700">{fmtAmount(totals.requested_amount, currency)}</div>
                </div>
                <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                    <div className="text-xs font-medium uppercase tracking-wide text-emerald-700">Approved (paperwork)</div>
                    <div className="mt-1 text-2xl font-semibold tabular-nums text-emerald-800">{totals.approved_count}</div>
                    <div className="mt-1 text-sm tabular-nums text-emerald-700">{fmtAmount(totals.approved_amount, currency)}</div>
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
                            <th className="px-4 py-2.5">Customer</th>
                            <th className="px-4 py-2.5">Reason</th>
                            <th className="px-4 py-2.5">Requested by</th>
                            <th className="px-4 py-2.5">Decided</th>
                            <th className="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {refunds.data.length === 0 && (
                            <tr><td colSpan={10} className="px-4 py-12 text-center text-sm text-slate-400">No refunds match.</td></tr>
                        )}
                        {refunds.data.map((r) => (
                            <tr key={r.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-mono text-xs text-slate-500">#{r.id}</td>
                                <td className="px-4 py-2.5">{statusChip(r.status)}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums font-medium">{fmtAmount(r.amount, currency)}</td>
                                <td className="px-4 py-2.5 text-xs">
                                    {r.order ? <Link href={route('orders.show', r.order.id)} className="text-slate-700 hover:underline">{r.order.order_number}</Link> : <span className="text-slate-400">—</span>}
                                </td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">
                                    {r.collection ? <span>#{r.collection.id}</span> : <span className="text-slate-400">—</span>}
                                </td>
                                <td className="px-4 py-2.5 text-xs text-slate-600">
                                    {r.customer ? r.customer.name : (r.order?.customer_name ?? <span className="text-slate-400">—</span>)}
                                </td>
                                <td className="px-4 py-2.5 text-xs text-slate-600">{r.reason ? <span title={r.reason}>{r.reason.slice(0, 60)}{r.reason.length > 60 ? '…' : ''}</span> : <span className="text-slate-400">—</span>}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{r.requested_by?.name ?? '—'}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">
                                    {r.approved_at ? <>by {r.approved_by?.name ?? '—'}<br /><span className="text-slate-400">{r.approved_at?.slice(0, 10)}</span></> : null}
                                    {r.rejected_at ? <>by {r.rejected_by?.name ?? '—'}<br /><span className="text-slate-400">{r.rejected_at?.slice(0, 10)}</span></> : null}
                                    {!r.approved_at && !r.rejected_at && <span className="text-slate-400">—</span>}
                                </td>
                                <td className="px-4 py-2.5 text-right space-x-2 whitespace-nowrap">
                                    {r.status === 'requested' && can('refunds.create') && (
                                        <Link href={route('refunds.edit', r.id)} className="text-xs text-slate-600 hover:underline">Edit</Link>
                                    )}
                                    {r.status === 'requested' && can('refunds.approve') && (
                                        <button type="button" onClick={() => onApprove(r)} className="text-xs text-emerald-700 hover:underline">Approve</button>
                                    )}
                                    {r.status === 'requested' && can('refunds.reject') && (
                                        <button type="button" onClick={() => onReject(r)} className="text-xs text-rose-700 hover:underline">Reject</button>
                                    )}
                                    {r.status === 'requested' && can('refunds.create') && (
                                        <button type="button" onClick={() => onDelete(r)} className="text-xs text-red-600 hover:underline">Delete</button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={refunds.links} />
        </AuthenticatedLayout>
    );
}
