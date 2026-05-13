import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import PaymentMethodForm from './Form';
import { Head, Link, useForm } from '@inertiajs/react';

export default function PaymentMethodCreate({ types, cashboxes }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        code: '',
        type: '',
        default_cashbox_id: null,
        is_active: true,
        description: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('payment-methods.store'));
    };

    return (
        <AuthenticatedLayout header="New payment method">
            <Head title="New payment method" />
            <PageHeader title="New payment method" />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5">
                <PaymentMethodForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    types={types}
                    cashboxes={cashboxes}
                />
                <div className="mt-6 flex justify-end gap-2">
                    <Link href={route('payment-methods.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                    >
                        {processing ? 'Creating…' : 'Create payment method'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
