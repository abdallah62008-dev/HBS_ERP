import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function CustomersIndex({ customers, filters }) {
    const can = useCan();
    const [q, setQ] = useState(filters?.q ?? '');
    const [riskLevel, setRiskLevel] = useState(filters?.risk_level ?? '');
    const [type, setType] = useState(filters?.customer_type ?? '');

    const submitFilters = (e) => {
        e?.preventDefault();
        router.get(
            route('customers.index'),
            { q: q || undefined, risk_level: riskLevel || undefined, customer_type: type || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const clearFilters = () => {
        setQ('');
        setRiskLevel('');
        setType('');
        router.get(route('customers.index'), {}, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header="Customers">
            <Head title="Customers" />

            <PageHeader
                title="Customers"
                subtitle={`${customers.total} record${customers.total === 1 ? '' : 's'}`}
                actions={
                    can('customers.create') && (
                        <Link
                            href={route('customers.create')}
                            className="inline-flex items-center rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
                        >
                            + New customer
                        </Link>
                    )
                }
            />

            {/* Filter bar */}
            <form onSubmit={submitFilters} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div className="flex-1 min-w-[180px]">
                    <label className="text-[11px] font-medium uppercase text-slate-500">Search</label>
                    <input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Name, phone, email…"
                        className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                    />
                </div>
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Risk</label>
                    <select
                        value={riskLevel}
                        onChange={(e) => setRiskLevel(e.target.value)}
                        className="mt-1 rounded-md border-slate-300 text-sm"
                    >
                        <option value="">Any</option>
                        <option>Low</option>
                        <option>Medium</option>
                        <option>High</option>
                    </select>
                </div>
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Type</label>
                    <select
                        value={type}
                        onChange={(e) => setType(e.target.value)}
                        className="mt-1 rounded-md border-slate-300 text-sm"
                    >
                        <option value="">Any</option>
                        <option>Normal</option>
                        <option>VIP</option>
                        <option>Watchlist</option>
                        <option>Blacklist</option>
                    </select>
                </div>
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">
                    Apply
                </button>
                <button type="button" onClick={clearFilters} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
                    Reset
                </button>
            </form>

            {/* Table */}
            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Name</th>
                            <th className="px-4 py-2.5">Phone</th>
                            <th className="px-4 py-2.5">City</th>
                            <th className="px-4 py-2.5">Type</th>
                            <th className="px-4 py-2.5">Risk</th>
                            <th className="px-4 py-2.5 text-right">Orders</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {customers.data.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-12 text-center text-sm text-slate-400">
                                    No customers match the current filters.
                                </td>
                            </tr>
                        )}
                        {customers.data.map((c) => (
                            <tr key={c.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5">
                                    <Link href={route('customers.show', c.id)} className="font-medium text-slate-800 hover:text-indigo-600">
                                        {c.name}
                                    </Link>
                                </td>
                                <td className="px-4 py-2.5 text-slate-600">{c.primary_phone}</td>
                                <td className="px-4 py-2.5 text-slate-600">
                                    {c.city}
                                    {c.governorate ? `, ${c.governorate}` : ''}
                                </td>
                                <td className="px-4 py-2.5">
                                    <StatusBadge value={c.customer_type} />
                                </td>
                                <td className="px-4 py-2.5">
                                    <StatusBadge value={c.risk_level} /> <span className="text-xs text-slate-400">({c.risk_score})</span>
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums text-slate-700">{c.orders_count}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={customers.links} />
        </AuthenticatedLayout>
    );
}
