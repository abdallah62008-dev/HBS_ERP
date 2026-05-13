import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportFilters from '@/Components/ReportFilters';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function fmt(n, currency = 'EGP') {
    const x = Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return `${currency} ${x}`;
}

export default function CashboxesReport({ from, to, filters, types, rows, totals }) {
    const { props } = usePage();
    const currency = props.app?.currency_code ?? 'EGP';

    const [type, setType] = useState(filters?.type ?? '');
    const [active, setActive] = useState(filters?.active ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('finance-reports.cashboxes'), {
            from, to,
            type: type || undefined,
            active: active || undefined,
        }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Cashbox Summary">
            <Head title="Cashbox Summary" />
            <PageHeader
                title="Cashbox Summary"
                subtitle={`Per-cashbox totals · ${from} to ${to}`}
                actions={<Link href={route('finance-reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Finance Reports</Link>}
            />
            <ReportFilters routeName="finance-reports.cashboxes" from={from} to={to} extra={{ type, active }} />

            <form onSubmit={apply} className="mb-4 flex gap-2">
                <select value={type} onChange={(e) => setType(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">All types</option>
                    {types.map((t) => <option key={t} value={t}>{t}</option>)}
                </select>
                <select value={active} onChange={(e) => setActive(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <button type="submit" className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">Apply</button>
            </form>

            <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-4">
                <Stat label="Cashboxes" value={totals.count} />
                <Stat label="Active" value={totals.active} />
                <Stat label="Inflow" value={fmt(totals.inflow, currency)} tone="emerald" />
                <Stat label="Outflow" value={fmt(Math.abs(totals.outflow), currency)} tone="rose" />
            </div>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Cashbox</th>
                            <th className="px-4 py-2.5">Type</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5 text-right">Opening</th>
                            <th className="px-4 py-2.5 text-right">Inflow</th>
                            <th className="px-4 py-2.5 text-right">Outflow</th>
                            <th className="px-4 py-2.5 text-right">Balance</th>
                            <th className="px-4 py-2.5 text-right">Tx</th>
                            <th className="px-4 py-2.5">Last tx</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.length === 0 && (
                            <tr><td colSpan={9} className="px-4 py-10 text-center text-sm text-slate-400">No cashboxes match the filters.</td></tr>
                        )}
                        {rows.map((c) => (
                            <tr key={c.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-medium text-slate-800">{c.name}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{c.type}</td>
                                <td className="px-4 py-2.5">
                                    <span className={'rounded-full px-2 py-0.5 text-xs ' + (c.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500')}>
                                        {c.is_active ? 'active' : 'inactive'}
                                    </span>
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums text-slate-600">{fmt(c.opening_balance, c.currency_code)}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums text-emerald-700">{fmt(c.inflow, c.currency_code)}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums text-rose-700">{fmt(Math.abs(c.outflow), c.currency_code)}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums font-medium">{fmt(c.balance, c.currency_code)}</td>
                                <td className="px-4 py-2.5 text-right text-xs tabular-nums text-slate-500">{c.tx_count}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{c.last_tx?.replace('T', ' ').slice(0, 16) ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
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
