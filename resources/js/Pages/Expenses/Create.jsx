import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ExpenseForm from './Form';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

export default function ExpenseCreate({ categories, campaigns, cashboxes, payment_methods }) {
    const { props } = usePage();
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        expense_category_id: categories[0]?.id ?? '',
        amount: '',
        currency_code: props.app?.currency_code ?? 'EGP',
        expense_date: new Date().toISOString().slice(0, 10),
        // Phase 4 — required.
        payment_method_id: '',
        cashbox_id: '',
        related_campaign_id: null,
        related_order_id: null,
        notes: '',
    });

    const submit = (e) => { e.preventDefault(); post(route('expenses.store')); };

    return (
        <AuthenticatedLayout header="New expense">
            <Head title="New expense" />
            <PageHeader
                title="New expense"
                subtitle="Creating an expense posts it to the chosen cashbox immediately (one OUT transaction)."
            />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5">
                <ExpenseForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    categories={categories}
                    campaigns={campaigns}
                    cashboxes={cashboxes}
                    payment_methods={payment_methods}
                    isEdit={false}
                />
                <div className="mt-6 flex justify-end gap-2">
                    <Link href={route('expenses.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {processing ? 'Saving…' : 'Record & post expense'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
