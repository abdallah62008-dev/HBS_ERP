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

export default function CollectionsReport({
    from, to,
    filters, rows, totals,
    cashboxes = [], payment_methods = [], collection_statuses = [],
}) {
    const { props } = usePage();
    const currency = props.app?.currency_code ?? 'EGP';

    const [cashboxId, setCashboxId] = useState(filters?.cashbox_id ?? '');
    const [paymentMethodId, setPaymentMethodId] = useState(filters?.payment_method_id ?? '');
    const [posted, setPosted] = useState(filters?.posted ?? '');
    const [settlementStatus, setSettlementStatus] = useState(filters?.settlement_status ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('finance-reports.collections'), {
            from, to,
            cashbox_id: cashboxId || undefined,
            payment_method_id: paymentMethodId || undefined,
            posted: posted || undefined,
            settlement_status: settlementStatus || undefined,
        }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Collections Report">
            <Head title="Collections Report" />
            <PageHeader
                title="Collections Report"
                subtitle={`${totals.count} collections · ${from} to ${to}`}
                actions={<Link href={route('finance-reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Finance Reports</Link>}
            />
            <ReportFilters routeName="finance-reports.collections" from={from} to={to} extra={{ cashbox_id: cashboxId, payment_method_id: paymentMethodId, posted, settlement_status: settlementStatus }} />

            <form onSubmit={apply} className="mb-4 flex flex-wrap gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <select value={cashboxId} onChange={(e) => setCashboxId(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">All cashboxes</option>
                    {cashboxes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
                <select value={paymentMethodId} onChange={(e) => setPaymentMethodId(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">All payment methods</option>
                    {payment_methods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                </select>
                <select value={settlementStatus} onChange={(e) => setSettlementStatus(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">All statuses</option>
                    {collection_statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
                <select value={posted} onChange={(e) => setPosted(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Posted + unposted</option>
                    <option value="posted">Posted only</option>
                    <option value="unposted">Unposted only</option>
                </select>
                <button type="submit" className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">Apply</button>
            </form>

            <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <Stat label="Count" value={totals.count} />
                <Stat label="Posted to cashbox" value={fmt(totals.posted_amount, currency)} tone="emerald" />
                <Stat label="Unposted" value={fmt(totals.unposted_amount, currency)} tone="amber" />
            </div>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">#</th>
                            <th className="px-4 py-2.5">Order</th>
                            <th className="px-4 py-2.5 text-right">Collected</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5">Cashbox</th>
                            <th className="px-4 py-2.5">Payment</th>
                            <th className="px-4 py-2.5">Posted at</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.data.length === 0 && (
                            <tr><td colSpan={7} className="px-4 py-10 text-center text-sm text-slate-400">No collections in range.</td></tr>
                        )}
                        {rows.data.map((r) => (
                            <tr key={r.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-mono text-xs text-slate-500">#{r.id}</td>
                                <td className="px-4 py-2.5 text-xs">
                                    <div className="text-slate-700">{r.order?.order_number ?? '—'}</div>
                                    <div className="text-slate-400">{r.order?.customer_name}</div>
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{fmt(r.amount_collected, r.cashbox?.currency_code ?? currency)}</td>
                                <td className="px-4 py-2.5 text-xs">{r.collection_status}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-600">{r.cashbox?.name ?? '—'}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-600">{r.payment_method?.name ?? '—'}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{r.cashbox_posted_at?.replace('T', ' ').slice(0, 16) ?? '—'}</td>
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
        emerald: 'border-emerald-200 bg-emerald-50',
        amber: 'border-amber-200 bg-amber-50',
    }[tone] ?? 'border-slate-200 bg-white';
    return (
        <div className={`rounded-lg border p-4 ${palette}`}>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 text-xl font-semibold tabular-nums text-slate-800">{value}</div>
        </div>
    );
}
