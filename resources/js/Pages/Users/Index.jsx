import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function UsersIndex({ users, filters, roles }) {
    const { props } = usePage();
    const me = props.auth?.user;
    const [q, setQ] = useState(filters?.q ?? '');
    const [roleId, setRoleId] = useState(filters?.role_id ?? '');
    const [status, setStatus] = useState(filters?.status ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('users.index'), {
            q: q || undefined,
            role_id: roleId || undefined,
            status: status || undefined,
        }, { preserveState: true, replace: true });
    };

    const remove = (u) => {
        if (!confirm(`Delete ${u.email}? This is reversible (soft-delete).`)) return;
        router.delete(route('users.destroy', u.id));
    };

    return (
        <AuthenticatedLayout header="Users">
            <Head title="Users" />
            <PageHeader
                title="Users"
                subtitle={`${users.total} record${users.total === 1 ? '' : 's'}`}
                actions={
                    <Link href={route('users.create')} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">+ New user</Link>
                }
            />

            <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div className="flex-1 min-w-[200px]">
                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name, email, entry code" className="block w-full rounded-md border-slate-300 text-sm" />
                </div>
                <select value={roleId} onChange={(e) => setRoleId(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any role</option>
                    {roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                </select>
                <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any status</option>
                    <option>Active</option>
                    <option>Inactive</option>
                    <option>Suspended</option>
                </select>
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" className="px-4 py-2.5">Name</th>
                            <th scope="col" className="px-4 py-2.5">Email</th>
                            <th scope="col" className="px-4 py-2.5">Role</th>
                            <th scope="col" className="px-4 py-2.5">Entry code</th>
                            <th scope="col" className="px-4 py-2.5">Status</th>
                            <th scope="col" className="px-4 py-2.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {users.data.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-12 text-center text-sm text-slate-400">No users yet.</td></tr>
                        )}
                        {users.data.map((u) => (
                            <tr key={u.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-medium text-slate-800">{u.name}</td>
                                <td className="px-4 py-2.5 text-slate-600">{u.email}</td>
                                <td className="px-4 py-2.5 text-slate-600">{u.role?.name ?? '—'}</td>
                                <td className="px-4 py-2.5 font-mono text-xs">{u.entry_code ?? '—'}</td>
                                <td className="px-4 py-2.5"><StatusBadge value={u.status} /></td>
                                <td className="px-4 py-2.5 text-right">
                                    <Link href={route('users.edit', u.id)} className="text-xs font-medium text-indigo-600 hover:underline">Edit</Link>
                                    {u.id !== me?.id && (
                                        <button onClick={() => remove(u)} className="ml-3 text-xs font-medium text-red-600 hover:underline">Delete</button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={users.links} />
        </AuthenticatedLayout>
    );
}
