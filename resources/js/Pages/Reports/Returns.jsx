import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportFilters from '@/Components/ReportFilters';
import { Head, Link, usePage } from '@inertiajs/react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function ReturnsReport({ from, to, totals, by_reason }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    return (
        <AuthenticatedLayout header="Returns report">
            <Head title="Returns report" />
            <PageHeader title="Returns" subtitle={`${from} to ${to}`}
                actions={<Link href={route('reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Reports</Link>}
            />
            <ReportFilters routeName="reports.returns" from={from} to={to} />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                <Stat label="Total returns" value={totals.total} />
                <Stat label="Restocked" value={totals.restocked} tone="emerald" />
                <Stat label="Damaged" value={totals.damaged} tone="red" />
                <Stat label="Refunds + losses" value={`${sym}${fmt(Number(totals.refund_total) + Number(totals.shipping_loss_total))}`} />
            </div>

            <div className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <div className="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">By reason</div>
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr><th className="px-4 py-2.5">Reason</th><th className="px-4 py-2.5 text-right">Count</th></tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {by_reason.length === 0 && (
                            <tr><td colSpan={2} className="px-4 py-10 text-center text-sm text-slate-400">No returns in range.</td></tr>
                        )}
                        {by_reason.map((r) => (
                            <tr key={r.reason}>
                                <td className="px-4 py-2 text-slate-700">{r.reason}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{r.count}</td>
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
