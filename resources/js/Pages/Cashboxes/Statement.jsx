import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Use the cashbox's own `currency_code` ("EGP") as a left-side prefix
 * for every amount. Avoids `app.currency_symbol` because cashbox
 * statements are amount-dense LTR tables where a 3-letter code reads
 * more cleanly than a glyph mixed with numerics.
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

export default function CashboxStatement({ cashbox, transactions, filters, phase1_source_types }) {
    const can = useCan();
    const pageTitle = `${cashbox.name} Statement`;

    const [from, setFrom] = useState(filters?.from ?? '');
    const [to, setTo] = useState(filters?.to ?? '');
    const [direction, setDirection] = useState(filters?.direction ?? '');
    const [sourceType, setSourceType] = useState(filters?.source_type ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('cashboxes.show', cashbox.id), {
            from: from || undefined,
            to: to || undefined,
            direction: direction || undefined,
            source_type: sourceType || undefined,
        }, { preserveState: true, replace: true });
    };

    /* Manual adjustment form (cashbox_transactions.create). */
    const adj = useForm({
        direction: 'in',
        amount: 0,
        notes: '',
        occurred_at: new Date().toISOString().slice(0, 10),
    });
    const [showAdj, setShowAdj] = useState(false);

    const submitAdjustment = (e) => {
        e.preventDefault();
        adj.post(route('cashboxes.transactions.store', cashbox.id), {
            preserveScroll: true,
            onSuccess: () => {
                adj.reset();
                adj.setData('direction', 'in');
                adj.setData('occurred_at', new Date().toISOString().slice(0, 10));
                setShowAdj(false);
            },
        });
    };

    return (
        <AuthenticatedLayout header={pageTitle}>
            <Head title={pageTitle} />
            <PageHeader
                title={pageTitle}
                subtitle={
                    <span>
                        <span className="font-mono">{cashbox.currency_code}</span> · {typeLabel(cashbox.type)} ·{' '}
                        {cashbox.is_active
                            ? <span className="text-emerald-700">Active</span>
                            : <span className="text-slate-500">Inactive</span>}
                    </span>
                }
                actions={
                    <div className="flex gap-2">
                        <Link href={route('cashboxes.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">All cashboxes</Link>
                        {can('cashboxes.edit') && (
                            <Link href={route('cashboxes.edit', cashbox.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Edit</Link>
                        )}
                        {can('cashbox_transactions.create') && cashbox.is_active && (
                            <button
                                type="button"
                                onClick={() => setShowAdj((v) => !v)}
                                className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
                            >
                                {showAdj ? 'Hide adjustment' : 'Record adjustment'}
                            </button>
                        )}
                    </div>
                }
            />

            {/* Balance + summary cards */}
            <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div className="rounded-lg border border-slate-200 bg-white p-4">
                    <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Current balance</div>
                    <div className={'mt-1 text-2xl font-semibold tabular-nums ' + (cashbox.balance < 0 ? 'text-red-600' : 'text-slate-900')}>
                        {fmtAmount(cashbox.balance, cashbox.currency_code)}
                    </div>
                    {cashbox.balance < 0 && !cashbox.allow_negative_balance && (
                        <div className="mt-1 text-xs text-red-600">Negative balance not permitted on this cashbox.</div>
                    )}
                </div>
                <div className="rounded-lg border border-slate-200 bg-white p-4">
                    <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Opening balance</div>
                    <div className="mt-1 text-xl tabular-nums text-slate-700">{fmtAmount(cashbox.opening_balance, cashbox.currency_code)}</div>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white p-4">
                    <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Transactions</div>
                    <div className="mt-1 text-xl tabular-nums text-slate-700">{transactions.total}</div>
                </div>
            </div>

            {/* Adjustment form */}
            {showAdj && can('cashbox_transactions.create') && (
                <form onSubmit={submitAdjustment} className="mb-4 rounded-lg border border-slate-200 bg-white p-4">
                    <h3 className="mb-3 text-sm font-semibold text-slate-700">Manual adjustment</h3>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                        <select
                            value={adj.data.direction}
                            onChange={(e) => adj.setData('direction', e.target.value)}
                            className="rounded-md border-slate-300 text-sm"
                        >
                            <option value="in">IN (+)</option>
                            <option value="out">OUT (−)</option>
                        </select>
                        <input
                            type="number"
                            min="0"
                            step="0.01"
                            value={adj.data.amount}
                            onChange={(e) => adj.setData('amount', e.target.value)}
                            placeholder="Amount"
                            className="rounded-md border-slate-300 text-sm"
                        />
                        <input
                            type="date"
                            value={adj.data.occurred_at}
                            onChange={(e) => adj.setData('occurred_at', e.target.value)}
                            className="rounded-md border-slate-300 text-sm"
                        />
                        <input
                            type="text"
                            value={adj.data.notes}
                            onChange={(e) => adj.setData('notes', e.target.value)}
                            placeholder="Adjustment reason / notes"
                            required
                            className="rounded-md border-slate-300 text-sm sm:col-span-1"
                        />
                    </div>
                    {(adj.errors.amount || adj.errors.notes || adj.errors.direction) && (
                        <p className="mt-2 text-xs text-red-600">
                            {adj.errors.amount || adj.errors.notes || adj.errors.direction}
                        </p>
                    )}
                    <div className="mt-3 flex justify-end">
                        <button
                            type="submit"
                            disabled={adj.processing}
                            className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                        >
                            {adj.processing ? 'Recording…' : 'Record adjustment'}
                        </button>
                    </div>
                </form>
            )}

            {/* Filters */}
            <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="rounded-md border-slate-300 text-sm" />
                <span className="text-xs text-slate-400">to</span>
                <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="rounded-md border-slate-300 text-sm" />
                <select value={direction} onChange={(e) => setDirection(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any direction</option>
                    <option value="in">IN</option>
                    <option value="out">OUT</option>
                </select>
                <select value={sourceType} onChange={(e) => setSourceType(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any source</option>
                    {phase1_source_types.map((s) => (
                        <option key={s} value={s}>{s.replaceAll('_', ' ')}</option>
                    ))}
                </select>
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            {/* Transactions */}
            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">When</th>
                            <th className="px-4 py-2.5">Dir</th>
                            <th className="px-4 py-2.5 text-right">Amount</th>
                            <th className="px-4 py-2.5">Source</th>
                            <th className="px-4 py-2.5">Notes</th>
                            <th className="px-4 py-2.5">By</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {transactions.data.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-12 text-center text-sm text-slate-400">
                                    No transactions in this window.
                                </td>
                            </tr>
                        )}
                        {transactions.data.map((tx) => (
                            <tr key={tx.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 text-slate-500">{tx.occurred_at}</td>
                                <td className="px-4 py-2.5">
                                    {tx.direction === 'in'
                                        ? <span className="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">IN</span>
                                        : <span className="inline-flex rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">OUT</span>}
                                </td>
                                <td className={'px-4 py-2.5 text-right tabular-nums font-medium ' + (tx.amount < 0 ? 'text-red-700' : 'text-emerald-700')}>
                                    {fmtAmount(tx.amount, cashbox.currency_code)}
                                </td>
                                <td className="px-4 py-2.5 text-xs text-slate-600">{tx.source_type ? tx.source_type.replaceAll('_', ' ') : '—'}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-600">{tx.notes ?? '—'}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{tx.created_by?.name ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={transactions.links} />
        </AuthenticatedLayout>
    );
}
