import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import PurchaseInvoiceForm from './Form';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

export default function PurchaseInvoiceCreate({ suppliers, warehouses, products }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const { data, setData, post, processing, errors } = useForm({
        invoice_number: '',
        supplier_id: '',
        warehouse_id: warehouses[0]?.id ?? '',
        invoice_date: new Date().toISOString().slice(0, 10),
        discount_amount: 0,
        shipping_cost: 0,
        notes: '',
        items: [{ product_id: '', quantity: 1, unit_cost: 0, discount_amount: 0, tax_amount: 0 }],
    });

    const submit = (e) => { e.preventDefault(); post(route('purchase-invoices.store')); };

    return (
        <AuthenticatedLayout header="New purchase invoice">
            <Head title="New purchase invoice" />
            <PageHeader title="New purchase invoice" subtitle="Saves as Draft until approved. Approval creates Purchase inventory movements." />

            <form onSubmit={submit}>
                <PurchaseInvoiceForm data={data} setData={setData} errors={errors} suppliers={suppliers} warehouses={warehouses} products={products} sym={sym} />

                <div className="mt-5 flex justify-end gap-2">
                    <Link href={route('purchase-invoices.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {processing ? 'Saving…' : 'Save as Draft'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
