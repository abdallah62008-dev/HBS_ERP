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

export default function TransfersReport({
    from, to,
    filters, rows, totals,
    cashboxes = [],
}) {
    const { props } = usePage();
    const currency = props.app?.currency_code ?? 'EGP';

    const [fromCashboxId, setFromCashboxId] = useState(filters?.from_cashbox_id ?? '');
    const [toCashboxId, setToCashboxId] = useState(filters?.to_cashbox_id ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('finance-reports.transfers'), {
            from, to,
            from_cashbox_id: fromCashboxId || undefined,
            to_cashbox_id: toCashboxId || undefined,
        }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Transfers Report">
            <Head title="Transfers Report" />
            <PageHeader
                title="Transfers Report"
                subtitle={`${totals.count} transfers · ${from} to ${to}`}
                actions={<Link href={route('finance-reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Finance Reports</Link>}
            />
            <ReportFilters routeName="finance-reports.transfers" from={from} to={to} extra={{ from_cashbox_id: fromCashboxId, to_cashbox_id: toCashboxId }} />

            <form onSubmit={apply} className="mb-4 flex flex-wrap gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <select value={fromCashboxId} onChange={(e) => setFromCashboxId(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">From: any</option>
                    {cashboxes.map((c) => <option key={c.id} value={c.id}>From: {c.name}</option>)}
                </select>
                <select value={toCashboxId} onChange={(e) => setToCashboxId(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">To: any</option>
                    {cashboxes.map((c) => <option key={c.id} value={c.id}>To: {c.name}</option>)}
                </select>
                <button type="submit" className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">Apply</button>
            </form>

            <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Stat label="Count" value={totals.count} />
                <Stat label="Total amount" value={fmt(totals.total_amount, currency)} tone="indigo" />
            </div>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">#</th>
                            <th className="px-4 py-2.5">When</th>
                            <th className="px-4 py-2.5">From</th>
                            <th className="px-4 py-2.5">To</th>
                            <th className="px-4 py-2.5 text-right">Amount</th>
                            <th className="px-4 py-2.5">By</th>
                            <th className="px-4 py-2.5">Reason</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.data.length === 0 && (
                            <tr><td colSpan={7} className="px-4 py-10 text-center text-sm text-slate-400">No transfers in range.</td></tr>
                        )}
                        {rows.data.map((t) => (
                            <tr key={t.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-mono text-xs text-slate-500">#{t.id}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{t.occurred_at?.replace('T', ' ').slice(0, 16)}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-700">{t.from_cashbox?.name ?? `#${t.from_cashbox_id}`}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-700">{t.to_cashbox?.name ?? `#${t.to_cashbox_id}`}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{fmt(t.amount, t.from_cashbox?.currency_code ?? currency)}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{t.created_by?.name ?? '—'}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500 truncate max-w-xs">{t.reason ?? ''}</td>
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
        indigo: 'border-indigo-200 bg-indigo-50',
    }[tone] ?? 'border-slate-200 bg-white';
    return (
        <div className={`rounded-lg border p-4 ${palette}`}>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 text-xl font-semibold tabular-nums text-slate-800">{value}</div>
        </div>
    );
}
