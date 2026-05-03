import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, useForm } from '@inertiajs/react';

function fmtDate(s) { return s?.replace('T', ' ').slice(0, 19); }

export default function BackupsIndex({ backups, last_success }) {
    const can = useCan();
    const run = useForm({ notes: '' });

    const submit = (e) => {
        e.preventDefault();
        if (!confirm('Run a database backup now? This may take a few seconds.')) return;
        run.post(route('backups.run'), { onSuccess: () => run.reset('notes') });
    };

    return (
        <AuthenticatedLayout header="Backups">
            <Head title="Backups" />
            <PageHeader
                title="Backups"
                subtitle={last_success
                    ? `Last successful backup: ${fmtDate(last_success.created_at)} · ${last_success.size}`
                    : 'No successful backup recorded yet.'}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                {can('backup.manage') && (
                    <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5 space-y-3">
                        <h2 className="text-sm font-semibold text-slate-700">Run database backup</h2>
                        <p className="text-xs text-slate-500">
                            Writes a JSON snapshot of every business-data table and zips them into a single archive.
                            Year-end closing requires a successful backup within the last 24 hours.
                        </p>
                        <textarea value={run.data.notes} onChange={(e) => run.setData('notes', e.target.value)} placeholder="Notes (optional)" rows={2} className="block w-full rounded-md border-slate-300 text-sm" />
                        <button type="submit" disabled={run.processing} className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                            {run.processing ? 'Backing up…' : 'Run backup now'}
                        </button>
                    </form>
                )}

                <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-2.5">When</th>
                                <th className="px-4 py-2.5">Type</th>
                                <th className="px-4 py-2.5">Size</th>
                                <th className="px-4 py-2.5">By</th>
                                <th className="px-4 py-2.5">Status</th>
                                <th className="px-4 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {backups.data.length === 0 && (
                                <tr><td colSpan={6} className="px-4 py-12 text-center text-sm text-slate-400">No backups yet.</td></tr>
                            )}
                            {backups.data.map((b) => (
                                <tr key={b.id}>
                                    <td className="px-4 py-2 text-slate-500">{fmtDate(b.created_at)}</td>
                                    <td className="px-4 py-2 text-slate-700">{b.backup_type}</td>
                                    <td className="px-4 py-2 text-slate-600">{b.size ?? '—'}</td>
                                    <td className="px-4 py-2 text-slate-500">{b.created_by?.name ?? '—'}</td>
                                    <td className="px-4 py-2"><StatusBadge value={b.status} tone={b.status === 'Success' ? 'success' : 'danger'} /></td>
                                    <td className="px-4 py-2 text-right">
                                        {b.file_url && b.status === 'Success' && (
                                            <a href={b.file_url} className="text-xs text-indigo-600 hover:underline">download</a>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <Pagination links={backups.links} />
        </AuthenticatedLayout>
    );
}
