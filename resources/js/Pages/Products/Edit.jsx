import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ProductForm from './Form';
import { Head, Link, useForm } from '@inertiajs/react';

export default function ProductEdit({ product, categories, marketer_tiers = [], tier_prices = {} }) {
    const { data, setData, put, processing, errors } = useForm({
        name: product.name ?? '',
        sku: product.sku ?? '',
        barcode: product.barcode ?? '',
        category_id: product.category_id ?? '',
        description: product.description ?? '',
        cost_price: product.cost_price ?? '0',
        selling_price: product.selling_price ?? '0',
        marketer_trade_price: product.marketer_trade_price ?? '0',
        minimum_selling_price: product.minimum_selling_price ?? '0',
        tax_enabled: !!product.tax_enabled,
        tax_rate: product.tax_rate ?? '0',
        reorder_level: product.reorder_level ?? 0,
        status: product.status ?? 'Active',
        price_change_reason: '',
        tier_prices: tier_prices ?? {},
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('products.update', product.id));
    };

    return (
        <AuthenticatedLayout header={`Edit · ${product.name}`}>
            <Head title={`Edit ${product.name}`} />
            <PageHeader title={`Edit ${product.name}`} subtitle={`SKU ${product.sku}`} />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5">
                <ProductForm data={data} setData={setData} errors={errors} categories={categories} marketerTiers={marketer_tiers} isEdit />

                <div className="mt-6 flex items-center justify-end gap-2">
                    <Link href={route('products.show', product.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
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
