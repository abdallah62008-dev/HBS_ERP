import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function MarketerWallet({ marketer, transactions }) {
    const can = useCan();
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const [payOpen, setPayOpen] = useState(false);
    const [adjOpen, setAdjOpen] = useState(false);

    const pay = useForm({ amount: marketer.wallet?.balance ?? 0, notes: '' });
    const adj = useForm({ delta: 0, notes: '' });

    const submitPay = (e) => {
        e.preventDefault();
        pay.post(route('marketers.payout', marketer.id), { onSuccess: () => { setPayOpen(false); pay.reset('notes'); } });
    };

    const submitAdj = (e) => {
        e.preventDefault();
        adj.post(route('marketers.adjust', marketer.id), { onSuccess: () => { setAdjOpen(false); adj.reset(); } });
    };

    return (
        <AuthenticatedLayout header={`Wallet · ${marketer.code}`}>
            <Head title={`Wallet ${marketer.code}`} />
            <PageHeader
                title={`Wallet · ${marketer.user?.name}`}
                subtitle={`Code ${marketer.code} · group ${marketer.price_group?.name}`}
                actions={
                    <div className="flex gap-2">
                        <Link href={route('marketers.show', marketer.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Profile</Link>
                        {can('marketers.wallet') && <button onClick={() => setAdjOpen(true)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">Adjustment</button>}
                        {can('marketers.wallet') && <button onClick={() => setPayOpen(true)} className="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-500">Pay out</button>}
                    </div>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-5">
                <Stat label="Expected" value={`${sym}${fmt(marketer.wallet?.total_expected)}`} />
                <Stat label="Pending" value={`${sym}${fmt(marketer.wallet?.total_pending)}`} tone="amber" />
                <Stat label="Earned" value={`${sym}${fmt(marketer.wallet?.total_earned)}`} tone="emerald" />
                <Stat label="Paid out" value={`${sym}${fmt(marketer.wallet?.total_paid)}`} />
                <Stat label="Balance" value={`${sym}${fmt(marketer.wallet?.balance)}`} tone="indigo" />
            </div>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">When</th>
                            <th className="px-4 py-2.5">Order</th>
                            <th className="px-4 py-2.5">Type</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5 text-right">Selling</th>
                            <th className="px-4 py-2.5 text-right">Trade</th>
                            <th className="px-4 py-2.5 text-right">Ship/Tax/Fees</th>
                            <th className="px-4 py-2.5 text-right">Net profit</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {transactions.data.length === 0 && (
                            <tr><td colSpan={8} className="px-4 py-12 text-center text-sm text-slate-400">No transactions yet.</td></tr>
                        )}
                        {transactions.data.map((tx) => (
                            <tr key={tx.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 text-slate-500">{tx.created_at?.replace('T', ' ').slice(0, 16)}</td>
                                <td className="px-4 py-2.5 font-mono text-xs">{tx.order?.order_number ?? '—'}</td>
                                <td className="px-4 py-2.5 text-slate-700">{tx.transaction_type}</td>
                                <td className="px-4 py-2.5"><StatusBadge value={tx.status} /></td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{fmt(tx.selling_price)}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{fmt(tx.trade_product_price)}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums text-xs text-slate-500">
                                    {fmt(tx.shipping_amount)} / {fmt(tx.tax_amount)} / {fmt(tx.extra_fees)}
                                </td>
                                <td className={'px-4 py-2.5 text-right tabular-nums font-medium ' + (Number(tx.net_profit) < 0 ? 'text-red-700' : 'text-slate-800')}>
                                    {sym}{fmt(tx.net_profit)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={transactions.links} />

            {/* Modals */}
            {payOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <form onSubmit={submitPay} className="w-full max-w-md rounded-lg bg-white p-5 shadow-xl space-y-3">
                        <h3 className="text-sm font-semibold text-slate-800">Pay out</h3>
                        <input type="number" step="0.01" min={0} value={pay.data.amount} onChange={(e) => pay.setData('amount', e.target.value)} className="block w-full rounded-md border-slate-300 text-sm" />
                        <textarea value={pay.data.notes} onChange={(e) => pay.setData('notes', e.target.value)} placeholder="Notes (e.g. bank ref)" rows={2} className="block w-full rounded-md border-slate-300 text-sm" />
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setPayOpen(false)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</button>
                            <button type="submit" disabled={pay.processing} className="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-500 disabled:opacity-60">Confirm payout</button>
                        </div>
                    </form>
                </div>
            )}
            {adjOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <form onSubmit={submitAdj} className="w-full max-w-md rounded-lg bg-white p-5 shadow-xl space-y-3">
                        <h3 className="text-sm font-semibold text-slate-800">Adjustment</h3>
                        <p className="text-xs text-slate-500">Positive = credit. Negative = debit.</p>
                        <input type="number" step="0.01" value={adj.data.delta} onChange={(e) => adj.setData('delta', e.target.value)} className="block w-full rounded-md border-slate-300 text-sm" />
                        <textarea value={adj.data.notes} onChange={(e) => adj.setData('notes', e.target.value)} placeholder="Reason (required)" rows={3} className="block w-full rounded-md border-slate-300 text-sm" />
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setAdjOpen(false)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</button>
                            <button type="submit" disabled={adj.processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">Save adjustment</button>
                        </div>
                    </form>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

function Stat({ label, value, tone }) {
    const palette = { amber: 'border-amber-200 bg-amber-50', emerald: 'border-emerald-200 bg-emerald-50', indigo: 'border-indigo-200 bg-indigo-50' }[tone] ?? 'border-slate-200 bg-white';
    return (
        <div className={`rounded-lg border p-5 ${palette}`}>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 text-2xl font-semibold tabular-nums text-slate-800">{value}</div>
        </div>
    );
}
