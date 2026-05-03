import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, usePage } from '@inertiajs/react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function MarketerDashboard({ marketer, kpis, recent_orders }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    return (
        <AuthenticatedLayout header="My dashboard">
            <Head title="My dashboard" />
            <PageHeader
                title={`Welcome, ${marketer.user?.name?.split(' ')[0] ?? marketer.code}`}
                subtitle={`Code ${marketer.code} · group ${marketer.price_group?.name}`}
                actions={
                    <Link href={route('marketer.statement')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">Statement</Link>
                }
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-4">
                <Stat label="Open orders" value={kpis.open_orders} tone="indigo" />
                <Stat label="Delivered" value={kpis.delivered_orders} tone="emerald" />
                <Stat label="Returned" value={kpis.returned_orders} tone="red" />
                <Stat label="Total orders" value={kpis.total_orders} />
            </div>

            <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="text-sm font-semibold text-slate-700">Wallet</h2>
                    <div className="mt-3 grid grid-cols-2 gap-3 text-sm">
                        <Money label="Expected" value={`${sym}${fmt(marketer.wallet?.total_expected)}`} />
                        <Money label="Pending" value={`${sym}${fmt(marketer.wallet?.total_pending)}`} />
                        <Money label="Earned" value={`${sym}${fmt(marketer.wallet?.total_earned)}`} />
                        <Money label="Balance" value={`${sym}${fmt(marketer.wallet?.balance)}`} bold />
                    </div>
                    <Link href={route('marketer.wallet')} className="mt-4 inline-block text-sm font-medium text-indigo-600 hover:underline">Open wallet →</Link>
                </div>

                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="text-sm font-semibold text-slate-700">Profit formula</h2>
                    <p className="mt-2 text-xs text-slate-600">
                        net_profit = selling_price − trade_product_price − shipping − tax − extra_fees
                    </p>
                    <ul className="mt-3 space-y-1 text-xs text-slate-500">
                        <li>· Earned only after order Delivered</li>
                        {marketer.shipping_deducted ? <li>· Shipping deducted</li> : <li className="text-slate-400">· Shipping NOT deducted</li>}
                        {marketer.tax_deducted ? <li>· Tax deducted</li> : <li className="text-slate-400">· Tax NOT deducted</li>}
                        <li>· Settled {marketer.settlement_cycle?.toLowerCase()}</li>
                    </ul>
                </div>
            </div>

            <div className="mt-6 rounded-lg border border-slate-200 bg-white">
                <div className="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                    <h2 className="text-sm font-semibold text-slate-700">Recent orders</h2>
                    <Link href={route('marketer.orders')} className="text-xs text-indigo-600 hover:underline">view all →</Link>
                </div>
                {recent_orders.length === 0 ? (
                    <div className="px-5 py-10 text-center text-sm text-slate-400">No orders yet.</div>
                ) : (
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-5 py-2">Order</th>
                                <th className="px-5 py-2">Customer</th>
                                <th className="px-5 py-2 text-right">Total</th>
                                <th className="px-5 py-2">Status</th>
                                <th className="px-5 py-2">Created</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {recent_orders.map((o) => (
                                <tr key={o.id} className="hover:bg-slate-50">
                                    <td className="px-5 py-2 font-mono text-xs">
                                        <Link href={route('marketer.orders.show', o.id)} className="text-slate-700 hover:text-indigo-600">{o.order_number}</Link>
                                    </td>
                                    <td className="px-5 py-2">{o.customer_name}</td>
                                    <td className="px-5 py-2 text-right tabular-nums">{sym}{fmt(o.total_amount)}</td>
                                    <td className="px-5 py-2"><StatusBadge value={o.status} /></td>
                                    <td className="px-5 py-2 text-slate-500">{o.created_at?.split('T')[0]}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function Stat({ label, value, tone }) {
    const palette = { indigo: 'border-indigo-200 bg-indigo-50', emerald: 'border-emerald-200 bg-emerald-50', red: 'border-red-200 bg-red-50' }[tone] ?? 'border-slate-200 bg-white';
    return (
        <div className={`rounded-lg border p-5 ${palette}`}>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 text-3xl font-semibold tabular-nums text-slate-800">{value}</div>
        </div>
    );
}

function Money({ label, value, bold }) {
    return (
        <div>
            <div className="text-[11px] font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className={'tabular-nums ' + (bold ? 'text-xl font-semibold text-slate-800' : 'text-base text-slate-700')}>{value}</div>
        </div>
    );
}
