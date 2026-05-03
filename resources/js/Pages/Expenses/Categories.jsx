import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function ExpenseCategoriesPage({ categories }) {
    const [editingId, setEditingId] = useState(null);
    const create = useForm({ name: '', description: '', status: 'Active' });

    const submit = (e) => { e.preventDefault(); create.post(route('expense-categories.store'), { onSuccess: () => create.reset('name', 'description') }); };
    const remove = (c) => { if (!confirm(`Delete "${c.name}"?`)) return; router.delete(route('expense-categories.destroy', c.id)); };

    return (
        <AuthenticatedLayout header="Expense categories">
            <Head title="Expense categories" />
            <PageHeader title="Expense categories"
                actions={<Link href={route('expenses.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Expenses</Link>}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5 space-y-2">
                    <h2 className="text-sm font-semibold text-slate-700">Add category</h2>
                    <input value={create.data.name} onChange={(e) => create.setData('name', e.target.value)} placeholder="Name" className="block w-full rounded-md border-slate-300 text-sm" />
                    {create.errors.name && <p className="text-xs text-red-600">{create.errors.name}</p>}
                    <textarea value={create.data.description} onChange={(e) => create.setData('description', e.target.value)} placeholder="Description" rows={2} className="block w-full rounded-md border-slate-300 text-sm" />
                    <button type="submit" disabled={create.processing} className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">Add</button>
                </form>

                <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-2.5">Name</th>
                                <th className="px-4 py-2.5">Description</th>
                                <th className="px-4 py-2.5">Status</th>
                                <th className="px-4 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {categories.length === 0 && (
                                <tr><td colSpan={4} className="px-4 py-12 text-center text-sm text-slate-400">No categories yet.</td></tr>
                            )}
                            {categories.map((c) => (
                                <Row key={c.id} category={c} editing={editingId === c.id} onEdit={() => setEditingId(c.id)} onCancel={() => setEditingId(null)} onDelete={() => remove(c)} />
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Row({ category, editing, onEdit, onCancel, onDelete }) {
    const f = useForm({ name: category.name, description: category.description ?? '', status: category.status });
    const save = (e) => { e.preventDefault(); f.put(route('expense-categories.update', category.id), { onSuccess: onCancel }); };

    if (!editing) {
        return (
            <tr className="hover:bg-slate-50">
                <td className="px-4 py-2.5 font-medium text-slate-800">{category.name}</td>
                <td className="px-4 py-2.5 text-slate-600">{category.description ?? '—'}</td>
                <td className="px-4 py-2.5"><StatusBadge value={category.status} /></td>
                <td className="px-4 py-2.5 text-right">
                    <button onClick={onEdit} className="mr-2 text-xs text-indigo-600 hover:underline">Edit</button>
                    <button onClick={onDelete} className="text-xs text-red-600 hover:underline">Delete</button>
                </td>
            </tr>
        );
    }
    return (
        <tr className="bg-slate-50">
            <td className="px-4 py-2.5"><input value={f.data.name} onChange={(e) => f.setData('name', e.target.value)} className="w-full rounded-md border-slate-300 text-sm" /></td>
            <td className="px-4 py-2.5"><input value={f.data.description} onChange={(e) => f.setData('description', e.target.value)} className="w-full rounded-md border-slate-300 text-sm" /></td>
            <td className="px-4 py-2.5">
                <select value={f.data.status} onChange={(e) => f.setData('status', e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option>Active</option><option>Inactive</option>
                </select>
            </td>
            <td className="px-4 py-2.5 text-right">
                <button onClick={save} disabled={f.processing} className="mr-2 text-xs text-indigo-600 hover:underline">Save</button>
                <button onClick={onCancel} className="text-xs text-slate-500 hover:underline">Cancel</button>
            </td>
        </tr>
    );
}
