import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import useCan from '@/Hooks/useCan';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function InventoryIndex({ rows, paginator, warehouses, filters }) {
    const can = useCan();
    const [q, setQ] = useState(filters?.q ?? '');
    const [warehouseId, setWarehouseId] = useState(filters?.warehouse_id ?? '');
    const lowOnly = !!filters?.low_stock_only;

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('inventory.index'), {
            q: q || undefined,
            warehouse_id: warehouseId || undefined,
            low_stock_only: lowOnly ? '1' : undefined,
        }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Inventory">
            <Head title="Inventory" />

            <PageHeader
                title="Inventory overview"
                subtitle="Movement-based stock per product / warehouse"
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Link href={route('inventory.movements')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">Movements log</Link>
                        <Link href={route('inventory.low-stock')} className={'rounded-md px-3 py-2 text-sm font-medium ' + (lowOnly ? 'bg-amber-500 text-white' : 'border border-amber-200 text-amber-700 bg-amber-50')}>Low stock</Link>
                        {can('inventory.adjust') && (
                            <Link href={route('stock-adjustments.create')} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">+ Adjustment</Link>
                        )}
                    </div>
                }
            />

            <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div className="flex-1 min-w-[200px]">
                    <label className="text-[11px] font-medium uppercase text-slate-500">Search</label>
                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name or SKU…" className="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                </div>
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Warehouse</label>
                    <select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} className="mt-1 rounded-md border-slate-300 text-sm">
                        <option value="">All</option>
                        {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                    </select>
                </div>
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">SKU</th>
                            <th className="px-4 py-2.5">Product</th>
                            {warehouses.map((w) => (
                                <th key={w.id} className="px-3 py-2.5 text-center" colSpan={3}>{w.name}</th>
                            ))}
                            <th className="px-4 py-2.5 text-right">Total avail.</th>
                        </tr>
                        <tr className="bg-slate-50 text-[10px] font-medium uppercase text-slate-400">
                            <th></th><th></th>
                            {warehouses.map((w) => (
                                <Cells key={w.id} />
                            ))}
                            <th></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.length === 0 && (
                            <tr><td colSpan={2 + warehouses.length * 3 + 1} className="px-4 py-12 text-center text-sm text-slate-400">No products match.</td></tr>
                        )}
                        {rows.map((r) => (
                            <tr key={r.id} className={r.is_low ? 'bg-amber-50/50 hover:bg-amber-100' : 'hover:bg-slate-50'}>
                                <td className="px-4 py-2 font-mono text-xs">{r.sku}</td>
                                <td className="px-4 py-2">
                                    <Link href={route('products.show', r.id)} className="font-medium text-slate-800 hover:text-indigo-600">{r.name}</Link>
                                    {r.is_low && <span className="ml-2 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-800">LOW</span>}
                                    {r.reorder_level > 0 && <span className="ml-2 text-[10px] text-slate-400">reorder ≤ {r.reorder_level}</span>}
                                </td>
                                {warehouses.map((w) => {
                                    const e = r.warehouses[w.id] ?? { on_hand: 0, reserved: 0, available: 0 };
                                    return (
                                        <>
                                            <td key={`${w.id}-on`} className="px-3 py-2 text-right tabular-nums text-slate-700">{e.on_hand}</td>
                                            <td key={`${w.id}-rv`} className="px-3 py-2 text-right tabular-nums text-amber-600">{e.reserved}</td>
                                            <td key={`${w.id}-av`} className="px-3 py-2 text-right tabular-nums font-semibold">{e.available}</td>
                                        </>
                                    );
                                })}
                                <td className="px-4 py-2 text-right tabular-nums font-semibold">{r.total_available}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={paginator.links} />
            <p className="mt-2 text-[11px] text-slate-400">on-hand · reserved · <strong>available</strong></p>
        </AuthenticatedLayout>
    );
}

function Cells() {
    return (
        <>
            <th className="px-3 py-1 text-right">on</th>
            <th className="px-3 py-1 text-right">res</th>
            <th className="px-3 py-1 text-right">avail</th>
        </>
    );
}
