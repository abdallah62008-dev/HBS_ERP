import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormField from '@/Components/FormField';
import { Head, Link, useForm } from '@inertiajs/react';

export default function TicketsEdit({ ticket, statuses, can_manage }) {
    const { data, setData, put, processing, errors } = useForm({
        subject: ticket.subject,
        message: ticket.message,
        status: ticket.status,
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('tickets.update', ticket.id));
    };

    return (
        <AuthenticatedLayout header={`Edit Ticket #${ticket.id}`}>
            <Head title={`Edit Ticket #${ticket.id}`} />
            <PageHeader title={`Edit ticket · #${ticket.id}`} subtitle={ticket.user?.name ? `Created by ${ticket.user.name}` : null} />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5 space-y-4">
                <FormField label="Subject" name="subject" value={data.subject} onChange={(v) => setData('subject', v)} error={errors.subject} required />

                <FormField label="Message" name="message" error={errors.message} required>
                    <textarea
                        id="message"
                        rows={6}
                        value={data.message}
                        onChange={(e) => setData('message', e.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                    />
                </FormField>

                {can_manage ? (
                    <FormField label="Status" name="status" error={errors.status}>
                        <select id="status" value={data.status} onChange={(e) => setData('status', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            {statuses.map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
                        </select>
                    </FormField>
                ) : (
                    <div className="rounded-md bg-slate-50 p-3 text-xs text-slate-500">
                        Only admins/managers/customer-service can change ticket status. Current status: <strong className="font-mono">{ticket.status}</strong>
                    </div>
                )}

                <div className="flex justify-end gap-2">
                    <Link href={route('tickets.show', ticket.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {processing ? 'Saving…' : 'Save changes'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
