import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import PurchaseInvoiceForm from './Form';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

export default function PurchaseInvoiceEdit({ invoice, suppliers, warehouses, products }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const { data, setData, put, processing, errors } = useForm({
        invoice_number: invoice.invoice_number,
        supplier_id: invoice.supplier_id,
        warehouse_id: invoice.warehouse_id,
        invoice_date: invoice.invoice_date?.slice(0, 10),
        discount_amount: invoice.discount_amount ?? 0,
        shipping_cost: invoice.shipping_cost ?? 0,
        notes: invoice.notes ?? '',
        items: invoice.items.map((it) => ({
            product_id: it.product_id,
            product_variant_id: it.product_variant_id,
            quantity: it.quantity,
            unit_cost: it.unit_cost,
            discount_amount: it.discount_amount,
            tax_amount: it.tax_amount,
        })),
    });

    const submit = (e) => { e.preventDefault(); put(route('purchase-invoices.update', invoice.id)); };

    return (
        <AuthenticatedLayout header={`Edit ${invoice.invoice_number}`}>
            <Head title={`Edit ${invoice.invoice_number}`} />
            <PageHeader title={`Edit ${invoice.invoice_number}`} subtitle="Draft only — approved invoices need an approval request to edit (Phase 8)." />

            <form onSubmit={submit}>
                <PurchaseInvoiceForm data={data} setData={setData} errors={errors} suppliers={suppliers} warehouses={warehouses} products={products} sym={sym} />

                <div className="mt-5 flex justify-end gap-2">
                    <Link href={route('purchase-invoices.show', invoice.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {processing ? 'Saving…' : 'Save changes'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
