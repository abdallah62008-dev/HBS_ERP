import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportFilters from '@/Components/ReportFilters';
import { Head, Link } from '@inertiajs/react';

export default function ShippingReport({ from, to, rows }) {
    return (
        <AuthenticatedLayout header="Shipping performance">
            <Head title="Shipping performance" />
            <PageHeader title="Shipping performance" subtitle={`${from} to ${to}`}
                actions={<Link href={route('reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Reports</Link>}
            />
            <ReportFilters routeName="reports.shipping" from={from} to={to} />

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Carrier</th>
                            <th className="px-4 py-2.5 text-right">Shipments</th>
                            <th className="px-4 py-2.5 text-right">Delivered</th>
                            <th className="px-4 py-2.5 text-right">Returned</th>
                            <th className="px-4 py-2.5 text-right">Delayed</th>
                            <th className="px-4 py-2.5 text-right">Delivery rate</th>
                            <th className="px-4 py-2.5 text-right">Return rate</th>
                            <th className="px-4 py-2.5 text-right">Avg days</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.length === 0 && (
                            <tr><td colSpan={8} className="px-4 py-12 text-center text-sm text-slate-400">No shipments in range.</td></tr>
                        )}
                        {rows.map((r) => (
                            <tr key={r.id}>
                                <td className="px-4 py-2 text-slate-800">{r.name}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{r.shipments}</td>
                                <td className="px-4 py-2 text-right tabular-nums text-emerald-700">{r.delivered}</td>
                                <td className="px-4 py-2 text-right tabular-nums text-red-700">{r.returned}</td>
                                <td className="px-4 py-2 text-right tabular-nums text-amber-700">{r.delayed}</td>
                                <td className={'px-4 py-2 text-right tabular-nums font-medium ' + (r.delivery_rate >= 80 ? 'text-emerald-700' : r.delivery_rate < 50 ? 'text-red-700' : 'text-slate-700')}>{r.delivery_rate}%</td>
                                <td className={'px-4 py-2 text-right tabular-nums ' + (r.return_rate >= 20 ? 'text-red-700' : 'text-slate-700')}>{r.return_rate}%</td>
                                <td className="px-4 py-2 text-right tabular-nums text-slate-600">{r.avg_days ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
