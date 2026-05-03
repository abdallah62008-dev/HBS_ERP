import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const STATUSES = ['', 'Pending', 'Received', 'Inspected', 'Restocked', 'Damaged', 'Closed'];

export default function ReturnsIndex({ returns: returnsList, filters }) {
    const can = useCan();
    const [q, setQ] = useState(filters?.q ?? '');

    const apply = (status) => {
        router.get(route('returns.index'), { q: q || undefined, status: status || undefined }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Returns">
            <Head title="Returns" />
            <PageHeader
                title="Returns"
                subtitle={`${returnsList.total} record${returnsList.total === 1 ? '' : 's'}`}
                actions={
                    can('returns.create') && (
                        <Link href={route('returns.create')} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">+ New return</Link>
                    )
                }
            />

            <div className="mb-4 flex flex-wrap items-end gap-2">
                <div className="flex-1 min-w-[200px]">
                    <input value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') apply(filters?.status); }} placeholder="Search by order # or customer" className="block w-full rounded-md border-slate-300 text-sm" />
                </div>
                <div className="flex gap-1.5">
                    {STATUSES.map((s) => (
                        <button key={s} onClick={() => apply(s)} className={'rounded-full border px-3 py-1 text-xs ' + ((filters?.status ?? '') === s ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600')}>
                            {s || 'All'}
                        </button>
                    ))}
                </div>
            </div>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">#</th>
                            <th className="px-4 py-2.5">Order</th>
                            <th className="px-4 py-2.5">Customer</th>
                            <th className="px-4 py-2.5">Reason</th>
                            <th className="px-4 py-2.5">Condition</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5 text-right">Refund</th>
                            <th className="px-4 py-2.5">Inspector</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {returnsList.data.length === 0 && (
                            <tr><td colSpan={8} className="px-4 py-12 text-center text-sm text-slate-400">No returns yet.</td></tr>
                        )}
                        {returnsList.data.map((r) => (
                            <tr key={r.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5">
                                    <Link href={route('returns.show', r.id)} className="font-mono text-xs text-slate-700 hover:text-indigo-600">#{r.id}</Link>
                                </td>
                                <td className="px-4 py-2.5 font-mono text-xs">
                                    <Link href={route('orders.show', r.order_id)} className="text-slate-600 hover:text-indigo-600">{r.order?.order_number}</Link>
                                </td>
                                <td className="px-4 py-2.5">{r.order?.customer_name}</td>
                                <td className="px-4 py-2.5 text-slate-600">{r.return_reason?.name}</td>
                                <td className="px-4 py-2.5"><StatusBadge value={r.product_condition} /></td>
                                <td className="px-4 py-2.5"><StatusBadge value={r.return_status} /></td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{Number(r.refund_amount).toFixed(2)}</td>
                                <td className="px-4 py-2.5 text-slate-500">{r.inspected_by?.name ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={returnsList.links} />
        </AuthenticatedLayout>
    );
}
