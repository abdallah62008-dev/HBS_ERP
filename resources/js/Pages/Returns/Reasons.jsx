import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function ReturnReasonsPage({ reasons }) {
    const can = useCan();
    const [editingId, setEditingId] = useState(null);
    const create = useForm({ name: '', description: '', status: 'Active' });

    const submitCreate = (e) => {
        e.preventDefault();
        create.post(route('return-reasons.store'), { onSuccess: () => create.reset('name', 'description') });
    };

    const remove = (r) => {
        if (!confirm(`Delete reason "${r.name}"?`)) return;
        router.delete(route('return-reasons.destroy', r.id));
    };

    return (
        <AuthenticatedLayout header="Return reasons">
            <Head title="Return reasons" />
            <PageHeader title="Return reasons" subtitle="Categories used when opening a return."
                actions={<Link href={route('returns.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Returns</Link>}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                {can('returns.approve') && (
                    <form onSubmit={submitCreate} className="rounded-lg border border-slate-200 bg-white p-5 space-y-2">
                        <h2 className="text-sm font-semibold text-slate-700">Add reason</h2>
                        <input value={create.data.name} onChange={(e) => create.setData('name', e.target.value)} placeholder="Name" className="block w-full rounded-md border-slate-300 text-sm" />
                        {create.errors.name && <p className="text-xs text-red-600">{create.errors.name}</p>}
                        <textarea value={create.data.description} onChange={(e) => create.setData('description', e.target.value)} placeholder="Description" rows={2} className="block w-full rounded-md border-slate-300 text-sm" />
                        <button type="submit" disabled={create.processing} className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">Add</button>
                    </form>
                )}

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
                            {reasons.length === 0 && (
                                <tr><td colSpan={4} className="px-4 py-12 text-center text-sm text-slate-400">No reasons yet.</td></tr>
                            )}
                            {reasons.map((r) => (
                                <Row key={r.id} reason={r} editing={editingId === r.id} onEdit={() => setEditingId(r.id)} onCancel={() => setEditingId(null)} onDelete={() => remove(r)} canEdit={can('returns.approve')} />
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Row({ reason, editing, onEdit, onCancel, onDelete, canEdit }) {
    const f = useForm({ name: reason.name, description: reason.description ?? '', status: reason.status });
    const save = (e) => { e.preventDefault(); f.put(route('return-reasons.update', reason.id), { onSuccess: onCancel }); };

    if (!editing) {
        return (
            <tr className="hover:bg-slate-50">
                <td className="px-4 py-2.5 font-medium text-slate-800">{reason.name}</td>
                <td className="px-4 py-2.5 text-slate-600">{reason.description ?? '—'}</td>
                <td className="px-4 py-2.5"><StatusBadge value={reason.status} /></td>
                <td className="px-4 py-2.5 text-right">
                    {canEdit && <button onClick={onEdit} className="mr-2 text-xs text-indigo-600 hover:underline">Edit</button>}
                    {canEdit && <button onClick={onDelete} className="text-xs text-red-600 hover:underline">Delete</button>}
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
                    <option>Active</option>
                    <option>Inactive</option>
                </select>
            </td>
            <td className="px-4 py-2.5 text-right">
                <button onClick={save} disabled={f.processing} className="mr-2 text-xs text-indigo-600 hover:underline">Save</button>
                <button onClick={onCancel} className="text-xs text-slate-500 hover:underline">Cancel</button>
            </td>
        </tr>
    );
}
