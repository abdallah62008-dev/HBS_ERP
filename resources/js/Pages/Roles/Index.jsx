import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link } from '@inertiajs/react';

export default function RolesIndex({ roles }) {
    return (
        <AuthenticatedLayout header="Roles & Permissions">
            <Head title="Roles & Permissions" />
            <PageHeader title="Roles &amp; Permissions" subtitle="System roles and what they can do" />

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" className="px-4 py-2.5">Role</th>
                            <th scope="col" className="px-4 py-2.5">Slug</th>
                            <th scope="col" className="px-4 py-2.5 text-right">Users</th>
                            <th scope="col" className="px-4 py-2.5 text-right">Permissions</th>
                            <th scope="col" className="px-4 py-2.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {roles.map((r) => (
                            <tr key={r.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5">
                                    <div className="font-medium text-slate-800">{r.name}</div>
                                    {r.description && <div className="text-xs text-slate-500">{r.description}</div>}
                                </td>
                                <td className="px-4 py-2.5 font-mono text-xs text-slate-500">{r.slug}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{r.users_count}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">
                                    {r.slug === 'super-admin' ? <span className="text-xs text-slate-400">(all)</span> : r.permissions_count}
                                </td>
                                <td className="px-4 py-2.5 text-right">
                                    <Link href={route('roles.edit', r.id)} className="text-xs font-medium text-indigo-600 hover:underline">Edit permissions</Link>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
