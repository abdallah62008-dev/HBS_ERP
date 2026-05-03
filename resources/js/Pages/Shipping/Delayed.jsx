import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';

function ShipmentRow({ shipment }) {
    return (
        <tr className="hover:bg-slate-50">
            <td className="px-4 py-2.5 font-mono text-xs">
                <Link href={route('shipping.shipments.show', shipment.id)} className="text-slate-700 hover:text-indigo-600">{shipment.tracking_number ?? `#${shipment.id}`}</Link>
            </td>
            <td className="px-4 py-2.5 font-mono text-xs">
                <Link href={route('orders.show', shipment.order_id)} className="text-slate-600 hover:text-indigo-600">{shipment.order?.order_number}</Link>
            </td>
            <td className="px-4 py-2.5">{shipment.order?.customer_name}</td>
            <td className="px-4 py-2.5 text-slate-600">{shipment.shipping_company?.name}</td>
            <td className="px-4 py-2.5"><StatusBadge value={shipment.shipping_status} /></td>
            <td className="px-4 py-2.5 text-slate-500">{shipment.assigned_at?.replace('T', ' ').slice(0, 16)}</td>
            <td className="px-4 py-2.5 text-amber-700 max-w-xs truncate" title={shipment.delayed_reason}>{shipment.delayed_reason ?? '—'}</td>
        </tr>
    );
}

export default function DelayedShipments({ explicit, stale, threshold_days }) {
    return (
        <AuthenticatedLayout header="Delayed shipments">
            <Head title="Delayed shipments" />
            <PageHeader
                title="Delayed shipments"
                subtitle={`Explicitly Delayed + active shipments older than ${threshold_days} days`}
                actions={<Link href={route('shipping.dashboard')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Shipping</Link>}
            />

            <Section title={`Marked Delayed (${explicit.length})`} rows={explicit} emptyMsg="None marked Delayed." />

            <div className="mt-6">
                <Section title={`Stale (assigned ≥ ${threshold_days}d ago, not delivered) — ${stale.length}`} rows={stale} emptyMsg="No stale active shipments." />
            </div>
        </AuthenticatedLayout>
    );
}

function Section({ title, rows, emptyMsg }) {
    return (
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div className="border-b border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700">{title}</div>
            <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                    <tr>
                        <th className="px-4 py-2.5">Tracking</th>
                        <th className="px-4 py-2.5">Order</th>
                        <th className="px-4 py-2.5">Customer</th>
                        <th className="px-4 py-2.5">Carrier</th>
                        <th className="px-4 py-2.5">Status</th>
                        <th className="px-4 py-2.5">Assigned</th>
                        <th className="px-4 py-2.5">Reason</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 && (
                        <tr><td colSpan={7} className="px-4 py-8 text-center text-sm text-slate-400">{emptyMsg}</td></tr>
                    )}
                    {rows.map((s) => <ShipmentRow key={s.id} shipment={s} />)}
                </tbody>
            </table>
        </div>
    );
}
