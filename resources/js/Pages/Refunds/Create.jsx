import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RefundForm from './Form';
import { Head, Link, useForm } from '@inertiajs/react';

export default function RefundCreate() {
    const { data, setData, post, processing, errors } = useForm({
        amount: '',
        reason: '',
        order_id: null,
        collection_id: null,
        order_return_id: null,
        customer_id: null,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('refunds.store'));
    };

    return (
        <AuthenticatedLayout header="New refund request">
            <Head title="New refund request" />
            <PageHeader
                title="New refund request"
                subtitle="Status starts as 'requested'. Approval and rejection are separate explicit actions."
            />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5">
                <RefundForm data={data} setData={setData} errors={errors} />
                <div className="mt-6 flex justify-end gap-2">
                    <Link href={route('refunds.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                    >
                        {processing ? 'Saving…' : 'Submit refund request'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
