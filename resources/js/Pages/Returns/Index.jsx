import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Returns index — three layers of filtering, ERP-standard:
 *
 *   1. Top tabs: Active | Resolved | All  (queue mode)
 *   2. Per-status chips (drill-down within the current mode)
 *   3. Free-text search (`q`) on order # / customer name
 *
 * Backend treats no `status` param as "Active" (excludes Restocked +
 * Closed). `?status=resolved` shows only the resolved bucket;
 * `?status=all` shows everything; `?status=<one of the statuses>` shows
 * that lifecycle stage on its own. All counts come from the backend so
 * tab labels stay in sync with the table.
 */

// Queue-mode tabs — the primary "where am I" axis.
const TABS = [
    { value: '',         label: 'Active',   countKey: 'active' },
    { value: 'resolved', label: 'Resolved', countKey: 'resolved' },
    { value: 'all',      label: 'All',      countKey: 'all' },
];

// Per-status drill-down chips, grouped so Active statuses sit together
// and resolved ones are visually separated.
const ACTIVE_STATUSES = ['Pending', 'Received', 'Inspected', 'Damaged'];
const RESOLVED_STATUSES = ['Restocked', 'Closed'];

const subtitleFor = (mode, counts) => {
    const total = counts?.all ?? 0;
    switch (mode) {
        case 'resolved':
            return `Resolved returns · ${counts?.resolved ?? 0} of ${total} total`;
        case 'all':
            return `All returns · ${total}`;
        case 'active':
            return `Active returns · ${counts?.active ?? 0} of ${total} total`;
        default: {
            // status:<Name> view — peel the status name back out
            if (mode?.startsWith('status:')) {
                const s = mode.slice('status:'.length);
                const c = counts?.by_status?.[s] ?? 0;
                return `${s} · ${c} of ${total} total`;
            }
            return `${total} record${total === 1 ? '' : 's'}`;
        }
    }
};

