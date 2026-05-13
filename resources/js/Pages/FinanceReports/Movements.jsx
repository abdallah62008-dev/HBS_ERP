import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import ReportFilters from '@/Components/ReportFilters';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function fmt(n, currency = 'EGP') {
    const x = Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return currency === 'EGP' ? `${x} جنيه` : `${currency} ${x}`;
}

export default function MovementsReport({
    from, to,
    filters, rows, totals,
    cashboxes = [], payment_methods = [], source_types = [], directions = [],
}) {
    const { props } = usePage();
    const currency = props.app?.currency_code ?? 'EGP';

    const [cashboxId, setCashboxId] = useState(filters?.cashbox_id ?? '');
    const [direction, setDirection] = useState(filters?.direction ?? '');
    const [sourceType, setSourceType] = useState(filters?.source_type ?? '');
    const [paymentMethodId, setPaymentMethodId] = useState(filters?.payment_method_id ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('finance-reports.movements'), {
            from, to,
            cashbox_id: cashboxId || undefined,
            direction: direction || undefined,
            source_type: sourceType || undefined,
            payment_method_id: paymentMethodId || undefined,
        }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Movements">
            <Head title="Cashbox Movements" />
            <PageHeader
                title="Cashbox Movements"
                subtitle={`${totals.count} transactions · ${from} to ${to}`}
                actions={<Link href={route('finance-reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Finance Reports</Link>}
            />
            <ReportFilters routeName="finance-reports.movements" from={from} to={to} extra={{ cashbox_id: cashboxId, direction, source_type: sourceType, payment_method_id: paymentMethodId }} />

            <form onSubmit={apply} className="mb-4 flex flex-wrap gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <select value={cashboxId} onChange={(e) => setCashboxId(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">All cashboxes</option>
                    {cashboxes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
                <select value={direction} onChange={(e) => setDirection(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">All directions</option>
                    {directions.map((d) => <option key={d} value={d}>{d}</option>)}
                </select>
                <select value={sourceType} onChange={(e) => setSourceType(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">All sources</option>
                    {source_types.map((s) => <option key={s} value={s}>{s.replaceAll('_', ' ')}</option>)}
                </select>
                <select value={paymentMethodId} onChange={(e) => setPaymentMethodId(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">All payment methods</option>
                    {payment_methods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                </select>
                <button type="submit" className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">Apply</button>
            </form>

            <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-4">
                <Stat label="Count" value={totals.count} />
                <Stat label="Inflow" value={fmt(totals.inflow, currency)} tone="emerald" />
                <Stat label="Outflow" value={fmt(Math.abs(totals.outflow), currency)} tone="rose" />
                <Stat label="Net" value={fmt(totals.net, currency)} tone={Number(totals.net) < 0 ? 'rose' : 'emerald'} />
            </div>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">When</th>
                            <th className="px-4 py-2.5">Cashbox</th>
                            <th className="px-4 py-2.5">Direction</th>
                            <th className="px-4 py-2.5">Source</th>
                            <th className="px-4 py-2.5">Ref</th>
                            <th className="px-4 py-2.5 text-right">Amount</th>
                            <th className="px-4 py-2.5">By</th>
                            <th className="px-4 py-2.5">Notes</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.data.length === 0 && (
                            <tr><td colSpan={8} className="px-4 py-10 text-center text-sm text-slate-400">No transactions match the filters.</td></tr>
                        )}
                        {rows.data.map((tx) => (
                            <tr key={tx.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 text-xs text-slate-500">{tx.occurred_at?.replace('T', ' ').slice(0, 16)}</td>
                                <td className="px-4 py-2.5 text-slate-700">{tx.cashbox?.name ?? `#${tx.cashbox_id}`}</td>
                                <td className="px-4 py-2.5">
                                    <span className={'rounded-full px-2 py-0.5 text-xs ' + (tx.direction === 'in' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700')}>
                                        {tx.direction}
                                    </span>
                                </td>
                                <td className="px-4 py-2.5 text-xs text-slate-600">{tx.source_type ? tx.source_type.replaceAll('_', ' ') : '—'}</td>
                                <td className="px-4 py-2.5 font-mono text-xs text-slate-500">{tx.source_id ? `#${tx.source_id}` : '—'}</td>
                                <td className={'px-4 py-2.5 text-right tabular-nums font-medium ' + (Number(tx.amount) < 0 ? 'text-rose-700' : 'text-emerald-700')}>
                                    {fmt(tx.amount, tx.cashbox?.currency_code ?? currency)}
                                </td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{tx.created_by?.name ?? '—'}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500 truncate max-w-xs" title={tx.notes ?? ''}>{tx.notes ?? ''}</td>
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
        rose: 'border-rose-200 bg-rose-50',
    }[tone] ?? 'border-slate-200 bg-white';
    return (
        <div className={`rounded-lg border p-4 ${palette}`}>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 text-xl font-semibold tabular-nums text-slate-800">{value}</div>
        </div>
    );
}
