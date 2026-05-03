import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormField from '@/Components/FormField';
import { Head, Link, useForm } from '@inertiajs/react';

export default function StockCountCreate({ warehouses, products }) {
    const { data, setData, post, processing, errors } = useForm({
        warehouse_id: warehouses[0]?.id ?? '',
        count_date: new Date().toISOString().slice(0, 10),
        notes: '',
        items: [{ product_id: '', counted_quantity: 0, notes: '' }],
    });

    const updateItem = (idx, key, value) => {
        const next = [...data.items];
        next[idx] = { ...next[idx], [key]: value };
        setData('items', next);
    };

    const addItem = () => setData('items', [...data.items, { product_id: '', counted_quantity: 0, notes: '' }]);
    const removeItem = (idx) => setData('items', data.items.filter((_, i) => i !== idx));

    const submit = (e) => { e.preventDefault(); post(route('stock-counts.store')); };

    return (
        <AuthenticatedLayout header="New stock count">
            <Head title="New stock count" />
            <PageHeader title="New stock count" subtitle="Record physical counts. The system fills in current quantities; differences become Stock Count Correction movements once approved." />

            <form onSubmit={submit} className="space-y-5">
                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <FormField label="Warehouse" name="warehouse_id" error={errors.warehouse_id} required>
                            <select id="warehouse_id" value={data.warehouse_id} onChange={(e) => setData('warehouse_id', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                                {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Count date" name="count_date" type="date" value={data.count_date} onChange={(v) => setData('count_date', v)} error={errors.count_date} required />
                        <FormField label="Notes" name="notes" value={data.notes} onChange={(v) => setData('notes', v)} error={errors.notes} />
                    </div>
                </section>

                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-slate-700">Counted products</h2>
                        <button type="button" onClick={addItem} className="rounded-md border border-slate-300 bg-white px-2 py-1 text-xs">+ Add line</button>
                    </div>

                    <div className="space-y-2">
                        {data.items.map((it, idx) => (
                            <div key={idx} className="grid grid-cols-12 gap-2">
                                <select value={it.product_id} onChange={(e) => updateItem(idx, 'product_id', e.target.value)} className="col-span-6 rounded-md border-slate-300 text-sm">
                                    <option value="">— Pick product —</option>
                                    {products.map((p) => <option key={p.id} value={p.id}>{p.sku} — {p.name}</option>)}
                                </select>
                                <input type="number" min={0} value={it.counted_quantity} onChange={(e) => updateItem(idx, 'counted_quantity', e.target.value)} placeholder="Counted" className="col-span-2 rounded-md border-slate-300 text-sm" />
                                <input type="text" value={it.notes ?? ''} onChange={(e) => updateItem(idx, 'notes', e.target.value)} placeholder="Note (optional)" className="col-span-3 rounded-md border-slate-300 text-sm" />
                                <button type="button" onClick={() => removeItem(idx)} disabled={data.items.length === 1} className="col-span-1 self-center text-xs text-red-500 hover:underline">remove</button>
                            </div>
                        ))}
                    </div>
                    {errors.items && <p className="mt-2 text-xs text-red-600">{errors.items}</p>}
                </section>

                <div className="flex justify-end gap-2">
                    <Link href={route('stock-counts.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {processing ? 'Submitting…' : 'Submit count'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
