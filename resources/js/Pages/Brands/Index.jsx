import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Orders & Products P-1 — Brand admin.
 *
 * Single-page CRUD modelled on Pages/Categories/Index.jsx. Operators
 * create brands inline, edit existing rows, and soft-deactivate.
 *
 * Deleting a brand is non-destructive: ON DELETE SET NULL on
 * products.brand_id means the brand simply disappears from products
 * tagged with it. Deactivating (is_active=false) keeps the brand on
 * existing products but removes it from the Product Form dropdown.
 */
export default function BrandsIndex({ brands }) {
    const can = useCan();
    const [editingId, setEditingId] = useState(null);

    const create = useForm({ name: '', description: '', is_active: true, sort_order: 0 });

    const submitCreate = (e) => {
        e.preventDefault();
        create.post(route('brands.store'), {
            onSuccess: () => create.reset('name', 'description'),
        });
    };

    const handleDelete = (brand) => {
        const msg = brand.products_count > 0
            ? `Delete brand "${brand.name}"? ${brand.products_count} product(s) will become brandless (not deleted).`
            : `Delete brand "${brand.name}"?`;
        if (!confirm(msg)) return;
        router.delete(route('brands.destroy', brand.id));
    };

    return (
        <AuthenticatedLayout header="Brands">
            <Head title="Brands" />
            <PageHeader title="Brands" subtitle="Tag products by manufacturer or label for reporting and marketplace organization." />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                {can('products.create') && (
                    <div className="rounded-lg border border-slate-200 bg-white p-5">
                        <h2 className="mb-3 text-sm font-semibold text-slate-700">Add brand</h2>
                        <form onSubmit={submitCreate} className="space-y-3">
                            <div>
                                <label className="mb-1 block text-xs font-medium text-slate-600">Name <span className="text-red-500">*</span></label>
                                <input
                                    type="text"
                                    placeholder="e.g. Apple, Samsung, HBS"
                                    value={create.data.name}
                                    onChange={(e) => create.setData('name', e.target.value)}
                                    className="block w-full rounded-md border-slate-300 text-sm"
                                    maxLength={255}
                                />
                                {create.errors.name && <p className="mt-1 text-xs text-red-600">{create.errors.name}</p>}
                            </div>

                            <div>
                                <label className="mb-1 block text-xs font-medium text-slate-600">Description (optional)</label>
                                <textarea
                                    rows={2}
                                    value={create.data.description}
                                    onChange={(e) => create.setData('description', e.target.value)}
                                    className="block w-full rounded-md border-slate-300 text-sm"
                                />
                                {create.errors.description && <p className="mt-1 text-xs text-red-600">{create.errors.description}</p>}
                            </div>

                            <div className="grid grid-cols-2 gap-2">
                                <label className="flex items-center gap-2 text-xs">
                                    <input
                                        type="checkbox"
                                        checked={create.data.is_active}
                                        onChange={(e) => create.setData('is_active', e.target.checked)}
                                        className="rounded border-slate-300"
                                    />
                                    Active
                                </label>
                                <div>
                                    <label className="mb-1 block text-xs font-medium text-slate-600">Sort</label>
                                    <input
                                        type="number"
                                        min={0}
                                        max={65535}
                                        value={create.data.sort_order}
                                        onChange={(e) => create.setData('sort_order', e.target.value)}
                                        className="block w-full rounded-md border-slate-300 text-sm"
                                    />
                                </div>
                            </div>

                            <button
                                type="submit"
                                disabled={create.processing || !create.data.name.trim()}
                                className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                            >
                                Add brand
                            </button>
                        </form>
                    </div>
                )}

                <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-2.5">Name</th>
                                <th className="px-4 py-2.5">Slug</th>
                                <th className="px-4 py-2.5">Products</th>
                                <th className="px-4 py-2.5">Sort</th>
                                <th className="px-4 py-2.5">Status</th>
                                <th className="px-4 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {brands.length === 0 && (
                                <tr><td colSpan={6} className="px-4 py-12 text-center text-sm text-slate-400">No brands yet. Add one to start tagging products.</td></tr>
                            )}
                            {brands.map((b) => (
                                <BrandRow
                                    key={b.id}
                                    brand={b}
                                    isEditing={editingId === b.id}
                                    onEdit={() => setEditingId(b.id)}
                                    onCancel={() => setEditingId(null)}
                                    onDelete={() => handleDelete(b)}
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

function BrandRow({ brand, isEditing, onEdit, onCancel, onDelete, canEdit, canDelete }) {
    const editForm = useForm({
        name: brand.name,
        description: brand.description ?? '',
        is_active: !!brand.is_active,
        sort_order: brand.sort_order ?? 0,
    });

    const submit = (e) => {
        e.preventDefault();
        editForm.put(route('brands.update', brand.id), {
            onSuccess: () => onCancel(),
        });
    };

    if (!isEditing) {
        return (
            <tr className={`hover:bg-slate-50 ${brand.is_active ? '' : 'opacity-60'}`}>
                <td className="px-4 py-2.5 font-medium text-slate-800">{brand.name}</td>
                <td className="px-4 py-2.5 font-mono text-xs text-slate-500">{brand.slug}</td>
                <td className="px-4 py-2.5 tabular-nums text-slate-600">{brand.products_count}</td>
                <td className="px-4 py-2.5 tabular-nums text-slate-600">{brand.sort_order}</td>
                <td className="px-4 py-2.5">
                    <StatusBadge value={brand.is_active ? 'Active' : 'Inactive'} />
                </td>
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
                {editForm.errors.name && <p className="mt-1 text-xs text-red-600">{editForm.errors.name}</p>}
            </td>
            <td className="px-4 py-2.5 font-mono text-xs text-slate-400">auto</td>
            <td className="px-4 py-2.5 text-slate-400">—</td>
            <td className="px-4 py-2.5">
                <input
                    type="number"
                    min={0}
                    max={65535}
                    value={editForm.data.sort_order}
                    onChange={(e) => editForm.setData('sort_order', e.target.value)}
                    className="w-20 rounded-md border-slate-300 text-sm"
                />
            </td>
            <td className="px-4 py-2.5">
                <label className="flex items-center gap-2 text-xs">
                    <input
                        type="checkbox"
                        checked={editForm.data.is_active}
                        onChange={(e) => editForm.setData('is_active', e.target.checked)}
                        className="rounded border-slate-300"
                    />
                    Active
                </label>
            </td>
            <td className="px-4 py-2.5 text-right">
                <button onClick={submit} disabled={editForm.processing} className="mr-2 text-xs text-indigo-600 hover:underline">Save</button>
                <button onClick={onCancel} className="text-xs text-slate-500 hover:underline">Cancel</button>
            </td>
        </tr>
    );
}
