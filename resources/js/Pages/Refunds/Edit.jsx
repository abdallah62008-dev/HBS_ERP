import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RefundForm from './Form';
import { Head, Link, useForm } from '@inertiajs/react';

export default function RefundEdit({ refund }) {
    const { data, setData, put, processing, errors } = useForm({
        amount: refund.amount ?? '',
        reason: refund.reason ?? '',
        order_id: refund.order_id ?? null,
        collection_id: refund.collection_id ?? null,
        order_return_id: refund.order_return_id ?? null,
        customer_id: refund.customer_id ?? null,
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('refunds.update', refund.id));
    };

    return (
        <AuthenticatedLayout header={`Edit refund #${refund.id}`}>
            <Head title={`Edit refund #${refund.id}`} />
            <PageHeader
                title={`Edit refund #${refund.id}`}
                subtitle={`Status: ${refund.status} · only requested refunds can be edited`}
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
                        {processing ? 'Saving…' : 'Save changes'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
