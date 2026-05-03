import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function ApprovalsIndex({ requests, filters, types, pending_count }) {
    const [status, setStatus] = useState(filters?.status ?? '');
    const [type, setType] = useState(filters?.type ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('approvals.index'), {
            status: status || undefined, type: type || undefined,
        }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Approvals">
            <Head title="Approvals" />
            <PageHeader
                title="Approvals"
                subtitle={`${pending_count} pending · ${requests.total} total`}
            />

            <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Status</label>
                    <select value={status} onChange={(e) => setStatus(e.target.value)} className="mt-1 rounded-md border-slate-300 text-sm">
                        <option value="">Any</option>
                        <option>Pending</option>
                        <option>Approved</option>
                        <option>Rejected</option>
                    </select>
                </div>
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Type</label>
                    <select value={type} onChange={(e) => setType(e.target.value)} className="mt-1 rounded-md border-slate-300 text-sm">
                        <option value="">Any</option>
                        {types.map((t) => <option key={t} value={t}>{t}</option>)}
                    </select>
                </div>
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">When</th>
                            <th className="px-4 py-2.5">Type</th>
                            <th className="px-4 py-2.5">Target</th>
                            <th className="px-4 py-2.5">Reason</th>
                            <th className="px-4 py-2.5">Requested by</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {requests.data.length === 0 && (
                            <tr><td colSpan={7} className="px-4 py-12 text-center text-sm text-slate-400">No approval requests.</td></tr>
                        )}
                        {requests.data.map((r) => (
                            <tr key={r.id} className={r.status === 'Pending' ? 'bg-amber-50/40' : ''}>
                                <td className="px-4 py-2.5 text-slate-500">{r.created_at?.replace('T', ' ').slice(0, 16)}</td>
                                <td className="px-4 py-2.5"><span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{r.approval_type}</span></td>
                                <td className="px-4 py-2.5 text-xs">{r.related_type ? `${r.related_type.split('\\').pop()}#${r.related_id}` : '—'}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-600 max-w-md truncate" title={r.reason}>{r.reason}</td>
                                <td className="px-4 py-2.5 text-slate-500">{r.requested_by?.name}</td>
                                <td className="px-4 py-2.5"><StatusBadge value={r.status} /></td>
                                <td className="px-4 py-2.5 text-right">
                                    <Link href={route('approvals.show', r.id)} className="text-xs text-indigo-600 hover:underline">view →</Link>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={requests.links} />
        </AuthenticatedLayout>
    );
}
