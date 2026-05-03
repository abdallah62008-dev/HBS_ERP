import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link } from '@inertiajs/react';

export default function YearEndIndex({ closings, open_year, all_years }) {
    const can = useCan();

    return (
        <AuthenticatedLayout header="Year-end closing">
            <Head title="Year-end closing" />
            <PageHeader title="Year-end closing" subtitle="Lock a fiscal year and open the next one." />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="lg:col-span-2 rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Open year</h2>
                    {open_year ? (
                        <div className="rounded-md border border-emerald-200 bg-emerald-50 p-4">
                            <div className="text-lg font-semibold text-slate-800">{open_year.name}</div>
                            <div className="text-xs text-slate-500">{open_year.start_date} → {open_year.end_date}</div>
                            {can('year_end.manage') && (
                                <Link href={route('year-end.review', open_year.id)} className="mt-3 inline-block rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">
                                    Begin year-end review →
                                </Link>
                            )}
                        </div>
                    ) : (
                        <p className="text-sm text-slate-500">No fiscal year is currently open. Run year-end on the most recent open year first.</p>
                    )}

                    <h3 className="mt-6 mb-2 text-sm font-semibold text-slate-700">All fiscal years</h3>
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-2">Name</th>
                                <th className="px-4 py-2">Period</th>
                                <th className="px-4 py-2">Status</th>
                                <th className="px-4 py-2">Closed at</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {all_years.map((y) => (
                                <tr key={y.id}>
                                    <td className="px-4 py-2">{y.name}</td>
                                    <td className="px-4 py-2 text-slate-500">{y.start_date} → {y.end_date}</td>
                                    <td className="px-4 py-2"><StatusBadge value={y.status} /></td>
                                    <td className="px-4 py-2 text-slate-500">{y.closed_at?.split('T')[0] ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Past closings</h2>
                    {closings.length === 0 ? (
                        <p className="text-xs text-slate-400">No closings yet.</p>
                    ) : (
                        <ul className="space-y-3 text-sm">
                            {closings.map((c) => (
                                <li key={c.id} className="border-b border-slate-100 pb-2 last:border-0">
                                    <div className="flex items-center justify-between">
                                        <span className="font-medium text-slate-800">{c.fiscal_year?.name} → {c.new_fiscal_year?.name ?? '—'}</span>
                                        <StatusBadge value={c.status} />
                                    </div>
                                    <div className="text-xs text-slate-500">
                                        Closed {c.completed_at?.split('T')[0]} by {c.created_by?.name ?? '—'}
                                    </div>
                                    {c.backup && (
                                        <div className="text-[11px] text-slate-400">Backup #{c.backup.id} ({c.backup.size})</div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
