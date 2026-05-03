import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportFilters from '@/Components/ReportFilters';
import { Head, Link } from '@inertiajs/react';

export default function StaffReport({ from, to, rows }) {
    return (
        <AuthenticatedLayout header="Staff report">
            <Head title="Staff report" />
            <PageHeader title="Staff performance" subtitle={`${from} to ${to}`}
                actions={<Link href={route('reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Reports</Link>}
            />
            <ReportFilters routeName="reports.staff" from={from} to={to} />

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Name</th>
                            <th className="px-4 py-2.5">Role</th>
                            <th className="px-4 py-2.5 text-right">Confirmed</th>
                            <th className="px-4 py-2.5 text-right">Shipped</th>
                            <th className="px-4 py-2.5 text-right">Delivered</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.length === 0 && (
                            <tr><td colSpan={5} className="px-4 py-12 text-center text-sm text-slate-400">No activity in range.</td></tr>
                        )}
                        {rows.map((r) => (
                            <tr key={r.id}>
                                <td className="px-4 py-2 text-slate-800">{r.name}</td>
                                <td className="px-4 py-2 text-slate-600">{r.role ?? '—'}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{r.confirmed_orders}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{r.shipped_orders}</td>
                                <td className="px-4 py-2 text-right tabular-nums text-emerald-700">{r.delivered_orders}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
