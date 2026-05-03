import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const QUICK = ['', 'New', 'Confirmed', 'Shipped', 'Delivered', 'Returned', 'Cancelled'];

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function MarketerOrders({ orders, filters }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';
    const [q, setQ] = useState(filters?.q ?? '');

    const apply = (status) => {
        router.get(route('marketer.orders'), { q: q || undefined, status: status || undefined }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="My orders">
            <Head title="My orders" />
            <PageHeader title="My orders" subtitle={`${orders.total} order${orders.total === 1 ? '' : 's'}`} />

            <div className="mb-3 flex flex-wrap items-end gap-2">
                <div className="flex-1 min-w-[200px]">
                    <input value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && apply(filters?.status)} placeholder="Search by order # or customer" className="block w-full rounded-md border-slate-300 text-sm" />
                </div>
            </div>
            <div className="mb-4 flex flex-wrap gap-1.5">
                {QUICK.map((s) => (
                    <button key={s} onClick={() => apply(s)} className={'rounded-full border px-3 py-1 text-xs ' + ((filters?.status ?? '') === s ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600')}>
                        {s || 'All'}
                    </button>
                ))}
            </div>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Order</th>
                            <th className="px-4 py-2.5">Customer</th>
                            <th className="px-4 py-2.5">City</th>
                            <th className="px-4 py-2.5 text-right">Total</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5">Created</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {orders.data.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-12 text-center text-sm text-slate-400">No orders match.</td></tr>
                        )}
                        {orders.data.map((o) => (
                            <tr key={o.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-mono text-xs">
                                    <Link href={route('marketer.orders.show', o.id)} className="text-slate-700 hover:text-indigo-600">{o.order_number}</Link>
                                </td>
                                <td className="px-4 py-2.5">{o.customer_name}</td>
                                <td className="px-4 py-2.5 text-slate-500">{o.city}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{sym}{fmt(o.total_amount)}</td>
                                <td className="px-4 py-2.5"><StatusBadge value={o.status} /></td>
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
