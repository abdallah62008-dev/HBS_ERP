import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function StockForecast({ lookback_days, rows }) {
    const [days, setDays] = useState(lookback_days);

    const apply = (e) => {
        e.preventDefault();
        router.get(route('reports.stock-forecast'), { lookback: days }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Stock forecast">
            <Head title="Stock forecast" />
            <PageHeader
                title="Stock forecast"
                subtitle={`Based on the last ${lookback_days} days of shipments`}
                actions={<Link href={route('reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Reports</Link>}
            />

            <form onSubmit={apply} className="mb-4 flex items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Look back N days</label>
                    <input type="number" value={days} onChange={(e) => setDays(e.target.value)} min={1} max={365} className="mt-1 block w-32 rounded-md border-slate-300 text-sm" />
                </div>
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">SKU</th>
                            <th className="px-4 py-2.5">Product</th>
                            <th className="px-4 py-2.5 text-right">Available</th>
                            <th className="px-4 py-2.5 text-right">Daily burn</th>
                            <th className="px-4 py-2.5 text-right">Days left</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.length === 0 && (
                            <tr><td colSpan={5} className="px-4 py-12 text-center text-sm text-slate-400">No shipping activity in window.</td></tr>
                        )}
                        {rows.map((r) => (
                            <tr key={r.id} className={r.days_left !== null && r.days_left < 7 ? 'bg-amber-50/60' : ''}>
                                <td className="px-4 py-2 font-mono text-xs">{r.sku}</td>
                                <td className="px-4 py-2 text-slate-800">{r.name}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{r.available}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{r.daily_burn}</td>
                                <td className={'px-4 py-2 text-right tabular-nums font-medium ' + (r.days_left !== null && r.days_left < 7 ? 'text-amber-700' : 'text-slate-700')}>
                                    {r.days_left ?? '—'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
