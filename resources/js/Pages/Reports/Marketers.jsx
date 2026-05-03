import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportFilters from '@/Components/ReportFilters';
import { Head, Link, usePage } from '@inertiajs/react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function MarketersReport({ from, to, rows }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    return (
        <AuthenticatedLayout header="Marketers report">
            <Head title="Marketers report" />
            <PageHeader title="Marketers" subtitle={`${from} to ${to}`}
                actions={<Link href={route('reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Reports</Link>}
            />
            <ReportFilters routeName="reports.marketers" from={from} to={to} />

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Code</th>
                            <th className="px-4 py-2.5">Marketer</th>
                            <th className="px-4 py-2.5 text-right">Orders</th>
                            <th className="px-4 py-2.5 text-right">Delivered</th>
                            <th className="px-4 py-2.5 text-right">Returned</th>
                            <th className="px-4 py-2.5 text-right">Return rate</th>
                            <th className="px-4 py-2.5 text-right">Revenue</th>
                            <th className="px-4 py-2.5 text-right">Earned profit</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.length === 0 && (
                            <tr><td colSpan={8} className="px-4 py-12 text-center text-sm text-slate-400">No marketers with orders in range.</td></tr>
                        )}
                        {rows.map((r) => (
                            <tr key={r.id}>
                                <td className="px-4 py-2 font-mono text-xs">{r.code}</td>
                                <td className="px-4 py-2 text-slate-800">{r.marketer_name}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{r.orders}</td>
                                <td className="px-4 py-2 text-right tabular-nums text-emerald-700">{r.delivered}</td>
                                <td className="px-4 py-2 text-right tabular-nums text-red-700">{r.returned}</td>
                                <td className={'px-4 py-2 text-right tabular-nums ' + (r.return_rate >= 30 ? 'text-red-700' : 'text-slate-700')}>{r.return_rate}%</td>
                                <td className="px-4 py-2 text-right tabular-nums">{sym}{fmt(r.revenue)}</td>
                                <td className="px-4 py-2 text-right tabular-nums font-medium">{sym}{fmt(r.earned)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
