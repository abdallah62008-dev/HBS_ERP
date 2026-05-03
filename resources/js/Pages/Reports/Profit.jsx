import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportFilters from '@/Components/ReportFilters';
import { Head, Link, usePage } from '@inertiajs/react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function ProfitReport({ from, to, delivered, expenses, final_net_profit }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    return (
        <AuthenticatedLayout header="Profit report">
            <Head title="Profit report" />
            <PageHeader title="Profit" subtitle={`Delivered orders ${from} to ${to}`}
                actions={<Link href={route('reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Reports</Link>}
            />

            <ReportFilters routeName="reports.profit" from={from} to={to} />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Stat label="Delivered orders" value={delivered.orders} />
                <Stat label="Revenue" value={`${sym}${fmt(delivered.revenue)}`} tone="indigo" />
                <Stat label="COGS" value={`${sym}${fmt(delivered.cogs)}`} />
                <Stat label="Gross profit" value={`${sym}${fmt(delivered.gross_profit)}`} tone="emerald" />
            </div>

            <div className="mt-6 rounded-lg border border-slate-200 bg-white p-5 space-y-2 text-sm">
                <Row label="Order net profit" value={`${sym}${fmt(delivered.net_profit)}`} />
                <Row label="− Expenses (period)" value={`–${sym}${fmt(expenses)}`} />
                <div className="border-t border-slate-200 pt-2">
                    <Row
                        label={<span className="font-semibold">Final net profit</span>}
                        value={<span className={'font-semibold ' + (Number(final_net_profit) < 0 ? 'text-red-700' : 'text-emerald-700')}>{sym}{fmt(final_net_profit)}</span>}
                    />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Stat({ label, value, tone }) {
    const palette = { indigo: 'border-indigo-200 bg-indigo-50', emerald: 'border-emerald-200 bg-emerald-50' }[tone] ?? 'border-slate-200 bg-white';
    return (
        <div className={`rounded-lg border p-5 ${palette}`}>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 text-2xl font-semibold tabular-nums text-slate-800">{value}</div>
        </div>
    );
}

function Row({ label, value }) {
    return <div className="flex justify-between"><span className="text-slate-500">{label}</span><span className="tabular-nums text-slate-800">{value}</span></div>;
}
