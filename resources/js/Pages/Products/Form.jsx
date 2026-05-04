import FormField from '@/Components/FormField';

export default function ProductForm({ data, setData, errors, categories, marketerTiers = [], isEdit = false }) {
    const updateTierCell = (code, key, value) => {
        const next = { ...(data.tier_prices ?? {}) };
        next[code] = { ...(next[code] ?? {}), [key]: value };
        setData('tier_prices', next);
    };
    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField label="Name" name="name" value={data.name} onChange={(v) => setData('name', v)} error={errors.name} required />

            <FormField
                label="SKU"
                name="sku"
                value={data.sku}
                onChange={(v) => setData('sku', v)}
                error={errors.sku}
                required
                hint="Must be unique across products and variants"
            />

            <FormField label="Barcode" name="barcode" value={data.barcode} onChange={(v) => setData('barcode', v)} error={errors.barcode} />

            <FormField label="Category" name="category_id" error={errors.category_id}>
                <select
                    id="category_id"
                    value={data.category_id ?? ''}
                    onChange={(e) => setData('category_id', e.target.value || null)}
                    className="mt-1 block w-full rounded-md border-slate-300 shadow-sm sm:text-sm"
                >
                    <option value="">— None —</option>
                    {categories.map((c) => (
                        <option key={c.id} value={c.id}>{c.name}</option>
                    ))}
                </select>
            </FormField>

            <FormField label="Description" name="description" error={errors.description} className="sm:col-span-2">
                <textarea
                    id="description"
                    rows={2}
                    value={data.description ?? ''}
                    onChange={(e) => setData('description', e.target.value)}
                    className="mt-1 block w-full rounded-md border-slate-300 shadow-sm sm:text-sm"
                />
            </FormField>

            <div className="sm:col-span-2 grid grid-cols-2 gap-4 rounded-md border border-slate-200 bg-slate-50 p-4 md:grid-cols-4">
                <FormField label="Cost price" name="cost_price" type="number" value={data.cost_price} onChange={(v) => setData('cost_price', v)} error={errors.cost_price} required />
                <FormField label="Selling price" name="selling_price" type="number" value={data.selling_price} onChange={(v) => setData('selling_price', v)} error={errors.selling_price} required />
                <FormField label="Trade price" name="marketer_trade_price" type="number" value={data.marketer_trade_price} onChange={(v) => setData('marketer_trade_price', v)} error={errors.marketer_trade_price} hint="Marketer pays this" />
                <FormField label="Min selling" name="minimum_selling_price" type="number" value={data.minimum_selling_price} onChange={(v) => setData('minimum_selling_price', v)} error={errors.minimum_selling_price} hint="Profit guard floor" />
            </div>

            <div className="sm:col-span-2 grid grid-cols-2 gap-4 md:grid-cols-4">
                <FormField label="Tax enabled" name="tax_enabled" error={errors.tax_enabled}>
                    <select
                        id="tax_enabled"
                        value={data.tax_enabled ? '1' : '0'}
                        onChange={(e) => setData('tax_enabled', e.target.value === '1')}
                        className="mt-1 block w-full rounded-md border-slate-300 shadow-sm sm:text-sm"
                    >
                        <option value="0">No</option>
                        <option value="1">Yes</option>
                    </select>
                </FormField>
                <FormField label="Tax rate (%)" name="tax_rate" type="number" value={data.tax_rate} onChange={(v) => setData('tax_rate', v)} error={errors.tax_rate} />
                <FormField label="Reorder level" name="reorder_level" type="number" value={data.reorder_level} onChange={(v) => setData('reorder_level', v)} error={errors.reorder_level} />
                <FormField label="Status" name="status" error={errors.status}>
                    <select
                        id="status"
                        value={data.status ?? 'Active'}
                        onChange={(e) => setData('status', e.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 shadow-sm sm:text-sm"
                    >
                        <option>Active</option>
                        <option>Inactive</option>
                        <option>Out of Stock</option>
                        <option>Discontinued</option>
                    </select>
                </FormField>
            </div>

            {/* Phase 5.6 — Marketer pricing tiers */}
            {marketerTiers.length > 0 && (
                <div className="sm:col-span-2 rounded-md border border-slate-200 bg-white p-4">
                    <div className="mb-2 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-slate-700">Marketer pricing tiers</h3>
                        <span className="text-[11px] text-slate-400">Optional · saved per (product, tier)</span>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-xs">
                            <thead className="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-3 py-2 text-left">Tier</th>
                                    <th className="px-3 py-2 text-right">Marketer cost price</th>
                                    <th className="px-3 py-2 text-right">Shipping cost</th>
                                    <th className="px-3 py-2 text-right">VAT %</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {marketerTiers.map((tier) => {
                                    const row = (data.tier_prices ?? {})[tier.code] ?? {};
                                    return (
                                        <tr key={tier.code}>
                                            <td className="px-3 py-1.5 font-medium text-slate-700">{tier.name}</td>
                                            <td className="px-3 py-1.5 text-right">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min={0}
                                                    value={row.marketer_cost_price ?? ''}
                                                    onChange={(e) => updateTierCell(tier.code, 'marketer_cost_price', e.target.value)}
                                                    className="w-28 rounded-md border-slate-300 text-right text-xs tabular-nums"
                                                    aria-label={`${tier.name} marketer cost price`}
                                                />
                                                {errors[`tier_prices.${tier.code}.marketer_cost_price`] && (
                                                    <p className="mt-0.5 text-[10px] text-red-600">
                                                        {errors[`tier_prices.${tier.code}.marketer_cost_price`]}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="px-3 py-1.5 text-right">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min={0}
                                                    value={row.shipping_cost ?? ''}
                                                    onChange={(e) => updateTierCell(tier.code, 'shipping_cost', e.target.value)}
                                                    className="w-28 rounded-md border-slate-300 text-right text-xs tabular-nums"
                                                    aria-label={`${tier.name} shipping cost`}
                                                />
                                                {errors[`tier_prices.${tier.code}.shipping_cost`] && (
                                                    <p className="mt-0.5 text-[10px] text-red-600">
                                                        {errors[`tier_prices.${tier.code}.shipping_cost`]}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="px-3 py-1.5 text-right">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min={0}
                                                    max={100}
                                                    value={row.vat_percent ?? ''}
                                                    onChange={(e) => updateTierCell(tier.code, 'vat_percent', e.target.value)}
                                                    className="w-20 rounded-md border-slate-300 text-right text-xs tabular-nums"
                                                    aria-label={`${tier.name} VAT percent`}
                                                />
                                                {errors[`tier_prices.${tier.code}.vat_percent`] && (
                                                    <p className="mt-0.5 text-[10px] text-red-600">
                                                        {errors[`tier_prices.${tier.code}.vat_percent`]}
                                                    </p>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <p className="mt-2 text-[11px] text-slate-400">
                        Leave a row empty to skip it. Clearing all three cells on a saved row removes the per-tier entry.
                    </p>
                </div>
            )}

            {isEdit && (
                <FormField
                    label="Reason for price change"
                    name="price_change_reason"
                    value={data.price_change_reason}
                    onChange={(v) => setData('price_change_reason', v)}
                    error={errors.price_change_reason}
                    hint="Required when any price field changes — written to price history"
                    className="sm:col-span-2"
                />
            )}
        </div>
    );
}
