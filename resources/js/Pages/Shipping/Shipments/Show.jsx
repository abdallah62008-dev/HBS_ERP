import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const STATUSES = ['Assigned', 'Picked Up', 'In Transit', 'Out for Delivery', 'Delivered', 'Returned', 'Delayed', 'Lost'];

export default function ShipmentShow({ shipment }) {
    const can = useCan();
    const [newStatus, setNewStatus] = useState(shipment.shipping_status);
    const [note, setNote] = useState('');

    const submit = (e) => {
        e.preventDefault();
        router.post(route('shipping.shipments.mark-status', shipment.id), { shipping_status: newStatus, note });
    };

    return (
        <AuthenticatedLayout header={`Shipment ${shipment.tracking_number ?? `#${shipment.id}`}`}>
            <Head title={`Shipment ${shipment.tracking_number}`} />

            <PageHeader
                title={<span className="font-mono">{shipment.tracking_number ?? `Shipment #${shipment.id}`}</span>}
                subtitle={`${shipment.shipping_company?.name} · order ${shipment.order?.order_number}`}
                actions={<Link href={route('shipping.shipments')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Shipments</Link>}
            />

            <div className="mb-4 flex items-center gap-2">
                <StatusBadge value={shipment.shipping_status} />
                {shipment.delayed_reason && <span className="text-xs text-amber-700">Delay: {shipment.delayed_reason}</span>}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="lg:col-span-2 rounded-lg border border-slate-200 bg-white p-5 space-y-3">
                    <h2 className="text-sm font-semibold text-slate-700">Order</h2>
                    <Link href={route('orders.show', shipment.order_id)} className="block">
                        <div className="font-mono text-sm text-slate-700">{shipment.order?.order_number}</div>
                        <div className="text-sm text-slate-700">{shipment.order?.customer_name} · {shipment.order?.customer_phone}</div>
                        <div className="text-xs text-slate-500">{shipment.order?.customer_address}</div>
                    </Link>

                    <h3 className="mt-4 text-sm font-semibold text-slate-700">Items</h3>
                    <ul className="space-y-1 text-sm">
                        {(shipment.order?.items ?? []).map((it) => (
                            <li key={it.id} className="flex justify-between border-b border-slate-100 py-1">
                                <span><span className="font-mono text-xs text-slate-500">{it.sku}</span> {it.product_name}</span>
                                <span className="tabular-nums text-slate-600">×{it.quantity}</span>
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="space-y-4">
                    <div className="rounded-lg border border-slate-200 bg-white p-5 space-y-2">
                        <h2 className="text-sm font-semibold text-slate-700">Timeline</h2>
                        {shipment.assigned_at && <div className="text-xs text-slate-600">Assigned: {shipment.assigned_at.replace('T', ' ').slice(0, 16)}</div>}
                        {shipment.picked_up_at && <div className="text-xs text-slate-600">Picked up: {shipment.picked_up_at.replace('T', ' ').slice(0, 16)}</div>}
                        {shipment.delivered_at && <div className="text-xs text-emerald-700">Delivered: {shipment.delivered_at.replace('T', ' ').slice(0, 16)}</div>}
                        {shipment.returned_at && <div className="text-xs text-red-600">Returned: {shipment.returned_at.replace('T', ' ').slice(0, 16)}</div>}
                    </div>

                    {can('shipping.update_status') && (
                        <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5 space-y-2">
                            <h2 className="text-sm font-semibold text-slate-700">Update status</h2>
                            <select value={newStatus} onChange={(e) => setNewStatus(e.target.value)} className="block w-full rounded-md border-slate-300 text-sm">
                                {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
                            </select>
                            <textarea value={note} onChange={(e) => setNote(e.target.value)} placeholder="Note (optional)" rows={2} className="block w-full rounded-md border-slate-300 text-sm" />
                            <button type="submit" className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">Save</button>
                        </form>
                    )}

                    {shipment.labels?.length > 0 && (
                        <div className="rounded-lg border border-slate-200 bg-white p-5 space-y-2">
                            <h2 className="text-sm font-semibold text-slate-700">Labels printed</h2>
                            {shipment.labels.map((l) => (
                                <div key={l.id} className="text-xs text-slate-500">
                                    {l.printed_at?.replace('T', ' ').slice(0, 16)} · {l.printed_by?.name ?? '—'}
                                </div>
                            ))}
                            <a href={route('shipping-labels.print', shipment.order_id)} target="_blank" rel="noreferrer" className="inline-block rounded-md border border-indigo-200 bg-white px-3 py-1.5 text-xs text-indigo-700 hover:bg-indigo-50">Re-print 4×6</a>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