export default function ReturnsIndex({
    returns: returnsList,
    filters,
    counts = { active: 0, resolved: 0, all: 0, by_status: {} },
    view_mode = 'active',
}) {
    const can = useCan();
    const [q, setQ] = useState(filters?.q ?? '');

    const apply = (status) => {
        router.get(
            route('returns.index'),
            { q: q || undefined, status: status || undefined },
            { preserveState: true, replace: true },
        );
    };

    const currentStatus = filters?.status ?? '';
    const isActiveView = view_mode === 'active';

    return (
        <AuthenticatedLayout header="Returns">
            <Head title="Returns" />
            <PageHeader
                title="Returns"
                subtitle={subtitleFor(view_mode, counts)}
                actions={
                    can('returns.create') && (
                        <Link href={route('returns.create')} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">
                            + New return
                        </Link>
                    )
                }
            />

            {/* Search */}
            <div className="mb-3">
                <input
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter') apply(filters?.status); }}
                    placeholder="Search by order # or customer"
                    className="block w-full max-w-md rounded-md border-slate-300 text-sm"
                />
            </div>

            {/* Primary tabs — queue mode */}
            <div className="mb-2 flex flex-wrap items-center gap-1.5 border-b border-slate-200 pb-2">
                {TABS.map((tab) => {
                    const active = currentStatus === tab.value;
                    return (
                        <button
                            key={tab.value || 'active'}
                            onClick={() => apply(tab.value)}
                            className={
                                'rounded-full px-3 py-1 text-xs font-medium transition-colors ' +
                                (active
                                    ? 'bg-slate-900 text-white'
                                    : 'bg-slate-100 text-slate-700 hover:bg-slate-200')
                            }
                            title={
                                tab.value === ''
                                    ? 'Active returns only — excludes Restocked and Closed'
                                    : tab.value === 'resolved'
                                        ? 'Returns that have left the workflow — Restocked or Closed'
                                        : 'All returns regardless of status'
                            }
                        >
                            {tab.label}
                            <span className={'ml-1.5 inline-block rounded-full px-1.5 py-0.5 text-[10px] tabular-nums ' + (active ? 'bg-slate-700 text-slate-100' : 'bg-white text-slate-500')}>
                                {counts?.[tab.countKey] ?? 0}
                            </span>
                        </button>
                    );
                })}
            </div>

            {/* Secondary chips — per-status drill-down */}
            <div className="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs">
                <span className="text-[10px] uppercase tracking-wider text-slate-400">Filter by status</span>
                <div className="flex flex-wrap gap-1.5">
                    {ACTIVE_STATUSES.map((s) => {
                        const active = currentStatus === s;
                        const count = counts?.by_status?.[s] ?? 0;
                        return (
                            <button
                                key={s}
                                onClick={() => apply(s)}
                                className={
                                    'rounded-full border px-2.5 py-0.5 text-xs ' +
                                    (active
                                        ? 'border-slate-900 bg-slate-900 text-white'
                                        : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300')
                                }
                                title={`Return status: ${s}`}
                            >
                                {s} <span className="tabular-nums text-[10px] opacity-75">{count}</span>
                            </button>
                        );
                    })}
                </div>
                <span className="hidden text-slate-300 sm:inline">·</span>
                <div className="flex flex-wrap gap-1.5">
                    {RESOLVED_STATUSES.map((s) => {
                        const active = currentStatus === s;
                        const count = counts?.by_status?.[s] ?? 0;
                        return (
                            <button
                                key={s}
                                onClick={() => apply(s)}
                                className={
                                    'rounded-full border px-2.5 py-0.5 text-xs ' +
                                    (active
                                        ? 'border-slate-900 bg-slate-900 text-white'
                                        : 'border-slate-200 bg-slate-50 text-slate-500 hover:border-slate-300')
                                }
                                title={`Resolved status: ${s}`}
                            >
                                {s} <span className="tabular-nums text-[10px] opacity-75">{count}</span>
                            </button>
                        );
                    })}
                </div>
            </div>

            {/* Helper notice — shown only on the Active queue so the
                operator knows where the missing-from-this-view returns
                went. Disappears on Resolved/All/per-status views. */}
            {isActiveView && counts.resolved > 0 && (
                <div className="mb-3 flex flex-wrap items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                    <span>
                        Showing active returns only. <strong>{counts.resolved}</strong> resolved
                        (<span className="font-mono">Restocked</span> + <span className="font-mono">Closed</span>) are hidden.
                    </span>
                    <button
                        type="button"
                        onClick={() => apply('resolved')}
                        className="font-medium text-indigo-600 hover:underline"
                    >
                        View Resolved →
                    </button>
                </div>
            )}

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">#</th>
                            <th className="px-4 py-2.5">Order</th>
                            <th className="px-4 py-2.5">Customer</th>
                            <th className="px-4 py-2.5">Reason</th>
                            <th className="px-4 py-2.5">Condition</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5 text-right">Refund</th>
                            <th className="px-4 py-2.5">Inspector</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {returnsList.data.length === 0 && (
                            <tr><td colSpan={8} className="px-4 py-12 text-center text-sm text-slate-400">
                                {isActiveView && counts.resolved > 0
                                    ? `No active returns. ${counts.resolved} resolved are under Resolved.`
                                    : 'No returns to show.'}
                            </td></tr>
                        )}
                        {returnsList.data.map((r) => (
                            <tr key={r.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5">
                                    <Link href={route('returns.show', r.id)} className="font-mono text-xs text-slate-700 hover:text-indigo-600">#{r.id}</Link>
                                </td>
                                <td className="px-4 py-2.5 font-mono text-xs">
                                    <Link href={route('orders.show', r.order_id)} className="text-slate-600 hover:text-indigo-600">{r.order?.order_number}</Link>
                                </td>
                                <td className="px-4 py-2.5">{r.order?.customer_name}</td>
                                <td className="px-4 py-2.5 text-slate-600">{r.return_reason?.name}</td>
                                <td className="px-4 py-2.5"><StatusBadge value={r.product_condition} /></td>
                                <td className="px-4 py-2.5"><StatusBadge value={r.return_status} /></td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{Number(r.refund_amount).toFixed(2)}</td>
                                <td className="px-4 py-2.5 text-slate-500">{r.inspected_by?.name ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={returnsList.links} />
        </AuthenticatedLayout>
    );
}
