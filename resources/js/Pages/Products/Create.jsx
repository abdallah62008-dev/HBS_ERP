import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ProductForm from './Form';
import useUnsavedChangesWarning from '@/Hooks/useUnsavedChangesWarning';
import { Head, Link, useForm } from '@inertiajs/react';

export default function ProductCreate({ categories, brands = [], channels = [], marketer_tiers = [] }) {
    const { data, setData, post, processing, errors, isDirty } = useForm({
        name: '',
        sku: '',
        barcode: '',
        category_id: '',
        brand_id: '',
        description: '',
        cost_price: '0',
        selling_price: '0',
        marketer_trade_price: '0',
        minimum_selling_price: '0',
        tax_enabled: false,
        tax_rate: '0',
        reorder_level: '0',
        status: 'Active',
        tier_prices: {},
        // Channel SKUs section is hidden on Create (no variants yet),
        // but ship an empty array so the payload is consistent.
        channel_skus: [],
    });

    useUnsavedChangesWarning(isDirty);

    const submit = (e) => {
        e.preventDefault();
        post(route('products.store'));
    };

    return (
        <AuthenticatedLayout header="New product">
            <Head title="New product" />
            <PageHeader title="New product" />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5">
                <ProductForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    categories={categories}
                    brands={brands}
                    channels={channels}
                    productVariants={[]}
                    marketerTiers={marketer_tiers}
                />

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
