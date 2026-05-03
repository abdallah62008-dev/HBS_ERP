import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormField from '@/Components/FormField';
import { Head, Link, useForm } from '@inertiajs/react';

export default function StockAdjustmentCreate({ warehouses, products }) {
    const { data, setData, post, processing, errors } = useForm({
        warehouse_id: warehouses[0]?.id ?? '',
        product_id: '',
        product_variant_id: null,
        new_quantity: 0,
        reason: '',
    });

    const submit = (e) => { e.preventDefault(); post(route('stock-adjustments.store')); };

    return (
        <AuthenticatedLayout header="New stock adjustment">
            <Head title="New adjustment" />
            <PageHeader title="Request a stock adjustment" subtitle="Will be applied to inventory once another team member approves." />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5 space-y-4">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <FormField label="Warehouse" name="warehouse_id" error={errors.warehouse_id} required>
                        <select id="warehouse_id" value={data.warehouse_id} onChange={(e) => setData('warehouse_id', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Product" name="product_id" error={errors.product_id} required>
                        <select id="product_id" value={data.product_id} onChange={(e) => setData('product_id', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            <option value="">— Pick product —</option>
                            {products.map((p) => <option key={p.id} value={p.id}>{p.sku} — {p.name}</option>)}
                        </select>
                    </FormField>
                    <FormField label="New on-hand quantity" name="new_quantity" type="number" value={data.new_quantity} onChange={(v) => setData('new_quantity', v)} error={errors.new_quantity} hint="The system computes the difference vs. current on-hand" required />
                </div>

                <FormField label="Reason" name="reason" error={errors.reason} required>
                    <textarea id="reason" rows={3} value={data.reason} onChange={(e) => setData('reason', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm" placeholder="e.g. Damaged stock found during routine check" />
                </FormField>

                <div className="flex justify-end gap-2">
                    <Link href={route('stock-adjustments.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {processing ? 'Submitting…' : 'Submit for approval'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
