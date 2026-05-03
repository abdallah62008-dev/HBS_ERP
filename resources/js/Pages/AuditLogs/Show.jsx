import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link } from '@inertiajs/react';

export default function AuditLogShow({ log }) {
    return (
        <AuthenticatedLayout header={`Audit log #${log.id}`}>
            <Head title={`Audit log ${log.id}`} />
            <PageHeader
                title={`${log.action} · ${log.module}`}
                subtitle={`${log.created_at?.replace('T', ' ').slice(0, 19)} · ${log.user?.name ?? 'system'}`}
                actions={<Link href={route('audit-logs.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← All logs</Link>}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Pane title="Old values" data={log.old_values_json} />
                <Pane title="New values" data={log.new_values_json} />
            </div>

            <div className="mt-4 rounded-lg border border-slate-200 bg-white p-5 text-sm space-y-1">
                <div><span className="text-slate-500">Record:</span> <span className="text-slate-800">{log.record_type ?? '—'}{log.record_id ? ` #${log.record_id}` : ''}</span></div>
                <div><span className="text-slate-500">IP:</span> <span className="text-slate-800">{log.ip_address ?? '—'}</span></div>
                <div className="text-xs text-slate-500 break-all"><span className="text-slate-500">User agent:</span> {log.user_agent ?? '—'}</div>
            </div>
        </AuthenticatedLayout>
    );
}

function Pane({ title, data }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-5">
            <h2 className="mb-2 text-sm font-semibold text-slate-700">{title}</h2>
            {data ? (
                <pre className="overflow-x-auto rounded-md bg-slate-900 p-3 text-xs text-slate-100">{JSON.stringify(data, null, 2)}</pre>
            ) : (
                <p className="text-sm text-slate-400">—</p>
            )}
        </div>
    );
}
