import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import ReportFilters from '@/Components/ReportFilters';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function fmt(n, currency = 'EGP') {
    const x = Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return `${currency} ${x}`;
}

function chip(status) {
    const tone = {
        requested: 'bg-amber-50 text-amber-700',
        approved: 'bg-emerald-50 text-emerald-700',
        rejected: 'bg-slate-100 text-slate-600',
        paid: 'bg-indigo-50 text-indigo-700',
    }[status] ?? 'bg-slate-100 text-slate-600';
    return <span className={'rounded-full px-2 py-0.5 text-xs ' + tone}>{status}</span>;
}

export default function MarketerPayoutsReport({
    from, to,
    filters, rows, totals, statuses = [],
    cashboxes = [], payment_methods = [], marketers = [],
}) {
    const { props } = usePage();
    const currency = props.app?.currency_code ?? 'EGP';

    const [status, setStatus] = useState(filters?.status ?? '');
    const [marketerId, setMarketerId] = useState(filters?.marketer_id ?? '');
    const [cashboxId, setCashboxId] = useState(filters?.cashbox_id ?? '');
    const [paymentMethodId, setPaymentMethodId] = useState(filters?.payment_method_id ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('finance-reports.marketer-payouts'), {
            from, to,
            status: status || undefined,
            marketer_id: marketerId || undefined,
            cashbox_id: cashboxId || undefined,
            payment_method_id: paymentMethodId || undefined,
        }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Marketer Payouts Report">
            <Head title="Marketer Payouts Report" />
            <PageHeader
                title="Marketer Payouts Report"
                subtitle={`${from} to ${to}`}
                actions={<Link href={route('finance-reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Finance Reports</Link>}
            />
            <ReportFilters routeName="finance-reports.marketer-payouts" from={from} to={to} extra={{ status, marketer_id: marketerId, cashbox_id: cashboxId, payment_method_id: paymentMethodId }} />

            <form onSubmit={apply} className="mb-4 flex flex-wrap gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">All statuses</option>
                    {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
                <select value={marketerId} onChange={(e) => setMarketerId(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">All marketers</option>
                    {marketers.map((m) => <option key={m.id} value={m.id}>{m.code} — {m.name}</option>)}
                </select>
                <select value={cashboxId} onChange={(e) => setCashboxId(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">All cashboxes</option>
                    {cashboxes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
                <select value={paymentMethodId} onChange={(e) => setPaymentMethodId(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">All payment methods</option>
                    {payment_methods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                </select>
                <button type="submit" className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">Apply</button>
            </form>

            <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Stat label="Requested" value={fmt(totals.requested_amount, currency)} tone="amber" />
                <Stat label="Approved" value={fmt(totals.approved_amount, currency)} tone="emerald" />
                <Stat label="Paid" value={fmt(totals.paid_amount, currency)} tone="indigo" />
                <Stat label="Rejected" value={fmt(totals.rejected_amount, currency)} />
            </div>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">#</th>
                            <th className="px-4 py-2.5">Marketer</th>
                            <th className="px-4 py-2.5 text-right">Amount</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5">Cashbox</th>
                            <th className="px-4 py-2.5">Paid by</th>
                            <th className="px-4 py-2.5">Paid at</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.data.length === 0 && (
                            <tr><td colSpan={7} className="px-4 py-10 text-center text-sm text-slate-400">No payouts in range.</td></tr>
                        )}
                        {rows.data.map((p) => (
                            <tr key={p.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-mono text-xs text-slate-500">#{p.id}</td>
                                <td className="px-4 py-2.5 text-xs">
                                    <div className="text-slate-700">{p.marketer?.user?.name}</div>
                                    <div className="font-mono text-slate-400">{p.marketer?.code}</div>
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{fmt(p.amount, currency)}</td>
                                <td className="px-4 py-2.5">{chip(p.status)}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-600">{p.cashbox?.name ?? '—'}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{p.paid_by?.name ?? '—'}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{p.paid_at?.replace('T', ' ').slice(0, 16) ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={rows.links} />
        </AuthenticatedLayout>
    );
}

function Stat({ label, value, tone }) {
    const palette = {
        amber: 'border-amber-200 bg-amber-50',
        emerald: 'border-emerald-200 bg-emerald-50',
        indigo: 'border-indigo-200 bg-indigo-50',
    }[tone] ?? 'border-slate-200 bg-white';
    return (
        <div className={`rounded-lg border p-4 ${palette}`}>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 text-xl font-semibold tabular-nums text-slate-800">{value}</div>
        </div>
    );
}
