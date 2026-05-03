import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportFilters from '@/Components/ReportFilters';
import { Head, Link, usePage } from '@inertiajs/react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function SalesReport({ from, to, by_day, totals }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    return (
        <AuthenticatedLayout header="Sales report">
            <Head title="Sales report" />
            <PageHeader title="Sales" subtitle={`${from} to ${to}`}
                actions={<Link href={route('reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Reports</Link>}
            />

            <ReportFilters routeName="reports.sales" from={from} to={to} />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                <Stat label="Orders" value={totals.orders} />
                <Stat label="Revenue" value={`${sym}${fmt(totals.revenue)}`} tone="indigo" />
                <Stat label="Delivered" value={totals.delivered} tone="emerald" />
                <Stat label="Returned" value={totals.returned} tone="red" />
            </div>

            <div className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Day</th>
                            <th className="px-4 py-2.5 text-right">Orders</th>
                            <th className="px-4 py-2.5 text-right">Revenue</th>
                            <th className="px-4 py-2.5 text-right">Delivered</th>
                            <th className="px-4 py-2.5 text-right">Delivered revenue</th>
                            <th className="px-4 py-2.5 text-right">Returned</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {by_day.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-12 text-center text-sm text-slate-400">No data in range.</td></tr>
                        )}
                        {by_day.map((d) => (
                            <tr key={d.day}>
                                <td className="px-4 py-2 text-slate-700">{d.day}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{d.orders}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{sym}{fmt(d.revenue)}</td>
                                <td className="px-4 py-2 text-right tabular-nums text-emerald-700">{d.delivered}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{sym}{fmt(d.delivered_revenue)}</td>
                                <td className="px-4 py-2 text-right tabular-nums text-red-700">{d.returned}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

function Stat({ label, value, tone }) {
    const palette = { indigo: 'border-indigo-200 bg-indigo-50', emerald: 'border-emerald-200 bg-emerald-50', red: 'border-red-200 bg-red-50' }[tone] ?? 'border-slate-200 bg-white';
    return (
        <div className={`rounded-lg border p-5 ${palette}`}>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 text-2xl font-semibold tabular-nums text-slate-800">{value}</div>
        </div>
    );
}
