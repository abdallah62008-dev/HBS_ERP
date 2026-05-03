import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function fmtMoney(n, sym = '') {
    if (n === null || n === undefined) return '—';
    return `${sym}${Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export default function ProductsIndex({ products, filters, categories }) {
    const can = useCan();
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const [q, setQ] = useState(filters?.q ?? '');
    const [status, setStatus] = useState(filters?.status ?? '');
    const [categoryId, setCategoryId] = useState(filters?.category_id ?? '');

    const submit = (e) => {
        e?.preventDefault();
        router.get(
            route('products.index'),
            { q: q || undefined, status: status || undefined, category_id: categoryId || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AuthenticatedLayout header="Products">
            <Head title="Products" />

            <PageHeader
                title="Products"
                subtitle={`${products.total} record${products.total === 1 ? '' : 's'}`}
                actions={
                    <div className="flex gap-2">
                        <Link
                            href={route('categories.index')}
                            className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50"
                        >
                            Categories
                        </Link>
                        {can('products.create') && (
                            <Link
                                href={route('products.create')}
                                className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
                            >
                                + New product
                            </Link>
                        )}
                    </div>
                }
            />

            <form onSubmit={submit} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div className="flex-1 min-w-[200px]">
                    <label className="text-[11px] font-medium uppercase text-slate-500">Search</label>
                    <input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Name, SKU, barcode…"
                        className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                    />
                </div>
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Status</label>
                    <select value={status} onChange={(e) => setStatus(e.target.value)} className="mt-1 rounded-md border-slate-300 text-sm">
                        <option value="">Any</option>
                        <option>Active</option>
                        <option>Inactive</option>
                        <option>Out of Stock</option>
                        <option>Discontinued</option>
                    </select>
                </div>
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Category</label>
                    <select value={categoryId} onChange={(e) => setCategoryId(e.target.value)} className="mt-1 rounded-md border-slate-300 text-sm">
                        <option value="">Any</option>
                        {categories.map((c) => (
                            <option key={c.id} value={c.id}>{c.name}</option>
                        ))}
                    </select>
                </div>
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">SKU</th>
                            <th className="px-4 py-2.5">Name</th>
                            <th className="px-4 py-2.5">Category</th>
                            <th className="px-4 py-2.5 text-right">Cost</th>
                            <th className="px-4 py-2.5 text-right">Selling</th>
                            <th className="px-4 py-2.5 text-right">Trade</th>
                            <th className="px-4 py-2.5">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {products.data.length === 0 && (
                            <tr><td colSpan={7} className="px-4 py-12 text-center text-sm text-slate-400">No products yet.</td></tr>
                        )}
                        {products.data.map((p) => (
                            <tr key={p.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-mono text-xs text-slate-600">{p.sku}</td>
                                <td className="px-4 py-2.5">
                                    <Link href={route('products.show', p.id)} className="font-medium text-slate-800 hover:text-indigo-600">
                                        {p.name}
                                    </Link>
                                </td>
                                <td className="px-4 py-2.5 text-slate-600">{p.category?.name || '—'}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{fmtMoney(p.cost_price, sym)}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{fmtMoney(p.selling_price, sym)}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{fmtMoney(p.marketer_trade_price, sym)}</td>
                                <td className="px-4 py-2.5"><StatusBadge value={p.status} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={products.links} />
        </AuthenticatedLayout>
    );
}
