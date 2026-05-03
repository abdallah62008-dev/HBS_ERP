import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, useForm } from '@inertiajs/react';

export default function ReturnShow({ return: ret, reasons }) {
    const can = useCan();

    const inspect = useForm({
        product_condition: ret.product_condition === 'Unknown' ? 'Good' : ret.product_condition,
        restockable: ret.restockable ?? true,
        refund_amount: ret.refund_amount,
        notes: ret.notes ?? '',
    });

    const submitInspect = (e) => {
        e.preventDefault();
        inspect.post(route('returns.inspect', ret.id));
    };

    const closeReturn = () => {
        if (!confirm('Close this return?')) return;
        router.post(route('returns.close', ret.id));
    };

    return (
        <AuthenticatedLayout header={`Return #${ret.id}`}>
            <Head title={`Return #${ret.id}`} />
            <PageHeader
                title={`Return #${ret.id}`}
                subtitle={`Order ${ret.order?.order_number} · ${ret.order?.customer_name}`}
                actions={<Link href={route('returns.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Returns</Link>}
            />

            <div className="mb-4 flex items-center gap-2">
                <StatusBadge value={ret.return_status} />
                <StatusBadge value={ret.product_condition} />
                <span className="text-xs text-slate-500">Reason: {ret.return_reason?.name}</span>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="lg:col-span-2 rounded-lg border border-slate-200 bg-white p-5 space-y-3">
                    <h2 className="text-sm font-semibold text-slate-700">Order items</h2>
                    <ul className="space-y-1 text-sm">
                        {(ret.order?.items ?? []).map((it) => (
                            <li key={it.id} className="flex justify-between border-b border-slate-100 py-1">
                                <span><span className="font-mono text-xs text-slate-500">{it.sku}</span> {it.product_name}</span>
                                <span className="tabular-nums text-slate-600">×{it.quantity}</span>
                            </li>
                        ))}
                    </ul>

                    {ret.notes && (
                        <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 whitespace-pre-line">
                            {ret.notes}
                        </div>
                    )}
                </div>

                <div className="space-y-4">
                    {can('returns.inspect') && ['Pending', 'Received'].includes(ret.return_status) && (
                        <form onSubmit={submitInspect} className="rounded-lg border border-slate-200 bg-white p-5 space-y-3">
                            <h2 className="text-sm font-semibold text-slate-700">Inspect</h2>
                            <select value={inspect.data.product_condition} onChange={(e) => inspect.setData('product_condition', e.target.value)} className="block w-full rounded-md border-slate-300 text-sm">
                                <option>Good</option>
                                <option>Damaged</option>
                                <option>Missing Parts</option>
                                <option>Unknown</option>
                            </select>
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={inspect.data.restockable} onChange={(e) => inspect.setData('restockable', e.target.checked)} className="rounded border-slate-300" />
                                Restock to inventory
                            </label>
                            <input type="number" step="0.01" min={0} value={inspect.data.refund_amount} onChange={(e) => inspect.setData('refund_amount', e.target.value)} placeholder="Refund amount" className="block w-full rounded-md border-slate-300 text-sm" />
                            <textarea value={inspect.data.notes} onChange={(e) => inspect.setData('notes', e.target.value)} placeholder="Inspection notes" rows={2} className="block w-full rounded-md border-slate-300 text-sm" />
                            <button type="submit" disabled={inspect.processing} className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                                {inspect.processing ? 'Saving…' : 'Save inspection'}
                            </button>
                            <p className="text-[11px] text-slate-500">If condition is anything other than Good + restockable, an inventory write-off (Return Damaged) is recorded automatically.</p>
                        </form>
                    )}

                    {ret.return_status !== 'Closed' && can('returns.approve') && (
                        <button onClick={closeReturn} className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">
                            Close return
                        </button>
                    )}

                    <div className="rounded-lg border border-slate-200 bg-white p-5 text-sm">
                        <h2 className="text-sm font-semibold text-slate-700">Refund</h2>
                        <div className="mt-1 text-2xl font-semibold tabular-nums text-slate-800">{Number(ret.refund_amount).toFixed(2)}</div>
                        <div className="mt-1 text-xs text-slate-500">Shipping loss: {Number(ret.shipping_loss_amount).toFixed(2)}</div>
                        {ret.inspected_by && (
                            <div className="mt-2 text-xs text-slate-500">Inspected by {ret.inspected_by.name} on {ret.inspected_at?.split('T')[0]}</div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
