import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

function fmtAmount(value, currency = 'EGP') {
    const n = Number(value ?? 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    return currency === 'EGP' ? `${n} جنيه` : `${currency} ${n}`;
}

function statusChip(status) {
    const tone = {
        requested: 'bg-amber-50 text-amber-700',
        approved: 'bg-emerald-50 text-emerald-700',
        rejected: 'bg-slate-100 text-slate-600',
        paid: 'bg-indigo-50 text-indigo-700',
    }[status] ?? 'bg-slate-100 text-slate-600';
    return (
        <span className={'inline-flex rounded-full px-2 py-0.5 text-xs font-medium ' + tone}>
            {status}
        </span>
    );
}

export default function MarketerPayoutsIndex({
    payouts,
    filters,
    totals,
    statuses,
    cashboxes = [],
    payment_methods = [],
}) {
    const can = useCan();
    const { props } = usePage();
    const currency = props.app?.currency_code ?? 'EGP';

    const [q, setQ] = useState(filters?.q ?? '');
    const [status, setStatus] = useState(filters?.status ?? '');
    const [payingId, setPayingId] = useState(null);

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('marketer-payouts.index'), {
            q: q || undefined,
            status: status || undefined,
        }, { preserveState: true, replace: true });
    };

    const onApprove = (p) => {
        if (!confirm(`Approve payout #${p.id} for ${fmtAmount(p.amount, currency)}?`)) return;
        router.post(route('marketer-payouts.approve', p.id), {}, { preserveScroll: true });
    };
    const onReject = (p) => {
        if (!confirm(`Reject payout #${p.id} for ${fmtAmount(p.amount, currency)}?`)) return;
        router.post(route('marketer-payouts.reject', p.id), {}, { preserveScroll: true });
    };
    const onDelete = (p) => {
        if (!confirm(`Delete requested payout #${p.id}? Only requested payouts can be deleted.`)) return;
        router.delete(route('marketer-payouts.destroy', p.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header="Marketer Payouts">
            <Head title="Marketer Payouts" />
            <PageHeader
                title="Marketer Payouts"
                subtitle={`${payouts.total} record${payouts.total === 1 ? '' : 's'}`}
                actions={
                    can('marketer_payouts.create') && (
                        <Link
                            href={route('marketer-payouts.create')}
                            className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
                        >
                            + Request payout
                        </Link>
                    )
                }
            />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                <Stat label="Requested" count={totals.requested_count} amount={fmtAmount(totals.requested_amount, currency)} tone="amber" />
                <Stat label="Approved" count={totals.approved_count} amount={fmtAmount(totals.approved_amount, currency)} tone="emerald" />
                <Stat label="Paid" count={totals.paid_count} amount={fmtAmount(totals.paid_amount, currency)} tone="indigo" />
                <Stat label="Rejected" count={totals.rejected_count} amount={fmtAmount(totals.rejected_amount, currency)} />
            </div>

            <form onSubmit={apply} className="mb-4 flex flex-wrap gap-2">
                <input
                    type="text"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder="Search marketer code / name…"
                    className="w-64 rounded-md border-slate-300 text-sm"
                />
                <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">All statuses</option>
                    {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
                <button type="submit" className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">Filter</button>
            </form>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">#</th>
                            <th className="px-4 py-2.5">Marketer</th>
                            <th className="px-4 py-2.5 text-right">Amount</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5">Cashbox</th>
                            <th className="px-4 py-2.5">Paid at</th>
                            <th className="px-4 py-2.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {payouts.data.length === 0 && (
                            <tr><td colSpan={7} className="px-4 py-12 text-center text-sm text-slate-400">No payouts.</td></tr>
                        )}
                        {payouts.data.map((p) => (
                            <tr key={p.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-mono text-xs text-slate-500">#{p.id}</td>
                                <td className="px-4 py-2.5">
                                    <div className="font-medium text-slate-700">{p.marketer?.user?.name}</div>
                                    <div className="font-mono text-xs text-slate-400">{p.marketer?.code}</div>
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums font-medium">{fmtAmount(p.amount, currency)}</td>
                                <td className="px-4 py-2.5">{statusChip(p.status)}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">
                                    {p.cashbox?.name ?? '—'}
                                    {p.payment_method && <span className="ml-1 text-slate-400">· {p.payment_method.name}</span>}
                                </td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{p.paid_at?.replace('T', ' ').slice(0, 16) ?? '—'}</td>
                                <td className="px-4 py-2.5 text-right">
                                    <div className="flex justify-end gap-1.5">
                                        {p.status === 'requested' && can('marketer_payouts.approve') && (
                                            <button onClick={() => onApprove(p)} className="rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100">Approve</button>
                                        )}
                                        {p.status === 'requested' && can('marketer_payouts.reject') && (
                                            <button onClick={() => onReject(p)} className="rounded border border-slate-200 bg-white px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-50">Reject</button>
                                        )}
                                        {p.status === 'approved' && can('marketer_payouts.pay') && (
                                            <button onClick={() => setPayingId(p.id)} className="rounded bg-indigo-600 px-2 py-1 text-xs font-medium text-white hover:bg-indigo-700">Pay</button>
                                        )}
                                        {p.status === 'requested' && can('marketer_payouts.create') && (
                                            <>
                                                <Link href={route('marketer-payouts.edit', p.id)} className="rounded border border-slate-200 bg-white px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-50">Edit</Link>
                                                <button onClick={() => onDelete(p)} className="rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-100">Delete</button>
                                            </>
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={payouts.links} />

            {payingId && (
                <PayModal
                    payout={payouts.data.find((p) => p.id === payingId)}
                    cashboxes={cashboxes}
                    paymentMethods={payment_methods}
                    onClose={() => setPayingId(null)}
                    currency={currency}
                />
            )}
        </AuthenticatedLayout>
    );
}

function Stat({ label, count, amount, tone }) {
    const palette = {
        amber: 'border-amber-200 bg-amber-50',
        emerald: 'border-emerald-200 bg-emerald-50',
        indigo: 'border-indigo-200 bg-indigo-50',
    }[tone] ?? 'border-slate-200 bg-white';
    return (
        <div className={`rounded-lg border p-4 ${palette}`}>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 text-xl font-semibold tabular-nums text-slate-800">{amount}</div>
            <div className="text-xs text-slate-500">{count} record{count === 1 ? '' : 's'}</div>
        </div>
    );
}

function PayModal({ payout, cashboxes, paymentMethods, onClose, currency }) {
    const form = useForm({
        cashbox_id: cashboxes[0]?.id ?? '',
        payment_method_id: paymentMethods[0]?.id ?? '',
        occurred_at: '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('marketer-payouts.pay', payout.id), { onSuccess: onClose, preserveScroll: true });
    };

    const cashbox = cashboxes.find((c) => Number(c.id) === Number(form.data.cashbox_id));
    const insufficient = cashbox && !cashbox.allow_negative_balance && cashbox.balance < Number(payout.amount);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <form onSubmit={submit} className="w-full max-w-md rounded-lg bg-white p-5 shadow-xl space-y-3">
                <h3 className="text-sm font-semibold text-slate-800">Pay payout #{payout.id} — {fmtAmount(payout.amount, currency)}</h3>

                <label className="block text-xs font-medium text-slate-600">
                    Cashbox
                    <select
                        value={form.data.cashbox_id}
                        onChange={(e) => form.setData('cashbox_id', e.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                    >
                        {cashboxes.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name} ({c.currency_code} {Number(c.balance ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2 })})
                            </option>
                        ))}
                    </select>
                </label>

                <label className="block text-xs font-medium text-slate-600">
                    Payment method
                    <select
                        value={form.data.payment_method_id}
                        onChange={(e) => form.setData('payment_method_id', e.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                    >
                        {paymentMethods.map((m) => (
                            <option key={m.id} value={m.id}>{m.name}</option>
                        ))}
                    </select>
                </label>

                <label className="block text-xs font-medium text-slate-600">
                    Occurred at <span className="text-slate-400">(optional)</span>
                    <input
                        type="datetime-local"
                        value={form.data.occurred_at}
                        onChange={(e) => form.setData('occurred_at', e.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                    />
                </label>

                {insufficient && (
                    <div className="rounded-md border border-rose-200 bg-rose-50 p-2 text-xs text-rose-700">
                        This cashbox would go negative ({fmtAmount(cashbox.balance, cashbox.currency_code)} available) and is configured to disallow it.
                    </div>
                )}

                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</button>
                    <button type="submit" disabled={form.processing || insufficient} className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60">
                        {form.processing ? 'Paying…' : 'Confirm payment'}
                    </button>
                </div>
            </form>
        </div>
    );
}
