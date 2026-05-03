import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function ReadyToShip({ orders, shipping_companies }) {
    const can = useCan();
    const [assigning, setAssigning] = useState({});

    const setAssign = (orderId, key, value) => {
        setAssigning((s) => ({ ...s, [orderId]: { ...(s[orderId] ?? {}), [key]: value } }));
    };

    const assign = (order) => {
        const data = assigning[order.id] ?? {};
        if (!data.shipping_company_id) return alert('Pick a shipping company first.');

        router.post(route('shipping.assign', order.id), {
            shipping_company_id: data.shipping_company_id,
            tracking_number: data.tracking_number || null,
            mark_ready_to_ship: 1,
        });
    };

    const confirmShip = (order) => {
        if (!confirm(`Confirm shipping for ${order.order_number}? This runs the checklist gate.`)) return;
        router.post(route('shipping.confirm-shipped', order.id));
    };

    return (
        <AuthenticatedLayout header="Ready to ship">
            <Head title="Ready to ship" />
            <PageHeader
                title="Ready to ship"
                subtitle={`${orders.total} packed order${orders.total === 1 ? '' : 's'}. Assign a carrier, print the label, then confirm.`}
                actions={<Link href={route('shipping.dashboard')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Shipping</Link>}
            />

            <div className="space-y-3">
                {orders.data.length === 0 && (
                    <div className="rounded-lg border border-slate-200 bg-white p-12 text-center text-sm text-slate-400">
                        No packed orders waiting.
                    </div>
                )}
                {orders.data.map((o) => (
                    <div key={o.id} className="rounded-lg border border-slate-200 bg-white p-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div className="flex items-center gap-2">
                                    <Link href={route('orders.show', o.id)} className="font-mono text-sm font-medium text-slate-800 hover:text-indigo-600">{o.order_number}</Link>
                                    <StatusBadge value={o.status} />
                                    <StatusBadge value={o.customer_risk_level} />
                                </div>
                                <div className="mt-1 text-sm text-slate-700">{o.customer_name} · {o.customer_phone}</div>
                                <div className="text-xs text-slate-500">{o.customer_address}</div>
                            </div>

                            <div className="flex items-center gap-2">
                                <Link href={route('shipping.checklist', o.id)} className="rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-xs hover:bg-slate-50">
                                    Run checklist
                                </Link>
                                {o.active_shipment && (
                                    <a href={route('shipping-labels.print', o.id)} target="_blank" rel="noreferrer" className="rounded-md border border-indigo-200 bg-white px-2.5 py-1.5 text-xs text-indigo-700 hover:bg-indigo-50">
                                        Print 4×6 label
                                    </a>
                                )}
                                {o.active_shipment && can('orders.change_status') && (
                                    <button onClick={() => confirmShip(o)} className="rounded-md bg-emerald-600 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-emerald-500">
                                        Confirm Shipped
                                    </button>
                                )}
                            </div>
                        </div>

                        {/* Assign carrier inline */}
                        {!o.active_shipment && can('shipping.assign') && (
                            <div className="mt-3 grid grid-cols-12 gap-2 rounded-md border border-slate-200 bg-slate-50 p-3">
                                <select
                                    value={assigning[o.id]?.shipping_company_id ?? ''}
                                    onChange={(e) => setAssign(o.id, 'shipping_company_id', e.target.value)}
                                    className="col-span-5 rounded-md border-slate-300 text-sm"
                                >
                                    <option value="">— Pick a shipping company —</option>
                                    {shipping_companies.map((c) => (
                                        <option key={c.id} value={c.id}>{c.name}</option>
                                    ))}
                                </select>
                                <input
                                    value={assigning[o.id]?.tracking_number ?? ''}
                                    onChange={(e) => setAssign(o.id, 'tracking_number', e.target.value)}
                                    placeholder="Tracking number (optional, auto-generated if empty)"
                                    className="col-span-5 rounded-md border-slate-300 text-sm"
                                />
                                <button onClick={() => assign(o)} className="col-span-2 rounded-md bg-slate-900 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-slate-700">
                                    Assign carrier
                                </button>
                            </div>
                        )}

                        {o.active_shipment && (
                            <div className="mt-3 flex items-center gap-3 rounded-md border border-emerald-200 bg-emerald-50 p-2 text-xs text-emerald-700">
                                <span className="font-medium">{o.active_shipment.shipping_company?.name}</span>
                                <span className="font-mono">{o.active_shipment.tracking_number}</span>
                            </div>
                        )}
                    </div>
                ))}
            </div>
            <Pagination links={orders.links} />
        </AuthenticatedLayout>
    );
}
