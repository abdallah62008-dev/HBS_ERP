import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link } from '@inertiajs/react';

export default function InventoryReport({ rows }) {
    const lowCount = rows.filter((r) => r.is_low).length;

    return (
        <AuthenticatedLayout header="Inventory report">
            <Head title="Inventory report" />
            <PageHeader
                title="Inventory snapshot"
                subtitle={`${rows.length} active product${rows.length === 1 ? '' : 's'} · ${lowCount} below reorder level`}
                actions={<Link href={route('reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Reports</Link>}
            />

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">SKU</th>
                            <th className="px-4 py-2.5">Product</th>
                            <th className="px-4 py-2.5 text-right">Reorder ≤</th>
                            <th className="px-4 py-2.5 text-right">On hand</th>
                            <th className="px-4 py-2.5 text-right">Reserved</th>
                            <th className="px-4 py-2.5 text-right">Available</th>
                            <th className="px-4 py-2.5">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.length === 0 && (
                            <tr><td colSpan={7} className="px-4 py-12 text-center text-sm text-slate-400">No active products.</td></tr>
                        )}
                        {rows.map((r) => (
                            <tr key={r.id} className={r.is_low ? 'bg-amber-50/60' : ''}>
                                <td className="px-4 py-2 font-mono text-xs">{r.sku}</td>
                                <td className="px-4 py-2 text-slate-800">{r.name}</td>
                                <td className="px-4 py-2 text-right tabular-nums text-slate-500">{r.reorder_level}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{r.on_hand}</td>
                                <td className="px-4 py-2 text-right tabular-nums text-amber-700">{r.reserved}</td>
                                <td className="px-4 py-2 text-right tabular-nums font-medium">{r.available}</td>
                                <td className="px-4 py-2">{r.is_low ? <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">LOW</span> : <span className="text-xs text-slate-400">OK</span>}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
