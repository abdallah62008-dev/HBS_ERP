import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportFilters from '@/Components/ReportFilters';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, usePage } from '@inertiajs/react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function CollectionsReport({ from, to, totals, by_status }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    return (
        <AuthenticatedLayout header="Collections report">
            <Head title="Collections report" />
            <PageHeader title="Collections" subtitle={`${from} to ${to}`}
                actions={<Link href={route('reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Reports</Link>}
            />
            <ReportFilters routeName="reports.collections" from={from} to={to} />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                <Stat label="Total collections" value={totals.total_count} />
                <Stat label="Outstanding" value={totals.outstanding_count} tone="amber" />
                <Stat label="Total due" value={`${sym}${fmt(totals.total_due)}`} />
                <Stat label="Collected" value={`${sym}${fmt(totals.total_collected)}`} tone="emerald" />
            </div>

            <div className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5 text-right">Count</th>
                            <th className="px-4 py-2.5 text-right">Due</th>
                            <th className="px-4 py-2.5 text-right">Collected</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {by_status.length === 0 && (
                            <tr><td colSpan={4} className="px-4 py-10 text-center text-sm text-slate-400">No collections in range.</td></tr>
                        )}
                        {by_status.map((s) => (
                            <tr key={s.collection_status}>
                                <td className="px-4 py-2"><StatusBadge value={s.collection_status} /></td>
                                <td className="px-4 py-2 text-right tabular-nums">{s.count}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{sym}{fmt(s.due)}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{sym}{fmt(s.collected)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

function Stat({ label, value, tone }) {
    const palette = { amber: 'border-amber-200 bg-amber-50', emerald: 'border-emerald-200 bg-emerald-50' }[tone] ?? 'border-slate-200 bg-white';
    return (
        <div className={`rounded-lg border p-5 ${palette}`}>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 text-2xl font-semibold tabular-nums text-slate-800">{value}</div>
        </div>
    );
}
