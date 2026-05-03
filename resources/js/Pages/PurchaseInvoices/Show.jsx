import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

function fmt(n) {
    return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export default function PurchaseInvoiceShow({ invoice }) {
    const can = useCan();
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const [payOpen, setPayOpen] = useState(false);
    const pay = useForm({ amount: invoice.remaining_amount ?? 0, payment_method: '', payment_date: new Date().toISOString().slice(0, 10), notes: '' });

    const isDraft = invoice.status === 'Draft';

    const handleApprove = () => {
        if (!confirm(`Approve invoice ${invoice.invoice_number}? This adds stock to ${invoice.warehouse?.name}.`)) return;
        router.post(route('purchase-invoices.approve', invoice.id));
    };

    const handleDelete = () => {
        if (!confirm('Delete this draft invoice?')) return;
        router.delete(route('purchase-invoices.destroy', invoice.id));
    };

    const submitPayment = (e) => {
        e.preventDefault();
        pay.post(route('purchase-invoices.payment', invoice.id), {
            onSuccess: () => { setPayOpen(false); pay.reset('amount', 'notes'); },
        });
    };

    return (
        <AuthenticatedLayout header={invoice.invoice_number}>
            <Head title={invoice.invoice_number} />

            <PageHeader
                title={<span className="font-mono">{invoice.invoice_number}</span>}
                subtitle={`${invoice.supplier?.name} · ${invoice.warehouse?.name} · ${invoice.invoice_date}`}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {isDraft && can('purchases.edit') && (
                            <Link href={route('purchase-invoices.edit', invoice.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">Edit</Link>
                        )}
                        {isDraft && can('purchases.approve') && (
                            <button onClick={handleApprove} className="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-500">
                                Approve & Receive
                            </button>
                        )}
                        {isDraft && can('purchases.delete') && (
                            <button onClick={handleDelete} className="rounded-md border border-red-200 bg-white px-3 py-2 text-sm text-red-600 hover:bg-red-50">Delete</button>
                        )}
                        {!isDraft && invoice.status !== 'Paid' && invoice.status !== 'Cancelled' && can('purchases.edit') && (
                            <button onClick={() => setPayOpen(true)} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">
                                Record payment
                            </button>
                        )}
                    </div>
                }
            />

            <div className="mb-4 flex items-center gap-2">
                <StatusBadge value={invoice.status} />
                {invoice.approved_at && <span className="text-xs text-slate-500">Approved {invoice.approved_at?.split('T')[0]} by {invoice.approved_by?.name}</span>}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="lg:col-span-2 rounded-lg border border-slate-200 bg-white">
                    <div className="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">
                        Items ({invoice.items.length})
                    </div>
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-5 py-2">SKU</th>
                                <th className="px-5 py-2">Product</th>
                                <th className="px-5 py-2 text-right">Qty</th>
                                <th className="px-5 py-2 text-right">Unit cost</th>
                                <th className="px-5 py-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {invoice.items.map((it) => (
                                <tr key={it.id}>
                                    <td className="px-5 py-2 font-mono text-xs">{it.sku}</td>
                                    <td className="px-5 py-2">{it.product?.name ?? '—'}</td>
                                    <td className="px-5 py-2 text-right tabular-nums">{it.quantity}</td>
                                    <td className="px-5 py-2 text-right tabular-nums">{fmt(it.unit_cost)}</td>
                                    <td className="px-5 py-2 text-right tabular-nums">{fmt(it.total_cost)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="space-y-4">
                    <div className="rounded-lg border border-slate-200 bg-white p-5">
                        <h2 className="mb-3 text-sm font-semibold text-slate-700">Totals</h2>
                        <Row k="Subtotal" v={`${sym}${fmt(invoice.subtotal)}`} />
                        <Row k="Discount" v={`–${sym}${fmt(invoice.discount_amount)}`} />
                        <Row k="Tax" v={`${sym}${fmt(invoice.tax_amount)}`} />
                        <Row k="Shipping" v={`${sym}${fmt(invoice.shipping_cost)}`} />
                        <div className="border-t border-slate-200 pt-1.5">
                            <Row k={<span className="font-semibold">Total</span>} v={<span className="font-semibold">{sym}{fmt(invoice.total_amount)}</span>} />
                        </div>
                        <Row k="Paid" v={`${sym}${fmt(invoice.paid_amount)}`} />
                        <Row k="Remaining" v={<span className="text-amber-700 font-medium">{sym}{fmt(invoice.remaining_amount)}</span>} />
                    </div>

                    {invoice.payments?.length > 0 && (
                        <div className="rounded-lg border border-slate-200 bg-white p-5">
                            <h2 className="mb-3 text-sm font-semibold text-slate-700">Payments</h2>
                            <ul className="space-y-2 text-sm">
                                {invoice.payments.map((p) => (
                                    <li key={p.id} className="flex justify-between border-b border-slate-100 pb-2 last:border-b-0">
                                        <div>
                                            <div className="text-slate-700">{sym}{fmt(p.amount)}</div>
                                            <div className="text-xs text-slate-500">{p.payment_date} · {p.payment_method ?? 'unspecified'}</div>
                                        </div>
                                        <div className="text-xs text-slate-400">{p.created_by?.name}</div>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </div>

            {/* Payment modal */}
            {payOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <form onSubmit={submitPayment} className="w-full max-w-md rounded-lg bg-white p-5 shadow-xl space-y-3">
                        <h3 className="text-sm font-semibold text-slate-800">Record payment</h3>
                        <input type="number" step="0.01" min={0} value={pay.data.amount} onChange={(e) => pay.setData('amount', e.target.value)} placeholder="Amount" className="block w-full rounded-md border-slate-300 text-sm" />
                        {pay.errors.amount && <p className="text-xs text-red-600">{pay.errors.amount}</p>}
                        <input type="text" value={pay.data.payment_method} onChange={(e) => pay.setData('payment_method', e.target.value)} placeholder="Method (Cash, Bank, etc.)" className="block w-full rounded-md border-slate-300 text-sm" />
                        <input type="date" value={pay.data.payment_date} onChange={(e) => pay.setData('payment_date', e.target.value)} className="block w-full rounded-md border-slate-300 text-sm" />
                        <textarea value={pay.data.notes} onChange={(e) => pay.setData('notes', e.target.value)} placeholder="Notes (optional)" rows={2} className="block w-full rounded-md border-slate-300 text-sm" />
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setPayOpen(false)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</button>
                            <button type="submit" disabled={pay.processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">Save</button>
                        </div>
                    </form>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

function Row({ k, v }) {
    return (
        <div className="flex justify-between text-sm py-0.5">
            <span className="text-slate-500">{k}</span>
            <span className="tabular-nums text-slate-800">{v}</span>
        </div>
    );
}
