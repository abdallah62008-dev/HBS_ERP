import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router } from '@inertiajs/react';

function Field({ label, value }) {
    return (
        <div>
            <div className="text-[11px] font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-0.5 text-sm text-slate-800">{value || <span className="text-slate-400">—</span>}</div>
        </div>
    );
}

export default function CustomerShow({ customer, risk_breakdown }) {
    const can = useCan();

    const handleDelete = () => {
        if (!confirm(`Delete customer "${customer.name}"? This is a soft delete and can be restored.`)) return;
        router.delete(route('customers.destroy', customer.id));
    };

    return (
        <AuthenticatedLayout header={customer.name}>
            <Head title={customer.name} />

            <PageHeader
                title={customer.name}
                subtitle={`Customer #${customer.id} · ${customer.primary_phone}`}
                actions={
                    <div className="flex items-center gap-2">
                        {can('customers.edit') && (
                            <Link
                                href={route('customers.edit', customer.id)}
                                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50"
                            >
                                Edit
                            </Link>
                        )}
                        {can('customers.delete') && (
                            <button
                                type="button"
                                onClick={handleDelete}
                                className="rounded-md border border-red-200 bg-white px-3 py-2 text-sm text-red-600 hover:bg-red-50"
                            >
                                Delete
                            </button>
                        )}
                    </div>
                }
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                {/* Profile card */}
                <div className="lg:col-span-2 rounded-lg border border-slate-200 bg-white p-5">
                    <div className="mb-4 flex items-center gap-2">
                        <StatusBadge value={customer.customer_type} />
                        <StatusBadge value={customer.risk_level} />
                        {customer.tags?.map((t) => (
                            <span key={t.id} className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">
                                {t.tag}
                            </span>
                        ))}
                    </div>

                    <div className="grid grid-cols-2 gap-x-6 gap-y-3">
                        <Field label="Primary phone" value={customer.primary_phone} />
                        <Field label="Secondary phone" value={customer.secondary_phone} />
                        <Field label="Email" value={customer.email} />
                        <Field label="Country" value={customer.country} />
                        <Field label="Governorate" value={customer.governorate} />
                        <Field label="City" value={customer.city} />
                        <div className="col-span-2">
                            <Field label="Default address" value={customer.default_address} />
                        </div>
                        {customer.notes && (
                            <div className="col-span-2">
                                <Field label="Notes" value={customer.notes} />
                            </div>
                        )}
                    </div>
                </div>

                {/* Risk panel */}
                {can('customers.view_risk') && (
                    <div className="rounded-lg border border-slate-200 bg-white p-5">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-sm font-semibold text-slate-700">Risk score</h2>
                            <StatusBadge value={risk_breakdown.level} />
                        </div>
                        <div className="text-3xl font-semibold tabular-nums text-slate-800">
                            {risk_breakdown.score}<span className="text-base text-slate-400">/100</span>
                        </div>
                        <div className="mt-3 space-y-1 text-xs text-slate-500">
                            {Object.keys(risk_breakdown.breakdown).length === 0 && (
                                <div className="text-slate-400">No history yet — score is 0.</div>
                            )}
                            {Object.entries(risk_breakdown.breakdown).map(([k, v]) => (
                                <div key={k} className="flex justify-between">
                                    <span className="capitalize">{k.replaceAll('_', ' ')}</span>
                                    <span className={v >= 0 ? 'text-red-600' : 'text-green-600'}>
                                        {v > 0 ? `+${v}` : v}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Recent orders */}
            <div className="mt-6 rounded-lg border border-slate-200 bg-white">
                <div className="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                    <h2 className="text-sm font-semibold text-slate-700">Recent orders</h2>
                    <span className="text-xs text-slate-400">{customer.orders?.length ?? 0} most recent</span>
                </div>

                {(!customer.orders || customer.orders.length === 0) ? (
                    <div className="px-5 py-10 text-center text-sm text-slate-400">No orders yet.</div>
                ) : (
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-5 py-2">Order #</th>
                                <th className="px-5 py-2">Status</th>
                                <th className="px-5 py-2">Total</th>
                                <th className="px-5 py-2">Created</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {customer.orders.map((o) => (
                                <tr key={o.id} className="hover:bg-slate-50">
                                    <td className="px-5 py-2 font-medium">
                                        <Link href={route('orders.show', o.id)} className="text-slate-700 hover:text-indigo-600">
                                            {o.order_number}
                                        </Link>
                                    </td>
                                    <td className="px-5 py-2"><StatusBadge value={o.status} /></td>
                                    <td className="px-5 py-2 tabular-nums">{o.total_amount}</td>
                                    <td className="px-5 py-2 text-slate-500">{o.created_at?.split('T')[0]}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
