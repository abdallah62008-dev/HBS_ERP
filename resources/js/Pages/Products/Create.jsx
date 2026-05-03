import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ProductForm from './Form';
import { Head, Link, useForm } from '@inertiajs/react';

export default function ProductCreate({ categories }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        sku: '',
        barcode: '',
        category_id: '',
        description: '',
        cost_price: '0',
        selling_price: '0',
        marketer_trade_price: '0',
        minimum_selling_price: '0',
        tax_enabled: false,
        tax_rate: '0',
        reorder_level: '0',
        status: 'Active',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('products.store'));
    };

    return (
        <AuthenticatedLayout header="New product">
            <Head title="New product" />
            <PageHeader title="New product" />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5">
                <ProductForm data={data} setData={setData} errors={errors} categories={categories} />

                <div className="mt-6 flex items-center justify-end gap-2">
                    <Link href={route('products.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                    >
                        {processing ? 'Saving…' : 'Create product'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
