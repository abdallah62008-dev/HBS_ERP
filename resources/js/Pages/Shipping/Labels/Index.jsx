import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import { Head, Link } from '@inertiajs/react';

export default function LabelsIndex({ labels }) {
    return (
        <AuthenticatedLayout header="Shipping labels">
            <Head title="Shipping labels" />
            <PageHeader title="Printed labels" subtitle={`${labels.total} label${labels.total === 1 ? '' : 's'}`}
                actions={<Link href={route('shipping.dashboard')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Shipping</Link>}
            />

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Printed</th>
                            <th className="px-4 py-2.5">Order</th>
                            <th className="px-4 py-2.5">Tracking</th>
                            <th className="px-4 py-2.5">Size</th>
                            <th className="px-4 py-2.5">By</th>
                            <th className="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {labels.data.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-12 text-center text-sm text-slate-400">No labels printed yet.</td></tr>
                        )}
                        {labels.data.map((l) => (
                            <tr key={l.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 text-slate-500">{l.printed_at?.replace('T', ' ').slice(0, 16)}</td>
                                <td className="px-4 py-2.5 font-mono text-xs">
                                    <Link href={route('orders.show', l.order_id)} className="text-slate-700 hover:text-indigo-600">{l.order?.order_number}</Link>
                                </td>
                                <td className="px-4 py-2.5 font-mono text-xs">{l.tracking_number}</td>
                                <td className="px-4 py-2.5 text-slate-600">{l.label_size}</td>
                                <td className="px-4 py-2.5 text-slate-500">{l.printed_by?.name ?? '—'}</td>
                                <td className="px-4 py-2.5 text-right">
                                    {l.label_pdf_url && (
                                        <a href={l.label_pdf_url} target="_blank" rel="noreferrer" className="text-xs text-indigo-600 hover:underline">view PDF</a>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={labels.links} />
        </AuthenticatedLayout>
    );
}
