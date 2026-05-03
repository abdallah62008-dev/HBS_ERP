import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const STATUSES = ['', 'Assigned', 'Picked Up', 'In Transit', 'Out for Delivery', 'Delivered', 'Returned', 'Delayed', 'Lost'];

export default function ShipmentsIndex({ shipments, filters, companies }) {
    const [q, setQ] = useState(filters?.q ?? '');
    const [status, setStatus] = useState(filters?.status ?? '');
    const [companyId, setCompanyId] = useState(filters?.shipping_company_id ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('shipping.shipments'), {
            q: q || undefined, status: status || undefined, shipping_company_id: companyId || undefined,
        }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Shipments">
            <Head title="Shipments" />
            <PageHeader title="Shipments" subtitle={`${shipments.total} record${shipments.total === 1 ? '' : 's'}`}
                actions={<Link href={route('shipping.dashboard')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Shipping</Link>}
            />

            <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div className="flex-1 min-w-[200px]">
                    <label className="text-[11px] font-medium uppercase text-slate-500">Search</label>
                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Tracking, order #, customer" className="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                </div>
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Status</label>
                    <select value={status} onChange={(e) => setStatus(e.target.value)} className="mt-1 rounded-md border-slate-300 text-sm">
                        {STATUSES.map((s) => <option key={s} value={s}>{s || 'Any'}</option>)}
                    </select>
                </div>
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Company</label>
                    <select value={companyId} onChange={(e) => setCompanyId(e.target.value)} className="mt-1 rounded-md border-slate-300 text-sm">
                        <option value="">Any</option>
                        {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                </div>
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Tracking</th>
                            <th className="px-4 py-2.5">Order</th>
                            <th className="px-4 py-2.5">Customer</th>
                            <th className="px-4 py-2.5">Carrier</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5">Assigned</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {shipments.data.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-12 text-center text-sm text-slate-400">No shipments match.</td></tr>
                        )}
                        {shipments.data.map((s) => (
                            <tr key={s.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-mono text-xs">
                                    <Link href={route('shipping.shipments.show', s.id)} className="text-slate-700 hover:text-indigo-600">{s.tracking_number ?? `#${s.id}`}</Link>
                                </td>
                                <td className="px-4 py-2.5 font-mono text-xs">
                                    <Link href={route('orders.show', s.order_id)} className="text-slate-600 hover:text-indigo-600">{s.order?.order_number}</Link>
                                </td>
                                <td className="px-4 py-2.5">{s.order?.customer_name}</td>
                                <td className="px-4 py-2.5 text-slate-600">{s.shipping_company?.name}</td>
                                <td className="px-4 py-2.5"><StatusBadge value={s.shipping_status} /></td>
                                <td className="px-4 py-2.5 text-slate-500">{s.assigned_at?.replace('T', ' ').slice(0, 16)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={shipments.links} />
        </AuthenticatedLayout>
    );
}
