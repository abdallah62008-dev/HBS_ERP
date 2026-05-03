import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const TYPE_TONES = {
    'Purchase': 'bg-emerald-100 text-emerald-700',
    'Return To Stock': 'bg-emerald-100 text-emerald-700',
    'Opening Balance': 'bg-emerald-100 text-emerald-700',
    'Transfer In': 'bg-emerald-100 text-emerald-700',
    'Ship': 'bg-blue-100 text-blue-700',
    'Return Damaged': 'bg-red-100 text-red-700',
    'Transfer Out': 'bg-blue-100 text-blue-700',
    'Reserve': 'bg-amber-100 text-amber-800',
    'Release Reservation': 'bg-slate-100 text-slate-700',
    'Adjustment': 'bg-purple-100 text-purple-700',
    'Stock Count Correction': 'bg-purple-100 text-purple-700',
};

export default function Movements({ movements, filters, warehouses, movement_types }) {
    const [q, setQ] = useState(filters?.q ?? '');
    const [warehouseId, setWarehouseId] = useState(filters?.warehouse_id ?? '');
    const [movementType, setMovementType] = useState(filters?.movement_type ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('inventory.movements'), {
            q: q || undefined, warehouse_id: warehouseId || undefined, movement_type: movementType || undefined,
        }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Inventory movements">
            <Head title="Inventory movements" />

            <PageHeader
                title="Inventory movements"
                subtitle={`${movements.total} record${movements.total === 1 ? '' : 's'} · the audit trail of every stock change`}
                actions={
                    <Link href={route('inventory.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Inventory</Link>
                }
            />

            <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div className="flex-1 min-w-[180px]">
                    <label className="text-[11px] font-medium uppercase text-slate-500">Product</label>
                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name or SKU…" className="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                </div>
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Warehouse</label>
                    <select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} className="mt-1 rounded-md border-slate-300 text-sm">
                        <option value="">All</option>
                        {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                    </select>
                </div>
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Type</label>
                    <select value={movementType} onChange={(e) => setMovementType(e.target.value)} className="mt-1 rounded-md border-slate-300 text-sm">
                        <option value="">All</option>
                        {movement_types.map((t) => <option key={t} value={t}>{t}</option>)}
                    </select>
                </div>
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">When</th>
                            <th className="px-4 py-2.5">Type</th>
                            <th className="px-4 py-2.5">Product</th>
                            <th className="px-4 py-2.5">Warehouse</th>
                            <th className="px-4 py-2.5 text-right">Qty</th>
                            <th className="px-4 py-2.5">Reference</th>
                            <th className="px-4 py-2.5">By</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {movements.data.length === 0 && (
                            <tr><td colSpan={7} className="px-4 py-12 text-center text-sm text-slate-400">No movements match.</td></tr>
                        )}
                        {movements.data.map((m) => (
                            <tr key={m.id}>
                                <td className="px-4 py-2 text-slate-500 whitespace-nowrap">{m.created_at?.replace('T', ' ').slice(0, 19)}</td>
                                <td className="px-4 py-2">
                                    <span className={'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ' + (TYPE_TONES[m.movement_type] ?? 'bg-slate-100 text-slate-700')}>
                                        {m.movement_type}
                                    </span>
                                </td>
                                <td className="px-4 py-2">
                                    <div className="font-medium text-slate-800">{m.product?.name ?? '—'}</div>
                                    <div className="text-xs font-mono text-slate-500">{m.product?.sku}</div>
                                </td>
                                <td className="px-4 py-2 text-slate-600">{m.warehouse?.name}</td>
                                <td className={'px-4 py-2 text-right tabular-nums ' + (m.quantity > 0 ? 'text-emerald-700' : 'text-red-700')}>
                                    {m.quantity > 0 ? '+' : ''}{m.quantity}
                                </td>
                                <td className="px-4 py-2 text-slate-500">
                                    {m.reference_type ? `${shortRef(m.reference_type)}#${m.reference_id}` : '—'}
                                </td>
                                <td className="px-4 py-2 text-slate-500">{m.created_by?.name ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={movements.links} />
        </AuthenticatedLayout>
    );
}

function shortRef(type) {
    return type?.split('\\').pop() ?? type;
}
