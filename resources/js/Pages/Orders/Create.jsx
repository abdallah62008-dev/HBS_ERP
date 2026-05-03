import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormField from '@/Components/FormField';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

export default function OrderCreate({ products }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';
    const can = useCan();

    const [matchedCustomer, setMatchedCustomer] = useState(null);
    const [duplicate, setDuplicate] = useState(null);
    const [quickModalOpen, setQuickModalOpen] = useState(false);

    const { data, setData, post, processing, errors, transform } = useForm({
        customer_id: null,
        customer: { name: '', primary_phone: '', secondary_phone: '', email: '', city: '', governorate: '', country: 'Egypt' },
        customer_address: '',
        city: '',
        governorate: '',
        country: 'Egypt',
        source: '',
        notes: '',
        internal_notes: '',
        discount_amount: 0,
        shipping_amount: 0,
        extra_fees: 0,
        items: [{ product_id: '', quantity: 1, unit_price: 0, discount_amount: 0 }],
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
        if (key === 'product_id') {
            const p = products.find((x) => x.id === Number(value));
            if (p) next[idx].unit_price = p.selling_price;
        }
        setData('items', next);
    };

    const addItem = () => setData('items', [...data.items, { product_id: '', quantity: 1, unit_price: 0, discount_amount: 0 }]);
    const removeItem = (idx) => setData('items', data.items.filter((_, i) => i !== idx));

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
                                <FormField label="City" name="customer.city" value={data.customer.city} onChange={(v) => setData('customer', { ...data.customer, city: v })} error={errors['customer.city']} required />
                                <FormField label="Country" name="customer.country" value={data.customer.country} onChange={(v) => setData('customer', { ...data.customer, country: v })} error={errors['customer.country']} required />
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
                        <FormField label="City" name="city" value={data.city} onChange={(v) => setData('city', v)} error={errors.city} required />
                        <FormField label="Governorate" name="governorate" value={data.governorate} onChange={(v) => setData('governorate', v)} error={errors.governorate} />
                        <FormField label="Country" name="country" value={data.country} onChange={(v) => setData('country', v)} error={errors.country} required />
                        <FormField label="Source" name="source" value={data.source} onChange={(v) => setData('source', v)} error={errors.source} hint="e.g. Facebook, TikTok, Walk-in" />
                    </div>
                </section>

                {/* Items */}
                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-slate-700">Items</h2>
                        <button type="button" onClick={addItem} className="rounded-md border border-slate-300 bg-white px-2 py-1 text-xs">
                            + Add item
                        </button>
                    </div>

                    <div className="space-y-3">
                        {data.items.map((it, idx) => (
                            <div key={idx} className="grid grid-cols-12 gap-2">
                                <select
                                    value={it.product_id}
                                    onChange={(e) => updateItem(idx, 'product_id', e.target.value)}
                                    className="col-span-5 rounded-md border-slate-300 text-sm"
                                >
                                    <option value="">— Pick product (SKU) —</option>
                                    {products.map((p) => (
                                        <option key={p.id} value={p.id}>{p.sku} — {p.name}</option>
                                    ))}
                                </select>
                                <input type="number" min={1} value={it.quantity} onChange={(e) => updateItem(idx, 'quantity', e.target.value)} placeholder="Qty" className="col-span-1 rounded-md border-slate-300 text-sm" />
                                <input type="number" step="0.01" min={0} value={it.unit_price} onChange={(e) => updateItem(idx, 'unit_price', e.target.value)} placeholder="Unit price" className="col-span-2 rounded-md border-slate-300 text-sm" />
                                <input type="number" step="0.01" min={0} value={it.discount_amount} onChange={(e) => updateItem(idx, 'discount_amount', e.target.value)} placeholder="Discount" className="col-span-2 rounded-md border-slate-300 text-sm" />
                                <div className="col-span-1 self-center text-right text-sm tabular-nums text-slate-600">
                                    {sym}{((Number(it.unit_price) * Number(it.quantity)) - Number(it.discount_amount || 0)).toFixed(2)}
                                </div>
                                <button type="button" onClick={() => removeItem(idx)} className="col-span-1 self-center text-xs text-red-500 hover:underline" disabled={data.items.length === 1}>
                                    remove
                                </button>
                            </div>
                        ))}
                    </div>

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
                    initial={{
                        name: data.customer.name,
                        primary_phone: data.customer.primary_phone,
                        secondary_phone: data.customer.secondary_phone,
                        email: data.customer.email,
                        city: data.customer.city || data.city || 'Cairo',
                        country: data.customer.country || data.country || 'Egypt',
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
function QuickCustomerModal({ initial, onClose, onCreated }) {
    const [form, setForm] = useState({
        name: initial.name ?? '',
        primary_phone: initial.primary_phone ?? '',
        secondary_phone: initial.secondary_phone ?? '',
        email: initial.email ?? '',
        city: initial.city ?? 'Cairo',
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
                        <FormField label="City" name="city" value={form.city} onChange={(v) => update('city', v)} error={errors.city?.[0]} required />
                        <FormField label="Country" name="country" value={form.country} onChange={(v) => update('country', v)} error={errors.country?.[0]} required />
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
