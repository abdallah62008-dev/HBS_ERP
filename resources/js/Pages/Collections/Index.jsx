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

export default function CollectionsIndex({ collections, filters, companies, totals }) {
    const can = useCan();
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const [editingId, setEditingId] = useState(null);
    const [q, setQ] = useState(filters?.q ?? '');
    const [status, setStatus] = useState(filters?.status ?? '');
    const [companyId, setCompanyId] = useState(filters?.shipping_company_id ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('collections.index'), {
            q: q || undefined, status: status || undefined, shipping_company_id: companyId || undefined,
        }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Collections">
            <Head title="Collections" />
            <PageHeader title="Collections (COD)" subtitle={`${collections.total} record${collections.total === 1 ? '' : 's'}`} />

            <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <div className="text-xs font-medium uppercase tracking-wide text-amber-700">Pending</div>
                    <div className="mt-1 text-2xl font-semibold tabular-nums text-amber-800">{sym}{fmt(totals.pending_amount)}</div>
                </div>
                <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                    <div className="text-xs font-medium uppercase tracking-wide text-emerald-700">Collected</div>
                    <div className="mt-1 text-2xl font-semibold tabular-nums text-emerald-800">{sym}{fmt(totals.collected_amount)}</div>
                </div>
            </div>

            <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div className="flex-1 min-w-[200px]">
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
                            <th className="px-4 py-2.5">Settlement</th>
                            <th className="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {collections.data.length === 0 && (
                            <tr><td colSpan={7} className="px-4 py-12 text-center text-sm text-slate-400">No collections.</td></tr>
                        )}
                        {collections.data.map((c) => (
                            <CollectionRow key={c.id} collection={c} sym={sym} editing={editingId === c.id} onEdit={() => setEditingId(c.id)} onCancel={() => setEditingId(null)} canEdit={can('collections.update')} />
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={collections.links} />
        </AuthenticatedLayout>
    );
}

function CollectionRow({ collection, sym, editing, onEdit, onCancel, canEdit }) {
    const f = useForm({
        collection_status: collection.collection_status,
        amount_collected: collection.amount_collected,
        settlement_reference: collection.settlement_reference ?? '',
        settlement_date: collection.settlement_date ?? '',
        notes: collection.notes ?? '',
    });

    const save = (e) => {
        e.preventDefault();
        f.put(route('collections.update', collection.id), { onSuccess: onCancel });
    };

    if (!editing) {
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
                <td className="px-4 py-2.5 text-xs text-slate-500">
                    {collection.settlement_reference ? `${collection.settlement_reference} · ${collection.settlement_date ?? ''}` : '—'}
                </td>
                <td className="px-4 py-2.5 text-right">
                    {canEdit && <button onClick={onEdit} className="text-xs text-indigo-600 hover:underline">Update</button>}
                </td>
            </tr>
        );
    }

    return (
        <tr className="bg-slate-50">
            <td className="px-4 py-2.5 font-mono text-xs">{collection.order?.order_number}</td>
            <td className="px-4 py-2.5 text-slate-500">—</td>
            <td className="px-4 py-2.5"></td>
            <td className="px-4 py-2.5">
                <input type="number" step="0.01" min={0} value={f.data.amount_collected} onChange={(e) => f.setData('amount_collected', e.target.value)} className="w-24 rounded-md border-slate-300 text-sm" />
            </td>
            <td className="px-4 py-2.5">
                <select value={f.data.collection_status} onChange={(e) => f.setData('collection_status', e.target.value)} className="rounded-md border-slate-300 text-sm">
                    {STATUSES.filter(Boolean).map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
            </td>
            <td className="px-4 py-2.5">
                <input value={f.data.settlement_reference} onChange={(e) => f.setData('settlement_reference', e.target.value)} placeholder="Ref" className="w-24 rounded-md border-slate-300 text-xs" />
                <input type="date" value={f.data.settlement_date ?? ''} onChange={(e) => f.setData('settlement_date', e.target.value)} className="mt-1 rounded-md border-slate-300 text-xs" />
            </td>
            <td className="px-4 py-2.5 text-right">
                <button onClick={save} disabled={f.processing} className="mr-2 text-xs text-indigo-600 hover:underline">Save</button>
                <button onClick={onCancel} className="text-xs text-slate-500 hover:underline">Cancel</button>
            </td>
        </tr>
    );
}
