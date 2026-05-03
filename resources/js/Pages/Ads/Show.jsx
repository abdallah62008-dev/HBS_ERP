import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, usePage } from '@inertiajs/react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function CampaignShow({ campaign }) {
    const can = useCan();
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const recompute = () => router.post(route('ads.rollup', campaign.id));
    const remove = () => { if (!confirm(`Delete campaign "${campaign.name}"?`)) return; router.delete(route('ads.destroy', campaign.id)); };

    return (
        <AuthenticatedLayout header={campaign.name}>
            <Head title={campaign.name} />
            <PageHeader
                title={campaign.name}
                subtitle={`${campaign.platform} · ${campaign.start_date} → ${campaign.end_date ?? 'ongoing'}`}
                actions={
                    <div className="flex gap-2">
                        <button onClick={recompute} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">Recompute</button>
                        {can('ads.edit') && <Link href={route('ads.edit', campaign.id)} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">Edit</Link>}
                        {can('ads.delete') && <button onClick={remove} className="rounded-md border border-red-200 bg-white px-3 py-2 text-sm text-red-600 hover:bg-red-50">Delete</button>}
                    </div>
                }
            />

            <div className="mb-4 flex items-center gap-2">
                <StatusBadge value={campaign.status} />
                {campaign.product && <span className="text-xs text-slate-500">Product: {campaign.product.name} ({campaign.product.sku})</span>}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-4">
                <Stat label="Spend" value={`${sym}${fmt(campaign.spend)}`} />
                <Stat label="Revenue" value={`${sym}${fmt(campaign.revenue)}`} tone="emerald" />
                <Stat label="Net profit" value={`${sym}${fmt(campaign.net_profit)}`} tone={Number(campaign.net_profit) < 0 ? 'red' : 'emerald'} />
                <Stat label="ROAS" value={`${Number(campaign.roas).toFixed(2)}×`} tone="indigo" />
            </div>

            <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Stat label="Orders" value={campaign.orders_count} />
                <Stat label="Delivered" value={campaign.delivered_orders_count} tone="emerald" />
                <Stat label="Returned" value={campaign.returned_orders_count} tone="red" />
            </div>

            {/* Linked expenses */}
            <div className="mt-6 rounded-lg border border-slate-200 bg-white">
                <div className="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">Linked expenses</div>
                {campaign.expenses?.length === 0 ? (
                    <div className="px-5 py-8 text-center text-sm text-slate-400">No expenses linked. Add expenses with this campaign in the related-campaign field.</div>
                ) : (
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-5 py-2">Date</th>
                                <th className="px-5 py-2">Title</th>
                                <th className="px-5 py-2">Category</th>
                                <th className="px-5 py-2 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {(campaign.expenses ?? []).map((e) => (
                                <tr key={e.id}>
                                    <td className="px-5 py-2 text-slate-500">{e.expense_date}</td>
                                    <td className="px-5 py-2">{e.title}</td>
                                    <td className="px-5 py-2 text-slate-500">{e.category?.name}</td>
                                    <td className="px-5 py-2 text-right tabular-nums">{sym}{fmt(e.amount)}</td>
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
    const palette = { emerald: 'border-emerald-200 bg-emerald-50', red: 'border-red-200 bg-red-50', indigo: 'border-indigo-200 bg-indigo-50' }[tone] ?? 'border-slate-200 bg-white';
    return (
        <div className={`rounded-lg border p-5 ${palette}`}>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 text-2xl font-semibold tabular-nums text-slate-800">{value}</div>
        </div>
    );
}
