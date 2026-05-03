import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, usePage } from '@inertiajs/react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function MarketerOrderShow({ order, profit_tx }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    return (
        <AuthenticatedLayout header={`Order ${order.order_number}`}>
            <Head title={order.order_number} />
            <PageHeader
                title={<span className="font-mono">{order.order_number}</span>}
                subtitle={`${order.customer_name} · ${order.customer_phone}`}
                actions={<Link href={route('marketer.orders')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← My orders</Link>}
            />

            <div className="mb-4 flex items-center gap-2">
                <StatusBadge value={order.status} />
                <span className="text-xs text-slate-500">Shipping: <StatusBadge value={order.shipping_status} /></span>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="lg:col-span-2 rounded-lg border border-slate-200 bg-white">
                    <div className="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">Items ({order.items.length})</div>
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-5 py-2">SKU</th>
                                <th className="px-5 py-2">Product</th>
                                <th className="px-5 py-2 text-right">Qty</th>
                                <th className="px-5 py-2 text-right">Unit</th>
                                <th className="px-5 py-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {order.items.map((it) => (
                                <tr key={it.id}>
                                    <td className="px-5 py-2 font-mono text-xs">{it.sku}</td>
                                    <td className="px-5 py-2">{it.product_name}</td>
                                    <td className="px-5 py-2 text-right tabular-nums">{it.quantity}</td>
                                    <td className="px-5 py-2 text-right tabular-nums">{fmt(it.unit_price)}</td>
                                    <td className="px-5 py-2 text-right tabular-nums">{fmt(it.total_price)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="text-sm font-semibold text-slate-700">My profit</h2>
                    {profit_tx ? (
                        <>
                            <div className="mt-2 flex items-center gap-2">
                                <StatusBadge value={profit_tx.status} />
                                <span className="text-xs text-slate-500">{profit_tx.transaction_type}</span>
                            </div>
                            <div className={'mt-3 text-3xl font-semibold tabular-nums ' + (Number(profit_tx.net_profit) < 0 ? 'text-red-600' : 'text-emerald-600')}>
                                {sym}{fmt(profit_tx.net_profit)}
                            </div>
                            <dl className="mt-4 space-y-1 text-xs">
                                <Line label="Selling" value={fmt(profit_tx.selling_price)} sym={sym} />
                                <Line label="Trade cost" value={`–${fmt(profit_tx.trade_product_price)}`} sym={sym} />
                                <Line label="Shipping" value={`–${fmt(profit_tx.shipping_amount)}`} sym={sym} />
                                <Line label="Tax" value={`–${fmt(profit_tx.tax_amount)}`} sym={sym} />
                                <Line label="Extra fees" value={`–${fmt(profit_tx.extra_fees)}`} sym={sym} />
                            </dl>
                        </>
                    ) : (
                        <p className="text-sm text-slate-400">No profit calculation yet.</p>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Line({ label, value, sym }) {
    return (
        <div className="flex justify-between">
            <span className="text-slate-500">{label}</span>
            <span className="tabular-nums text-slate-800">{sym}{value}</span>
        </div>
    );
}
