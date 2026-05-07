import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormField from '@/Components/FormField';
import { Head, Link, useForm } from '@inertiajs/react';

export default function TicketsCreate({ statuses, can_manage }) {
    const { data, setData, post, processing, errors } = useForm({
        subject: '',
        message: '',
        status: 'open',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('tickets.store'));
    };

    return (
        <AuthenticatedLayout header="New ticket">
            <Head title="New ticket" />
            <PageHeader title="New ticket" subtitle="File a new support or issue ticket." />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5 space-y-4">
                <FormField label="Subject" name="subject" value={data.subject} onChange={(v) => setData('subject', v)} error={errors.subject} required />

                <FormField label="Message" name="message" error={errors.message} required>
                    <textarea
                        id="message"
                        rows={6}
                        value={data.message}
                        onChange={(e) => setData('message', e.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                        placeholder="Describe the issue in detail."
                    />
                </FormField>

                {can_manage && (
                    <FormField label="Initial status" name="status" error={errors.status} hint="Admin/manager only · defaults to open">
                        <select id="status" value={data.status} onChange={(e) => setData('status', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            {statuses.map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
                        </select>
                    </FormField>
                )}

                <div className="flex justify-end gap-2">
                    <Link href={route('tickets.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {processing ? 'Saving…' : 'Create ticket'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
