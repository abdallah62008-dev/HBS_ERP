import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import { Head, Link } from '@inertiajs/react';

export default function ExportsIndex({ exporters, recent }) {
    return (
        <AuthenticatedLayout header="Export center">
            <Head title="Export center" />
            <PageHeader title="Exports" subtitle={`${recent.total} historical export${recent.total === 1 ? '' : 's'}`}
                actions={<Link href={route('import-export.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Hub</Link>}
            />

            <div className="rounded-lg border border-slate-200 bg-white p-5">
                <h2 className="mb-3 text-sm font-semibold text-slate-700">Available exports</h2>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    {exporters.map((e) => (
                        <a key={e.slug} href={route('exports.download', { type: e.slug })} className="flex items-center justify-between rounded-md border border-slate-200 p-3 hover:border-indigo-400 hover:bg-indigo-50">
                            <div>
                                <div className="text-sm font-medium text-slate-800">{e.label}</div>
                                <div className="text-xs text-slate-500">.xlsx</div>
                            </div>
                            <span className="text-xs text-indigo-600">Download</span>
                        </a>
                    ))}
                </div>
            </div>

            <div className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <div className="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">Export history</div>
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">When</th>
                            <th className="px-4 py-2.5">Type</th>
                            <th className="px-4 py-2.5 text-right">Rows</th>
                            <th className="px-4 py-2.5">By</th>
                            <th className="px-4 py-2.5">IP</th>
                            <th className="px-4 py-2.5">Filters</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {recent.data.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-12 text-center text-sm text-slate-400">No exports yet.</td></tr>
                        )}
                        {recent.data.map((x) => (
                            <tr key={x.id}>
                                <td className="px-4 py-2.5 text-slate-500">{x.created_at?.replace('T', ' ').slice(0, 16)}</td>
                                <td className="px-4 py-2.5 text-slate-700">{x.export_type}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{x.rows_count}</td>
                                <td className="px-4 py-2.5 text-slate-500">{x.exported_by?.name ?? '—'}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{x.ip_address ?? '—'}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500 max-w-xs truncate" title={JSON.stringify(x.filters_json ?? {})}>
                                    {x.filters_json && Object.keys(x.filters_json).length > 0 ? JSON.stringify(x.filters_json) : '—'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={recent.links} />
        </AuthenticatedLayout>
    );
}
