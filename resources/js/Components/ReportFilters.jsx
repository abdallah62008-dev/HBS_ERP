import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Shared from/to date filter for report pages. `routeName` is the
 * Ziggy route name to call when "Apply" is clicked.
 */
export default function ReportFilters({ routeName, from, to, extra }) {
    const [f, setF] = useState(from ?? '');
    const [t, setT] = useState(to ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route(routeName), { from: f || undefined, to: t || undefined, ...extra }, { preserveState: true, replace: true });
    };

    const preset = (days) => {
        const end = new Date().toISOString().slice(0, 10);
        const start = new Date(Date.now() - days * 86400000).toISOString().slice(0, 10);
        setF(start); setT(end);
        router.get(route(routeName), { from: start, to: end, ...extra }, { preserveState: true, replace: true });
    };

    return (
        <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
            <div>
                <label className="text-[11px] font-medium uppercase text-slate-500">From</label>
                <input type="date" value={f} onChange={(e) => setF(e.target.value)} className="mt-1 rounded-md border-slate-300 text-sm" />
            </div>
            <div>
                <label className="text-[11px] font-medium uppercase text-slate-500">To</label>
                <input type="date" value={t} onChange={(e) => setT(e.target.value)} className="mt-1 rounded-md border-slate-300 text-sm" />
            </div>
            <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            <div className="flex gap-1 ml-auto">
                <button type="button" onClick={() => preset(7)} className="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs">Last 7d</button>
                <button type="button" onClick={() => preset(30)} className="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs">Last 30d</button>
                <button type="button" onClick={() => preset(90)} className="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs">Last 90d</button>
            </div>
        </form>
    );
}
