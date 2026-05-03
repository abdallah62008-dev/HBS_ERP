import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportFilters from '@/Components/ReportFilters';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, usePage } from '@inertiajs/react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function AdsReport({ from, to, rows, totals }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    return (
        <AuthenticatedLayout header="Ads report">
            <Head title="Ads report" />
            <PageHeader title="Ads" subtitle={`${from} to ${to}`}
                actions={<Link href={route('reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Reports</Link>}
            />
            <ReportFilters routeName="reports.ads" from={from} to={to} />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <Stat label="Total spend" value={`${sym}${fmt(totals.spend)}`} />
                <Stat label="Total revenue" value={`${sym}${fmt(totals.revenue)}`} tone="emerald" />
                <Stat label="Net" value={`${sym}${fmt(totals.net)}`} tone={Number(totals.net) < 0 ? 'red' : 'emerald'} />
            </div>

            <div className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Campaign</th>
                            <th className="px-4 py-2.5">Platform</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5 text-right">Spend</th>
                            <th className="px-4 py-2.5 text-right">Revenue</th>
                            <th className="px-4 py-2.5 text-right">Orders</th>
                            <th className="px-4 py-2.5 text-right">Delivered</th>
                            <th className="px-4 py-2.5 text-right">Net</th>
                            <th className="px-4 py-2.5 text-right">ROAS</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.length === 0 && (
                            <tr><td colSpan={9} className="px-4 py-12 text-center text-sm text-slate-400">No campaigns in range.</td></tr>
                        )}
                        {rows.map((r) => (
                            <tr key={r.id}>
                                <td className="px-4 py-2"><Link href={route('ads.show', r.id)} className="text-slate-800 hover:text-indigo-600">{r.name}</Link></td>
                                <td className="px-4 py-2 text-slate-600">{r.platform}</td>
                                <td className="px-4 py-2"><StatusBadge value={r.status} /></td>
                                <td className="px-4 py-2 text-right tabular-nums">{sym}{fmt(r.spend)}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{sym}{fmt(r.revenue)}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{r.orders_count}</td>
                                <td className="px-4 py-2 text-right tabular-nums text-emerald-700">{r.delivered_orders_count}</td>
                                <td className={'px-4 py-2 text-right tabular-nums ' + (Number(r.net_profit) < 0 ? 'text-red-700' : 'text-emerald-700')}>{sym}{fmt(r.net_profit)}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{Number(r.roas).toFixed(2)}×</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

function Stat({ label, value, tone }) {
    const palette = { emerald: 'border-emerald-200 bg-emerald-50', red: 'border-red-200 bg-red-50' }[tone] ?? 'border-slate-200 bg-white';
    return (
        <div className={`rounded-lg border p-5 ${palette}`}>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 text-2xl font-semibold tabular-nums text-slate-800">{value}</div>
        </div>
    );
}
