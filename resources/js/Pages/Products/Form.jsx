import FormField from '@/Components/FormField';
import useCan from '@/Hooks/useCan';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';

const VAT_DEFAULT = 14;

const formatMoney = (n) => {
    if (n === null || n === undefined || Number.isNaN(n)) return '—';
    return Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const computeExpectedProfit = (sellingPrice, row) => {
    const sp = parseFloat(sellingPrice);
    if (!Number.isFinite(sp) || sp <= 0) return null;
    const cost = parseFloat(row.marketer_cost_price);
    const shipping = parseFloat(row.shipping_cost);
    // VAT defaults to 14 when blank — matches backend syncTierPrices().
    const vatRaw = row.vat_percent;
    const vat = vatRaw === '' || vatRaw === null || vatRaw === undefined
        ? VAT_DEFAULT
        : parseFloat(vatRaw);
    const hasAny = [
        row.marketer_cost_price,
        row.shipping_cost,
        row.collection_cost,
        row.return_cost,
    ].some((v) => v !== '' && v !== null && v !== undefined);
    if (!hasAny) return null;
    const vatAmount = sp * ((Number.isFinite(vat) ? vat : 0) / 100);
    return sp
        - vatAmount
        - (Number.isFinite(cost) ? cost : 0)
        - (Number.isFinite(shipping) ? shipping : 0);
};

export default function ProductForm({ data, setData, errors, categories, marketerTiers = [], isEdit = false }) {
    const can = useCan();
    // Local categories state lets the inline Quick Category modal append a
    // new row without re-fetching the page or losing the in-progress
    // product form. Initialised from the prop on first render.
    const [cats, setCats] = useState(categories);
    const [showCategoryModal, setShowCategoryModal] = useState(false);

    const onCategoryCreated = (newCategory) => {
        setCats((prev) => [...prev, newCategory]);
        setData('category_id', newCategory.id);
        setShowCategoryModal(false);
    };

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
                <div className="mt-1 flex gap-2">
                    <select
                        id="category_id"
                        value={data.category_id ?? ''}
                        onChange={(e) => setData('category_id', e.target.value || null)}
                        className="block w-full rounded-md border-slate-300 shadow-sm sm:text-sm"
                    >
                        <option value="">— None —</option>
                        {cats.map((c) => (
                            <option key={c.id} value={c.id}>{c.name}</option>
                        ))}
                    </select>
                    {can('products.create') && (
                        <button
                            type="button"
                            onClick={() => setShowCategoryModal(true)}
                            className="shrink-0 rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                            aria-label="Add new category"
                            title="Add a new category without leaving this form"
                        >
                            + Add
                        </button>
                    )}
                </div>
            </FormField>

            {showCategoryModal && (
                <QuickCategoryModal
                    parents={cats.filter((c) => !c.parent_id)}
                    onClose={() => setShowCategoryModal(false)}
                    onCreated={onCategoryCreated}
                />
            )}

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
                        <span className="text-[11px] text-slate-400">Optional · saved per (product, tier) · VAT defaults to {VAT_DEFAULT}%</span>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-xs">
                            <thead className="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-3 py-2 text-left">Tier</th>
                                    <th className="px-3 py-2 text-right">Cost price</th>
                                    <th className="px-3 py-2 text-right">Shipping cost</th>
                                    <th className="px-3 py-2 text-right">VAT %</th>
                                    <th className="px-3 py-2 text-right">Collection cost</th>
                                    <th className="px-3 py-2 text-right">Return cost</th>
                                    <th className="px-3 py-2 text-right">Expected profit</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {marketerTiers.map((tier) => {
                                    const row = (data.tier_prices ?? {})[tier.code] ?? {};
                                    const expected = computeExpectedProfit(data.selling_price, row);
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
                                                    className="w-24 rounded-md border-slate-300 text-right text-xs tabular-nums"
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
                                                    className="w-24 rounded-md border-slate-300 text-right text-xs tabular-nums"
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
                                                    placeholder={String(VAT_DEFAULT)}
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
                                            <td className="px-3 py-1.5 text-right">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min={0}
                                                    value={row.collection_cost ?? ''}
                                                    onChange={(e) => updateTierCell(tier.code, 'collection_cost', e.target.value)}
                                                    className="w-24 rounded-md border-slate-300 text-right text-xs tabular-nums"
                                                    aria-label={`${tier.name} collection cost`}
                                                />
                                                {errors[`tier_prices.${tier.code}.collection_cost`] && (
                                                    <p className="mt-0.5 text-[10px] text-red-600">
                                                        {errors[`tier_prices.${tier.code}.collection_cost`]}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="px-3 py-1.5 text-right">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min={0}
                                                    value={row.return_cost ?? ''}
                                                    onChange={(e) => updateTierCell(tier.code, 'return_cost', e.target.value)}
                                                    className="w-24 rounded-md border-slate-300 text-right text-xs tabular-nums"
                                                    aria-label={`${tier.name} return cost`}
                                                />
                                                {errors[`tier_prices.${tier.code}.return_cost`] && (
                                                    <p className="mt-0.5 text-[10px] text-red-600">
                                                        {errors[`tier_prices.${tier.code}.return_cost`]}
                                                    </p>
                                                )}
                                            </td>
                                            <td
                                                className={`px-3 py-1.5 text-right tabular-nums ${expected === null ? 'text-slate-400' : expected < 0 ? 'text-red-600' : 'text-emerald-700'}`}
                                                aria-label={`${tier.name} expected profit`}
                                            >
                                                {expected === null ? '—' : formatMoney(expected)}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <p className="mt-2 text-[11px] text-slate-400">
                        Leave a tier&apos;s cells empty to skip it. Clearing every cell of a saved row removes it. Collection &amp; return costs are stored for visibility — they are not subtracted from the expected profit preview.
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

/**
 * Inline category creation triggered from the Product form. Submits
 * directly to POST /categories with `Accept: application/json` so the
 * existing controller returns the new category as JSON instead of
 * redirecting to /categories. Mirrors the `QuickCustomerModal` pattern
 * used in the Order Create page.
 */
function QuickCategoryModal({ parents, onClose, onCreated }) {
    const { props } = usePage();
    const [name, setName] = useState('');
    const [parentId, setParentId] = useState('');
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const [generalError, setGeneralError] = useState(null);

    const submit = async (e) => {
        e.preventDefault();
        // This modal's <form> is nested inside the product page's <form>
        // (ProductForm renders inside <form onSubmit> on BOTH Products/Create
        // and Products/Edit). `submit` events bubble — so without
        // stopPropagation this event also reaches the outer product form's
        // onSubmit, firing a spurious products.store request that re-renders
        // the whole page and discards the just-created category. Stopping
        // propagation here keeps the quick-add self-contained.
        e.stopPropagation();
        setSubmitting(true);
        setErrors({});
        setGeneralError(null);

        try {
            const csrf = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || '');
            const res = await fetch(route('categories.store'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    name: name.trim(),
                    parent_id: parentId || null,
                    status: 'Active',
                }),
            });

            if (res.status === 201 || res.status === 200) {
                const body = await res.json();
                if (body?.category?.id) {
                    onCreated(body.category);
                    return;
                }
                setGeneralError('Unexpected response from server.');
                return;
            }

            if (res.status === 422) {
                const body = await res.json().catch(() => ({}));
                setErrors(body.errors ?? {});
                return;
            }

            if (res.status === 403) {
                setGeneralError('You do not have permission to create categories.');
                return;
            }

            setGeneralError(`Could not create category (HTTP ${res.status}).`);
        } catch (err) {
            setGeneralError('Network error. Please try again.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="quick-category-title"
            onClick={onClose}
        >
            <div
                className="w-full max-w-md rounded-lg border border-slate-200 bg-white p-5 shadow-xl"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="mb-3 flex items-center justify-between">
                    <h3 id="quick-category-title" className="text-sm font-semibold text-slate-700">Add category</h3>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md text-xs text-slate-400 hover:text-slate-700"
                        aria-label="Close"
                    >
                        ✕
                    </button>
                </div>

                {generalError && (
                    <div className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                        {generalError}
                    </div>
                )}

                <form onSubmit={submit} className="space-y-3">
                    <div>
                        <label className="mb-1 block text-xs font-medium text-slate-600">Name <span className="text-red-500">*</span></label>
                        <input
                            type="text"
                            autoFocus
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            className="block w-full rounded-md border-slate-300 text-sm"
                            required
                            maxLength={255}
                        />
                        {errors.name && <p className="mt-1 text-xs text-red-600">{Array.isArray(errors.name) ? errors.name[0] : errors.name}</p>}
                    </div>

                    <div>
                        <label className="mb-1 block text-xs font-medium text-slate-600">Parent (optional)</label>
                        <select
                            value={parentId}
                            onChange={(e) => setParentId(e.target.value)}
                            className="block w-full rounded-md border-slate-300 text-sm"
                        >
                            <option value="">— No parent —</option>
                            {parents.map((p) => (
                                <option key={p.id} value={p.id}>{p.name}</option>
                            ))}
                        </select>
                        {errors.parent_id && <p className="mt-1 text-xs text-red-600">{Array.isArray(errors.parent_id) ? errors.parent_id[0] : errors.parent_id}</p>}
                    </div>

                    <p className="text-[11px] text-slate-400">New categories are saved as Active. Manage status from the Categories page.</p>

                    <div className="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={submitting || !name.trim()}
                            className="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                        >
                            {submitting ? 'Saving…' : 'Save category'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
