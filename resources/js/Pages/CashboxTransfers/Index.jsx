import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import useCan from '@/Hooks/useCan';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Cashbox transfers use the source/dest cashbox `currency_code` ("EGP")
 * as the amount prefix — consistent with the rest of the cashbox UI.
 */
function fmtAmount(value, currency = 'EGP') {
    const n = Number(value ?? 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    return currency === 'EGP' ? `${n} جنيه` : `${currency} ${n}`;
}

export default function CashboxTransfersIndex({ transfers, filters, cashboxes }) {
    const can = useCan();

    const [from, setFrom] = useState(filters?.from ?? '');
    const [to, setTo] = useState(filters?.to ?? '');
    const [fromCb, setFromCb] = useState(filters?.from_cashbox_id ?? '');
    const [toCb, setToCb] = useState(filters?.to_cashbox_id ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('cashbox-transfers.index'), {
            from: from || undefined,
            to: to || undefined,
            from_cashbox_id: fromCb || undefined,
            to_cashbox_id: toCb || undefined,
        }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Cashbox Transfers">
            <Head title="Cashbox Transfers" />
            <PageHeader
                title="Cashbox Transfers"
                subtitle={`${transfers.total} transfer${transfers.total === 1 ? '' : 's'} · moves between cashboxes are always recorded as a pair of transactions`}
                actions={
                    can('cashbox_transfers.create') && (
                        <Link
                            href={route('cashbox-transfers.create')}
                            className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
                        >
                            + New transfer
                        </Link>
                    )
                }
            />

            <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="rounded-md border-slate-300 text-sm" />
                <span className="text-xs text-slate-400">to</span>
                <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="rounded-md border-slate-300 text-sm" />

                <select value={fromCb} onChange={(e) => setFromCb(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any source</option>
                    {cashboxes.map((c) => (
                        <option key={c.id} value={c.id}>{c.name}{!c.is_active ? ' (inactive)' : ''}</option>
                    ))}
                </select>

                <select value={toCb} onChange={(e) => setToCb(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any destination</option>
                    {cashboxes.map((c) => (
                        <option key={c.id} value={c.id}>{c.name}{!c.is_active ? ' (inactive)' : ''}</option>
                    ))}
                </select>

                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">When</th>
                            <th className="px-4 py-2.5">From</th>
                            <th className="px-4 py-2.5">To</th>
                            <th className="px-4 py-2.5 text-right">Amount</th>
                            <th className="px-4 py-2.5">Reason</th>
                            <th className="px-4 py-2.5">By</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {transfers.data.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-12 text-center text-sm text-slate-400">
                                    No transfers in this window.
                                </td>
                            </tr>
                        )}
                        {transfers.data.map((t) => (
                            <tr key={t.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 text-slate-500">{t.occurred_at}</td>
                                <td className="px-4 py-2.5">
                                    {t.from_cashbox ? (
                                        <Link href={route('cashboxes.show', t.from_cashbox.id)} className="text-slate-800 hover:underline">
                                            {t.from_cashbox.name}
                                        </Link>
                                    ) : '—'}
                                </td>
                                <td className="px-4 py-2.5">
                                    {t.to_cashbox ? (
                                        <Link href={route('cashboxes.show', t.to_cashbox.id)} className="text-slate-800 hover:underline">
                                            {t.to_cashbox.name}
                                        </Link>
                                    ) : '—'}
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums font-medium text-slate-800">
                                    {fmtAmount(t.amount, t.from_cashbox?.currency_code)}
                                </td>
                                <td className="px-4 py-2.5 text-slate-600 text-xs">{t.reason ?? '—'}</td>
                                <td className="px-4 py-2.5 text-slate-500 text-xs">{t.created_by?.name ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={transfers.links} />
        </AuthenticatedLayout>
    );
}
