import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import useCan from '@/Hooks/useCan';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Cashbox UI uses the cashbox's own `currency_code` (e.g. "EGP") as a
 * left-side prefix. We intentionally do NOT use the shared
 * `app.currency_symbol` because cashbox displays are LTR-leaning amount
 * tables — a 3-letter currency code reads more cleanly here.
 */
function fmtAmount(value, currency = 'EGP') {
    const n = Number(value ?? 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    return `${currency} ${n}`;
}

function typeLabel(t) {
    return t ? t.replaceAll('_', ' ') : '—';
}

export default function CashboxesIndex({ cashboxes, filters, types }) {
    const can = useCan();

    const [q, setQ] = useState(filters?.q ?? '');
    const [type, setType] = useState(filters?.type ?? '');
    const [status, setStatus] = useState(filters?.status ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('cashboxes.index'), {
            q: q || undefined,
            type: type || undefined,
            status: status || undefined,
        }, { preserveState: true, replace: true });
    };

    const onDeactivate = (cb) => {
        if (!confirm(`Deactivate "${cb.name}"? You can reactivate it later.`)) return;
        router.post(route('cashboxes.deactivate', cb.id), {}, { preserveScroll: true });
    };

    const onReactivate = (cb) => {
        router.post(route('cashboxes.reactivate', cb.id), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header="Cashboxes">
            <Head title="Cashboxes" />
            <PageHeader
                title="Cashboxes"
                subtitle={`${cashboxes.length} cashbox${cashboxes.length === 1 ? '' : 'es'} · balances computed from transactions`}
                actions={
                    can('cashboxes.create') && (
                        <Link
                            href={route('cashboxes.create')}
                            className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
                        >
                            + New cashbox
                        </Link>
                    )
                }
            />

            <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div className="flex-1 min-w-[200px]">
                    <input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Search by name"
                        className="block w-full rounded-md border-slate-300 text-sm"
                    />
                </div>
                <select value={type} onChange={(e) => setType(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any type</option>
                    {types.map((t) => <option key={t} value={t}>{typeLabel(t)}</option>)}
                </select>
                <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Active + Inactive</option>
                    <option value="active">Active only</option>
                    <option value="inactive">Inactive only</option>
                </select>
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Name</th>
                            <th className="px-4 py-2.5">Type</th>
                            <th className="px-4 py-2.5">Currency</th>
                            <th className="px-4 py-2.5 text-right">Opening</th>
                            <th className="px-4 py-2.5 text-right">Balance</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {cashboxes.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-12 text-center text-sm text-slate-400">
                                    No cashboxes yet. Create one to start tracking money locations.
                                </td>
                            </tr>
                        )}
                        {cashboxes.map((c) => (
                            <tr key={c.id} className={'hover:bg-slate-50 ' + (c.is_active ? '' : 'opacity-60')}>
                                <td className="px-4 py-2.5 text-slate-800 font-medium">
                                    <Link href={route('cashboxes.show', c.id)} className="hover:underline">
                                        {c.name}
                                    </Link>
                                </td>
                                <td className="px-4 py-2.5 text-slate-600">{typeLabel(c.type)}</td>
                                <td className="px-4 py-2.5 text-slate-500 text-xs font-mono">{c.currency_code}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums text-slate-500">{fmtAmount(c.opening_balance, c.currency_code)}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums font-semibold text-slate-800">{fmtAmount(c.balance, c.currency_code)}</td>
                                <td className="px-4 py-2.5">
                                    {c.is_active ? (
                                        <span className="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Active</span>
                                    ) : (
                                        <span className="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Inactive</span>
                                    )}
                                </td>
                                <td className="px-4 py-2.5 text-right space-x-2">
                                    <Link href={route('cashboxes.show', c.id)} className="text-xs text-indigo-600 hover:underline">Statement</Link>
                                    {can('cashboxes.edit') && (
                                        <Link href={route('cashboxes.edit', c.id)} className="text-xs text-slate-600 hover:underline">Edit</Link>
                                    )}
                                    {can('cashboxes.deactivate') && (
                                        c.is_active ? (
                                            <button type="button" onClick={() => onDeactivate(c)} className="text-xs text-red-600 hover:underline">Deactivate</button>
                                        ) : (
                                            <button type="button" onClick={() => onReactivate(c)} className="text-xs text-emerald-600 hover:underline">Reactivate</button>
                                        )
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
