import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router } from '@inertiajs/react';

export default function ReadyToPack({ orders }) {
    const can = useCan();

    const markPacked = (order) => {
        if (!confirm(`Mark order ${order.order_number} as Packed?`)) return;
        router.post(route('shipping.mark-packed', order.id));
    };

    return (
        <AuthenticatedLayout header="Ready to pack">
            <Head title="Ready to pack" />
            <PageHeader
                title="Ready to pack"
                subtitle={`${orders.total} confirmed order${orders.total === 1 ? '' : 's'} waiting for warehouse pick + pack`}
                actions={<Link href={route('shipping.dashboard')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Shipping</Link>}
            />

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Order</th>
                            <th className="px-4 py-2.5">Customer</th>
                            <th className="px-4 py-2.5">City</th>
                            <th className="px-4 py-2.5 text-right">Items</th>
                            <th className="px-4 py-2.5">Risk</th>
                            <th className="px-4 py-2.5 text-right"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {orders.data.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-12 text-center text-sm text-slate-400">All confirmed orders are already packed.</td></tr>
                        )}
                        {orders.data.map((o) => (
                            <tr key={o.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-mono text-xs">
                                    <Link href={route('orders.show', o.id)} className="font-medium text-slate-700 hover:text-indigo-600">{o.order_number}</Link>
                                </td>
                                <td className="px-4 py-2.5">
                                    <div className="font-medium text-slate-800">{o.customer_name}</div>
                                    <div className="text-xs text-slate-500">{o.customer_phone}</div>
                                </td>
                                <td className="px-4 py-2.5 text-slate-600">{o.city}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{o.items?.length ?? 0}</td>
                                <td className="px-4 py-2.5"><StatusBadge value={o.customer_risk_level} /></td>
                                <td className="px-4 py-2.5 text-right">
                                    {can('orders.change_status') && (
                                        <button onClick={() => markPacked(o)} className="rounded-md bg-slate-900 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-slate-700">
                                            Mark Packed
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={orders.links} />
        </AuthenticatedLayout>
    );
}
