import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';

export default function ImportExportHub({ importers, exporters, recent_imports, recent_exports }) {
    return (
        <AuthenticatedLayout header="Import / Export">
            <Head title="Import / Export" />
            <PageHeader title="Import / Export center" subtitle="Bulk-load Excel data and stream filtered exports." />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Import</h2>
                    <ul className="space-y-2 text-sm">
                        {importers.map((i) => (
                            <li key={i.slug} className="flex items-center justify-between">
                                <div>
                                    <div className="font-medium text-slate-800">{i.label}</div>
                                    <div className="text-xs text-slate-500">{i.headers.join(', ')}</div>
                                </div>
                                <Link href={route('imports.create', { type: i.slug })} className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs hover:bg-slate-50">
                                    Upload →
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <div className="mt-4 flex justify-between text-xs">
                        <Link href={route('imports.index')} className="text-indigo-600 hover:underline">All import history →</Link>
                    </div>
                </div>

                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Export</h2>
                    <ul className="space-y-2 text-sm">
                        {exporters.map((e) => (
                            <li key={e.slug} className="flex items-center justify-between">
                                <div className="font-medium text-slate-800">{e.label}</div>
                                <a href={route('exports.download', { type: e.slug })} className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs hover:bg-slate-50">
                                    Download Excel
                                </a>
                            </li>
                        ))}
                    </ul>
                    <div className="mt-4 flex justify-between text-xs">
                        <Link href={route('exports.index')} className="text-indigo-600 hover:underline">All export history →</Link>
                    </div>
                </div>
            </div>

            <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Card title="Recent imports" empty="No imports yet.">
                    {recent_imports.map((j) => (
                        <li key={j.id} className="flex items-center justify-between border-b border-slate-100 py-2 last:border-0">
                            <div>
                                <Link href={route('imports.show', j.id)} className="text-sm font-medium text-slate-700 hover:text-indigo-600">{j.import_type}</Link>
                                <div className="text-xs text-slate-500">{j.original_file_name} · {j.created_at?.replace('T', ' ').slice(0, 16)}</div>
                            </div>
                            <StatusBadge value={j.status} />
                        </li>
                    ))}
                </Card>

                <Card title="Recent exports" empty="No exports yet.">
                    {recent_exports.map((x) => (
                        <li key={x.id} className="flex items-center justify-between border-b border-slate-100 py-2 last:border-0">
                            <div>
                                <div className="text-sm font-medium text-slate-700">{x.export_type}</div>
                                <div className="text-xs text-slate-500">{x.rows_count} rows · {x.exported_by?.name ?? '—'} · {x.created_at?.replace('T', ' ').slice(0, 16)}</div>
                            </div>
                        </li>
                    ))}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}

function Card({ title, empty, children }) {
    const items = Array.isArray(children) ? children : [children].filter(Boolean);
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-5">
            <h2 className="mb-3 text-sm font-semibold text-slate-700">{title}</h2>
            {items.length === 0 ? (
                <p className="text-sm text-slate-400">{empty}</p>
            ) : (
                <ul>{items}</ul>
            )}
        </div>
    );
}
