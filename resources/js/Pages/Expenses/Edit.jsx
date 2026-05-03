import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ExpenseForm from './Form';
import { Head, Link, useForm } from '@inertiajs/react';

export default function ExpenseEdit({ expense, categories, campaigns }) {
    const { data, setData, put, processing, errors } = useForm({
        title: expense.title,
        expense_category_id: expense.expense_category_id,
        amount: expense.amount,
        currency_code: expense.currency_code,
        expense_date: expense.expense_date?.slice(0, 10),
        payment_method: expense.payment_method ?? '',
        related_campaign_id: expense.related_campaign_id,
        related_order_id: expense.related_order_id,
        notes: expense.notes ?? '',
    });

    const submit = (e) => { e.preventDefault(); put(route('expenses.update', expense.id)); };

    return (
        <AuthenticatedLayout header={`Edit · ${expense.title}`}>
            <Head title={`Edit ${expense.title}`} />
            <PageHeader title={`Edit · ${expense.title}`} />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5">
                <ExpenseForm data={data} setData={setData} errors={errors} categories={categories} campaigns={campaigns} />
                <div className="mt-6 flex justify-end gap-2">
                    <Link href={route('expenses.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {processing ? 'Saving…' : 'Save changes'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
