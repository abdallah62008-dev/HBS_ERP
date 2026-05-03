import FormField from '@/Components/FormField';
import { useMemo } from 'react';

/**
 * Shared form component for purchase-invoice create + edit. Computes
 * subtotal/total live so the operator sees the impact of each line.
 */
export default function PurchaseInvoiceForm({ data, setData, errors, suppliers, warehouses, products, sym = '' }) {
    const updateItem = (idx, key, value) => {
        const next = [...data.items];
        next[idx] = { ...next[idx], [key]: value };
        if (key === 'product_id') {
            const p = products.find((x) => x.id === Number(value));
            if (p && (!next[idx].unit_cost || Number(next[idx].unit_cost) === 0)) {
                next[idx].unit_cost = p.cost_price;
            }
        }
        setData('items', next);
    };

    const addItem = () => setData('items', [...data.items, { product_id: '', quantity: 1, unit_cost: 0, discount_amount: 0, tax_amount: 0 }]);
    const removeItem = (idx) => setData('items', data.items.filter((_, i) => i !== idx));

    const totals = useMemo(() => {
        let sub = 0;
        let tax = 0;
        for (const it of data.items) {
            sub += (Number(it.unit_cost) * Number(it.quantity)) - Number(it.discount_amount || 0);
            tax += Number(it.tax_amount || 0);
        }
        const total = Math.max(0, sub - Number(data.discount_amount || 0) + tax + Number(data.shipping_cost || 0));
        return { sub: sub.toFixed(2), tax: tax.toFixed(2), total: total.toFixed(2) };
    }, [data.items, data.discount_amount, data.shipping_cost]);

    return (
        <div className="space-y-5">
            <section className="rounded-lg border border-slate-200 bg-white p-5">
                <h2 className="mb-3 text-sm font-semibold text-slate-700">Invoice details</h2>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <FormField label="Invoice number" name="invoice_number" value={data.invoice_number} onChange={(v) => setData('invoice_number', v)} error={errors.invoice_number} required hint="From the supplier's paper invoice" />
                    <FormField label="Invoice date" name="invoice_date" type="date" value={data.invoice_date} onChange={(v) => setData('invoice_date', v)} error={errors.invoice_date} required />
                    <FormField label="Supplier" name="supplier_id" error={errors.supplier_id} required>
                        <select id="supplier_id" value={data.supplier_id ?? ''} onChange={(e) => setData('supplier_id', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            <option value="">— Choose supplier —</option>
                            {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Warehouse" name="warehouse_id" error={errors.warehouse_id} required>
                        <select id="warehouse_id" value={data.warehouse_id ?? ''} onChange={(e) => setData('warehouse_id', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            <option value="">— Choose warehouse —</option>
                            {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                        </select>
                    </FormField>
                </div>
            </section>

            <section className="rounded-lg border border-slate-200 bg-white p-5">
                <div className="mb-3 flex items-center justify-between">
                    <h2 className="text-sm font-semibold text-slate-700">Items</h2>
                    <button type="button" onClick={addItem} className="rounded-md border border-slate-300 bg-white px-2 py-1 text-xs">+ Add line</button>
                </div>
                <div className="space-y-2">
                    {data.items.map((it, idx) => (
                        <div key={idx} className="grid grid-cols-12 gap-2">
                            <select value={it.product_id} onChange={(e) => updateItem(idx, 'product_id', e.target.value)} className="col-span-5 rounded-md border-slate-300 text-sm">
                                <option value="">— Pick product —</option>
                                {products.map((p) => <option key={p.id} value={p.id}>{p.sku} — {p.name}</option>)}
                            </select>
                            <input type="number" min={1} value={it.quantity} onChange={(e) => updateItem(idx, 'quantity', e.target.value)} placeholder="Qty" className="col-span-1 rounded-md border-slate-300 text-sm" />
                            <input type="number" step="0.01" min={0} value={it.unit_cost} onChange={(e) => updateItem(idx, 'unit_cost', e.target.value)} placeholder="Unit cost" className="col-span-2 rounded-md border-slate-300 text-sm" />
                            <input type="number" step="0.01" min={0} value={it.discount_amount} onChange={(e) => updateItem(idx, 'discount_amount', e.target.value)} placeholder="Discount" className="col-span-1 rounded-md border-slate-300 text-sm" />
                            <input type="number" step="0.01" min={0} value={it.tax_amount} onChange={(e) => updateItem(idx, 'tax_amount', e.target.value)} placeholder="Tax" className="col-span-1 rounded-md border-slate-300 text-sm" />
                            <div className="col-span-1 self-center text-right text-sm tabular-nums text-slate-600">
                                {sym}{((Number(it.unit_cost) * Number(it.quantity)) - Number(it.discount_amount || 0) + Number(it.tax_amount || 0)).toFixed(2)}
                            </div>
                            <button type="button" onClick={() => removeItem(idx)} disabled={data.items.length === 1} className="col-span-1 self-center text-xs text-red-500 hover:underline">remove</button>
                        </div>
                    ))}
                </div>
                {errors.items && <p className="mt-2 text-xs text-red-600">{errors.items}</p>}
            </section>

            <section className="rounded-lg border border-slate-200 bg-white p-5">
                <h2 className="mb-3 text-sm font-semibold text-slate-700">Adjustments</h2>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <FormField label="Invoice-level discount" name="discount_amount" type="number" value={data.discount_amount} onChange={(v) => setData('discount_amount', v)} error={errors.discount_amount} />
                    <FormField label="Shipping cost" name="shipping_cost" type="number" value={data.shipping_cost} onChange={(v) => setData('shipping_cost', v)} error={errors.shipping_cost} />
                    <FormField label="Notes" name="notes" error={errors.notes} className="sm:col-span-2">
                        <textarea id="notes" rows={2} value={data.notes ?? ''} onChange={(e) => setData('notes', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                    </FormField>
                </div>

                <div className="mt-4 flex items-center justify-end gap-6 text-sm">
                    <div className="text-slate-500">Subtotal: <span className="tabular-nums text-slate-800">{sym}{totals.sub}</span></div>
                    <div className="text-slate-500">Tax: <span className="tabular-nums text-slate-800">{sym}{totals.tax}</span></div>
                    <div className="text-base font-semibold text-slate-800">Total: <span className="tabular-nums">{sym}{totals.total}</span></div>
                </div>
            </section>
        </div>
    );
}
