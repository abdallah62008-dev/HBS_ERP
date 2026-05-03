import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, usePage } from '@inertiajs/react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function MarketerStatement({ marketer, payouts, earned }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    return (
        <AuthenticatedLayout header="My statement">
            <Head title="My statement" />
            <PageHeader
                title="Statement"
                subtitle={`Earned ${sym}${fmt(marketer.wallet?.total_earned)} · Paid ${sym}${fmt(marketer.wallet?.total_paid)} · Balance ${sym}${fmt(marketer.wallet?.balance)}`}
                actions={<a href={route('marketer.statement.export')} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">Download Excel</a>}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div className="rounded-lg border border-slate-200 bg-white">
                    <div className="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">Earned profit (delivered orders)</div>
                    {earned.length === 0 ? (
                        <div className="px-5 py-8 text-center text-sm text-slate-400">No earned profit yet.</div>
                    ) : (
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-5 py-2">Delivered</th>
                                    <th className="px-5 py-2">Order</th>
                                    <th className="px-5 py-2 text-right">Profit</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {earned.map((tx) => (
                                    <tr key={tx.id}>
                                        <td className="px-5 py-2 text-slate-500">{tx.order?.delivered_at?.split('T')[0] ?? '—'}</td>
                                        <td className="px-5 py-2 font-mono text-xs">{tx.order?.order_number}</td>
                                        <td className="px-5 py-2 text-right tabular-nums">{sym}{fmt(tx.net_profit)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                <div className="rounded-lg border border-slate-200 bg-white">
                    <div className="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">Payouts</div>
                    {payouts.length === 0 ? (
                        <div className="px-5 py-8 text-center text-sm text-slate-400">No payouts yet.</div>
                    ) : (
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-5 py-2">Date</th>
                                    <th className="px-5 py-2">Notes</th>
                                    <th className="px-5 py-2 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {payouts.map((p) => (
                                    <tr key={p.id}>
                                        <td className="px-5 py-2 text-slate-500">{p.created_at?.split('T')[0]}</td>
                                        <td className="px-5 py-2 text-slate-600">{p.notes ?? '—'}</td>
                                        <td className="px-5 py-2 text-right tabular-nums">{sym}{fmt(p.net_profit)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
