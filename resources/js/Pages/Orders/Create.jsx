import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormField from '@/Components/FormField';
import StatusBadge from '@/Components/StatusBadge';
import LocationSelect from '@/Components/LocationSelect';
import useCan from '@/Hooks/useCan';
import useUnsavedChangesWarning from '@/Hooks/useUnsavedChangesWarning';
import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

export default function OrderCreate({
    products,
    categories = [],
    locations = [],
    default_country_code = 'EG',
    entry_code_preview = null,
    marketers = [],
    can_view_profit = false,
    // O-1: action gates + optional duplicate prefill.
    can_print_label = false,
    duplicate_from = null,
    // C-1: optional customer prefill via `?customer_id=`. Null when no
    // param, invalid id, or when duplicate_from is also set (the
    // backend prefers duplicate_from in that case).
    prefill_customer = null,
}) {
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

    /* Performance Phase 1 — server-side product search.
       The page used to receive the full active product catalogue in
       the `products` prop and filter client-side. It now receives only
       the first 25 (alphabetical) and we call /orders/products/search
       on every search/category change (debounced 250 ms). */
    const [searchedProducts, setSearchedProducts] = useState(products);
    const [searchLoading, setSearchLoading] = useState(false);

    /* O-1: rolling cache of every product the user has *seen* (initial
       seed + every search result + every scanned hit). Order item rows
       look up product metadata (stock, min selling, name, sku, tax) from
       this map instead of the static `products` prop, so a product added
       from a search beyond the initial 25 still has accurate per-line
       warnings (low stock, below-min selling, tax preview). */
    const [productCache, setProductCache] = useState(() => {
        const map = new Map();
        for (const p of products ?? []) map.set(Number(p.id), p);
        return map;
    });
    const mergeProductsIntoCache = (list) => {
        if (!Array.isArray(list) || list.length === 0) return;
        setProductCache((prev) => {
            const next = new Map(prev);
            for (const p of list) next.set(Number(p.id), p);
            return next;
        });
    };
    const lookupProduct = (productId) => productCache.get(Number(productId)) || null;

    const defaultCountryName = (locations.find((c) => c.code === default_country_code)?.name_en) ?? 'Egypt';

    const { data, setData, post, processing, errors, transform, isDirty } = useForm({
        customer_id: null,
        customer: {
            name: '', primary_phone: '', secondary_phone: '',
            primary_phone_whatsapp: true,
            email: '',
            city: '', governorate: '', country: defaultCountryName,
        },
        customer_address: '',
        city: '',
        governorate: '',
        country: defaultCountryName,
        // Phase 5.8: per-order phone snapshot. Defaults mirror the
        // customer's profile (or sensible defaults for inline customers).
        customer_phone_secondary: '',
        customer_phone_whatsapp: true,
        // Phase 5.9: optional marketer attachment for admin-created orders.
        marketer_id: null,
        source: '',
        external_order_reference: '',
        notes: '',
        internal_notes: '',
        discount_amount: 0,
        shipping_amount: 0,
        extra_fees: 0,
        items: [],
        duplicate_acknowledged: false,
        // O-1: post-save redirect intent. Set by the Save button group;
        // backend treats anything outside the allow-list as `save`.
        submit_action: 'save',
    });

    // Warn before leaving the page if there are unsaved edits.
    useUnsavedChangesWarning(isDirty);

    /* Phase 5.9 — live marketer profit preview state */
    const [marketerProfit, setMarketerProfit] = useState(null);

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

    /* Phase 5.9: live marketer profit preview when a marketer is selected
       and at least one item is in the cart. Re-fetches on debounce when
       any of (marketer_id, items, prices) changes.
       Cost/profit gate: only fire the request when the operator has the
       `orders.view_profit` permission. The backend would return 403
       otherwise; bailing here avoids the noisy console error. */
    useEffect(() => {
        if (!can_view_profit) {
            setMarketerProfit(null);
            return;
        }
        if (!data.marketer_id || data.items.length === 0) {
            setMarketerProfit(null);
            return;
        }
        const validItems = data.items.filter((i) => i.product_id && Number(i.quantity) > 0 && Number(i.unit_price) >= 0);
        if (validItems.length === 0) {
            setMarketerProfit(null);
            return;
        }
        const timer = setTimeout(() => {
            fetch(route('orders.marketer-profit-preview'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
                },
                body: JSON.stringify({
                    marketer_id: data.marketer_id,
                    items: validItems.map((i) => ({
                        product_id: i.product_id,
                        product_variant_id: i.product_variant_id ?? null,
                        quantity: Number(i.quantity),
                        unit_price: Number(i.unit_price),
                    })),
                }),
            })
                .then((r) => r.json())
                .then((j) => setMarketerProfit(j))
                .catch(() => setMarketerProfit(null));
        }, 400);
        return () => clearTimeout(timer);
    }, [data.marketer_id, JSON.stringify(data.items.map((i) => `${i.product_id}-${i.quantity}-${i.unit_price}`))]);

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
     *
     * Performance Phase 1: the page no longer holds the full catalogue,
     * so we hit the server-side search endpoint with q=<scanned value>
     * and pick the first exact SKU/barcode match.
     */
    const findExactByScan = async (raw) => {
        const v = (raw || '').trim();
        if (!v) return null;
        try {
            const params = new URLSearchParams({ q: v, limit: '25' });
            const r = await fetch(`${route('orders.products.search')}?${params.toString()}`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!r.ok) return null;
            const j = await r.json();
            const list = Array.isArray(j.products) ? j.products : [];
            const lower = v.toLowerCase();
            return (
                list.find((p) => (p.sku || '').toLowerCase() === lower) ||
                list.find((p) => (p.barcode || '').toLowerCase() === lower) ||
                null
            );
        } catch (e) {
            return null;
        }
    };

    const handleScanKey = async (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const value = scanInput;
        const match = await findExactByScan(value);
        if (!match) {
            setScanFeedback({ tone: 'error', text: `No product matches "${value}".` });
            // Keep value in field so the operator can correct typos
            scanInputRef.current?.focus();
            return;
        }
        // O-1: cache the scanned product so warnings resolve later.
        mergeProductsIntoCache([match]);
        addProductToItems(match);
        setScanFeedback({ tone: 'success', text: `Added: ${match.name} (${match.sku})` });
        setScanInput('');
        scanInputRef.current?.focus();
    };

    /* ──────────────────── O-1: duplicate prefill ──────────────────── */
    // When the user lands here via `?duplicate_from=<id>`, hydrate the
    // form ONCE on mount with the source order's customer + items.
    // Anything financial (totals, profit, marketer wallet, audit) stays
    // re-computed at save time by OrderService::createFromPayload.
    useEffect(() => {
        if (!duplicate_from) return;
        // Use a functional setter to avoid clobbering any other field a
        // future effect might set on first render.
        setData((prev) => ({
            ...prev,
            customer_id: duplicate_from.customer_id ?? null,
            customer_address: duplicate_from.customer_address ?? prev.customer_address,
            city: duplicate_from.city ?? prev.city,
            governorate: duplicate_from.governorate ?? prev.governorate,
            country: duplicate_from.country ?? prev.country,
            marketer_id: duplicate_from.marketer_id ?? null,
            source: duplicate_from.source ?? prev.source,
            shipping_amount: duplicate_from.shipping_amount ?? prev.shipping_amount,
            items: Array.isArray(duplicate_from.items)
                ? duplicate_from.items.map((it) => ({
                    product_id: it.product_id,
                    product_variant_id: it.product_variant_id ?? null,
                    quantity: it.quantity,
                    unit_price: it.unit_price,
                    discount_amount: it.discount_amount ?? 0,
                }))
                : prev.items,
        }));
        // The source order's customer card needs `matchedCustomer` for
        // the green "existing customer" panel to render. The backend
        // ships a slim summary in `duplicate_from.customer` so we don't
        // need a separate fetch.
        if (duplicate_from.customer) {
            setMatchedCustomer(duplicate_from.customer);
        }
        // The duplicate banner from the parent customer's prior order
        // SHOULD fire — it tells the user "you just placed this exact
        // order" — but we don't auto-acknowledge it. The user reviews
        // and clicks through. Items + customer were intentionally
        // duplicated so the warning is meaningful.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /* ──────────────────── C-1: customer prefill ──────────────────── */
    // When the user lands here via `?customer_id=<id>` (Customer Show →
    // Add Order), hydrate the customer slot ONCE on mount. Skipped when
    // duplicate_from is also set because that path already carries a
    // (possibly different) customer.
    //
    // Items remain empty by design — the operator picks them. Only the
    // customer + address are pre-filled.
    useEffect(() => {
        if (!prefill_customer || duplicate_from) return;
        setData((prev) => ({
            ...prev,
            customer_id: prefill_customer.id ?? null,
            customer_address: prefill_customer.default_address ?? prev.customer_address,
            city: prefill_customer.city ?? prev.city,
            governorate: prefill_customer.governorate ?? prev.governorate,
            country: prefill_customer.country ?? prev.country,
            customer_phone_secondary: prefill_customer.secondary_phone ?? prev.customer_phone_secondary,
            customer_phone_whatsapp: prefill_customer.primary_phone_whatsapp ?? prev.customer_phone_whatsapp,
        }));
        // Show the green "existing customer" card by populating the
        // matchedCustomer state directly — same shape the lookupByPhone
        // endpoint returns. Avoids an extra round-trip.
        if (prefill_customer.id) {
            setMatchedCustomer(prefill_customer);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /**
     * Performance Phase 1 — debounced server-side product search.
     *
     * On mount we already have the initial 25 products from the
     * `products` prop. On every change to `searchQuery` or
     * `categoryFilter` we re-fetch from `/orders/products/search`
     * after a 250 ms debounce. The endpoint returns the SAME shape
     * `productsForOrderEntry()` used to return, so the row component
     * below doesn't need to change.
     *
     * Selected items (`data.items`) live in a separate state slice,
     * so they stay stable when the search results change.
     */
    useEffect(() => {
        const controller = new AbortController();
        const q = searchQuery.trim();
        const params = new URLSearchParams();
        if (q) params.set('q', q);
        if (categoryFilter) params.set('category_id', String(categoryFilter));
        params.set('limit', '25');

        const timer = setTimeout(() => {
            setSearchLoading(true);
            fetch(`${route('orders.products.search')}?${params.toString()}`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : Promise.reject(new Error('search failed'))))
                .then((j) => {
                    const list = Array.isArray(j.products) ? j.products : [];
                    setSearchedProducts(list);
                    // O-1: feed every fetched product into the cache so
                    // per-line warnings (stock, below-min) still resolve
                    // when the user adds a product, then narrows the
                    // search to something else.
                    mergeProductsIntoCache(list);
                })
                .catch((err) => {
                    if (err?.name !== 'AbortError') {
                        // Silent fallback — keep whatever we had before.
                    }
                })
                .finally(() => setSearchLoading(false));
        }, 250);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [searchQuery, categoryFilter]);

    // Backwards-compatible alias so the existing JSX (`searchResults.map`,
    // `searchResults.length`) continues to work unchanged.
    const searchResults = searchedProducts;

    /* Live totals — mirror what the backend computes per-line so the
       Review Total panel matches the saved order down to the kobo. */
    const totals = useMemo(() => {
        let subtotal = 0;
        let tax = 0;
        for (const it of data.items) {
            // O-1: lookup via productCache so totals stay accurate even
            // for products added beyond the initial 25-product seed.
            const product = lookupProduct(it.product_id);
            const lineGross = (Number(it.unit_price) * Number(it.quantity)) - Number(it.discount_amount || 0);
            const lineSubtotal = Math.max(0, lineGross);
            subtotal += lineSubtotal;
            if (product?.tax_enabled) {
                tax += lineSubtotal * (Number(product.tax_rate || 0) / 100);
            }
        }
        const shipping = Number(data.shipping_amount || 0);
        const discount = Number(data.discount_amount || 0);
        const extra = Number(data.extra_fees || 0);
        const total = Math.max(0, subtotal + tax + shipping + extra - discount);
        return {
            subtotal: subtotal.toFixed(2),
            tax: tax.toFixed(2),
            shipping: shipping.toFixed(2),
            discount: discount.toFixed(2),
            extra: extra.toFixed(2),
            total: total.toFixed(2),
            hasTax: tax > 0,
        };
    }, [data.items, productCache, data.shipping_amount, data.extra_fees, data.discount_amount]);

    /* ──────────────────── O-1: pre-submit warnings ────────────────────
       Non-blocking. Computed from data already shipped to the page
       (or already fetched from the marketer profit preview when a
       marketer is attached). Server-side ProfitGuardService keeps its
       independent block on below-min sales; this is operator visibility,
       not a security gate. */
    const LOW_MARGIN_THRESHOLD = 10; // percent — UI-only heuristic
    const warnings = useMemo(() => {
        const list = [];
        if (data.items.length === 0) return list;

        // Map profit-preview lines by line-index so we can pair stock
        // warnings with margin warnings for the same row. The preview
        // is keyed by line order, not product_id, because the same
        // product can appear twice with different prices.
        const previewLines = marketerProfit?.lines ?? [];

        data.items.forEach((it, idx) => {
            const p = lookupProduct(it.product_id);
            const label = p?.name ? `${p.name}` : `Item #${idx + 1}`;
            const qty = Number(it.quantity || 0);
            const price = Number(it.unit_price || 0);

            // 1) Low stock
            if (p && Number.isFinite(Number(p.available)) && qty > Number(p.available)) {
                list.push({
                    kind: 'low_stock',
                    tone: 'amber',
                    line: idx,
                    label,
                    message: `Only ${p.available} unit${p.available === 1 ? '' : 's'} available — over-selling by ${qty - Number(p.available)}.`,
                });
            }

            // 2) Below minimum selling price
            const minPrice = Number(p?.minimum_selling_price ?? 0);
            if (p && minPrice > 0 && price < minPrice) {
                list.push({
                    kind: 'below_min',
                    tone: 'red',
                    line: idx,
                    label,
                    message: `Price ${price.toFixed(2)} is below minimum ${minPrice.toFixed(2)} — server will block unless an override is approved.`,
                });
            }

            // 3) Marketer-preview-derived warnings (only when the
            //    preview is loaded — cost is otherwise not on the
            //    client by design).
            if (previewLines[idx]) {
                const pl = previewLines[idx];
                if (!Number.isFinite(pl.cost_price) || pl.cost_price <= 0) {
                    list.push({
                        kind: 'missing_cost',
                        tone: 'amber',
                        line: idx,
                        label,
                        message: 'Product cost is missing — profit preview may be inaccurate.',
                    });
                } else if (Number.isFinite(pl.profit) && pl.profit < 0) {
                    list.push({
                        kind: 'negative_profit',
                        tone: 'red',
                        line: idx,
                        label,
                        message: `Marketer profit is negative (${pl.profit.toFixed(2)}).`,
                    });
                } else if (Number.isFinite(pl.profit) && pl.unit_price > 0 && qty > 0) {
                    const revenue = pl.unit_price * qty;
                    const marginPct = revenue > 0 ? (pl.profit / revenue) * 100 : 0;
                    if (revenue > 0 && marginPct < LOW_MARGIN_THRESHOLD) {
                        list.push({
                            kind: 'low_margin',
                            tone: 'amber',
                            line: idx,
                            label,
                            message: `Margin is ${marginPct.toFixed(1)}% — below the ${LOW_MARGIN_THRESHOLD}% target.`,
                        });
                    }
                }
            }
        });

        return list;
    }, [data.items, productCache, marketerProfit]);

    // Quick per-line warning lookup for inline badges in the items table.
    const warningsByLine = useMemo(() => {
        const map = new Map();
        for (const w of warnings) {
            if (!map.has(w.line)) map.set(w.line, []);
            map.get(w.line).push(w);
        }
        return map;
    }, [warnings]);

    /* Submit gate: require a customer (existing or inline name+phone) AND at least one item. */
    const customerReady = data.customer_id
        ? true
        : (data.customer.name?.trim() && data.customer.primary_phone?.trim());
    const itemsReady = data.items.length > 0;
    const canSubmit = !processing && customerReady && itemsReady;

    const useExistingCustomer = () => {
        if (!matchedCustomer) return;
        setData('customer_id', matchedCustomer.id);
        setData('customer_address', matchedCustomer.default_address ?? '');
        setData('city', matchedCustomer.city ?? '');
        setData('governorate', matchedCustomer.governorate ?? '');
        setData('country', matchedCustomer.country ?? 'Egypt');
        // Phase 5.8: also mirror the customer's secondary phone +
        // WhatsApp preference onto the order snapshot so the matched
        // customer's contact context flows in by default.
        setData('customer_phone_secondary', matchedCustomer.secondary_phone ?? '');
        setData('customer_phone_whatsapp', matchedCustomer.primary_phone_whatsapp ?? true);
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
            <PageHeader title="New order" subtitle="Capture customer, items, and totals" />

            {/* O-1: duplicate-source banner. Only renders when the user
                landed via `?duplicate_from=<id>` and the source order was
                resolvable. Non-blocking — pure context. */}
            {duplicate_from && (
                <div className="mb-3 rounded-md border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm text-indigo-800">
                    <div className="flex items-center justify-between gap-2">
                        <div>
                            <span className="font-semibold">Duplicating from</span>{' '}
                            <Link href={route('orders.show', duplicate_from.source_order_id)} className="font-mono text-xs text-indigo-700 hover:underline">
                                {duplicate_from.source_order_number}
                            </Link>{' '}
                            <span className="text-[12px]">— customer, items, and shipping have been pre-filled. Adjust before saving.</span>
                        </div>
                    </div>
                </div>
            )}

            {/* C-1: customer prefill banner. Renders only when arriving
                via `?customer_id=` (not via duplicate_from). Items remain
                empty — operator picks them. */}
            {prefill_customer && !duplicate_from && (
                <div className="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
                    <div className="flex items-center justify-between gap-2">
                        <div>
                            <span className="font-semibold">Creating order for</span>{' '}
                            <Link href={route('customers.show', prefill_customer.id)} className="font-medium text-emerald-700 hover:underline">
                                {prefill_customer.name}
                            </Link>{' '}
                            <span className="text-[12px]">— customer details pre-filled. Add items below.</span>
                        </div>
                    </div>
                </div>
            )}

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
                        <div className="rounded-md border border-emerald-200 bg-emerald-50 p-4">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-200 text-[11px] font-semibold text-emerald-800">
                                            ✓
                                        </span>
                                        <div className="text-sm font-semibold text-slate-800">{matchedCustomer.name}</div>
                                    </div>
                                    <div className="mt-1.5 grid grid-cols-1 gap-x-4 gap-y-0.5 text-xs text-slate-600 sm:grid-cols-2">
                                        <div>📞 {matchedCustomer.primary_phone}</div>
                                        {matchedCustomer.secondary_phone && <div>📞 {matchedCustomer.secondary_phone}</div>}
                                        {matchedCustomer.email && <div>✉ {matchedCustomer.email}</div>}
                                        {matchedCustomer.city && <div>📍 {matchedCustomer.city}{matchedCustomer.governorate ? `, ${matchedCustomer.governorate}` : ''}</div>}
                                    </div>
                                    {matchedCustomer.default_address && (
                                        <div className="mt-1 text-xs text-slate-600">📦 <span className="text-slate-700">{matchedCustomer.default_address}</span></div>
                                    )}
                                    {/* Phase 5.8: per-order phone + WhatsApp snapshot for matched customer */}
                                    <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <FormField label="Secondary phone (this order)" name="customer_phone_secondary" value={data.customer_phone_secondary} onChange={(v) => setData('customer_phone_secondary', v)} error={errors.customer_phone_secondary} hint="Optional · stored alongside this order" />
                                        <label className="mt-6 flex items-center gap-2 text-sm text-slate-700">
                                            <input
                                                type="checkbox"
                                                checked={!!data.customer_phone_whatsapp}
                                                onChange={(e) => setData('customer_phone_whatsapp', e.target.checked)}
                                                className="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                            />
                                            <span aria-hidden="true" className="text-base">🟢</span>
                                            <span>Primary phone reachable on WhatsApp</span>
                                        </label>
                                    </div>
                                    <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                        <StatusBadge value={matchedCustomer.customer_type} />
                                        <StatusBadge value={matchedCustomer.risk_level} />
                                        <span className="text-[11px] text-slate-500">
                                            {matchedCustomer.orders_count ?? 0} orders · {matchedCustomer.returned_orders_count ?? 0} returned
                                        </span>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setData('customer_id', null);
                                        setMatchedCustomer(null);
                                    }}
                                    className="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-medium text-slate-600 hover:bg-slate-50"
                                >
                                    Use different customer
                                </button>
                            </div>
                        </div>
                    ) : (
                        <>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                    <FormField
                                        label="Primary phone"
                                        name="customer.primary_phone"
                                        value={data.customer.primary_phone}
                                        onChange={(v) => setData('customer', { ...data.customer, primary_phone: v })}
                                        error={errors['customer.primary_phone']}
                                        required
                                        hint={matchedCustomer ? null : 'We auto-match existing customers as you type'}
                                    />
                                    {/* Phase 5.8 — WhatsApp checkbox sits next to the main phone. */}
                                    <label className="mt-2 flex items-center gap-2 text-sm text-slate-700">
                                        <input
                                            type="checkbox"
                                            checked={!!data.customer.primary_phone_whatsapp}
                                            onChange={(e) => {
                                                const v = e.target.checked;
                                                setData('customer', { ...data.customer, primary_phone_whatsapp: v });
                                                setData('customer_phone_whatsapp', v);
                                            }}
                                            className="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span aria-hidden="true" className="text-base">🟢</span>
                                        <span>Reachable on WhatsApp</span>
                                    </label>
                                </div>
                                <FormField
                                    label="Name"
                                    name="customer.name"
                                    value={data.customer.name}
                                    onChange={(v) => setData('customer', { ...data.customer, name: v })}
                                    error={errors['customer.name']}
                                    required
                                />
                                <FormField label="Email" type="email" name="customer.email" value={data.customer.email} onChange={(v) => setData('customer', { ...data.customer, email: v })} error={errors['customer.email']} />
                                <FormField
                                    label="Secondary phone"
                                    name="customer.secondary_phone"
                                    value={data.customer.secondary_phone}
                                    onChange={(v) => {
                                        setData('customer', { ...data.customer, secondary_phone: v });
                                        // Phase 5.8: mirror to per-order snapshot.
                                        setData('customer_phone_secondary', v);
                                    }}
                                    error={errors['customer.secondary_phone']}
                                    hint="Optional · also stored on the order"
                                />
                                <LocationSelect
                                    locations={locations}
                                    country={data.customer.country}
                                    state={data.customer.governorate}
                                    city={data.customer.city}
                                    onChange={({ country, state, city }) => {
                                        setData('customer', { ...data.customer, country, governorate: state, city });
                                        // Phase 5.8: customer's main address is the shipping address.
                                        setData('country', country);
                                        setData('governorate', state);
                                        setData('city', city);
                                    }}
                                    errors={{
                                        country: errors['customer.country'],
                                        state: errors['customer.governorate'],
                                        city: errors['customer.city'],
                                    }}
                                    required
                                />
                                {/* Phase 5.8 — main customer address (also used as shipping address). */}
                                <FormField
                                    label="Main address"
                                    name="customer_address"
                                    error={errors.customer_address}
                                    className="sm:col-span-2"
                                    required
                                    hint="Used for both billing and shipping"
                                >
                                    <textarea
                                        id="customer_address"
                                        rows={2}
                                        value={data.customer_address}
                                        onChange={(e) => setData('customer_address', e.target.value)}
                                        className="mt-1 block w-full rounded-md border-slate-300 shadow-sm sm:text-sm"
                                    />
                                </FormField>
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

                {/* Order details — initial status, external ref, entry code, source, marketer (Phase 5.4 + 5.8 + 5.9) */}
                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Order details</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label className="block text-sm font-medium text-slate-700">Initial status</label>
                            <div className="mt-1 inline-flex items-center rounded-md bg-slate-100 px-2.5 py-1.5 text-xs font-semibold text-slate-700">
                                New
                            </div>
                            <p className="mt-1 text-[11px] text-slate-400">Status changes happen after the order is created.</p>
                        </div>
                        <FormField
                            label="External order reference"
                            name="external_order_reference"
                            value={data.external_order_reference}
                            onChange={(v) => setData('external_order_reference', v)}
                            error={errors.external_order_reference}
                            hint="Optional · website / marketplace order id (e.g. WEB-10295)"
                        />
                        <div>
                            <label className="block text-sm font-medium text-slate-700">Entered by (entry code)</label>
                            <div className="mt-1 inline-flex items-center rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1.5 font-mono text-xs text-slate-700">
                                {entry_code_preview ?? '—'}
                            </div>
                            <p className="mt-1 text-[11px] text-slate-400">Auto-detected. Marketer orders use the marketer&apos;s code.</p>
                        </div>
                        <FormField label="Source" name="source" value={data.source} onChange={(v) => setData('source', v)} error={errors.source} hint="e.g. Facebook, TikTok, Walk-in" />
                        {/* Phase 5.9 — admin-only "On behalf of marketer" picker. Drives the live profit preview. */}
                        <FormField
                            label="On behalf of marketer"
                            name="marketer_id"
                            error={errors.marketer_id}
                            hint="Optional · enables the marketer profit preview"
                            className="lg:col-span-2"
                        >
                            <select
                                id="marketer_id"
                                value={data.marketer_id ?? ''}
                                onChange={(e) => setData('marketer_id', e.target.value || null)}
                                className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                            >
                                <option value="">— None (admin-side order) —</option>
                                {marketers.map((m) => (
                                    <option key={m.id} value={m.id}>
                                        {m.code} — {m.name ?? '(no name)'}{m.tier_name ? ` · ${m.tier_name}` : ''}
                                    </option>
                                ))}
                            </select>
                        </FormField>
                        {/* Marketer profit preview block (Phase 5.9). Only renders once a marketer + items are present.
                            Cost/profit gate: hidden for users without `orders.view_profit`
                            (Order Agents, Warehouse Agents, Viewers, Marketers). The
                            backing endpoint also enforces the same permission. */}
                        {can_view_profit && marketerProfit && marketerProfit.lines && (
                            <div className="lg:col-span-2 rounded-md border border-emerald-200 bg-emerald-50 p-3">
                                <div className="flex items-center justify-between text-xs font-semibold text-emerald-800">
                                    <span>Marketer profit preview</span>
                                    <span className="font-mono text-sm">{sym}{Number(marketerProfit.total ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                                </div>
                                <div className="mt-1.5 text-[11px] text-emerald-700">
                                    {marketerProfit.marketer?.code}
                                    {marketerProfit.marketer?.tier ? ` · Tier ${marketerProfit.marketer.tier}` : ''}
                                    {' · '}
                                    {marketerProfit.lines.length} line{marketerProfit.lines.length === 1 ? '' : 's'}
                                </div>
                                <details className="mt-2">
                                    <summary className="cursor-pointer text-[11px] text-emerald-700 hover:text-emerald-900">Per-line breakdown</summary>
                                    <table className="mt-1 w-full text-[11px] tabular-nums">
                                        <thead className="text-emerald-700">
                                            <tr>
                                                <th className="text-left">#</th>
                                                <th className="text-right">qty</th>
                                                <th className="text-right">price</th>
                                                <th className="text-right">cost</th>
                                                <th className="text-right">ship</th>
                                                <th className="text-right">VAT%</th>
                                                <th className="text-right">profit</th>
                                                <th className="text-left pl-2">src</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {marketerProfit.lines.map((l, idx) => (
                                                <tr key={idx} className="text-emerald-900">
                                                    <td>{idx + 1}</td>
                                                    <td className="text-right">{l.quantity}</td>
                                                    <td className="text-right">{l.unit_price.toFixed(2)}</td>
                                                    <td className="text-right">{l.cost_price.toFixed(2)}</td>
                                                    <td className="text-right">{l.shipping_cost.toFixed(2)}</td>
                                                    <td className="text-right">{l.vat_percent.toFixed(2)}</td>
                                                    <td className="text-right font-semibold">{l.profit.toFixed(2)}</td>
                                                    <td className="pl-2 text-[10px] text-emerald-700">{l.source}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </details>
                            </div>
                        )}
                    </div>
                </section>

                {/* Product selection */}
                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Product selection</h2>

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
                        {searchLoading && searchResults.length === 0 ? (
                            <div className="px-3 py-6 text-center text-xs text-slate-400" aria-busy="true">
                                Searching…
                            </div>
                        ) : searchResults.length === 0 ? (
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
                    <div className="mt-5 mb-2 flex items-center justify-between">
                        <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-500">Order items</h3>
                        {data.items.length > 0 && (
                            <span className="text-[11px] text-slate-400">
                                {data.items.length} item{data.items.length === 1 ? '' : 's'} ·
                                {' '}{data.items.reduce((acc, it) => acc + Number(it.quantity || 0), 0)} units
                            </span>
                        )}
                    </div>
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
                                        const p = lookupProduct(it.product_id);
                                        const lineTotal = (Number(it.unit_price) * Number(it.quantity)) - Number(it.discount_amount || 0);
                                        const overReserved = p && Number(it.quantity) > Number(p.available);
                                        // O-1: per-line warnings collected from the warnings panel.
                                        const lineWarnings = warningsByLine.get(idx) ?? [];
                                        const minPrice = Number(p?.minimum_selling_price ?? 0);
                                        const isBelowMin = minPrice > 0 && Number(it.unit_price) < minPrice;
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
                                                        className={'w-20 rounded-md text-right text-xs tabular-nums ' + (isBelowMin ? 'border-red-300 bg-red-50 text-red-700' : 'border-slate-300')}
                                                        title={isBelowMin ? `Below minimum (${minPrice.toFixed(2)})` : ''}
                                                    />
                                                    {isBelowMin && (
                                                        <div className="mt-0.5 text-[10px] text-red-600">min {minPrice.toFixed(2)}</div>
                                                    )}
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

                {/* Shipping & Fees */}
                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Shipping &amp; fees</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <FormField label="Shipping" name="shipping_amount" type="number" value={data.shipping_amount} onChange={(v) => setData('shipping_amount', v)} error={errors.shipping_amount} />
                        <FormField label="Discount" name="discount_amount" type="number" value={data.discount_amount} onChange={(v) => setData('discount_amount', v)} error={errors.discount_amount} />
                        <FormField label="Extra fees" name="extra_fees" type="number" value={data.extra_fees} onChange={(v) => setData('extra_fees', v)} error={errors.extra_fees} />
                    </div>
                </section>

                {/* Review Total — read-only summary mirroring the backend math */}
                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Review total</h2>
                    <dl className="space-y-1.5 text-sm">
                        <div className="flex justify-between">
                            <dt className="text-slate-500">Subtotal</dt>
                            <dd className="tabular-nums text-slate-800">{sym}{totals.subtotal}</dd>
                        </div>
                        {totals.hasTax && (
                            <div className="flex justify-between">
                                <dt className="text-slate-500">Tax</dt>
                                <dd className="tabular-nums text-slate-800">{sym}{totals.tax}</dd>
                            </div>
                        )}
                        <div className="flex justify-between">
                            <dt className="text-slate-500">Shipping</dt>
                            <dd className="tabular-nums text-slate-800">{sym}{totals.shipping}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-500">Discount</dt>
                            <dd className="tabular-nums text-red-700">−{sym}{totals.discount}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-500">Extra fees</dt>
                            <dd className="tabular-nums text-slate-800">{sym}{totals.extra}</dd>
                        </div>
                        <div className="my-1 border-t border-slate-200" />
                        <div className="flex justify-between text-base">
                            <dt className="font-semibold text-slate-800">Grand total</dt>
                            <dd className="font-semibold tabular-nums text-slate-900">{sym}{totals.total}</dd>
                        </div>
                    </dl>
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

                {/* O-1: Pre-submit warnings panel. Non-blocking — informs
                    the operator of issues the server might not flag. */}
                {warnings.length > 0 && (
                    <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm" role="status">
                        <div className="flex items-center justify-between gap-2">
                            <div className="font-semibold text-amber-900">
                                Heads up — {warnings.length} warning{warnings.length === 1 ? '' : 's'}
                            </div>
                            <span className="text-[11px] text-amber-700">Save is still enabled. Review before submitting.</span>
                        </div>
                        <ul className="mt-2 list-disc space-y-0.5 pl-5 text-[12px] text-amber-900">
                            {warnings.map((w, i) => (
                                <li key={i} className={w.tone === 'red' ? 'text-red-700' : 'text-amber-900'}>
                                    <span className="font-medium">{w.label}:</span> {w.message}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* O-1: Save action group. Primary "Save" plus secondary
                    variants that change only the post-save redirect.
                    Buttons set `submit_action` then submit the form, so
                    a single backend code path handles all of them. */}
                <div className="flex flex-wrap items-center justify-end gap-2">
                    {!canSubmit && !processing && (
                        <span className="text-xs text-slate-500">
                            {!customerReady && !itemsReady && 'Add a customer and at least one item to continue.'}
                            {!customerReady && itemsReady && 'Pick or add a customer to continue.'}
                            {customerReady && !itemsReady && 'Add at least one item to continue.'}
                        </span>
                    )}
                    <Link href={route('orders.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>

                    {/* Secondary saves — always visible when canSubmit; greyed otherwise. */}
                    <button
                        type="submit"
                        disabled={!canSubmit}
                        onClick={() => setData('submit_action', 'save_add_new')}
                        title="Create this order then open a fresh blank form"
                        className="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Save &amp; Add New
                    </button>
                    <button
                        type="submit"
                        disabled={!canSubmit}
                        onClick={() => setData('submit_action', 'save_duplicate')}
                        title="Create this order then start another pre-filled from it"
                        className="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Save &amp; Duplicate
                    </button>
                    {can_print_label && (
                        <button
                            type="submit"
                            disabled={!canSubmit}
                            onClick={() => setData('submit_action', 'save_print_label')}
                            title="Create this order then jump straight to the shipping label"
                            className="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Save &amp; Print Label
                        </button>
                    )}

                    <button
                        type="submit"
                        disabled={!canSubmit}
                        onClick={() => setData('submit_action', 'save')}
                        title={!canSubmit ? 'Complete customer + add items first' : ''}
                        className="inline-flex items-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {processing && (
                            <svg className="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={3}>
                                <circle cx="12" cy="12" r="10" opacity="0.25" />
                                <path d="M22 12a10 10 0 0 1-10 10" strokeLinecap="round" />
                            </svg>
                        )}
                        <span>{processing ? 'Saving…' : 'Save order'}</span>
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
 *
 * UX (Phase 4):
 *   - autoFocus on Name field so operators can start typing immediately
 *   - debounced phone-lookup → if a customer with that phone already
 *     exists, the modal switches to a "Customer already exists" panel
 *     with a one-click "Use existing customer" action that hands the
 *     existing record back to the parent (no duplicate created)
 *   - notes field added (optional)
 *   - submitting button shows a spinner-style label + disables itself
 *   - inline field validation rendered via FormField error props
 *
 * POSTs to `/customers` with an Accept: application/json header — the
 * controller (CustomersController::store) returns the created customer
 * as JSON in that case. RBAC is enforced by the same `permission:customers.create`
 * middleware on the route.
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
        notes: '',
    });
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const [generalError, setGeneralError] = useState(null);
    const [duplicateMatch, setDuplicateMatch] = useState(null);
    const nameInputRef = useRef(null);

    const update = (k, v) => setForm((f) => ({ ...f, [k]: v }));

    // Autofocus the name input when the modal mounts.
    useEffect(() => {
        const t = setTimeout(() => nameInputRef.current?.focus(), 50);
        return () => clearTimeout(t);
    }, []);

    // Debounced duplicate-phone check using the existing /customers/lookup
    // endpoint. Runs as the operator types; clears as soon as the phone
    // changes back to a non-matching value.
    useEffect(() => {
        const phone = (form.primary_phone || '').trim();
        if (phone.length < 4) {
            setDuplicateMatch(null);
            return;
        }
        const timer = setTimeout(() => {
            fetch(`${route('customers.lookup')}?phone=${encodeURIComponent(phone)}`, {
                headers: { Accept: 'application/json' },
            })
                .then((r) => (r.ok ? r.json() : { customer: null }))
                .then((j) => setDuplicateMatch(j.customer))
                .catch(() => setDuplicateMatch(null));
        }, 400);
        return () => clearTimeout(timer);
    }, [form.primary_phone]);

    const submit = async (e) => {
        e.preventDefault();
        if (submitting) return;
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
                        <FormField label="Name" name="name" error={errors.name?.[0]} required>
                            <input
                                ref={nameInputRef}
                                id="name"
                                type="text"
                                value={form.name}
                                onChange={(e) => update('name', e.target.value)}
                                className="mt-1 block w-full rounded-md border-slate-300 shadow-sm sm:text-sm"
                                autoComplete="off"
                            />
                        </FormField>
                        <FormField label="Primary phone" name="primary_phone" value={form.primary_phone} onChange={(v) => update('primary_phone', v)} error={errors.primary_phone?.[0]} required />

                        {/* Duplicate-phone banner — shows BEFORE the operator
                            invests further effort. Spans both columns. */}
                        {duplicateMatch && (
                            <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 sm:col-span-2">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <strong>Customer already exists:</strong>{' '}
                                        <span className="font-semibold">{duplicateMatch.name}</span>{' '}
                                        <span className="text-amber-700">· {duplicateMatch.primary_phone}</span>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => onCreated(duplicateMatch)}
                                        className="rounded-md border border-amber-300 bg-white px-2.5 py-1 text-[11px] font-medium text-amber-800 hover:bg-amber-100"
                                    >
                                        Use existing customer
                                    </button>
                                </div>
                            </div>
                        )}

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
                        <FormField label="Detailed address" name="default_address" error={errors.default_address?.[0]} required className="sm:col-span-2">
                            <textarea
                                id="default_address"
                                rows={2}
                                value={form.default_address}
                                onChange={(e) => update('default_address', e.target.value)}
                                className="mt-1 block w-full rounded-md border-slate-300 shadow-sm sm:text-sm"
                            />
                        </FormField>
                        <FormField label="Notes (optional)" name="notes" error={errors.notes?.[0]} className="sm:col-span-2">
                            <textarea
                                id="notes"
                                rows={2}
                                value={form.notes}
                                onChange={(e) => update('notes', e.target.value)}
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
                            disabled={submitting || !!duplicateMatch}
                            className="inline-flex items-center gap-2 rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                        >
                            {submitting && (
                                <svg className="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={3}>
                                    <circle cx="12" cy="12" r="10" opacity="0.25" />
                                    <path d="M22 12a10 10 0 0 1-10 10" strokeLinecap="round" />
                                </svg>
                            )}
                            <span>{submitting ? 'Saving…' : 'Save & select'}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
