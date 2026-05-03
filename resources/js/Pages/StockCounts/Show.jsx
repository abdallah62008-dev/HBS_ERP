import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router } from '@inertiajs/react';

export default function StockCountShow({ count }) {
    const can = useCan();

    const approve = () => {
        if (!confirm(`Approve stock count and apply ${count.items.filter((i) => i.difference !== 0).length} corrections?`)) return;
        router.post(route('stock-counts.approve', count.id));
    };

    return (
        <AuthenticatedLayout header={`Stock count #${count.id}`}>
            <Head title={`Stock count ${count.id}`} />

            <PageHeader
                title={`Stock count #${count.id}`}
                subtitle={`${count.warehouse?.name} · ${count.count_date} · created by ${count.created_by?.name ?? '—'}`}
                actions={
                    <div className="flex gap-2">
                        <Link href={route('stock-counts.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Back</Link>
                        {count.status === 'Submitted' && can('inventory.count') && (
                            <button onClick={approve} className="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-500">
                                Approve & Apply corrections
                            </button>
                        )}
                    </div>
                }
            />

            <div className="mb-4 flex items-center gap-2">
                <StatusBadge value={count.status} />
                {count.approved_at && <span className="text-xs text-slate-500">Approved {count.approved_at?.split('T')[0]} by {count.approved_by?.name}</span>}
            </div>

            {count.notes && (
                <div className="mb-4 rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-700 whitespace-pre-line">
                    {count.notes}
                </div>
            )}

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">SKU</th>
                            <th className="px-4 py-2.5">Product</th>
                            <th className="px-4 py-2.5 text-right">System</th>
                            <th className="px-4 py-2.5 text-right">Counted</th>
                            <th className="px-4 py-2.5 text-right">Δ</th>
                            <th className="px-4 py-2.5">Notes</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {count.items.map((it) => (
                            <tr key={it.id} className={it.difference !== 0 ? 'bg-amber-50/60' : ''}>
                                <td className="px-4 py-2 font-mono text-xs">{it.product?.sku}</td>
                                <td className="px-4 py-2">{it.product?.name}</td>
                                <td className="px-4 py-2 text-right tabular-nums text-slate-600">{it.system_quantity}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{it.counted_quantity}</td>
                                <td className={'px-4 py-2 text-right tabular-nums ' + (it.difference > 0 ? 'text-emerald-700' : it.difference < 0 ? 'text-red-700' : 'text-slate-400')}>
                                    {it.difference > 0 ? '+' : ''}{it.difference}
                                </td>
                                <td className="px-4 py-2 text-slate-500">{it.notes ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
