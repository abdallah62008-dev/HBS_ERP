import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Shared from/to date filter for every report page.
 *
 * Operational presets (Today / Yesterday / This month / Last 30d /
 * This year / Last year) replaced the older rolling-window-only presets
 * (Last 7d / 30d / 90d) because operators reason in calendar units —
 * "what came in this month?" — far more often than in N-day windows.
 *
 * Date math is intentionally **local-time**. The previous version used
 * `new Date().toISOString().slice(0,10)` which is UTC and produced
 * off-by-one results for any user east of GMT (e.g. Cairo, UTC+2 —
 * after midnight UTC the day already turned but the slice still showed
 * the previous day). `formatLocal()` assembles `YYYY-MM-DD` from the
 * browser's local Y/M/D so the operator sees their own calendar day.
 *
 * Prop signature is unchanged: `{ routeName, from, to, extra }`. Every
 * existing caller (12 Reports/* pages + 9 FinanceReports/* pages) keeps
 * working without modification.
 */

/** Format a JS Date as `YYYY-MM-DD` in the browser's local timezone. */
function formatLocal(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

/** Return a new Date offset by `days` (positive or negative) from `base`. */
function addDays(base, days) {
    const d = new Date(base);
    d.setDate(d.getDate() + days);
    return d;
}

/**
 * Compute every preset's `{from, to}` pair as `YYYY-MM-DD` strings.
 * Computed once per render so the active-highlight check can compare
 * strings directly.
 */
function computePresets() {
    const today = new Date();
    const todayStr = formatLocal(today);
    const yesterdayStr = formatLocal(addDays(today, -1));
    const monthStart = formatLocal(new Date(today.getFullYear(), today.getMonth(), 1));
    const last30Start = formatLocal(addDays(today, -29)); // inclusive 30-day window
    const yearStart = formatLocal(new Date(today.getFullYear(), 0, 1));
    const lastYearStart = formatLocal(new Date(today.getFullYear() - 1, 0, 1));
    const lastYearEnd = formatLocal(new Date(today.getFullYear() - 1, 11, 31));

    return [
        { key: 'today', label: 'Today', from: todayStr, to: todayStr },
        { key: 'yesterday', label: 'Yesterday', from: yesterdayStr, to: yesterdayStr },
        { key: 'this-month', label: 'This month', from: monthStart, to: todayStr },
        { key: 'last-30d', label: 'Last 30d', from: last30Start, to: todayStr },
        { key: 'this-year', label: 'This year', from: yearStart, to: todayStr },
        { key: 'last-year', label: 'Last year', from: lastYearStart, to: lastYearEnd },
    ];
}

export default function ReportFilters({ routeName, from, to, extra }) {
    const [f, setF] = useState(from ?? '');
    const [t, setT] = useState(to ?? '');

    const presets = computePresets();
    // A preset is "active" when the *applied* filter (the from/to prop
    // arriving from the server, not the unsubmitted local state) matches
    // exactly. Using the prop here means refreshing the page or coming
    // back via a deep link still highlights the right chip.
    const activeKey = presets.find((p) => p.from === from && p.to === to)?.key;

    const apply = (e) => {
        e?.preventDefault();
        router.get(
            route(routeName),
            { from: f || undefined, to: t || undefined, ...extra },
            { preserveState: true, replace: true },
        );
    };

    const applyPreset = (preset) => {
        setF(preset.from);
        setT(preset.to);
        router.get(
            route(routeName),
            { from: preset.from, to: preset.to, ...extra },
            { preserveState: true, replace: true },
        );
    };

    return (
        <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
            <div>
                <label className="text-[11px] font-medium uppercase text-slate-500">From</label>
                <input
                    type="date"
                    value={f}
                    onChange={(e) => setF(e.target.value)}
                    className="mt-1 rounded-md border-slate-300 text-sm"
                />
            </div>
            <div>
                <label className="text-[11px] font-medium uppercase text-slate-500">To</label>
                <input
                    type="date"
                    value={t}
                    onChange={(e) => setT(e.target.value)}
                    className="mt-1 rounded-md border-slate-300 text-sm"
                />
            </div>
            <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">
                Apply
            </button>

            {/* Preset chips. `flex-wrap` keeps the row tidy on narrower
                screens; on `sm:` and up we push them to the right with
                ml-auto so they sit next to the Apply button. */}
            <div className="flex flex-wrap gap-1 sm:ml-auto">
                {presets.map((p) => {
                    const active = activeKey === p.key;
                    return (
                        <button
                            key={p.key}
                            type="button"
                            onClick={() => applyPreset(p)}
                            className={
                                'rounded-full border px-3 py-1 text-xs transition-colors ' +
                                (active
                                    ? 'border-slate-900 bg-slate-900 text-white'
                                    : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50')
                            }
                            title={`${p.from} → ${p.to}`}
                            aria-pressed={active}
                        >
                            {p.label}
                        </button>
                    );
                })}
            </div>
        </form>
    );
}
