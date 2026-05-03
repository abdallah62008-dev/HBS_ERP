import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, usePage } from '@inertiajs/react';

function fmt(n) {
    return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export default function SupplierShow({ supplier, balance }) {
    const can = useCan();
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const handleDelete = () => {
        if (!confirm(`Delete supplier "${supplier.name}"?`)) return;
        router.delete(route('suppliers.destroy', supplier.id));
    };

    return (
        <AuthenticatedLayout header={supplier.name}>
            <Head title={supplier.name} />

            <PageHeader
                title={supplier.name}
                subtitle={`${supplier.phone ?? '—'} · ${supplier.city ?? '—'}`}
                actions={
                    <div className="flex gap-2">
                        {can('suppliers.edit') && (
                            <Link href={route('suppliers.edit', supplier.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">Edit</Link>
                        )}
                        {can('suppliers.edit') && (
                            <button onClick={handleDelete} className="rounded-md border border-red-200 bg-white px-3 py-2 text-sm text-red-600 hover:bg-red-50">Delete</button>
                        )}
                    </div>
                }
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="lg:col-span-2 rounded-lg border border-slate-200 bg-white p-5 space-y-3">
                    <div className="mb-2 flex items-center gap-2">
                        <StatusBadge value={supplier.status} />
                    </div>
                    <Field label="Email" value={supplier.email} />
                    <Field label="Country" value={supplier.country} />
                    <Field label="Address" value={supplier.address} />
                    {supplier.notes && <Field label="Notes" value={supplier.notes} />}
                </div>

                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="text-sm font-semibold text-slate-700">Outstanding balance</h2>
                    <div className={`mt-2 text-3xl font-semibold tabular-nums ${balance > 0 ? 'text-amber-600' : 'text-emerald-600'}`}>
                        {sym}{fmt(balance)}
                    </div>
                    <p className="mt-1 text-xs text-slate-500">
                        {balance > 0 ? 'You owe this supplier' : 'Account is settled'}
                    </p>
                </div>
            </div>

            {/* Recent invoices */}
            <div className="mt-6 rounded-lg border border-slate-200 bg-white">
                <div className="border-b border-slate-200 px-5 py-3 flex items-center justify-between">
                    <h2 className="text-sm font-semibold text-slate-700">Recent purchase invoices</h2>
                    {can('purchases.create') && (
                        <Link href={route('purchase-invoices.create')} className="text-xs font-medium text-indigo-600 hover:underline">+ New invoice</Link>
                    )}
                </div>
                {(!supplier.purchase_invoices || supplier.purchase_invoices.length === 0) ? (
                    <div className="px-5 py-8 text-center text-sm text-slate-400">No invoices yet.</div>
                ) : (
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-5 py-2">Invoice #</th>
                                <th className="px-5 py-2">Date</th>
                                <th className="px-5 py-2">Status</th>
                                <th className="px-5 py-2 text-right">Total</th>
                                <th className="px-5 py-2 text-right">Paid</th>
                                <th className="px-5 py-2 text-right">Remaining</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {supplier.purchase_invoices.map((inv) => (
                                <tr key={inv.id} className="hover:bg-slate-50">
                                    <td className="px-5 py-2 font-mono text-xs">
                                        <Link href={route('purchase-invoices.show', inv.id)} className="text-slate-700 hover:text-indigo-600">{inv.invoice_number}</Link>
                                    </td>
                                    <td className="px-5 py-2 text-slate-500">{inv.invoice_date}</td>
                                    <td className="px-5 py-2"><StatusBadge value={inv.status} /></td>
                                    <td className="px-5 py-2 text-right tabular-nums">{fmt(inv.total_amount)}</td>
                                    <td className="px-5 py-2 text-right tabular-nums">{fmt(inv.paid_amount)}</td>
                                    <td className="px-5 py-2 text-right tabular-nums">{fmt(inv.remaining_amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {/* Recent payments */}
            <div className="mt-6 rounded-lg border border-slate-200 bg-white">
                <div className="border-b border-slate-200 px-5 py-3">
                    <h2 className="text-sm font-semibold text-slate-700">Recent payments</h2>
                </div>
                {(!supplier.payments || supplier.payments.length === 0) ? (
                    <div className="px-5 py-8 text-center text-sm text-slate-400">No payments recorded.</div>
                ) : (
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-5 py-2">Date</th>
                                <th className="px-5 py-2 text-right">Amount</th>
                                <th className="px-5 py-2">Method</th>
                                <th className="px-5 py-2">By</th>
                                <th className="px-5 py-2">Notes</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {supplier.payments.map((p) => (
                                <tr key={p.id}>
                                    <td className="px-5 py-2 text-slate-500">{p.payment_date}</td>
                                    <td className="px-5 py-2 text-right tabular-nums">{fmt(p.amount)}</td>
                                    <td className="px-5 py-2 text-slate-600">{p.payment_method ?? '—'}</td>
                                    <td className="px-5 py-2 text-slate-500">{p.created_by?.name ?? '—'}</td>
                                    <td className="px-5 py-2 text-slate-600">{p.notes ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function Field({ label, value }) {
    return (
        <div>
            <div className="text-[11px] font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-0.5 text-sm text-slate-800">{value || <span className="text-slate-400">—</span>}</div>
        </div>
    );
}
