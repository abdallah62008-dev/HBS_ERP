import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function TicketsIndex({ tickets, filters, statuses, can_manage }) {
    const can = useCan();
    const [q, setQ] = useState(filters?.q ?? '');
    const [status, setStatus] = useState(filters?.status ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('tickets.index'), {
            q: q || undefined,
            status: status || undefined,
        }, { preserveState: true, replace: true });
    };

    const remove = (t) => {
        if (!confirm(`Delete ticket #${t.id} (${t.subject})?`)) return;
        router.delete(route('tickets.destroy', t.id));
    };

    return (
        <AuthenticatedLayout header="Tickets">
            <Head title="Tickets" />
            <PageHeader
                title="Tickets"
                subtitle={`${tickets.total} record${tickets.total === 1 ? '' : 's'}${can_manage ? '' : ' · own only'}`}
                actions={
                    can('tickets.create') && (
                        <Link href={route('tickets.create')} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">+ New ticket</Link>
                    )
                }
            />

            <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div className="flex-1 min-w-[200px]">
                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search subject, message, user" className="block w-full rounded-md border-slate-300 text-sm" />
                </div>
                <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any status</option>
                    {statuses.map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
                </select>
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" className="px-4 py-2.5">#</th>
                            <th scope="col" className="px-4 py-2.5">Subject</th>
                            <th scope="col" className="px-4 py-2.5">Status</th>
                            <th scope="col" className="px-4 py-2.5">Created by</th>
                            <th scope="col" className="px-4 py-2.5">Created</th>
                            <th scope="col" className="px-4 py-2.5">Updated</th>
                            <th scope="col" className="px-4 py-2.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {tickets.data.length === 0 && (
                            <tr><td colSpan={7} className="px-4 py-12 text-center text-sm text-slate-400">
                                {(q || status) ? 'No tickets match your filters.' : 'No tickets yet.'}
                            </td></tr>
                        )}
                        {tickets.data.map((t) => (
                            <tr key={t.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-mono text-xs text-slate-500">{t.id}</td>
                                <td className="px-4 py-2.5">
                                    <Link href={route('tickets.show', t.id)} className="font-medium text-slate-800 hover:text-indigo-600">{t.subject}</Link>
                                </td>
                                <td className="px-4 py-2.5"><StatusBadge value={t.status?.replace('_', ' ')} /></td>
                                <td className="px-4 py-2.5">
                                    <div className="text-slate-700">{t.user?.name ?? '—'}</div>
                                    <div className="text-xs text-slate-500">{t.user?.email}</div>
                                </td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{t.created_at?.replace('T', ' ').slice(0, 16)}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{t.updated_at?.replace('T', ' ').slice(0, 16)}</td>
                                <td className="px-4 py-2.5 text-right whitespace-nowrap">
                                    <Link href={route('tickets.show', t.id)} className="text-xs font-medium text-indigo-600 hover:underline">View</Link>
                                    {can('tickets.edit') && (
                                        <Link href={route('tickets.edit', t.id)} className="ml-3 text-xs font-medium text-indigo-600 hover:underline">Edit</Link>
                                    )}
                                    {can('tickets.delete') && (
                                        <button onClick={() => remove(t)} className="ml-3 text-xs font-medium text-red-600 hover:underline">Delete</button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={tickets.links} />
        </AuthenticatedLayout>
    );
}
