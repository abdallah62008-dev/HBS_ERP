import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const STATUSES = ['', 'Not Collected', 'Collected', 'Partially Collected', 'Pending Settlement', 'Settlement Received', 'Rejected', 'Refunded'];

function fmt(n) {
    return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export default function CollectionsIndex({
    collections,
    filters,
    companies,
    cashboxes,
    payment_methods,
    totals,
    postable_statuses,
}) {
    const can = useCan();
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const [editingId, setEditingId] = useState(null);
    const [postingId, setPostingId] = useState(null);
    const [q, setQ] = useState(filters?.q ?? '');
    const [status, setStatus] = useState(filters?.status ?? '');
    const [companyId, setCompanyId] = useState(filters?.shipping_company_id ?? '');
    const [cashboxId, setCashboxId] = useState(filters?.cashbox_id ?? '');
    const [paymentMethodId, setPaymentMethodId] = useState(filters?.payment_method_id ?? '');
    const [posted, setPosted] = useState(filters?.posted ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('collections.index'), {
            q: q || undefined,
            status: status || undefined,
            shipping_company_id: companyId || undefined,
            cashbox_id: cashboxId || undefined,
            payment_method_id: paymentMethodId || undefined,
            posted: posted || undefined,
        }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Collections">
            <Head title="Collections" />
            <PageHeader title="Collections (COD)" subtitle={`${collections.total} record${collections.total === 1 ? '' : 's'}`} />

            <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-4">
                <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <div className="text-xs font-medium uppercase tracking-wide text-amber-700">Pending</div>
                    <div className="mt-1 text-2xl font-semibold tabular-nums text-amber-800">{sym}{fmt(totals.pending_amount)}</div>
                </div>
                <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                    <div className="text-xs font-medium uppercase tracking-wide text-emerald-700">Collected</div>
                    <div className="mt-1 text-2xl font-semibold tabular-nums text-emerald-800">{sym}{fmt(totals.collected_amount)}</div>
                </div>
                <div className="rounded-lg border border-indigo-200 bg-indigo-50 p-4">
                    <div className="text-xs font-medium uppercase tracking-wide text-indigo-700">Posted to cashbox</div>
                    <div className="mt-1 text-2xl font-semibold tabular-nums text-indigo-800">{sym}{fmt(totals.posted_amount)}</div>
                </div>
                <div className="rounded-lg border border-rose-200 bg-rose-50 p-4">
                    <div className="text-xs font-medium uppercase tracking-wide text-rose-700">Awaiting posting</div>
                    <div className="mt-1 text-2xl font-semibold tabular-nums text-rose-800">{sym}{fmt(totals.unposted_amount)}</div>
                </div>
            </div>

            <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div className="flex-1 min-w-[180px]">
                    <label className="text-[11px] font-medium uppercase text-slate-500">Search</label>
                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Order # or customer" className="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                </div>
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Status</label>
                    <select value={status} onChange={(e) => setStatus(e.target.value)} className="mt-1 rounded-md border-slate-300 text-sm">
                        {STATUSES.map((s) => <option key={s} value={s}>{s || 'Any'}</option>)}
                    </select>
                </div>
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Carrier</label>
                    <select value={companyId} onChange={(e) => setCompanyId(e.target.value)} className="mt-1 rounded-md border-slate-300 text-sm">
                        <option value="">Any</option>
                        {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                </div>
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Cashbox</label>
                    <select value={cashboxId} onChange={(e) => setCashboxId(e.target.value)} className="mt-1 rounded-md border-slate-300 text-sm">
                        <option value="">Any</option>
                        {cashboxes.map((c) => <option key={c.id} value={c.id}>{c.name}{!c.is_active ? ' (inactive)' : ''}</option>)}
                    </select>
                </div>
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Method</label>
                    <select value={paymentMethodId} onChange={(e) => setPaymentMethodId(e.target.value)} className="mt-1 rounded-md border-slate-300 text-sm">
                        <option value="">Any</option>
                        {payment_methods.map((p) => <option key={p.id} value={p.id}>{p.name}{!p.is_active ? ' (inactive)' : ''}</option>)}
                    </select>
                </div>
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Posted?</label>
                    <select value={posted} onChange={(e) => setPosted(e.target.value)} className="mt-1 rounded-md border-slate-300 text-sm">
                        <option value="">All</option>
                        <option value="posted">Posted</option>
                        <option value="unposted">Unposted</option>
                    </select>
                </div>
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Order</th>
                            <th className="px-4 py-2.5">Carrier</th>
                            <th className="px-4 py-2.5 text-right">Due</th>
                            <th className="px-4 py-2.5 text-right">Collected</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5">Method</th>
                            <th className="px-4 py-2.5">Cashbox</th>
                            <th className="px-4 py-2.5">Posted?</th>
                            <th className="px-4 py-2.5">Settlement</th>
                            <th className="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {collections.data.length === 0 && (
                            <tr><td colSpan={10} className="px-4 py-12 text-center text-sm text-slate-400">No collections.</td></tr>
                        )}
                        {collections.data.map((c) => (
                            <CollectionRow
                                key={c.id}
                                collection={c}
                                sym={sym}
                                cashboxes={cashboxes}
                                paymentMethods={payment_methods}
                                postableStatuses={postable_statuses}
                                editing={editingId === c.id}
                                posting={postingId === c.id}
                                onEdit={() => { setEditingId(c.id); setPostingId(null); }}
                                onPost={() => { setPostingId(c.id); setEditingId(null); }}
                                onCancel={() => { setEditingId(null); setPostingId(null); }}
                                canEdit={can('collections.update')}
                                canPost={can('collections.reconcile_settlement')}
                            />
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={collections.links} />
        </AuthenticatedLayout>
    );
}

function CollectionRow({
    collection,
    sym,
    cashboxes,
    paymentMethods,
    postableStatuses,
    editing,
    posting,
    onEdit,
    onPost,
    onCancel,
    canEdit,
    canPost,
}) {
    // Inline update form (status / amount / settlement / cashbox+method assignment).
    const f = useForm({
        collection_status: collection.collection_status,
        amount_collected: collection.amount_collected,
        settlement_reference: collection.settlement_reference ?? '',
        settlement_date: collection.settlement_date ?? '',
        notes: collection.notes ?? '',
        payment_method_id: collection.payment_method_id ?? '',
        cashbox_id: collection.cashbox_id ?? '',
    });

    // Separate, smaller form for posting to cashbox.
    const p = useForm({
        cashbox_id: collection.cashbox_id ?? '',
        payment_method_id: collection.payment_method_id ?? '',
        amount: collection.amount_collected ?? '',
        occurred_at: collection.settlement_date ?? new Date().toISOString().slice(0, 10),
    });

    const save = (e) => {
        e.preventDefault();
        f.put(route('collections.update', collection.id), { onSuccess: onCancel });
    };

    const post = (e) => {
        e.preventDefault();
        p.post(route('collections.post-to-cashbox', collection.id), { onSuccess: onCancel });
    };

    const isPosted = !!collection.cashbox_transaction_id;
    const isPostable =
        !isPosted &&
        postableStatuses.includes(collection.collection_status) &&
        Number(collection.amount_collected) > 0;

    if (editing) {
        return (
            <tr className="bg-slate-50 align-top">
                <td className="px-4 py-2.5 font-mono text-xs" colSpan={10}>
                    <form onSubmit={save} className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div className="col-span-2 sm:col-span-1">
                            <label className="text-[10px] uppercase text-slate-500">Status</label>
                            <select value={f.data.collection_status} onChange={(e) => f.setData('collection_status', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                                {STATUSES.filter(Boolean).map((s) => <option key={s} value={s}>{s}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="text-[10px] uppercase text-slate-500">Amount collected</label>
                            <input type="number" step="0.01" min={0} value={f.data.amount_collected} onChange={(e) => f.setData('amount_collected', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                        </div>
                        <div>
                            <label className="text-[10px] uppercase text-slate-500">Settlement ref</label>
                            <input value={f.data.settlement_reference} onChange={(e) => f.setData('settlement_reference', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                        </div>
                        <div>
                            <label className="text-[10px] uppercase text-slate-500">Settlement date</label>
                            <input type="date" value={f.data.settlement_date ?? ''} onChange={(e) => f.setData('settlement_date', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                        </div>

                        <div>
                            <label className="text-[10px] uppercase text-slate-500">Payment method</label>
                            <select value={f.data.payment_method_id ?? ''} onChange={(e) => f.setData('payment_method_id', e.target.value || null)} disabled={isPosted} className="mt-1 block w-full rounded-md border-slate-300 text-sm disabled:bg-slate-100 disabled:text-slate-500">
                                <option value="">— Pick —</option>
                                {paymentMethods.filter((p) => p.is_active || p.id === collection.payment_method_id).map((p) => (
                                    <option key={p.id} value={p.id}>{p.name}{!p.is_active ? ' (inactive)' : ''}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="text-[10px] uppercase text-slate-500">Cashbox</label>
                            <select value={f.data.cashbox_id ?? ''} onChange={(e) => f.setData('cashbox_id', e.target.value || null)} disabled={isPosted} className="mt-1 block w-full rounded-md border-slate-300 text-sm disabled:bg-slate-100 disabled:text-slate-500">
                                <option value="">— Pick —</option>
                                {cashboxes.filter((c) => c.is_active || c.id === collection.cashbox_id).map((c) => (
                                    <option key={c.id} value={c.id}>{c.name}{!c.is_active ? ' (inactive)' : ''}</option>
                                ))}
                            </select>
                        </div>
                        <div className="col-span-2 sm:col-span-2">
                            <label className="text-[10px] uppercase text-slate-500">Notes</label>
                            <input value={f.data.notes ?? ''} onChange={(e) => f.setData('notes', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                        </div>

                        <div className="col-span-2 sm:col-span-4 flex justify-end gap-2">
                            <button type="button" onClick={onCancel} className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs">Cancel</button>
                            <button type="submit" disabled={f.processing} className="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-medium text-white disabled:opacity-60">
                                {f.processing ? 'Saving…' : 'Save'}
                            </button>
                        </div>
                    </form>
                </td>
            </tr>
        );
    }

    if (posting) {
        return (
            <tr className="bg-indigo-50 align-top">
                <td className="px-4 py-2.5" colSpan={10}>
                    <form onSubmit={post} className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div>
                            <label className="text-[10px] uppercase text-slate-500">Cashbox *</label>
                            <select value={p.data.cashbox_id ?? ''} onChange={(e) => p.setData('cashbox_id', e.target.value)} required className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                                <option value="">— Pick —</option>
                                {cashboxes.filter((c) => c.is_active).map((c) => (
                                    <option key={c.id} value={c.id}>{c.name} ({c.currency_code})</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="text-[10px] uppercase text-slate-500">Payment method *</label>
                            <select value={p.data.payment_method_id ?? ''} onChange={(e) => p.setData('payment_method_id', e.target.value)} required className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                                <option value="">— Pick —</option>
                                {paymentMethods.filter((m) => m.is_active).map((m) => (
                                    <option key={m.id} value={m.id}>{m.name}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="text-[10px] uppercase text-slate-500">Amount</label>
                            <input type="number" step="0.01" min={0.01} value={p.data.amount ?? ''} onChange={(e) => p.setData('amount', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                        </div>
                        <div>
                            <label className="text-[10px] uppercase text-slate-500">Occurred at</label>
                            <input type="date" value={p.data.occurred_at ?? ''} onChange={(e) => p.setData('occurred_at', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                        </div>

                        <div className="col-span-2 sm:col-span-4 flex items-center justify-between gap-2">
                            <span className="text-xs text-slate-500">
                                Records one cashbox transaction (IN). Cannot be undone in this phase.
                            </span>
                            <div className="flex gap-2">
                                <button type="button" onClick={onCancel} className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs">Cancel</button>
                                <button type="submit" disabled={p.processing} className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white disabled:opacity-60">
                                    {p.processing ? 'Posting…' : 'Post to cashbox'}
                                </button>
                            </div>
                        </div>
                    </form>
                </td>
            </tr>
        );
    }

    return (
        <tr className="hover:bg-slate-50">
            <td className="px-4 py-2.5 font-mono text-xs">
                <Link href={route('orders.show', collection.order_id)} className="text-slate-700 hover:text-indigo-600">{collection.order?.order_number}</Link>
                <div className="text-xs text-slate-500">{collection.order?.customer_name}</div>
            </td>
            <td className="px-4 py-2.5 text-slate-600">{collection.shipping_company?.name ?? '—'}</td>
            <td className="px-4 py-2.5 text-right tabular-nums">{sym}{Number(collection.amount_due).toFixed(2)}</td>
            <td className="px-4 py-2.5 text-right tabular-nums">{sym}{Number(collection.amount_collected).toFixed(2)}</td>
            <td className="px-4 py-2.5"><StatusBadge value={collection.collection_status} /></td>
            <td className="px-4 py-2.5 text-xs text-slate-600">
                {collection.payment_method ? collection.payment_method.name : <span className="text-slate-400">—</span>}
            </td>
            <td className="px-4 py-2.5 text-xs text-slate-600">
                {collection.cashbox ? collection.cashbox.name : <span className="text-slate-400">—</span>}
            </td>
            <td className="px-4 py-2.5">
                {isPosted ? (
                    <span className="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Posted</span>
                ) : isPostable ? (
                    <span className="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">Awaiting</span>
                ) : (
                    <span className="text-xs text-slate-400">—</span>
                )}
            </td>
            <td className="px-4 py-2.5 text-xs text-slate-500">
                {collection.settlement_reference ? `${collection.settlement_reference} · ${collection.settlement_date ?? ''}` : '—'}
            </td>
            <td className="px-4 py-2.5 text-right space-x-2">
                {canEdit && <button onClick={onEdit} className="text-xs text-indigo-600 hover:underline">Edit</button>}
                {canPost && isPostable && (
                    <button onClick={onPost} className="text-xs text-emerald-700 hover:underline">Post to cashbox</button>
                )}
            </td>
        </tr>
    );
}
