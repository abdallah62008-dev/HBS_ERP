import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const STATUSES = ['', 'Draft', 'Received', 'Partially Received', 'Paid', 'Partially Paid', 'Unpaid', 'Cancelled'];

function fmt(n) {
    return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export default function PurchaseInvoicesIndex({ invoices, filters, suppliers }) {
    const can = useCan();
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const [q, setQ] = useState(filters?.q ?? '');
    const [status, setStatus] = useState(filters?.status ?? '');
    const [supplierId, setSupplierId] = useState(filters?.supplier_id ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('purchase-invoices.index'), {
            q: q || undefined, status: status || undefined, supplier_id: supplierId || undefined,
        }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Purchase invoices">
            <Head title="Purchase invoices" />
            <PageHeader
                title="Purchase invoices"
                subtitle={`${invoices.total} record${invoices.total === 1 ? '' : 's'}`}
                actions={
                    can('purchases.create') && (
                        <Link href={route('purchase-invoices.create')} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">
                            + New invoice
                        </Link>
                    )
                }
            />

            <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div className="flex-1 min-w-[200px]">
                    <label className="text-[11px] font-medium uppercase text-slate-500">Search</label>
                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Invoice #" className="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                </div>
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Status</label>
                    <select value={status} onChange={(e) => setStatus(e.target.value)} className="mt-1 rounded-md border-slate-300 text-sm">
                        {STATUSES.map((s) => <option key={s} value={s}>{s || 'Any'}</option>)}
                    </select>
                </div>
                <div>
                    <label className="text-[11px] font-medium uppercase text-slate-500">Supplier</label>
                    <select value={supplierId} onChange={(e) => setSupplierId(e.target.value)} className="mt-1 rounded-md border-slate-300 text-sm">
                        <option value="">Any</option>
                        {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </select>
                </div>
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Invoice #</th>
                            <th className="px-4 py-2.5">Supplier</th>
                            <th className="px-4 py-2.5">Warehouse</th>
                            <th className="px-4 py-2.5">Date</th>
                            <th className="px-4 py-2.5 text-right">Total</th>
                            <th className="px-4 py-2.5 text-right">Remaining</th>
                            <th className="px-4 py-2.5">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {invoices.data.length === 0 && (
                            <tr><td colSpan={7} className="px-4 py-12 text-center text-sm text-slate-400">No invoices match.</td></tr>
                        )}
                        {invoices.data.map((inv) => (
                            <tr key={inv.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-mono text-xs">
                                    <Link href={route('purchase-invoices.show', inv.id)} className="font-medium text-slate-700 hover:text-indigo-600">{inv.invoice_number}</Link>
                                </td>
                                <td className="px-4 py-2.5 text-slate-700">{inv.supplier?.name ?? '—'}</td>
                                <td className="px-4 py-2.5 text-slate-600">{inv.warehouse?.name ?? '—'}</td>
                                <td className="px-4 py-2.5 text-slate-500">{inv.invoice_date}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">{sym}{fmt(inv.total_amount)}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums text-amber-700">{sym}{fmt(inv.remaining_amount)}</td>
                                <td className="px-4 py-2.5"><StatusBadge value={inv.status} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={invoices.links} />
        </AuthenticatedLayout>
    );
}
