import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function StockAdjustmentsIndex({ adjustments, filters }) {
    const can = useCan();
    const [rejectingId, setRejectingId] = useState(null);
    const [rejectReason, setRejectReason] = useState('');

    const approve = (a) => {
        if (!confirm(`Approve adjustment of ${a.difference > 0 ? '+' : ''}${a.difference} units? This applies an inventory_movement.`)) return;
        router.post(route('stock-adjustments.approve', a.id));
    };

    const submitReject = (id) => {
        if (rejectReason.trim().length < 5) return alert('Reason must be at least 5 characters.');
        router.post(route('stock-adjustments.reject', id), { rejection_reason: rejectReason }, {
            onSuccess: () => { setRejectingId(null); setRejectReason(''); },
        });
    };

    const apply = (status) => {
        router.get(route('stock-adjustments.index'), { status: status || undefined }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Stock adjustments">
            <Head title="Stock adjustments" />

            <PageHeader
                title="Stock adjustments"
                subtitle="Manual stock changes — must be approved by a different team member"
                actions={
                    can('inventory.adjust') && (
                        <Link href={route('stock-adjustments.create')} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">+ New adjustment</Link>
                    )
                }
            />

            <div className="mb-4 flex gap-1.5">
                {['', 'Pending', 'Approved', 'Rejected'].map((s) => (
                    <button key={s} onClick={() => apply(s)} className={'rounded-full border px-3 py-1 text-xs ' + ((filters?.status ?? '') === s ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600')}>
                        {s || 'All'}
                    </button>
                ))}
            </div>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Product</th>
                            <th className="px-4 py-2.5">Warehouse</th>
                            <th className="px-4 py-2.5 text-right">Old → New</th>
                            <th className="px-4 py-2.5 text-right">Δ</th>
                            <th className="px-4 py-2.5">Reason</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5">Requested by</th>
                            <th className="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {adjustments.data.length === 0 && (
                            <tr><td colSpan={8} className="px-4 py-12 text-center text-sm text-slate-400">No adjustments yet.</td></tr>
                        )}
                        {adjustments.data.map((a) => (
                            <tr key={a.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5">
                                    <div className="font-medium text-slate-800">{a.product?.name}</div>
                                    <div className="text-xs font-mono text-slate-500">{a.product?.sku}</div>
                                </td>
                                <td className="px-4 py-2.5 text-slate-600">{a.warehouse?.name}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{a.old_quantity} → <strong>{a.new_quantity}</strong></td>
                                <td className={'px-4 py-2.5 text-right tabular-nums ' + (a.difference > 0 ? 'text-emerald-700' : 'text-red-700')}>
                                    {a.difference > 0 ? '+' : ''}{a.difference}
                                </td>
                                <td className="px-4 py-2.5 text-slate-600 max-w-xs truncate" title={a.reason}>{a.reason}</td>
                                <td className="px-4 py-2.5"><StatusBadge value={a.status} /></td>
                                <td className="px-4 py-2.5 text-slate-500">{a.created_by?.name ?? '—'}</td>
                                <td className="px-4 py-2.5 text-right">
                                    {a.status === 'Pending' && can('inventory.adjust') && (
                                        rejectingId === a.id ? (
                                            <div className="flex flex-col gap-1">
                                                <input value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} placeholder="Reason" className="rounded-md border-slate-300 text-xs" />
                                                <div className="flex gap-1">
                                                    <button onClick={() => submitReject(a.id)} className="text-xs text-red-600 hover:underline">Confirm</button>
                                                    <button onClick={() => setRejectingId(null)} className="text-xs text-slate-500 hover:underline">Cancel</button>
                                                </div>
                                            </div>
                                        ) : (
                                            <div className="flex justify-end gap-2">
                                                <button onClick={() => approve(a)} className="text-xs text-emerald-700 hover:underline">Approve</button>
                                                <button onClick={() => setRejectingId(a.id)} className="text-xs text-red-600 hover:underline">Reject</button>
                                            </div>
                                        )
                                    )}
                                    {a.status === 'Rejected' && a.rejection_reason && (
                                        <div className="text-xs text-slate-400 max-w-xs truncate" title={a.rejection_reason}>{a.rejection_reason}</div>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={adjustments.links} />
        </AuthenticatedLayout>
    );
}
