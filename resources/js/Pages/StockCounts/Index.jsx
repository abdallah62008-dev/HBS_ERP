import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router } from '@inertiajs/react';

export default function StockCountsIndex({ counts, filters }) {
    const can = useCan();

    const apply = (status) => {
        router.get(route('stock-counts.index'), { status: status || undefined }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Stock counts">
            <Head title="Stock counts" />
            <PageHeader
                title="Stock counts"
                subtitle="Periodic recounts. Approving applies differences as Stock Count Correction movements."
                actions={
                    can('inventory.count') && (
                        <Link href={route('stock-counts.create')} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">+ New count</Link>
                    )
                }
            />

            <div className="mb-4 flex gap-1.5">
                {['', 'Submitted', 'Approved', 'Rejected'].map((s) => (
                    <button key={s} onClick={() => apply(s)} className={'rounded-full border px-3 py-1 text-xs ' + ((filters?.status ?? '') === s ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600')}>
                        {s || 'All'}
                    </button>
                ))}
            </div>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Date</th>
                            <th className="px-4 py-2.5">Warehouse</th>
                            <th className="px-4 py-2.5 text-right">Items</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5">Created by</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {counts.data.length === 0 && (
                            <tr><td colSpan={5} className="px-4 py-12 text-center text-sm text-slate-400">No counts yet.</td></tr>
                        )}
                        {counts.data.map((c) => (
                            <tr key={c.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5">
                                    <Link href={route('stock-counts.show', c.id)} className="font-mono text-xs text-slate-700 hover:text-indigo-600">{c.count_date} #{c.id}</Link>
                                </td>
                                <td className="px-4 py-2.5 text-slate-700">{c.warehouse?.name}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{c.items_count}</td>
                                <td className="px-4 py-2.5"><StatusBadge value={c.status} /></td>
                                <td className="px-4 py-2.5 text-slate-500">{c.created_by?.name ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={counts.links} />
        </AuthenticatedLayout>
    );
}
