import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormField from '@/Components/FormField';
import StatusBadge from '@/Components/StatusBadge';
import LocationSelect from '@/Components/LocationSelect';
import useCan from '@/Hooks/useCan';
import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

export default function OrderCreate({ products, categories = [], locations = [], default_country_code = 'EG' }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';
    const can = useCan();

    const [matchedCustomer, setMatchedCustomer] = useState(null);
    const [duplicate, setDuplicate] = useState(null);
    const [quickModalOpen, setQuickModalOpen] = useState(false);

    /* Phase 3: product entry state */
    const [categoryFilter, setCategoryFilter] = useState('');
    const [searchQuery, setSearchQuery] = useState('');
    const [scanInput, setScanInput] = useState('');
    const [scanFeedback, setScanFeedback] = useState(null); // {tone:'success'|'error', text}
    const scanInputRef = useRef(null);

    const defaultCountryName = (locations.find((c) => c.code === default_country_code)?.name_en) ?? 'Egypt';

    const { data, setData, post, processing, errors, transform } = useForm({
        customer_id: null,
        customer: { name: '', primary_phone: '', secondary_phone: '', email: '', city: '', governorate: '', country: defaultCountryName },
        customer_address: '',
        city: '',
        governorate: '',
        country: defaultCountryName,
        source: '',
        notes: '',
        internal_notes: '',
        discount_amount: 0,
        shipping_amount: 0,
        extra_fees: 0,
        items: [],
        duplicate_acknowledged: false,
    });

    /* Lookup customer by phone (debounced) */
    useEffect(() => {
        const phone = data.customer.primary_phone?.trim();
        if (!phone || phone.length < 4) {
            setMatchedCustomer(null);
            return;
        }
        const timer = setTimeout(() => {
            fetch(`${route('customers.lookup')}?phone=${encodeURIComponent(phone)}`)
                .then((r) => r.json())
                .then((j) => setMatchedCustomer(j.customer))
                .catch(() => setMatchedCustomer(null));
        }, 350);
        return () => clearTimeout(timer);
    }, [data.customer.primary_phone]);

    /* Run dedupe check when key fields change (debounced) */
    useEffect(() => {
        const productIds = data.items.map((i) => i.product_id).filter(Boolean);
        const phone = data.customer_id ? matchedCustomer?.primary_phone : data.customer.primary_phone;
        if (!phone || productIds.length === 0) {
            setDuplicate(null);
            return;
        }
        const timer = setTimeout(() => {
            fetch(route('orders.check-duplicate'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '') },
                body: JSON.stringify({
                    primary_phone: phone,
                    customer_name: data.customer_id ? matchedCustomer?.name : data.customer.name,
                    city: data.city,
                    customer_address: data.customer_address,
                    product_ids: productIds,
                    customer_id: data.customer_id,
                }),
            })
                .then((r) => r.json())
                .then((j) => setDuplicate(j))
                .catch(() => setDuplicate(null));
        }, 600);
        return () => clearTimeout(timer);
    }, [data.customer.primary_phone, data.customer.name, data.customer_id, data.city, data.customer_address, JSON.stringify(data.items.map((i) => i.product_id))]);

    /* Item helpers */
    const updateItem = (idx, key, value) => {
        const next = [...data.items];
        next[idx] = { ...next[idx], [key]: value };
        setData('items', next);
    };

    const removeItem = (idx) => setData('items', data.items.filter((_, i) => i !== idx));

    /**
     * Add a product to the items list. If it's already there, increment its qty.
     * Used by both the [+ Add] button on the search results and the scan handler.
     */
    const addProductToItems = (product, qtyDelta = 1) => {
        const existingIdx = data.items.findIndex((it) => Number(it.product_id) === Number(product.id));
        if (existingIdx >= 0) {
            const next = [...data.items];
            next[existingIdx] = {
                ...next[existingIdx],
                quantity: Number(next[existingIdx].quantity || 0) + qtyDelta,
            };
            setData('items', next);
        } else {
            setData('items', [
                ...data.items,
                {
                    product_id: product.id,
                    quantity: qtyDelta,
                    unit_price: product.selling_price,
                    discount_amount: 0,
                },
            ]);
        }
    };

    /**
     * Lookup helper used by the scan field. Matches against:
     *   - product SKU (exact, case-insensitive)
     *   - product barcode (exact, case-insensitive)
     */
    const findExactByScan = (raw) => {
        const v = (raw || '').trim().toLowerCase();
        if (!v) return null;
        return (
            products.find((p) => (p.sku || '').toLowerCase() === v) ||
            products.find((p) => (p.barcode || '').toLowerCase() === v) ||
            null
        );
    };

    const handleScanKey = (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const value = scanInput;
        const match = findExactByScan(value);
        if (!match) {
            setScanFeedback({ tone: 'error', text: `No product matches "${value}".` });
            // Keep value in field so the operator can correct typos
            scanInputRef.current?.focus();
            return;
        }
        addProductToItems(match);
        setScanFeedback({ tone: 'success', text: `Added: ${match.name} (${match.sku})` });
        setScanInput('');
        scanInputRef.current?.focus();
    };

    /**
     * Search-filter results. Limited to 25 visible rows so a long catalogue
     * doesn't dump everything before the operator types anything.
     */
    const searchResults = useMemo(() => {
        const q = searchQuery.trim().toLowerCase();
        let list = products;
        if (categoryFilter) {
            list = list.filter((p) => Number(p.category_id) === Number(categoryFilter));
        }
        if (q) {
            list = list.filter((p) =>
                (p.name || '').toLowerCase().includes(q) ||
                (p.sku || '').toLowerCase().includes(q) ||
                (p.barcode || '').toLowerCase().includes(q)
            );
        }
        return list.slice(0, 25);
    }, [products, categoryFilter, searchQuery]);

    /* Live totals */
    const totals = useMemo(() => {
        let subtotal = 0;
        for (const it of data.items) {
            subtotal += (Number(it.unit_price) * Number(it.quantity)) - Number(it.discount_amount || 0);
        }
        const total = Math.max(0, subtotal + Number(data.shipping_amount || 0) + Number(data.extra_fees || 0) - Number(data.discount_amount || 0));
        return { subtotal: subtotal.toFixed(2), total: total.toFixed(2) };
    }, [data.items, data.shipping_amount, data.extra_fees, data.discount_amount]);

    const useExistingCustomer = () => {
        if (!matchedCustomer) return;
        setData('customer_id', matchedCustomer.id);
        setData('customer_address', matchedCustomer.default_address ?? '');
        setData('city', matchedCustomer.city ?? '');
        setData('governorate', matchedCustomer.governorate ?? '');
        setData('country', matchedCustomer.country ?? 'Egypt');
    };

    transform((d) => {
        // Drop the inline customer block when an existing customer is picked.
        if (d.customer_id) {
            const { customer, ...rest } = d;
            return rest;
        }
        return d;
    });

    const submit = (e) => {
        e.preventDefault();
        // High duplicate score requires explicit acknowledgement.
        if (duplicate && duplicate.score >= 60 && !data.duplicate_acknowledged) {
            if (!confirm(`Possible duplicate (score ${duplicate.score}). Continue anyway?`)) return;
            setData('duplicate_acknowledged', true);
        }
        post(route('orders.store'));
    };

    return (
        <AuthenticatedLayout header="New order">
            <Head title="New order" />
            <PageHeader title="New order" subtitle="Capture customer, items, and shipping snapshot" />

            <form onSubmit={submit} className="space-y-5">
                {/* Customer panel */}
                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-slate-700">Customer</h2>
                        {can('customers.create') && !data.customer_id && (
                            <button
                                type="button"
                                onClick={() => setQuickModalOpen(true)}
                                className="rounded-md border border-indigo-300 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-100"
                            >
                                + New customer
                            </button>
                        )}
                    </div>

                    {data.customer_id && matchedCustomer ? (
                        <div className="rounded-md border border-emerald-200 bg-emerald-50 p-3">
                            <div className="flex items-center justify-between">
                                <div>
                                    <div className="text-sm font-semibold text-slate-800">{matchedCustomer.name}</div>
                                    <div className="text-xs text-slate-600">{matchedCustomer.primary_phone}</div>
                                    <div className="mt-1 flex items-center gap-1.5">
                                        <StatusBadge value={matchedCustomer.customer_type} />
                                        <StatusBadge value={matchedCustomer.risk_level} />
                                        <span className="text-xs text-slate-500">{matchedCustomer.orders_count ?? 0} orders · {matchedCustomer.returned_orders_count ?? 0} returned</span>
                                    </div>
                                </div>
                                <button type="button" onClick={() => { setData('customer_id', null); }} className="text-xs text-slate-500 hover:underline">
                                    Use a different customer
                                </button>
                            </div>
                        </div>
                    ) : (
                        <>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <FormField
                                    label="Primary phone"
                                    name="customer.primary_phone"
                                    value={data.customer.primary_phone}
                                    onChange={(v) => setData('customer', { ...data.customer, primary_phone: v })}
                                    error={errors['customer.primary_phone']}
                                    required
                                    hint={matchedCustomer ? null : 'We auto-match existing customers as you type'}
                                />
                                <FormField
                                    label="Name"
                                    name="customer.name"
                                    value={data.customer.name}
                                    onChange={(v) => setData('customer', { ...data.customer, name: v })}
                                    error={errors['customer.name']}
                                    required
                                />
                                <FormField label="Email" type="email" name="customer.email" value={data.customer.email} onChange={(v) => setData('customer', { ...data.customer, email: v })} error={errors['customer.email']} />
                                <FormField label="Secondary phone" name="customer.secondary_phone" value={data.customer.secondary_phone} onChange={(v) => setData('customer', { ...data.customer, secondary_phone: v })} error={errors['customer.secondary_phone']} />
                                <LocationSelect
                                    locations={locations}
                                    country={data.customer.country}
                                    state={data.customer.governorate}
                                    city={data.customer.city}
                                    onChange={({ country, state, city }) => {
                                        setData('customer', { ...data.customer, country, governorate: state, city });
                                    }}
                                    errors={{
                                        country: errors['customer.country'],
                                        state: errors['customer.governorate'],
                                        city: errors['customer.city'],
                                    }}
                                    required
                                />
                            </div>

                            {matchedCustomer && (
                                <div className="mt-3 flex items-center justify-between rounded-md border border-blue-200 bg-blue-50 p-3 text-sm">
                                    <div>
                                        <span className="font-medium text-slate-800">{matchedCustomer.name}</span>{' '}
                                        <span className="text-slate-500">already exists with this phone</span>
                                        {' '}<StatusBadge value={matchedCustomer.risk_level} className="ml-1" />
                                    </div>
                                    <button type="button" onClick={useExistingCustomer} className="text-xs font-medium text-indigo-600 hover:underline">
                                        Use existing customer
                                    </button>
                                </div>
                            )}
                        </>
                    )}
                </section>

                {/* Shipping snapshot */}
                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Delivery address</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <FormField
                            label="Address"
                            name="customer_address"
                            error={errors.customer_address}
                            className="sm:col-span-2"
                            required
                        >
                            <textarea
                                id="customer_address"
                                rows={2}
                                value={data.customer_address}
                                onChange={(e) => setData('customer_address', e.target.value)}
                                className="mt-1 block w-full rounded-md border-slate-300 shadow-sm sm:text-sm"
                            />
                        </FormField>
                        <LocationSelect
                            locations={locations}
                            country={data.country}
                            state={data.governorate}
                            city={data.city}
                            onChange={({ country, state, city }) => {
                                setData('country', country);
                                setData('governorate', state);
                                setData('city', city);
                            }}
                            errors={{ country: errors.country, state: errors.governorate, city: errors.city }}
                            required
                        />
                        <FormField label="Source" name="source" value={data.source} onChange={(v) => setData('source', v)} error={errors.source} hint="e.g. Facebook, TikTok, Walk-in" />
                    </div>
                </section>

                {/* Items */}
                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Items</h2>

                    {/* Product entry toolbar: category filter + search + scan */}
                    <div className="mb-3 grid grid-cols-1 gap-2 sm:grid-cols-12">
                        <select
                            value={categoryFilter}
                            onChange={(e) => setCategoryFilter(e.target.value)}
                            className="rounded-md border-slate-300 text-sm sm:col-span-3"
                            aria-label="Filter by category"
                        >
                            <option value="">All categories</option>
                            {categories.map((c) => (
                                <option key={c.id} value={c.id}>{c.name}</option>
                            ))}
                        </select>
                        <input
                            type="text"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            placeholder="Search by product name or SKU"
                            className="rounded-md border-slate-300 text-sm sm:col-span-5"
                            aria-label="Search products by name or SKU"
                        />
                        <input
                            ref={scanInputRef}
                            type="text"
                            value={scanInput}
                            onChange={(e) => { setScanInput(e.target.value); if (scanFeedback) setScanFeedback(null); }}
                            onKeyDown={handleScanKey}
                            placeholder="Scan SKU / Barcode + Enter"
                            className="rounded-md border-slate-300 text-sm sm:col-span-4"
                            aria-label="Scan SKU or barcode"
                        />
                    </div>

                    {scanFeedback && (
                        <div
                            className={
                                'mb-3 rounded-md border px-3 py-2 text-xs ' +
                                (scanFeedback.tone === 'success'
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                    : 'border-red-200 bg-red-50 text-red-700')
                            }
                        >
                            {scanFeedback.text}
                        </div>
                    )}

                    {/* Search results panel */}
                    <div className="mb-4 rounded-md border border-slate-200">
                        {searchResults.length === 0 ? (
                            <div className="px-3 py-6 text-center text-xs text-slate-400">
                                {searchQuery || categoryFilter
                                    ? 'No products match the current filter / search.'
                                    : 'Type a name, pick a category, or scan a SKU above to find products.'}
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-xs">
                                    <thead className="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                                        <tr>
                                            <th className="px-3 py-2 text-left">Product</th>
                                            <th className="px-3 py-2 text-left">SKU</th>
                                            <th className="px-3 py-2 text-left">Category</th>
                                            <th className="px-3 py-2 text-right">Price</th>
                                            <th className="px-3 py-2 text-right">Available</th>
                                            <th className="px-3 py-2 text-right"></th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {searchResults.map((p) => (
                                            <tr key={p.id} className="hover:bg-slate-50">
                                                <td className="px-3 py-1.5 text-slate-700">{p.name}</td>
                                                <td className="px-3 py-1.5 font-mono text-[11px] text-slate-500">{p.sku}</td>
                                                <td className="px-3 py-1.5 text-slate-500">{p.category_name ?? '—'}</td>
                                                <td className="whitespace-nowrap px-3 py-1.5 text-right tabular-nums text-slate-700">{sym}{Number(p.selling_price).toFixed(2)}</td>
                                                <td className={'whitespace-nowrap px-3 py-1.5 text-right tabular-nums ' + (p.available <= 0 ? 'text-red-600' : 'text-slate-700')}>{p.available}</td>
                                                <td className="whitespace-nowrap px-3 py-1.5 text-right">
                                                    <button
                                                        type="button"
                                                        onClick={() => addProductToItems(p)}
                                                        className="rounded-md border border-indigo-300 bg-indigo-50 px-2.5 py-1 text-[11px] font-medium text-indigo-700 hover:bg-indigo-100"
                                                    >
                                                        + Add
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                {searchResults.length === 25 && (
                                    <div className="border-t border-slate-100 px-3 py-1.5 text-[11px] text-slate-400">
                                        Showing first 25 — narrow the search to see more.
                                    </div>
                                )}
                            </div>
                        )}
                    </div>

                    {/* Selected order items */}
                    {data.items.length === 0 ? (
                        <div className="rounded-md border border-dashed border-slate-200 px-3 py-4 text-center text-xs text-slate-400">
                            No items yet — search or scan above to add the first one.
                        </div>
                    ) : (
                        <div className="overflow-x-auto rounded-md border border-slate-200">
                            <table className="min-w-full text-xs">
                                <thead className="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th className="px-3 py-2 text-left">Product</th>
                                        <th className="px-3 py-2 text-left">SKU</th>
                                        <th className="px-3 py-2 text-right">Qty</th>
                                        <th className="px-3 py-2 text-right">Unit price</th>
                                        <th className="px-3 py-2 text-right">Discount</th>
                                        <th className="px-3 py-2 text-right">Line total</th>
                                        <th className="px-3 py-2 text-right"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {data.items.map((it, idx) => {
                                        const p = products.find((x) => Number(x.id) === Number(it.product_id));
                                        const lineTotal = (Number(it.unit_price) * Number(it.quantity)) - Number(it.discount_amount || 0);
                                        const overReserved = p && Number(it.quantity) > Number(p.available);
                                        return (
                                            <tr key={idx} className="hover:bg-slate-50">
                                                <td className="px-3 py-1.5 text-slate-700">{p?.name ?? '—'}</td>
                                                <td className="px-3 py-1.5 font-mono text-[11px] text-slate-500">{p?.sku ?? '—'}</td>
                                                <td className="px-3 py-1.5 text-right">
                                                    <input
                                                        type="number"
                                                        min={1}
                                                        value={it.quantity}
                                                        onChange={(e) => updateItem(idx, 'quantity', e.target.value)}
                                                        className={'w-16 rounded-md text-right text-xs tabular-nums ' + (overReserved ? 'border-red-300 text-red-700' : 'border-slate-300')}
                                                    />
                                                    {overReserved && (
                                                        <div className="mt-0.5 text-[10px] text-red-600">{p.available} avail.</div>
                                                    )}
                                                </td>
                                                <td className="px-3 py-1.5 text-right">
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min={0}
                                                        value={it.unit_price}
                                                        onChange={(e) => updateItem(idx, 'unit_price', e.target.value)}
                                                        className="w-20 rounded-md border-slate-300 text-right text-xs tabular-nums"
                                                    />
                                                </td>
                                                <td className="px-3 py-1.5 text-right">
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min={0}
                                                        value={it.discount_amount}
                                                        onChange={(e) => updateItem(idx, 'discount_amount', e.target.value)}
                                                        className="w-20 rounded-md border-slate-300 text-right text-xs tabular-nums"
                                                    />
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-1.5 text-right tabular-nums text-slate-700">
                                                    {sym}{lineTotal.toFixed(2)}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-1.5 text-right">
                                                    <button
                                                        type="button"
                                                        onClick={() => removeItem(idx)}
                                                        className="text-[11px] text-red-500 hover:underline"
                                                    >
                                                        remove
                                                    </button>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {errors.items && <p className="mt-2 text-xs text-red-600">{errors.items}</p>}
                </section>

                {/* Totals + adjustments */}
                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Totals</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <FormField label="Discount" name="discount_amount" type="number" value={data.discount_amount} onChange={(v) => setData('discount_amount', v)} error={errors.discount_amount} />
                        <FormField label="Shipping" name="shipping_amount" type="number" value={data.shipping_amount} onChange={(v) => setData('shipping_amount', v)} error={errors.shipping_amount} />
                        <FormField label="Extra fees" name="extra_fees" type="number" value={data.extra_fees} onChange={(v) => setData('extra_fees', v)} error={errors.extra_fees} />
                    </div>

                    <div className="mt-4 flex items-center justify-end gap-6 text-sm">
                        <div className="text-slate-500">Subtotal: <span className="tabular-nums text-slate-800">{sym}{totals.subtotal}</span></div>
                        <div className="text-base font-semibold text-slate-800">Total: <span className="tabular-nums">{sym}{totals.total}</span></div>
                    </div>
                </section>

                {/* Notes */}
                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Notes</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <FormField label="Customer-facing notes" name="notes" error={errors.notes}>
                            <textarea
                                id="notes"
                                rows={2}
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                className="mt-1 block w-full rounded-md border-slate-300 shadow-sm sm:text-sm"
                            />
                        </FormField>
                        <FormField label="Internal notes" name="internal_notes" error={errors.internal_notes}>
                            <textarea
                                id="internal_notes"
                                rows={2}
                                value={data.internal_notes}
                                onChange={(e) => setData('internal_notes', e.target.value)}
                                className="mt-1 block w-full rounded-md border-slate-300 shadow-sm sm:text-sm"
                            />
                        </FormField>
                    </div>
                </section>

                {/* Duplicate banner */}
                {duplicate && duplicate.score > 0 && (
                    <div
                        className={
                            'rounded-md border p-3 text-sm ' +
                            (duplicate.score >= 60
                                ? 'border-red-200 bg-red-50 text-red-800'
                                : 'border-amber-200 bg-amber-50 text-amber-800')
                        }
                    >
                        <div className="font-semibold">Possible duplicate · score {duplicate.score}/100</div>
                        <ul className="mt-1 list-disc pl-5">
                            {duplicate.reasons.map((r, i) => <li key={i}>{r}</li>)}
                        </ul>
                    </div>
                )}

                <div className="flex items-center justify-end gap-2">
                    <Link href={route('orders.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                    >
                        {processing ? 'Saving…' : 'Create order'}
                    </button>
                </div>
            </form>

            {quickModalOpen && (
                <QuickCustomerModal
                    locations={locations}
                    initial={{
                        name: data.customer.name,
                        primary_phone: data.customer.primary_phone,
                        secondary_phone: data.customer.secondary_phone,
                        email: data.customer.email,
                        governorate: data.customer.governorate || data.governorate || '',
                        city: data.customer.city || data.city || '',
                        country: data.customer.country || data.country || defaultCountryName,
                        default_address: data.customer_address,
                    }}
                    onClose={() => setQuickModalOpen(false)}
                    onCreated={(customer) => {
                        // Adopt the new customer into the order form without
                        // touching items, totals, or notes.
                        setData((prev) => ({
                            ...prev,
                            customer_id: customer.id,
                            customer_address: customer.default_address ?? prev.customer_address,
                            city: customer.city ?? prev.city,
                            governorate: customer.governorate ?? prev.governorate,
                            country: customer.country ?? prev.country,
                        }));
                        setMatchedCustomer(customer);
                        setQuickModalOpen(false);
                    }}
                />
            )}
        </AuthenticatedLayout>
    );
}

/**
 * Inline modal for creating a customer without leaving the order form.
 * POSTs to `/customers` with an Accept: application/json header — the
 * controller (CustomersController::store) returns the created customer
 * as JSON in that case. RBAC is enforced by the same `permission:customers.create`
 * middleware on the route, so this UI is only reachable for users who
 * could already create customers via /customers/create.
 */
function QuickCustomerModal({ initial, onClose, onCreated, locations = [] }) {
    const [form, setForm] = useState({
        name: initial.name ?? '',
        primary_phone: initial.primary_phone ?? '',
        secondary_phone: initial.secondary_phone ?? '',
        email: initial.email ?? '',
        governorate: initial.governorate ?? '',
        city: initial.city ?? '',
        country: initial.country ?? 'Egypt',
        default_address: initial.default_address ?? '',
    });
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const [generalError, setGeneralError] = useState(null);

    const update = (k, v) => setForm((f) => ({ ...f, [k]: v }));

    const submit = async (e) => {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setGeneralError(null);

        try {
            const xsrf = decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''
            );
            const res = await fetch(route('customers.store'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrf,
                },
                body: JSON.stringify(form),
            });
            if (res.status === 422) {
                const j = await res.json();
                setErrors(j.errors || {});
                return;
            }
            if (res.status === 403) {
                setGeneralError('You do not have permission to create customers.');
                return;
            }
            if (!res.ok) {
                setGeneralError('Could not create customer. Try again.');
                return;
            }
            const j = await res.json();
            onCreated(j.customer);
        } catch (err) {
            setGeneralError('Network error. Try again.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div
            className="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4"
            onClick={onClose}
        >
            <div
                className="w-full max-w-lg rounded-lg bg-white shadow-xl"
                onClick={(e) => e.stopPropagation()}
            >
                <form onSubmit={submit}>
                    <div className="border-b border-slate-200 px-5 py-3">
                        <h3 className="text-sm font-semibold text-slate-800">New customer</h3>
                        <p className="mt-0.5 text-xs text-slate-500">
                            Saved immediately. The order form keeps your items and totals.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 gap-3 px-5 py-4 sm:grid-cols-2">
                        <FormField label="Name" name="name" value={form.name} onChange={(v) => update('name', v)} error={errors.name?.[0]} required />
                        <FormField label="Primary phone" name="primary_phone" value={form.primary_phone} onChange={(v) => update('primary_phone', v)} error={errors.primary_phone?.[0]} required />
                        <FormField label="Secondary phone" name="secondary_phone" value={form.secondary_phone} onChange={(v) => update('secondary_phone', v)} error={errors.secondary_phone?.[0]} />
                        <FormField label="Email" type="email" name="email" value={form.email} onChange={(v) => update('email', v)} error={errors.email?.[0]} />
                        <LocationSelect
                            locations={locations}
                            country={form.country}
                            state={form.governorate}
                            city={form.city}
                            onChange={({ country, state, city }) => {
                                setForm((f) => ({ ...f, country, governorate: state, city }));
                            }}
                            errors={{
                                country: errors.country?.[0],
                                state: errors.governorate?.[0],
                                city: errors.city?.[0],
                            }}
                            required
                        />
                        <FormField label="Address" name="default_address" error={errors.default_address?.[0]} required className="sm:col-span-2">
                            <textarea
                                id="default_address"
                                rows={2}
                                value={form.default_address}
                                onChange={(e) => update('default_address', e.target.value)}
                                className="mt-1 block w-full rounded-md border-slate-300 shadow-sm sm:text-sm"
                            />
                        </FormField>
                    </div>

                    {generalError && (
                        <div className="mx-5 mb-3 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                            {generalError}
                        </div>
                    )}

                    <div className="flex items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={submitting}
                            className="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                        >
                            {submitting ? 'Saving…' : 'Save & select'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
