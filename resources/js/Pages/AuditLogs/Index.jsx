import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function AuditLogsIndex({ logs, filters, modules, actions, users }) {
    const [q, setQ] = useState(filters?.q ?? '');
    const [module, setModule] = useState(filters?.module ?? '');
    const [action, setAction] = useState(filters?.action ?? '');
    const [userId, setUserId] = useState(filters?.user_id ?? '');
    const [from, setFrom] = useState(filters?.from ?? '');
    const [to, setTo] = useState(filters?.to ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('audit-logs.index'), {
            q: q || undefined, module: module || undefined, action: action || undefined,
            user_id: userId || undefined, from: from || undefined, to: to || undefined,
        }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Audit logs">
            <Head title="Audit logs" />
            <PageHeader title="Audit logs" subtitle={`${logs.total} record${logs.total === 1 ? '' : 's'}`} />

            <form onSubmit={apply} className="mb-4 grid grid-cols-1 gap-2 rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-3 lg:grid-cols-6">
                <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search action / module" className="rounded-md border-slate-300 text-sm" />
                <select value={module} onChange={(e) => setModule(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any module</option>
                    {modules.map((m) => <option key={m} value={m}>{m}</option>)}
                </select>
                <select value={action} onChange={(e) => setAction(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any action</option>
                    {actions.map((a) => <option key={a} value={a}>{a}</option>)}
                </select>
                <select value={userId} onChange={(e) => setUserId(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any user</option>
                    {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                </select>
                <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="rounded-md border-slate-300 text-sm" />
                <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="rounded-md border-slate-300 text-sm" />
                <button type="submit" className="sm:col-span-3 lg:col-span-6 rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">When</th>
                            <th className="px-4 py-2.5">User</th>
                            <th className="px-4 py-2.5">Module</th>
                            <th className="px-4 py-2.5">Action</th>
                            <th className="px-4 py-2.5">Record</th>
                            <th className="px-4 py-2.5">IP</th>
                            <th className="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {logs.data.length === 0 && (
                            <tr><td colSpan={7} className="px-4 py-12 text-center text-sm text-slate-400">No audit log entries match.</td></tr>
                        )}
                        {logs.data.map((l) => (
                            <tr key={l.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2 text-slate-500 whitespace-nowrap">{l.created_at?.replace('T', ' ').slice(0, 19)}</td>
                                <td className="px-4 py-2">
                                    <div className="text-slate-700">{l.user?.name ?? <span className="text-slate-400">system</span>}</div>
                                    <div className="text-xs text-slate-500">{l.user?.email}</div>
                                </td>
                                <td className="px-4 py-2 text-slate-600">{l.module}</td>
                                <td className="px-4 py-2"><span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs">{l.action}</span></td>
                                <td className="px-4 py-2 text-xs">
                                    {l.record_type ? `${shortType(l.record_type)}#${l.record_id ?? '—'}` : '—'}
                                </td>
                                <td className="px-4 py-2 text-xs text-slate-500">{l.ip_address ?? '—'}</td>
                                <td className="px-4 py-2 text-right">
                                    <Link href={route('audit-logs.show', l.id)} className="text-xs text-indigo-600 hover:underline">view</Link>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={logs.links} />
        </AuthenticatedLayout>
    );
}

function shortType(t) { return t?.split('\\').pop() ?? t; }
