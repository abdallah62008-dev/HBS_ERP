import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportFilters from '@/Components/ReportFilters';
import useCan from '@/Hooks/useCan';
import { Head, Link, usePage } from '@inertiajs/react';

function fmtAmount(value, currency = 'EGP') {
    const n = Number(value ?? 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    return `${currency} ${n}`;
}

const REPORTS = [
    { title: 'Cashboxes',      href: '/finance/reports/cashboxes',         desc: 'Per-cashbox balance, inflow, outflow, last activity' },
    { title: 'Movements',      href: '/finance/reports/movements',         desc: 'Full cashbox transaction ledger with filters' },
    { title: 'Collections',    href: '/finance/reports/collections',       desc: 'Posted COD collections with cashbox linkage' },
    { title: 'Expenses',       href: '/finance/reports/expenses',          desc: 'Expenses and their cashbox postings' },
    { title: 'Refunds',        href: '/finance/reports/refunds',           desc: 'Refund lifecycle + paid amounts' },
    { title: 'Marketer Payouts', href: '/finance/reports/marketer-payouts', desc: 'Payout lifecycle + cashbox out' },
    { title: 'Transfers',      href: '/finance/reports/transfers',         desc: 'Inter-cashbox transfers' },
    { title: 'Cash flow',      href: '/finance/reports/cash-flow',         desc: 'Inflow/outflow grouped by source type' },
];

export default function FinanceReportsIndex({
    from, to,
    total_balance,
    inflow, outflow, net,
    collections_posted, expenses_posted, refunds_paid, marketer_payouts_paid,
    transfers_count, transfers_amount,
}) {
    const { props } = usePage();
    const currency = props.app?.currency_code ?? 'EGP';

    return (
        <AuthenticatedLayout header="Finance Reports">
            <Head title="Finance Reports" />
            <PageHeader
                title="Finance Reports"
                subtitle={`Cashbox-domain reports · ${from} to ${to}`}
            />

            <ReportFilters routeName="finance-reports.index" from={from} to={to} />

            <div className="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Card title="Total cashbox balance" value={fmtAmount(total_balance, currency)} tone="indigo" hint="Live sum across active cashboxes" />
                <Card title="Cash inflow" value={fmtAmount(inflow, currency)} tone="emerald" hint="Cashbox IN, excl. transfers" />
                <Card title="Cash outflow" value={fmtAmount(Math.abs(outflow), currency)} tone="rose" hint="Cashbox OUT, excl. transfers" />
                <Card title="Net" value={fmtAmount(net, currency)} tone={Number(net) < 0 ? 'rose' : 'emerald'} hint="Inflow − Outflow" />

                <Card title="Collections posted" value={fmtAmount(collections_posted, currency)} />
                <Card title="Expenses posted" value={fmtAmount(expenses_posted, currency)} />
                <Card title="Refunds paid" value={fmtAmount(refunds_paid, currency)} />
                <Card title="Marketer payouts paid" value={fmtAmount(marketer_payouts_paid, currency)} />

                <Card title="Transfers" value={`${transfers_count} · ${fmtAmount(transfers_amount, currency)}`} hint="Count · total amount (one side)" />
            </div>

            <h2 className="mb-2 text-sm font-semibold text-slate-700">Drill-down reports</h2>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {REPORTS.map((r) => (
                    <Link key={r.href} href={r.href} className="group rounded-lg border border-slate-200 bg-white p-5 transition hover:-translate-y-0.5 hover:shadow-sm">
                        <div className="text-sm font-semibold text-slate-800 group-hover:text-indigo-600">{r.title}</div>
                        <p className="mt-1 text-xs text-slate-500">{r.desc}</p>
                    </Link>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

function Card({ title, value, tone, hint }) {
    const palette = {
        indigo: 'border-indigo-200 bg-indigo-50',
        emerald: 'border-emerald-200 bg-emerald-50',
        rose: 'border-rose-200 bg-rose-50',
    }[tone] ?? 'border-slate-200 bg-white';
    return (
        <div className={`rounded-lg border p-4 ${palette}`}>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{title}</div>
            <div className="mt-1 text-xl font-semibold tabular-nums text-slate-800">{value}</div>
            {hint && <div className="text-[11px] text-slate-500">{hint}</div>}
        </div>
    );
}
