import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';

export default function CategoriesIndex({ categories }) {
    const can = useCan();
    const [editingId, setEditingId] = useState(null);

    // Create form
    const create = useForm({ name: '', parent_id: '', status: 'Active' });

    const submitCreate = (e) => {
        e.preventDefault();
        create.post(route('categories.store'), {
            onSuccess: () => create.reset('name', 'parent_id'),
        });
    };

    const handleDelete = (cat) => {
        if (!confirm(`Delete category "${cat.name}"? Products will be uncategorised.`)) return;
        router.delete(route('categories.destroy', cat.id));
    };

    return (
        <AuthenticatedLayout header="Categories">
            <Head title="Categories" />
            <PageHeader title="Categories" subtitle="Group products. Phase 2." />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                {/* Create form */}
                {can('products.create') && (
                    <div className="rounded-lg border border-slate-200 bg-white p-5">
                        <h2 className="mb-3 text-sm font-semibold text-slate-700">Add category</h2>
                        <form onSubmit={submitCreate} className="space-y-3">
                            <input
                                type="text"
                                placeholder="Name"
                                value={create.data.name}
                                onChange={(e) => create.setData('name', e.target.value)}
                                className="block w-full rounded-md border-slate-300 text-sm"
                            />
                            {create.errors.name && <p className="text-xs text-red-600">{create.errors.name}</p>}

                            <select
                                value={create.data.parent_id ?? ''}
                                onChange={(e) => create.setData('parent_id', e.target.value || null)}
                                className="block w-full rounded-md border-slate-300 text-sm"
                            >
                                <option value="">— No parent —</option>
                                {categories.filter((c) => !c.parent_id).map((c) => (
                                    <option key={c.id} value={c.id}>{c.name}</option>
                                ))}
                            </select>

                            <select
                                value={create.data.status}
                                onChange={(e) => create.setData('status', e.target.value)}
                                className="block w-full rounded-md border-slate-300 text-sm"
                            >
                                <option>Active</option>
                                <option>Inactive</option>
                            </select>

                            <button
                                type="submit"
                                disabled={create.processing}
                                className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                            >
                                Add
                            </button>
                        </form>
                    </div>
                )}

                {/* Table */}
                <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-2.5">Name</th>
                                <th className="px-4 py-2.5">Parent</th>
                                <th className="px-4 py-2.5">Products</th>
                                <th className="px-4 py-2.5">Status</th>
                                <th className="px-4 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {categories.length === 0 && (
                                <tr><td colSpan={5} className="px-4 py-12 text-center text-sm text-slate-400">No categories yet.</td></tr>
                            )}
                            {categories.map((c) => (
                                <CategoryRow
                                    key={c.id}
                                    category={c}
                                    parents={categories.filter((p) => !p.parent_id && p.id !== c.id)}
                                    isEditing={editingId === c.id}
                                    onEdit={() => setEditingId(c.id)}
                                    onCancel={() => setEditingId(null)}
                                    onDelete={() => handleDelete(c)}
                                    canEdit={can('products.edit')}
                                    canDelete={can('products.delete')}
                                />
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function CategoryRow({ category, parents, isEditing, onEdit, onCancel, onDelete, canEdit, canDelete }) {
    const editForm = useForm({
        name: category.name,
        parent_id: category.parent_id ?? '',
        status: category.status,
    });

    const submit = (e) => {
        e.preventDefault();
        editForm.put(route('categories.update', category.id), {
            onSuccess: () => onCancel(),
        });
    };

    if (!isEditing) {
        return (
            <tr className="hover:bg-slate-50">
                <td className="px-4 py-2.5 font-medium text-slate-800">{category.name}</td>
                <td className="px-4 py-2.5 text-slate-600">{category.parent?.name ?? '—'}</td>
                <td className="px-4 py-2.5 tabular-nums text-slate-600">{category.products_count}</td>
                <td className="px-4 py-2.5"><StatusBadge value={category.status} /></td>
                <td className="px-4 py-2.5 text-right">
                    {canEdit && <button onClick={onEdit} className="mr-2 text-xs text-indigo-600 hover:underline">Edit</button>}
                    {canDelete && <button onClick={onDelete} className="text-xs text-red-600 hover:underline">Delete</button>}
                </td>
            </tr>
        );
    }

    return (
        <tr className="bg-slate-50">
            <td className="px-4 py-2.5">
                <input
                    value={editForm.data.name}
                    onChange={(e) => editForm.setData('name', e.target.value)}
                    className="w-full rounded-md border-slate-300 text-sm"
                />
            </td>
            <td className="px-4 py-2.5">
                <select
                    value={editForm.data.parent_id ?? ''}
                    onChange={(e) => editForm.setData('parent_id', e.target.value || null)}
                    className="w-full rounded-md border-slate-300 text-sm"
                >
                    <option value="">—</option>
                    {parents.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
            </td>
            <td className="px-4 py-2.5 text-slate-400">—</td>
            <td className="px-4 py-2.5">
                <select
                    value={editForm.data.status}
                    onChange={(e) => editForm.setData('status', e.target.value)}
                    className="rounded-md border-slate-300 text-sm"
                >
                    <option>Active</option>
                    <option>Inactive</option>
                </select>
            </td>
            <td className="px-4 py-2.5 text-right">
                <button onClick={submit} disabled={editForm.processing} className="mr-2 text-xs text-indigo-600 hover:underline">Save</button>
                <button onClick={onCancel} className="text-xs text-slate-500 hover:underline">Cancel</button>
            </td>
        </tr>
    );
}
