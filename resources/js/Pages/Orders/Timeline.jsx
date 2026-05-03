import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';

export default function OrderTimeline({ order }) {
    const events = (order.status_history ?? []).map((h) => ({
        kind: 'status',
        when: h.created_at,
        from: h.old_status,
        to: h.new_status,
        by: h.changed_by?.name,
        notes: h.notes,
    }));

    return (
        <AuthenticatedLayout header={`Timeline · ${order.order_number}`}>
            <Head title={`Timeline ${order.order_number}`} />

            <PageHeader
                title={<span className="font-mono">{order.order_number}</span>}
                subtitle="Status history and notes"
                actions={
                    <Link href={route('orders.show', order.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">
                        ← Back to order
                    </Link>
                }
            />

            <div className="rounded-lg border border-slate-200 bg-white p-5">
                {events.length === 0 ? (
                    <p className="text-sm text-slate-400">No status changes recorded yet.</p>
                ) : (
                    <ol className="relative space-y-5 border-l-2 border-slate-200 pl-5">
                        {events.map((e, i) => (
                            <li key={i} className="relative">
                                <span className="absolute -left-[27px] top-1 h-4 w-4 rounded-full border-2 border-white bg-indigo-500"></span>
                                <div className="flex flex-wrap items-baseline gap-2">
                                    <StatusBadge value={e.to} />
                                    {e.from && <span className="text-xs text-slate-400">from {e.from}</span>}
                                    <span className="text-xs text-slate-400">·</span>
                                    <span className="text-xs text-slate-500">{new Date(e.when).toLocaleString()}</span>
                                </div>
                                {e.by && <div className="mt-1 text-xs text-slate-500">by {e.by}</div>}
                                {e.notes && <p className="mt-1 text-sm text-slate-700">{e.notes}</p>}
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
