import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function CompaniesIndex({ companies }) {
    const can = useCan();
    const create = useForm({ name: '', contact_name: '', phone: '', email: '', api_enabled: false, status: 'Active' });
    const [editingId, setEditingId] = useState(null);

    const submitCreate = (e) => {
        e.preventDefault();
        create.post(route('shipping-companies.store'), {
            onSuccess: () => create.reset('name', 'contact_name', 'phone', 'email'),
        });
    };

    const handleDelete = (c) => {
        if (!confirm(`Delete company "${c.name}"?`)) return;
        router.delete(route('shipping-companies.destroy', c.id));
    };

    return (
        <AuthenticatedLayout header="Shipping companies">
            <Head title="Shipping companies" />
            <PageHeader title="Shipping companies" subtitle="Carriers and rate cards." />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                {can('shipping.assign') && (
                    <form onSubmit={submitCreate} className="rounded-lg border border-slate-200 bg-white p-5 space-y-2">
                        <h2 className="text-sm font-semibold text-slate-700">Add company</h2>
                        <input value={create.data.name} onChange={(e) => create.setData('name', e.target.value)} placeholder="Name" className="block w-full rounded-md border-slate-300 text-sm" />
                        {create.errors.name && <p className="text-xs text-red-600">{create.errors.name}</p>}
                        <input value={create.data.contact_name} onChange={(e) => create.setData('contact_name', e.target.value)} placeholder="Contact name" className="block w-full rounded-md border-slate-300 text-sm" />
                        <input value={create.data.phone} onChange={(e) => create.setData('phone', e.target.value)} placeholder="Phone" className="block w-full rounded-md border-slate-300 text-sm" />
                        <input value={create.data.email} onChange={(e) => create.setData('email', e.target.value)} placeholder="Email" className="block w-full rounded-md border-slate-300 text-sm" />
                        <button type="submit" disabled={create.processing} className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">Add</button>
                    </form>
                )}

                <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-2.5">Name</th>
                                <th className="px-4 py-2.5">Contact</th>
                                <th className="px-4 py-2.5 text-right">Shipments</th>
                                <th className="px-4 py-2.5 text-right">Rates</th>
                                <th className="px-4 py-2.5">Status</th>
                                <th className="px-4 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {companies.length === 0 && (
                                <tr><td colSpan={6} className="px-4 py-12 text-center text-sm text-slate-400">No companies yet.</td></tr>
                            )}
                            {companies.map((c) => (
                                <Row key={c.id} company={c} editing={editingId === c.id} onEdit={() => setEditingId(c.id)} onCancel={() => setEditingId(null)} onDelete={() => handleDelete(c)} canEdit={can('shipping.assign')} />
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Row({ company, editing, onEdit, onCancel, onDelete, canEdit }) {
    const f = useForm({
        name: company.name,
        contact_name: company.contact_name ?? '',
        phone: company.phone ?? '',
        email: company.email ?? '',
        status: company.status,
        api_enabled: company.api_enabled,
    });

    const save = (e) => {
        e.preventDefault();
        f.put(route('shipping-companies.update', company.id), { onSuccess: onCancel });
    };

    if (!editing) {
        return (
            <tr className="hover:bg-slate-50">
                <td className="px-4 py-2.5 font-medium text-slate-800">{company.name}</td>
                <td className="px-4 py-2.5 text-slate-600">{company.contact_name ?? company.email ?? '—'}</td>
                <td className="px-4 py-2.5 text-right tabular-nums">{company.shipments_count}</td>
                <td className="px-4 py-2.5 text-right">
                    <Link href={route('shipping-companies.rates', company.id)} className="text-xs text-indigo-600 hover:underline">{company.rates_count}</Link>
                </td>
                <td className="px-4 py-2.5"><StatusBadge value={company.status} /></td>
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
            <td className="px-4 py-2.5"><input value={f.data.contact_name} onChange={(e) => f.setData('contact_name', e.target.value)} placeholder="Contact" className="w-full rounded-md border-slate-300 text-sm" /></td>
            <td className="px-4 py-2.5 text-slate-400">—</td>
            <td className="px-4 py-2.5 text-slate-400">—</td>
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
