import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import CashboxForm from './Form';
import { Head, Link, useForm } from '@inertiajs/react';

export default function CashboxEdit({ cashbox, types }) {
    const { data, setData, put, processing, errors } = useForm({
        name: cashbox.name ?? '',
        type: cashbox.type ?? '',
        allow_negative_balance: !!cashbox.allow_negative_balance,
        is_active: !!cashbox.is_active,
        description: cashbox.description ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('cashboxes.update', cashbox.id));
    };

    return (
        <AuthenticatedLayout header={`Edit ${cashbox.name}`}>
            <Head title={`Edit ${cashbox.name}`} />
            <PageHeader
                title={`Edit ${cashbox.name}`}
                subtitle="Opening balance and currency are immutable after creation."
                actions={
                    <Link
                        href={route('cashboxes.show', cashbox.id)}
                        className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                    >
                        View statement
                    </Link>
                }
            />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5">
                <CashboxForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    types={types}
                    isEdit={true}
                    hasTransactions={cashbox.has_transactions}
                />

                <p className="mt-4 text-xs text-slate-500">
                    Opening balance:{' '}
                    <span className="tabular-nums">
                        {cashbox.currency_code}{' '}
                        {Number(cashbox.opening_balance ?? 0).toLocaleString(undefined, {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2,
                        })}
                    </span>
                </p>

                <div className="mt-6 flex justify-end gap-2">
                    <Link
                        href={route('cashboxes.index')}
                        className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                    >
                        Cancel
                    </Link>
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
