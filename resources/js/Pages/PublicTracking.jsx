import { Head } from '@inertiajs/react';

/* R3 — Public, customer-facing order tracking page. No AuthenticatedLayout
   on purpose: this page is served via a signed URL to people who don't
   have an account in the system. */

const STATUS_LABEL = {
    'New': 'Order received',
    'Pending Confirmation': 'Awaiting confirmation',
    'Confirmed': 'Confirmed',
    'Ready to Pack': 'Being prepared',
    'Packed': 'Packed',
    'Ready to Ship': 'Ready to ship',
    'Shipped': 'Shipped',
    'Out for Delivery': 'Out for delivery',
    'Delivered': 'Delivered',
    'Returned': 'Returned',
    'Cancelled': 'Cancelled',
    'On Hold': 'On hold',
    'Need Review': 'Under review',
};

function fmtDate(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

function fmtMoney(amount, currency) {
    const value = Number(amount || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    return `${currency ?? ''} ${value}`.trim();
}

export default function PublicTracking({ order, shipment, timeline }) {
    const friendly = STATUS_LABEL[order.status] ?? order.status;

    return (
        <div className="min-h-screen bg-slate-50 px-4 py-10">
            <Head title={`Tracking · ${order.order_number}`} />

            <div className="mx-auto max-w-2xl">
                <h1 className="text-2xl font-semibold text-slate-800">Order tracking</h1>
                <p className="mt-1 text-sm text-slate-500">
                    Hi {order.customer_name} — here is the latest on order{' '}
                    <span className="font-mono font-medium text-slate-700">{order.order_number}</span>.
                </p>

                <div className="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                        Current status
                    </div>
                    <div className="mt-1 text-2xl font-bold text-indigo-700">{friendly}</div>
                    {order.total_amount > 0 && (
                        <div className="mt-2 text-xs text-slate-500">
                            Order total: {fmtMoney(order.total_amount, order.currency_code)}
                        </div>
                    )}
                </div>

                {shipment && (
                    <div className="mt-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                            Shipping
                        </div>
                        <dl className="mt-2 grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1.5 text-sm">
                            {shipment.carrier_name && (
                                <>
                                    <dt className="text-slate-500">Carrier</dt>
                                    <dd className="text-slate-800">{shipment.carrier_name}</dd>
                                </>
                            )}
                            {shipment.tracking_number && (
                                <>
                                    <dt className="text-slate-500">Tracking number</dt>
                                    <dd className="font-mono text-slate-800">{shipment.tracking_number}</dd>
                                </>
                            )}
                            <dt className="text-slate-500">Shipment status</dt>
                            <dd className="text-slate-800">{shipment.shipping_status}</dd>
                        </dl>
                    </div>
                )}

                <div className="mt-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                        Timeline
                    </div>
                    <ol className="mt-3 space-y-2.5">
                        <li className="flex items-baseline justify-between gap-3 text-sm">
                            <span className="text-slate-700">Order placed</span>
                            <span className="text-xs tabular-nums text-slate-500">{fmtDate(order.created_at)}</span>
                        </li>
                        {timeline.map((row, i) => (
                            <li key={i} className="flex items-baseline justify-between gap-3 text-sm">
                                <span className="text-slate-700">
                                    {STATUS_LABEL[row.new_status] ?? row.new_status}
                                </span>
                                <span className="text-xs tabular-nums text-slate-500">{fmtDate(row.at)}</span>
                            </li>
                        ))}
                    </ol>
                </div>

                <p className="mt-6 text-center text-xs text-slate-400">
                    Questions? Contact support and reference your order number.
                </p>
            </div>
        </div>
    );
}
