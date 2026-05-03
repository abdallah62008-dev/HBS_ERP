import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, usePage } from '@inertiajs/react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function MarketerPortalWallet({ marketer, transactions }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    return (
        <AuthenticatedLayout header="My wallet">
            <Head title="My wallet" />
            <PageHeader
                title="My wallet"
                subtitle="Profit lifecycle: Expected → Pending (when shipped) → Earned (when delivered)"
                actions={<a href={route('marketer.statement.export')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">Excel statement</a>}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-5">
                <Stat label="Expected" value={`${sym}${fmt(marketer.wallet?.total_expected)}`} />
                <Stat label="Pending" value={`${sym}${fmt(marketer.wallet?.total_pending)}`} tone="amber" />
                <Stat label="Earned" value={`${sym}${fmt(marketer.wallet?.total_earned)}`} tone="emerald" />
                <Stat label="Paid" value={`${sym}${fmt(marketer.wallet?.total_paid)}`} />
                <Stat label="Balance" value={`${sym}${fmt(marketer.wallet?.balance)}`} tone="indigo" />
            </div>

            <div className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">When</th>
                            <th className="px-4 py-2.5">Order</th>
                            <th className="px-4 py-2.5">Type</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5 text-right">Net profit</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {transactions.data.length === 0 && (
                            <tr><td colSpan={5} className="px-4 py-12 text-center text-sm text-slate-400">No transactions yet.</td></tr>
                        )}
                        {transactions.data.map((tx) => (
                            <tr key={tx.id}>
                                <td className="px-4 py-2.5 text-slate-500">{tx.created_at?.replace('T', ' ').slice(0, 16)}</td>
                                <td className="px-4 py-2.5 font-mono text-xs">{tx.order?.order_number ?? '—'}</td>
                                <td className="px-4 py-2.5 text-slate-700">{tx.transaction_type}</td>
                                <td className="px-4 py-2.5"><StatusBadge value={tx.status} /></td>
                                <td className={'px-4 py-2.5 text-right tabular-nums ' + (Number(tx.net_profit) < 0 ? 'text-red-700' : 'text-slate-800')}>
                                    {sym}{fmt(tx.net_profit)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={transactions.links} />
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
