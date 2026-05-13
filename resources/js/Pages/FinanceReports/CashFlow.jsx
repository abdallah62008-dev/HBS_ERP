import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportFilters from '@/Components/ReportFilters';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function fmt(n, currency = 'EGP') {
    const x = Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return currency === 'EGP' ? `${x} جنيه` : `${currency} ${x}`;
}

export default function CashFlowReport({
    from, to,
    cashbox_id,
    cashboxes = [],
    rows, totals, source_types = [],
}) {
    const { props } = usePage();
    const currency = props.app?.currency_code ?? 'EGP';

    const [selected, setSelected] = useState(cashbox_id ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('finance-reports.cash-flow'), {
            from, to,
            cashbox_id: selected || undefined,
        }, { preserveState: true, replace: true });
    };

    // Build a stable row order so missing source types still appear with zero.
    const byType = Object.fromEntries(rows.map((r) => [r.source_type, r]));

    return (
        <AuthenticatedLayout header="Cash Flow (Cashbox)">
            <Head title="Cash Flow (Cashbox)" />
            <PageHeader
                title="Cash Flow (Cashbox)"
                subtitle={`Grouped by source · ${from} to ${to}`}
                actions={<Link href={route('finance-reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Finance Reports</Link>}
            />
            <ReportFilters routeName="finance-reports.cash-flow" from={from} to={to} extra={{ cashbox_id: selected }} />

            <form onSubmit={apply} className="mb-4 flex flex-wrap gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <select value={selected} onChange={(e) => setSelected(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">All cashboxes</option>
                    {cashboxes.map((c) => <option key={c.id} value={c.id}>{c.name} ({c.currency_code})</option>)}
                </select>
                <button type="submit" className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">Apply</button>
            </form>

            <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <Card title="Inflow (incl. transfers)" value={fmt(totals.inflow, currency)} tone="emerald" />
                <Card title="Outflow (incl. transfers)" value={fmt(Math.abs(totals.outflow), currency)} tone="rose" />
                <Card title="Net (incl. transfers)" value={fmt(totals.net_including_transfers, currency)} tone={Number(totals.net_including_transfers) < 0 ? 'rose' : 'emerald'} />
            </div>

            <div className="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <Card title="Inflow (excl. transfers)" value={fmt(totals.inflow_excluding_transfers, currency)} tone="emerald" hint="Real cash position movement" />
                <Card title="Outflow (excl. transfers)" value={fmt(Math.abs(totals.outflow_excluding_transfers), currency)} tone="rose" />
                <Card title="Net (excl. transfers)" value={fmt(totals.net_excluding_transfers, currency)} tone={Number(totals.net_excluding_transfers) < 0 ? 'rose' : 'emerald'} hint="Company-wide net change" />
            </div>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Source type</th>
                            <th className="px-4 py-2.5 text-right">Inflow</th>
                            <th className="px-4 py-2.5 text-right">Outflow</th>
                            <th className="px-4 py-2.5 text-right">Net</th>
                            <th className="px-4 py-2.5 text-right">Tx count</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {source_types.map((s) => {
                            const r = byType[s];
                            const inflow = Number(r?.inflow ?? 0);
                            const outflow = Number(r?.outflow ?? 0);
                            return (
                                <tr key={s} className="hover:bg-slate-50">
                                    <td className="px-4 py-2.5 text-slate-700">{s.replaceAll('_', ' ')}</td>
                                    <td className="px-4 py-2.5 text-right tabular-nums text-emerald-700">{fmt(inflow, currency)}</td>
                                    <td className="px-4 py-2.5 text-right tabular-nums text-rose-700">{fmt(Math.abs(outflow), currency)}</td>
                                    <td className={'px-4 py-2.5 text-right tabular-nums font-medium ' + (inflow + outflow < 0 ? 'text-rose-700' : 'text-emerald-700')}>
                                        {fmt(inflow + outflow, currency)}
                                    </td>
                                    <td className="px-4 py-2.5 text-right text-xs text-slate-500">{r?.count ?? 0}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

function Card({ title, value, tone, hint }) {
    const palette = {
        emerald: 'border-emerald-200 bg-emerald-50',
        rose: 'border-rose-200 bg-rose-50',
    }[tone] ?? 'border-slate-200 bg-white';
    return (
        <div className={`rounded-lg border p-5 ${palette}`}>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{title}</div>
            <div className="mt-1 text-2xl font-semibold tabular-nums text-slate-800">{value}</div>
            {hint && <div className="mt-1 text-xs text-slate-500">{hint}</div>}
        </div>
    );
}
