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

export default function RefundsReport({
    from, to,
    filters, rows, totals, statuses = [],
    cashboxes = [], payment_methods = [],
}) {
    const { props } = usePage();
    const currency = props.app?.currency_code ?? 'EGP';

    const [status, setStatus] = useState(filters?.status ?? '');
    const [cashboxId, setCashboxId] = useState(filters?.cashbox_id ?? '');
    const [paymentMethodId, setPaymentMethodId] = useState(filters?.payment_method_id ?? '');
    const [orderId, setOrderId] = useState(filters?.order_id ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('finance-reports.refunds'), {
            from, to,
            status: status || undefined,
            cashbox_id: cashboxId || undefined,
            payment_method_id: paymentMethodId || undefined,
            order_id: orderId || undefined,
        }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Refunds Report">
            <Head title="Refunds Report" />
            <PageHeader
                title="Refunds Report"
                subtitle={`${from} to ${to}`}
                actions={<Link href={route('finance-reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Finance Reports</Link>}
            />
            <ReportFilters routeName="finance-reports.refunds" from={from} to={to} extra={{ status, cashbox_id: cashboxId, payment_method_id: paymentMethodId, order_id: orderId }} />

            <form onSubmit={apply} className="mb-4 flex flex-wrap gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">All statuses</option>
                    {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
                <select value={cashboxId} onChange={(e) => setCashboxId(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">All cashboxes</option>
                    {cashboxes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
                <select value={paymentMethodId} onChange={(e) => setPaymentMethodId(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">All payment methods</option>
                    {payment_methods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                </select>
                <input type="text" value={orderId} onChange={(e) => setOrderId(e.target.value)} placeholder="Order ID" className="w-32 rounded-md border-slate-300 text-sm" />
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
                            <th className="px-4 py-2.5">Order</th>
                            <th className="px-4 py-2.5 text-right">Amount</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5">Cashbox</th>
                            <th className="px-4 py-2.5">Requested by</th>
                            <th className="px-4 py-2.5">Paid by</th>
                            <th className="px-4 py-2.5">Paid at</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.data.length === 0 && (
                            <tr><td colSpan={8} className="px-4 py-10 text-center text-sm text-slate-400">No refunds in range.</td></tr>
                        )}
                        {rows.data.map((r) => (
                            <tr key={r.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-mono text-xs text-slate-500">#{r.id}</td>
                                <td className="px-4 py-2.5 text-xs">
                                    <div className="text-slate-700">{r.order?.order_number ?? '—'}</div>
                                    <div className="text-slate-400">{r.order?.customer_name}</div>
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{fmt(r.amount, currency)}</td>
                                <td className="px-4 py-2.5">{chip(r.status)}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-600">{r.cashbox?.name ?? '—'}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{r.requested_by?.name ?? '—'}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{r.paid_by?.name ?? '—'}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{r.paid_at?.replace('T', ' ').slice(0, 16) ?? '—'}</td>
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
