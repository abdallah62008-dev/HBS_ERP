import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const QUICK_FILTERS = [
    { label: 'All', value: '' },
    { label: 'New', value: 'New' },
    { label: 'Pending Confirmation', value: 'Pending Confirmation' },
    { label: 'Confirmed', value: 'Confirmed' },
    { label: 'Ready to Ship', value: 'Ready to Ship' },
    { label: 'Shipped', value: 'Shipped' },
    { label: 'Delivered', value: 'Delivered' },
    { label: 'Returned', value: 'Returned' },
    { label: 'Cancelled', value: 'Cancelled' },
];

export default function OrdersIndex({ orders, filters, statuses, filter_customer = null }) {
    const can = useCan();
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const [q, setQ] = useState(filters?.q ?? '');

    // R17 — bulk status transition. `selected` holds the order ids the
    // operator has ticked on the current page. `bulkStatus` is the
    // chosen target status; `bulkSubmitting` disables the submit button
    // while the round-trip runs. We never touch the rows on the server
    // outside of the controller — every change passes through
    // OrderService::changeStatus per-order.
    const [selected, setSelected] = useState([]);
    const [bulkStatus, setBulkStatus] = useState('');
    const [bulkSubmitting, setBulkSubmitting] = useState(false);

    const apply = (overrides = {}) => {
        // Reset the selection whenever the filter / search changes so
        // the action bar doesn't reference ids that may no longer be on
        // screen.
        setSelected([]);
        router.get(
            route('orders.index'),
            {
                q: overrides.q ?? q ?? undefined,
                status: overrides.status ?? filters?.status ?? undefined,
                risk_level: overrides.risk_level ?? filters?.risk_level ?? undefined,
                // C-1: preserve the customer_id filter across quick-filter
                // clicks and searches so the operator's "View Orders"
                // context isn't lost when they click "Delivered" / etc.
                customer_id: overrides.customer_id !== undefined
                    ? overrides.customer_id
                    : (filters?.customer_id ?? undefined),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    // C-1: clear ONLY the customer_id filter while preserving status/q.
    const clearCustomerFilter = () => apply({ customer_id: undefined });

    // R17 helpers.
    const pageOrderIds = orders.data.map((o) => o.id);
    const allOnPageSelected = pageOrderIds.length > 0 && pageOrderIds.every((id) => selected.includes(id));
    const toggleOne = (id) => {
        setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
    };
    const toggleAllOnPage = () => {
        setSelected((s) =>
            allOnPageSelected ? s.filter((id) => !pageOrderIds.includes(id)) : Array.from(new Set([...s, ...pageOrderIds]))
        );
    };
    const submitBulkStatus = () => {
        if (! bulkStatus || selected.length === 0) return;
        setBulkSubmitting(true);
        router.post(
            route('orders.bulk-change-status'),
            { order_ids: selected, status: bulkStatus },
            {
                preserveScroll: true,
                onFinish: () => setBulkSubmitting(false),
                onSuccess: () => { setSelected([]); setBulkStatus(''); },
            },
        );
    };
    const canBulk = can('orders.change_status');

    // R17 — Returned needs a per-order return payload; excluded from
    // bulk. The server also rejects it (defence-in-depth).
    const BULK_TARGETS = (statuses ?? []).filter((s) => s !== 'Returned');

    return (
        <AuthenticatedLayout header="Orders">
            <Head title="Orders" />

            <PageHeader
                title="Orders"
                subtitle={`${orders.total} record${orders.total === 1 ? '' : 's'}`}
                actions={
                    <div className="flex gap-2">
                        {can('orders.export') && (
                            <a
                                href={route('orders.export', { ...filters })}
                                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50"
                            >
                                Export Excel
                            </a>
                        )}
                        {can('orders.create') && (
                            <Link
                                href={route('orders.create')}
                                className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
                            >
                                + New order
                            </Link>
                        )}
                    </div>
                }
            />

            {/* C-1: customer filter pill. Renders only when arriving
                via `?customer_id=`. Clicking the ✕ clears that filter
                while preserving the rest (status, q, etc.). */}
            {filter_customer && (
                <div className="mb-3 inline-flex items-center gap-2 rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs text-indigo-800">
                    <span>
                        Showing orders for{' '}
                        <Link
                            href={route('customers.show', filter_customer.id)}
                            className="font-medium hover:underline"
                        >
                            {filter_customer.name}
                        </Link>
                        {filter_customer.primary_phone && (
                            <span className="ml-1 text-indigo-500">· {filter_customer.primary_phone}</span>
                        )}
                    </span>
                    <button
                        type="button"
                        onClick={clearCustomerFilter}
                        className="ml-1 text-indigo-600 hover:text-indigo-900"
                        aria-label="Clear customer filter"
                    >
                        ✕
                    </button>
                </div>
            )}

            {/* Quick filters */}
            <div className="mb-4 flex flex-wrap gap-1.5">
                {QUICK_FILTERS.map((f) => {
                    const active = (filters?.status ?? '') === f.value;
                    return (
                        <button
                            key={f.value}
                            type="button"
                            onClick={() => apply({ status: f.value || undefined })}
                            className={
                                'rounded-full border px-3 py-1 text-xs ' +
                                (active
                                    ? 'border-slate-900 bg-slate-900 text-white'
                                    : 'border-slate-200 bg-white text-slate-600 hover:border-slate-400')
                            }
                        >
                            {f.label}
                        </button>
                    );
                })}
            </div>

            {/* Search */}
            <form
                onSubmit={(e) => { e.preventDefault(); apply({ q }); }}
                className="mb-4 flex gap-2"
            >
                <input
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder="Search by order #, display # (with -code), external ref, name, or phone…"
                    className="flex-1 rounded-md border-slate-300 text-sm"
                />
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">
                    Search
                </button>
            </form>

            {/* R17 — bulk action bar. Renders only when the user has
                orders.change_status AND at least one row is ticked. */}
            {canBulk && selected.length > 0 && (
                <div className="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3">
                    <span className="text-sm font-medium text-indigo-900">
                        {selected.length} selected
                    </span>
                    <select
                        value={bulkStatus}
                        onChange={(e) => setBulkStatus(e.target.value)}
                        className="rounded-md border-slate-300 text-sm"
                    >
                        <option value="">— change status to —</option>
                        {BULK_TARGETS.map((s) => (
                            <option key={s} value={s}>{s}</option>
                        ))}
                    </select>
                    <button
                        type="button"
                        onClick={submitBulkStatus}
                        disabled={!bulkStatus || bulkSubmitting}
                        className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white disabled:cursor-not-allowed disabled:bg-slate-300"
                    >
                        {bulkSubmitting ? 'Applying…' : 'Apply'}
                    </button>
                    <button
                        type="button"
                        onClick={() => { setSelected([]); setBulkStatus(''); }}
                        className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-50"
                    >
                        Clear
                    </button>
                    <span className="text-[11px] text-indigo-700">
                        Each order is gated individually — DAG, approvals, shipping checklist still apply.
                    </span>
                </div>
            )}

            <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            {canBulk && (
                                <th className="px-4 py-2.5">
                                    <input
                                        type="checkbox"
                                        checked={allOnPageSelected}
                                        onChange={toggleAllOnPage}
                                        aria-label="Select all on this page"
                                        className="rounded border-slate-300"
                                    />
                                </th>
                            )}
                            <th className="px-4 py-2.5">Order</th>
                            <th className="px-4 py-2.5">External ref</th>
                            <th className="px-4 py-2.5">Customer</th>
                            <th className="px-4 py-2.5">City</th>
                            <th className="px-4 py-2.5 text-right">Total</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5">Risk</th>
                            <th className="px-4 py-2.5">Created</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {orders.data.length === 0 && (
                            <tr><td colSpan={canBulk ? 9 : 8} className="px-4 py-12 text-center text-sm text-slate-400">No orders match.</td></tr>
                        )}
                        {orders.data.map((o) => (
                            <tr key={o.id} className="hover:bg-slate-50">
                                {canBulk && (
                                    <td className="px-4 py-2.5">
                                        <input
                                            type="checkbox"
                                            checked={selected.includes(o.id)}
                                            onChange={() => toggleOne(o.id)}
                                            aria-label={`Select order ${o.display_order_number ?? o.order_number}`}
                                            className="rounded border-slate-300"
                                        />
                                    </td>
                                )}
                                <td className="px-4 py-2.5">
                                    <Link href={route('orders.show', o.id)} className="font-mono text-xs font-medium text-slate-700 hover:text-indigo-600">
                                        {o.display_order_number ?? o.order_number}
                                    </Link>
                                    {o.duplicate_score >= 50 && (
                                        <span className="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-800" title="Possible duplicate">
                                            DUP {o.duplicate_score}
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-2.5 font-mono text-xs text-slate-500">
                                    {o.external_order_reference || <span className="text-slate-300">—</span>}
                                </td>
                                <td className="px-4 py-2.5">
                                    <div className="font-medium text-slate-800">{o.customer_name}</div>
                                    <div className="text-xs text-slate-500">{o.customer_phone}</div>
                                </td>
                                <td className="px-4 py-2.5 text-slate-600">{o.city}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{sym}{Number(o.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                                <td className="px-4 py-2.5"><StatusBadge value={o.status} /></td>
                                <td className="px-4 py-2.5"><StatusBadge value={o.customer_risk_level} /></td>
                                <td className="px-4 py-2.5 text-slate-500">{o.created_at?.split('T')[0]}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={orders.links} />
        </AuthenticatedLayout>
    );
}
