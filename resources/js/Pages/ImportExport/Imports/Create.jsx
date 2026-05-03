import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export default function ImportCreate({ type, importers }) {
    const current = useMemo(() => importers.find((i) => i.slug === type) ?? importers[0], [type, importers]);
    const [picked, setPicked] = useState(current.slug);
    const upload = useForm({ type: picked, file: null });

    const submit = (e) => {
        e.preventDefault();
        upload.post(route('imports.upload'), { forceFormData: true });
    };

    const importer = importers.find((i) => i.slug === picked) ?? current;

    return (
        <AuthenticatedLayout header="New import">
            <Head title="New import" />
            <PageHeader
                title={`Import — ${importer.label}`}
                subtitle="Upload an Excel file. Step 1: preview. Step 2: commit. Step 3 (if eligible): undo."
                actions={<Link href={route('import-export.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Hub</Link>}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <form onSubmit={submit} className="lg:col-span-2 rounded-lg border border-slate-200 bg-white p-5 space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-slate-700">Import type</label>
                        <select
                            value={picked}
                            onChange={(e) => { setPicked(e.target.value); upload.setData('type', e.target.value); }}
                            className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                        >
                            {importers.map((i) => <option key={i.slug} value={i.slug}>{i.label}</option>)}
                        </select>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-slate-700">Excel file</label>
                        <input
                            type="file"
                            accept=".xlsx,.xls,.csv"
                            onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)}
                            className="mt-1 block w-full text-sm"
                        />
                        {upload.errors.file && <p className="mt-1 text-xs text-red-600">{upload.errors.file}</p>}
                    </div>

                    <div className="flex justify-end gap-2">
                        <Link href={route('import-export.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                        <button type="submit" disabled={!upload.data.file || upload.processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                            {upload.processing ? 'Uploading…' : 'Upload + preview'}
                        </button>
                    </div>
                </form>

                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-2 text-sm font-semibold text-slate-700">Expected columns</h2>
                    <ul className="space-y-1 text-xs">
                        {importer.headers.map((h) => (
                            <li key={h} className="flex items-start gap-2">
                                <span className="font-mono text-slate-700">{h}</span>
                                {importer.header_notes?.[h] && <span className="text-slate-500">— {importer.header_notes[h]}</span>}
                            </li>
                        ))}
                    </ul>
                    {importer.header_notes?.['*'] && <p className="mt-2 text-xs text-slate-500">{importer.header_notes['*']}</p>}
                    {!importer.can_undo && (
                        <p className="mt-3 rounded border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800">
                            ⚠ This import type cannot be undone after committing. Preview carefully.
                        </p>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
