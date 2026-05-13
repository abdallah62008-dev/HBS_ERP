import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import useCan from '@/Hooks/useCan';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

function typeLabel(t) {
    return t ? t.replaceAll('_', ' ') : '—';
}

export default function PaymentMethodsIndex({ paymentMethods, filters, types }) {
    const can = useCan();

    const [q, setQ] = useState(filters?.q ?? '');
    const [type, setType] = useState(filters?.type ?? '');
    const [status, setStatus] = useState(filters?.status ?? '');

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('payment-methods.index'), {
            q: q || undefined,
            type: type || undefined,
            status: status || undefined,
        }, { preserveState: true, replace: true });
    };

    const onDeactivate = (pm) => {
        if (!confirm(`Deactivate "${pm.name}"? You can reactivate later.`)) return;
        router.post(route('payment-methods.deactivate', pm.id), {}, { preserveScroll: true });
    };

    const onReactivate = (pm) => {
        router.post(route('payment-methods.reactivate', pm.id), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header="Payment Methods">
            <Head title="Payment Methods" />
            <PageHeader
                title="Payment Methods"
                subtitle={`${paymentMethods.length} method${paymentMethods.length === 1 ? '' : 's'} · how money moves through the business`}
                actions={
                    can('payment_methods.create') && (
                        <Link
                            href={route('payment-methods.create')}
                            className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
                        >
                            + New payment method
                        </Link>
                    )
                }
            />

            <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div className="flex-1 min-w-[200px]">
                    <input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Search by name or code"
                        className="block w-full rounded-md border-slate-300 text-sm"
                    />
                </div>
                <select value={type} onChange={(e) => setType(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any type</option>
                    {types.map((t) => <option key={t} value={t}>{typeLabel(t)}</option>)}
                </select>
                <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Active + Inactive</option>
                    <option value="active">Active only</option>
                    <option value="inactive">Inactive only</option>
                </select>
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Name</th>
                            <th className="px-4 py-2.5">Code</th>
                            <th className="px-4 py-2.5">Type</th>
                            <th className="px-4 py-2.5">Default cashbox</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {paymentMethods.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-12 text-center text-sm text-slate-400">
                                    No payment methods match the filters.
                                </td>
                            </tr>
                        )}
                        {paymentMethods.map((pm) => (
                            <tr key={pm.id} className={'hover:bg-slate-50 ' + (pm.is_active ? '' : 'opacity-60')}>
                                <td className="px-4 py-2.5 text-slate-800 font-medium">{pm.name}</td>
                                <td className="px-4 py-2.5 text-slate-500 font-mono text-xs">{pm.code}</td>
                                <td className="px-4 py-2.5 text-slate-600">{typeLabel(pm.type)}</td>
                                <td className="px-4 py-2.5 text-slate-600 text-xs">
                                    {pm.default_cashbox ? (
                                        <span className={pm.default_cashbox.is_active ? '' : 'text-slate-400 italic'}>
                                            {pm.default_cashbox.name}{!pm.default_cashbox.is_active ? ' (inactive)' : ''}
                                        </span>
                                    ) : '—'}
                                </td>
                                <td className="px-4 py-2.5">
                                    {pm.is_active ? (
                                        <span className="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Active</span>
                                    ) : (
                                        <span className="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Inactive</span>
                                    )}
                                </td>
                                <td className="px-4 py-2.5 text-right space-x-2">
                                    {can('payment_methods.edit') && (
                                        <Link href={route('payment-methods.edit', pm.id)} className="text-xs text-slate-600 hover:underline">Edit</Link>
                                    )}
                                    {can('payment_methods.deactivate') && (
                                        pm.is_active ? (
                                            <button type="button" onClick={() => onDeactivate(pm)} className="text-xs text-red-600 hover:underline">Deactivate</button>
                                        ) : (
                                            <button type="button" onClick={() => onReactivate(pm)} className="text-xs text-emerald-600 hover:underline">Reactivate</button>
                                        )
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
