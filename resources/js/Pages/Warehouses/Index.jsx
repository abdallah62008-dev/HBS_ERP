import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';

export default function WarehousesIndex({ warehouses }) {
    const can = useCan();
    const [editingId, setEditingId] = useState(null);

    const create = useForm({ name: '', location: '', status: 'Active', is_default: false });

    const submitCreate = (e) => {
        e.preventDefault();
        create.post(route('warehouses.store'), {
            onSuccess: () => create.reset('name', 'location', 'is_default'),
        });
    };

    const handleDelete = (w) => {
        if (!confirm(`Delete warehouse "${w.name}"?`)) return;
        router.delete(route('warehouses.destroy', w.id));
    };

    return (
        <AuthenticatedLayout header="Warehouses">
            <Head title="Warehouses" />
            <PageHeader title="Warehouses" subtitle="Stock locations. Phase 3." />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                {can('inventory.adjust') && (
                    <div className="rounded-lg border border-slate-200 bg-white p-5">
                        <h2 className="mb-3 text-sm font-semibold text-slate-700">Add warehouse</h2>
                        <form onSubmit={submitCreate} className="space-y-3">
                            <input value={create.data.name} onChange={(e) => create.setData('name', e.target.value)} placeholder="Name" className="block w-full rounded-md border-slate-300 text-sm" />
                            {create.errors.name && <p className="text-xs text-red-600">{create.errors.name}</p>}

                            <input value={create.data.location} onChange={(e) => create.setData('location', e.target.value)} placeholder="Location" className="block w-full rounded-md border-slate-300 text-sm" />

                            <select value={create.data.status} onChange={(e) => create.setData('status', e.target.value)} className="block w-full rounded-md border-slate-300 text-sm">
                                <option>Active</option>
                                <option>Inactive</option>
                            </select>

                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={create.data.is_default} onChange={(e) => create.setData('is_default', e.target.checked)} className="rounded border-slate-300" />
                                Set as default warehouse
                            </label>

                            <button type="submit" disabled={create.processing} className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                                Add
                            </button>
                        </form>
                    </div>
                )}

                <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-2.5">Name</th>
                                <th className="px-4 py-2.5">Location</th>
                                <th className="px-4 py-2.5">Movements</th>
                                <th className="px-4 py-2.5">Status</th>
                                <th className="px-4 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {warehouses.length === 0 && (
                                <tr><td colSpan={5} className="px-4 py-12 text-center text-sm text-slate-400">No warehouses yet.</td></tr>
                            )}
                            {warehouses.map((w) => (
                                <Row key={w.id} warehouse={w} editing={editingId === w.id} onEdit={() => setEditingId(w.id)} onCancel={() => setEditingId(null)} onDelete={() => handleDelete(w)} canEdit={can('inventory.adjust')} canDelete={can('inventory.adjust')} />
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Row({ warehouse, editing, onEdit, onCancel, onDelete, canEdit, canDelete }) {
    const f = useForm({
        name: warehouse.name,
        location: warehouse.location ?? '',
        status: warehouse.status,
        is_default: warehouse.is_default,
    });

    const save = (e) => {
        e.preventDefault();
        f.put(route('warehouses.update', warehouse.id), { onSuccess: onCancel });
    };

    if (!editing) {
        return (
            <tr className="hover:bg-slate-50">
                <td className="px-4 py-2.5 font-medium text-slate-800">
                    {warehouse.name}
                    {warehouse.is_default && <span className="ml-2 rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] text-indigo-700">DEFAULT</span>}
                </td>
                <td className="px-4 py-2.5 text-slate-600">{warehouse.location ?? '—'}</td>
                <td className="px-4 py-2.5 tabular-nums text-slate-600">{warehouse.movements_count}</td>
                <td className="px-4 py-2.5"><StatusBadge value={warehouse.status} /></td>
                <td className="px-4 py-2.5 text-right">
                    {canEdit && <button onClick={onEdit} className="mr-2 text-xs text-indigo-600 hover:underline">Edit</button>}
                    {canDelete && <button onClick={onDelete} className="text-xs text-red-600 hover:underline">Delete</button>}
                </td>
            </tr>
        );
    }

    return (
        <tr className="bg-slate-50">
            <td className="px-4 py-2.5"><input value={f.data.name} onChange={(e) => f.setData('name', e.target.value)} className="w-full rounded-md border-slate-300 text-sm" /></td>
            <td className="px-4 py-2.5"><input value={f.data.location} onChange={(e) => f.setData('location', e.target.value)} className="w-full rounded-md border-slate-300 text-sm" /></td>
            <td className="px-4 py-2.5 text-slate-400">—</td>
            <td className="px-4 py-2.5">
                <select value={f.data.status} onChange={(e) => f.setData('status', e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option>Active</option>
                    <option>Inactive</option>
                </select>
                <label className="mt-1 flex items-center gap-1 text-xs">
                    <input type="checkbox" checked={f.data.is_default} onChange={(e) => f.setData('is_default', e.target.checked)} className="rounded border-slate-300" />
                    default
                </label>
            </td>
            <td className="px-4 py-2.5 text-right">
                <button onClick={save} disabled={f.processing} className="mr-2 text-xs text-indigo-600 hover:underline">Save</button>
                <button onClick={onCancel} className="text-xs text-slate-500 hover:underline">Cancel</button>
            </td>
        </tr>
    );
}
