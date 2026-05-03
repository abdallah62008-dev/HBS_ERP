import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function AdsIndex({ campaigns, filters, platforms }) {
    const can = useCan();
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const [q, setQ] = useState(filters?.q ?? '');
    const [platform, setPlatform] = useState(filters?.platform ?? '');
    const [status, setStatus] = useState(filters?.status ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('ads.index'), {
            q: q || undefined, platform: platform || undefined, status: status || undefined,
        }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Ad campaigns">
            <Head title="Ad campaigns" />
            <PageHeader
                title="Ad campaigns"
                subtitle={`${campaigns.total} record${campaigns.total === 1 ? '' : 's'}`}
                actions={can('ads.create') && <Link href={route('ads.create')} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">+ New campaign</Link>}
            />

            <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div className="flex-1 min-w-[200px]">
                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name" className="block w-full rounded-md border-slate-300 text-sm" />
                </div>
                <select value={platform} onChange={(e) => setPlatform(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any platform</option>
                    {platforms.map((p) => <option key={p} value={p}>{p}</option>)}
                </select>
                <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any status</option>
                    <option>Active</option><option>Paused</option><option>Ended</option>
                </select>
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Name</th>
                            <th className="px-4 py-2.5">Platform</th>
                            <th className="px-4 py-2.5">Product</th>
                            <th className="px-4 py-2.5 text-right">Spend</th>
                            <th className="px-4 py-2.5 text-right">Revenue</th>
                            <th className="px-4 py-2.5 text-right">Net</th>
                            <th className="px-4 py-2.5 text-right">ROAS</th>
                            <th className="px-4 py-2.5">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {campaigns.data.length === 0 && (
                            <tr><td colSpan={8} className="px-4 py-12 text-center text-sm text-slate-400">No campaigns yet.</td></tr>
                        )}
                        {campaigns.data.map((c) => (
                            <tr key={c.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5">
                                    <Link href={route('ads.show', c.id)} className="font-medium text-slate-800 hover:text-indigo-600">{c.name}</Link>
                                    <div className="text-xs text-slate-500">{c.start_date} → {c.end_date ?? '—'}</div>
                                </td>
                                <td className="px-4 py-2.5"><span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{c.platform}</span></td>
                                <td className="px-4 py-2.5 text-slate-600">{c.product?.name ?? '—'}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{sym}{fmt(c.spend)}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{sym}{fmt(c.revenue)}</td>
                                <td className={'px-4 py-2.5 text-right tabular-nums ' + (Number(c.net_profit) < 0 ? 'text-red-700' : 'text-emerald-700')}>{sym}{fmt(c.net_profit)}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{Number(c.roas).toFixed(2)}×</td>
                                <td className="px-4 py-2.5"><StatusBadge value={c.status} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={campaigns.links} />
        </AuthenticatedLayout>
    );
}
