import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportFilters from '@/Components/ReportFilters';
import { Head, Link, usePage } from '@inertiajs/react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function CashFlowReport({ from, to, inflows, outflows, net }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    return (
        <AuthenticatedLayout header="Cash flow">
            <Head title="Cash flow" />
            <PageHeader title="Cash flow" subtitle={`${from} to ${to}`}
                actions={<Link href={route('reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Reports</Link>}
            />
            <ReportFilters routeName="reports.cash-flow" from={from} to={to} />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card title="Inflows" value={`${sym}${fmt(inflows)}`} tone="emerald" hint="Delivered order revenue" />
                <Card title="Outflows" value={`${sym}${fmt(outflows.total)}`} tone="red"
                    items={[
                        ['Expenses', outflows.expenses],
                        ['Supplier payments', outflows.supplier_payments],
                        ['Marketer payouts', outflows.marketer_payouts],
                    ]}
                    sym={sym}
                />
                <Card title="Net" value={`${sym}${fmt(net)}`} tone={Number(net) < 0 ? 'red' : 'emerald'} hint="Inflows − Outflows" />
            </div>
        </AuthenticatedLayout>
    );
}

function Card({ title, value, tone, hint, items, sym }) {
    const palette = { emerald: 'border-emerald-200 bg-emerald-50', red: 'border-red-200 bg-red-50' }[tone] ?? 'border-slate-200 bg-white';
    return (
        <div className={`rounded-lg border p-5 ${palette}`}>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{title}</div>
            <div className="mt-1 text-3xl font-semibold tabular-nums text-slate-800">{value}</div>
            {hint && <div className="mt-1 text-xs text-slate-500">{hint}</div>}
            {items && (
                <ul className="mt-3 space-y-1 text-xs">
                    {items.map(([label, val]) => (
                        <li key={label} className="flex justify-between">
                            <span className="text-slate-500">{label}</span>
                            <span className="tabular-nums text-slate-700">{sym}{fmt(val)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
