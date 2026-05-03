import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';

export default function ImportsIndex({ jobs }) {
    return (
        <AuthenticatedLayout header="Import history">
            <Head title="Import history" />
            <PageHeader title="Import history" subtitle={`${jobs.total} job${jobs.total === 1 ? '' : 's'}`}
                actions={<Link href={route('import-export.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Hub</Link>}
            />

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">When</th>
                            <th className="px-4 py-2.5">Type</th>
                            <th className="px-4 py-2.5">File</th>
                            <th className="px-4 py-2.5 text-right">Total</th>
                            <th className="px-4 py-2.5 text-right">OK</th>
                            <th className="px-4 py-2.5 text-right">Failed</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5">By</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {jobs.data.length === 0 && (
                            <tr><td colSpan={8} className="px-4 py-12 text-center text-sm text-slate-400">No imports yet.</td></tr>
                        )}
                        {jobs.data.map((j) => (
                            <tr key={j.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 text-slate-500">{j.created_at?.replace('T', ' ').slice(0, 16)}</td>
                                <td className="px-4 py-2.5">
                                    <Link href={route('imports.show', j.id)} className="text-slate-700 hover:text-indigo-600">{j.import_type}</Link>
                                </td>
                                <td className="px-4 py-2.5 text-xs text-slate-500 max-w-xs truncate" title={j.original_file_name}>{j.original_file_name}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{j.total_rows}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums text-emerald-700">{j.successful_rows}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums text-red-700">{j.failed_rows}</td>
                                <td className="px-4 py-2.5"><StatusBadge value={j.status} /></td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{j.created_by?.name ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={jobs.links} />
        </AuthenticatedLayout>
    );
}
