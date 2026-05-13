import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import PaymentMethodForm from './Form';
import { Head, Link, useForm } from '@inertiajs/react';

export default function PaymentMethodEdit({ paymentMethod, types, cashboxes }) {
    const { data, setData, put, processing, errors } = useForm({
        name: paymentMethod.name ?? '',
        code: paymentMethod.code ?? '',
        type: paymentMethod.type ?? '',
        default_cashbox_id: paymentMethod.default_cashbox_id ?? null,
        is_active: !!paymentMethod.is_active,
        description: paymentMethod.description ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('payment-methods.update', paymentMethod.id));
    };

    return (
        <AuthenticatedLayout header={`Edit ${paymentMethod.name}`}>
            <Head title={`Edit ${paymentMethod.name}`} />
            <PageHeader title={`Edit ${paymentMethod.name}`} />

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
                        {processing ? 'Saving…' : 'Save changes'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
