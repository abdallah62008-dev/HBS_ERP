import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function ExpensesIndex({ expenses, filters, categories, total_amount }) {
    const can = useCan();
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const [q, setQ] = useState(filters?.q ?? '');
    const [categoryId, setCategoryId] = useState(filters?.category_id ?? '');
    const [from, setFrom] = useState(filters?.from ?? '');
    const [to, setTo] = useState(filters?.to ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('expenses.index'), {
            q: q || undefined, category_id: categoryId || undefined, from: from || undefined, to: to || undefined,
        }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Expenses">
            <Head title="Expenses" />
            <PageHeader
                title="Expenses"
                subtitle={`${expenses.total} record${expenses.total === 1 ? '' : 's'} · total ${sym}${fmt(total_amount)}`}
                actions={
                    <div className="flex gap-2">
                        <Link href={route('expense-categories.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">Categories</Link>
                        {can('expenses.create') && <Link href={route('expenses.create')} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">+ New expense</Link>}
                    </div>
                }
            />

            <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div className="flex-1 min-w-[200px]">
                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Title or notes" className="block w-full rounded-md border-slate-300 text-sm" />
                </div>
                <select value={categoryId} onChange={(e) => setCategoryId(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any category</option>
                    {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
                <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="rounded-md border-slate-300 text-sm" />
                <span className="text-xs text-slate-400">to</span>
                <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="rounded-md border-slate-300 text-sm" />
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Date</th>
                            <th className="px-4 py-2.5">Title</th>
                            <th className="px-4 py-2.5">Category</th>
                            <th className="px-4 py-2.5">Linked</th>
                            <th className="px-4 py-2.5 text-right">Amount</th>
                            <th className="px-4 py-2.5">By</th>
                            <th className="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {expenses.data.length === 0 && (
                            <tr><td colSpan={7} className="px-4 py-12 text-center text-sm text-slate-400">No expenses match.</td></tr>
                        )}
                        {expenses.data.map((e) => (
                            <tr key={e.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 text-slate-500">{e.expense_date}</td>
                                <td className="px-4 py-2.5 text-slate-800">{e.title}</td>
                                <td className="px-4 py-2.5 text-slate-600">{e.category?.name}</td>
                                <td className="px-4 py-2.5 text-slate-500 text-xs">
                                    {e.related_order ? <Link href={route('orders.show', e.related_order.id)} className="hover:underline">order {e.related_order.order_number}</Link> : null}
                                    {e.related_campaign ? <Link href={route('ads.show', e.related_campaign.id)} className="hover:underline">campaign {e.related_campaign.name}</Link> : null}
                                    {!e.related_order && !e.related_campaign && '—'}
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums font-medium">{sym}{fmt(e.amount)}</td>
                                <td className="px-4 py-2.5 text-slate-500">{e.created_by?.name ?? '—'}</td>
                                <td className="px-4 py-2.5 text-right">
                                    {can('expenses.edit') && <Link href={route('expenses.edit', e.id)} className="text-xs text-indigo-600 hover:underline">Edit</Link>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={expenses.links} />
        </AuthenticatedLayout>
    );
}
