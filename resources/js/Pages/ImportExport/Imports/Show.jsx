import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';

export default function ImportShow({ job, rows }) {
    const ready = job.status === 'Ready';
    const completed = job.status === 'Completed';
    const undone = job.status === 'Undone';
    const failed = job.status === 'Failed';

    const commit = () => {
        if (!confirm(`Commit ${job.successful_rows} record(s)? Failed and Duplicate rows will be skipped.`)) return;
        router.post(route('imports.commit', job.id));
    };

    const undo = () => {
        if (!confirm('Reverse this import? Created records will be deleted.')) return;
        router.post(route('imports.undo', job.id));
    };

    return (
        <AuthenticatedLayout header={`Import #${job.id}`}>
            <Head title={`Import ${job.id}`} />
            <PageHeader
                title={`${job.import_type} · ${job.original_file_name}`}
                subtitle={`Uploaded ${job.created_at?.replace('T', ' ').slice(0, 16)} by ${job.created_by?.name ?? '—'}`}
                actions={
                    <div className="flex gap-2">
                        <Link href={route('imports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← History</Link>
                        {ready && (
                            <button onClick={commit} className="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-500">
                                Commit {job.successful_rows} record(s)
                            </button>
                        )}
                        {completed && job.can_undo && (
                            <button onClick={undo} className="rounded-md border border-red-200 bg-white px-3 py-2 text-sm text-red-600 hover:bg-red-50">
                                Undo import
                            </button>
                        )}
                    </div>
                }
            />

            <div className="mb-4 flex flex-wrap items-center gap-3">
                <StatusBadge value={job.status} />
                <span className="text-xs text-slate-500">
                    {job.total_rows} total · {job.successful_rows} ok · {job.failed_rows} failed · {job.duplicate_rows} duplicate
                </span>
                {undone && job.undone_at && (
                    <span className="text-xs text-amber-700">Undone {job.undone_at.split('T')[0]} by {job.undone_by?.name}</span>
                )}
            </div>

            {failed && (
                <div className="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                    The import did not complete. Fix the file and re-upload.
                </div>
            )}

            <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Row</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5">Data</th>
                            <th className="px-4 py-2.5">Error / record</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.data.length === 0 && (
                            <tr><td colSpan={4} className="px-4 py-12 text-center text-sm text-slate-400">No rows.</td></tr>
                        )}
                        {rows.data.map((r) => (
                            <tr key={r.id} className={r.status === 'Failed' ? 'bg-red-50/40' : r.status === 'Duplicate' ? 'bg-amber-50/40' : ''}>
                                <td className="px-4 py-2 text-slate-500">{r.row_number}</td>
                                <td className="px-4 py-2"><StatusBadge value={r.status} tone={r.status === 'Success' ? 'success' : r.status === 'Duplicate' ? 'warning' : r.status === 'Failed' ? 'danger' : 'neutral'} /></td>
                                <td className="px-4 py-2 max-w-md truncate text-xs font-mono text-slate-600" title={JSON.stringify(r.raw_data_json)}>
                                    {JSON.stringify(r.raw_data_json)?.slice(0, 80)}{JSON.stringify(r.raw_data_json)?.length > 80 ? '…' : ''}
                                </td>
                                <td className="px-4 py-2 text-xs">
                                    {r.error_message && <span className="text-red-700">{r.error_message}</span>}
                                    {r.created_record_type && (
                                        <span className="text-slate-500">
                                            → {r.created_record_type.split('\\').pop()}#{r.created_record_id}
                                        </span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={rows.links} />
        </AuthenticatedLayout>
    );
}
