import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function SuppliersIndex({ suppliers, filters }) {
    const can = useCan();
    const [q, setQ] = useState(filters?.q ?? '');

    const submit = (e) => {
        e.preventDefault();
        router.get(route('suppliers.index'), { q: q || undefined }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Suppliers">
            <Head title="Suppliers" />
            <PageHeader
                title="Suppliers"
                subtitle={`${suppliers.total} record${suppliers.total === 1 ? '' : 's'}`}
                actions={
                    can('suppliers.create') && (
                        <Link href={route('suppliers.create')} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">
                            + New supplier
                        </Link>
                    )
                }
            />

            <form onSubmit={submit} className="mb-4 flex gap-2">
                <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search by name, phone, email…" className="flex-1 rounded-md border-slate-300 text-sm" />
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Search</button>
            </form>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Name</th>
                            <th className="px-4 py-2.5">Phone</th>
                            <th className="px-4 py-2.5">City</th>
                            <th className="px-4 py-2.5 text-right">Products</th>
                            <th className="px-4 py-2.5 text-right">Invoices</th>
                            <th className="px-4 py-2.5">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {suppliers.data.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-12 text-center text-sm text-slate-400">No suppliers yet.</td></tr>
                        )}
                        {suppliers.data.map((s) => (
                            <tr key={s.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5">
                                    <Link href={route('suppliers.show', s.id)} className="font-medium text-slate-800 hover:text-indigo-600">{s.name}</Link>
                                </td>
                                <td className="px-4 py-2.5 text-slate-600">{s.phone ?? '—'}</td>
                                <td className="px-4 py-2.5 text-slate-600">{s.city ?? '—'}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{s.products_count}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{s.purchase_invoices_count}</td>
                                <td className="px-4 py-2.5"><StatusBadge value={s.status} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={suppliers.links} />
        </AuthenticatedLayout>
    );
}
