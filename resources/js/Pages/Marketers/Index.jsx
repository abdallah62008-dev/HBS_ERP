import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function fmt(n) {
    return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export default function MarketersIndex({ marketers, filters, price_groups }) {
    const can = useCan();
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';
    const [q, setQ] = useState(filters?.q ?? '');
    const [status, setStatus] = useState(filters?.status ?? '');
    const [groupId, setGroupId] = useState(filters?.price_group_id ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('marketers.index'), {
            q: q || undefined, status: status || undefined, price_group_id: groupId || undefined,
        }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Marketers">
            <Head title="Marketers" />
            <PageHeader
                title="Marketers"
                subtitle={`${marketers.total} record${marketers.total === 1 ? '' : 's'}`}
                actions={
                    can('marketers.create') && (
                        <Link href={route('marketers.create')} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">+ New marketer</Link>
                    )
                }
            />

            <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div className="flex-1 min-w-[200px]">
                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Code, name, email" className="block w-full rounded-md border-slate-300 text-sm" />
                </div>
                <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any status</option>
                    <option>Active</option>
                    <option>Inactive</option>
                    <option>Suspended</option>
                </select>
                <select value={groupId} onChange={(e) => setGroupId(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any group</option>
                    {price_groups.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
                </select>
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Code</th>
                            <th className="px-4 py-2.5">Name</th>
                            <th className="px-4 py-2.5">Group</th>
                            <th className="px-4 py-2.5">Tier</th>
                            <th className="px-4 py-2.5 text-right">Orders</th>
                            <th className="px-4 py-2.5 text-right">Earned</th>
                            <th className="px-4 py-2.5 text-right">Balance</th>
                            <th className="px-4 py-2.5">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {marketers.data.length === 0 && (
                            <tr><td colSpan={8} className="px-4 py-12 text-center text-sm text-slate-400">No marketers yet.</td></tr>
                        )}
                        {marketers.data.map((m) => (
                            <tr key={m.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-mono text-xs">
                                    <Link href={route('marketers.show', m.id)} className="text-slate-700 hover:text-indigo-600">{m.code}</Link>
                                </td>
                                <td className="px-4 py-2.5">
                                    <div className="font-medium text-slate-800">{m.user?.name}</div>
                                    <div className="text-xs text-slate-500">{m.user?.email}</div>
                                </td>
                                <td className="px-4 py-2.5 text-slate-600">{m.price_group?.name}</td>
                                <td className="px-4 py-2.5 text-slate-600">
                                    {m.price_tier
                                        ? <span className="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{m.price_tier.name}</span>
                                        : <span className="text-xs text-slate-400">—</span>}
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{m.orders_count}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{sym}{fmt(m.wallet?.total_earned)}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums font-medium">{sym}{fmt(m.wallet?.balance)}</td>
                                <td className="px-4 py-2.5"><StatusBadge value={m.status} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={marketers.links} />
        </AuthenticatedLayout>
    );
}
