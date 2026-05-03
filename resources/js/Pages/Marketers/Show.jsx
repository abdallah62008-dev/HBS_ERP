import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, usePage } from '@inertiajs/react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function MarketerShow({ marketer, recent_transactions }) {
    const can = useCan();
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    return (
        <AuthenticatedLayout header={`Marketer ${marketer.code}`}>
            <Head title={`Marketer ${marketer.code}`} />
            <PageHeader
                title={<>{marketer.user?.name} <span className="ml-2 font-mono text-sm text-slate-500">({marketer.code})</span></>}
                subtitle={`${marketer.user?.email} · ${marketer.price_group?.name}`}
                actions={
                    <div className="flex gap-2">
                        {can('marketers.wallet') && <Link href={route('marketers.wallet', marketer.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">Wallet</Link>}
                        {can('marketers.prices') && <Link href={route('marketers.prices', marketer.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">Prices</Link>}
                        {can('marketers.statement') && <a href={route('marketers.statement', marketer.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">Excel statement</a>}
                        {can('marketers.edit') && <Link href={route('marketers.edit', marketer.id)} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">Edit</Link>}
                    </div>
                }
            />

            <div className="mb-4 flex items-center gap-2">
                <StatusBadge value={marketer.status} />
                <span className="text-xs text-slate-500">Settlement: {marketer.settlement_cycle}</span>
                {marketer.commission_after_delivery_only && <span className="rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-700">Earned-after-delivery</span>}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-4">
                <Stat label="Expected" value={`${sym}${fmt(marketer.wallet?.total_expected)}`} tone="slate" />
                <Stat label="Pending" value={`${sym}${fmt(marketer.wallet?.total_pending)}`} tone="amber" />
                <Stat label="Earned" value={`${sym}${fmt(marketer.wallet?.total_earned)}`} tone="emerald" />
                <Stat label="Balance" value={`${sym}${fmt(marketer.wallet?.balance)}`} tone="indigo" />
            </div>

            <div className="mt-6 rounded-lg border border-slate-200 bg-white">
                <div className="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">Recent transactions</div>
                {recent_transactions.length === 0 ? (
                    <div className="px-5 py-8 text-center text-sm text-slate-400">No transactions yet.</div>
                ) : (
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-5 py-2">When</th>
                                <th className="px-5 py-2">Order</th>
                                <th className="px-5 py-2">Type</th>
                                <th className="px-5 py-2">Status</th>
                                <th className="px-5 py-2 text-right">Net profit</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {recent_transactions.map((tx) => (
                                <tr key={tx.id} className="hover:bg-slate-50">
                                    <td className="px-5 py-2 text-slate-500">{tx.created_at?.replace('T', ' ').slice(0, 16)}</td>
                                    <td className="px-5 py-2 font-mono text-xs">{tx.order?.order_number ?? '—'}</td>
                                    <td className="px-5 py-2 text-slate-700">{tx.transaction_type}</td>
                                    <td className="px-5 py-2"><StatusBadge value={tx.status} /></td>
                                    <td className={'px-5 py-2 text-right tabular-nums ' + (Number(tx.net_profit) < 0 ? 'text-red-700' : 'text-slate-800')}>
                                        {sym}{fmt(tx.net_profit)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function Stat({ label, value, tone }) {
    const palette = { slate: 'border-slate-200', amber: 'border-amber-200 bg-amber-50', emerald: 'border-emerald-200 bg-emerald-50', indigo: 'border-indigo-200 bg-indigo-50' }[tone] ?? 'border-slate-200';
    return (
        <div className={`rounded-lg border bg-white p-5 ${palette}`}>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 text-2xl font-semibold tabular-nums text-slate-800">{value}</div>
        </div>
    );
}
