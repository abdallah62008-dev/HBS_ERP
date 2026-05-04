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

export default function OrdersIndex({ orders, filters, statuses }) {
    const can = useCan();
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const [q, setQ] = useState(filters?.q ?? '');

    const apply = (overrides = {}) => {
        router.get(
            route('orders.index'),
            {
                q: overrides.q ?? q ?? undefined,
                status: overrides.status ?? filters?.status ?? undefined,
                risk_level: overrides.risk_level ?? filters?.risk_level ?? undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

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

            <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
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
                            <tr><td colSpan={8} className="px-4 py-12 text-center text-sm text-slate-400">No orders match.</td></tr>
                        )}
                        {orders.data.map((o) => (
                            <tr key={o.id} className="hover:bg-slate-50">
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
