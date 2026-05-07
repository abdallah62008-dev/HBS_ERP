import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';

export default function TicketsShow({ ticket, can_edit, can_delete }) {
    const remove = () => {
        if (!confirm(`Delete ticket #${ticket.id}?`)) return;
        router.delete(route('tickets.destroy', ticket.id));
    };

    return (
        <AuthenticatedLayout header={`Ticket #${ticket.id}`}>
            <Head title={`Ticket #${ticket.id}`} />
            <PageHeader
                title={ticket.subject}
                subtitle={<><span className="font-mono text-xs">#{ticket.id}</span> · created {ticket.created_at?.replace('T', ' ').slice(0, 16)}</>}
                actions={
                    <div className="flex gap-2">
                        <Link href={route('tickets.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Back</Link>
                        {can_edit && (
                            <Link href={route('tickets.edit', ticket.id)} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">Edit</Link>
                        )}
                        {can_delete && (
                            <button onClick={remove} className="rounded-md border border-red-300 bg-white px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">Delete</button>
                        )}
                    </div>
                }
            />

            <section className="rounded-lg border border-slate-200 bg-white p-5 space-y-4">
                <div className="flex flex-wrap items-center gap-3 text-sm">
                    <span className="text-xs font-medium uppercase tracking-wide text-slate-500">Status:</span>
                    <StatusBadge value={ticket.status?.replace('_', ' ')} />
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <div className="text-xs font-medium uppercase tracking-wide text-slate-500">Created by</div>
                        <div className="mt-1 text-sm text-slate-800">{ticket.user?.name ?? '—'}</div>
                        <div className="text-xs text-slate-500">{ticket.user?.email}</div>
                    </div>
                    <div>
                        <div className="text-xs font-medium uppercase tracking-wide text-slate-500">Last updated</div>
                        <div className="mt-1 text-sm text-slate-800">{ticket.updated_at?.replace('T', ' ').slice(0, 16)}</div>
                    </div>
                </div>

                <div>
                    <div className="text-xs font-medium uppercase tracking-wide text-slate-500">Message</div>
                    <pre className="mt-1 whitespace-pre-wrap rounded-md bg-slate-50 p-3 text-sm text-slate-800">{ticket.message}</pre>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
