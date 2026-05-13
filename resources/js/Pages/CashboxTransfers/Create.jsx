import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormField from '@/Components/FormField';
import InputError from '@/Components/InputError';
import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo } from 'react';

/**
 * `EGP 0.00` style — same convention as the rest of the cashbox UI.
 * Avoids the shared `app.currency_symbol`.
 */
function fmtAmount(value, currency = 'EGP') {
    const n = Number(value ?? 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    return currency === 'EGP' ? `${n} جنيه` : `${currency} ${n}`;
}

export default function CashboxTransferCreate({ cashboxes }) {
    const { data, setData, post, processing, errors } = useForm({
        from_cashbox_id: '',
        to_cashbox_id: '',
        amount: '',
        occurred_at: new Date().toISOString().slice(0, 10),
        reason: '',
    });

    // Live balance preview for the chosen source cashbox — helps the
    // operator notice "insufficient balance" before the server rejects.
    const fromCashbox = useMemo(
        () => cashboxes.find((c) => String(c.id) === String(data.from_cashbox_id)) ?? null,
        [cashboxes, data.from_cashbox_id]
    );
    const toCashbox = useMemo(
        () => cashboxes.find((c) => String(c.id) === String(data.to_cashbox_id)) ?? null,
        [cashboxes, data.to_cashbox_id]
    );

    const wouldOverdraw =
        fromCashbox &&
        !fromCashbox.allow_negative_balance &&
        Number(data.amount || 0) > Number(fromCashbox.balance || 0);

    const currencyMismatch =
        fromCashbox && toCashbox && fromCashbox.currency_code !== toCashbox.currency_code;

    const submit = (e) => {
        e.preventDefault();
        post(route('cashbox-transfers.store'));
    };

    return (
        <AuthenticatedLayout header="New transfer">
            <Head title="New cashbox transfer" />
            <PageHeader
                title="New cashbox transfer"
                subtitle="Records one transfer + two cashbox transactions (one out, one in)."
            />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField label="From cashbox" name="from_cashbox_id" error={errors.from_cashbox_id} required>
                        <select
                            id="from_cashbox_id"
                            value={data.from_cashbox_id}
                            onChange={(e) => setData('from_cashbox_id', e.target.value)}
                            className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                            required
                        >
                            <option value="">— Pick source —</option>
                            {cashboxes.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name} — {fmtAmount(c.balance, c.currency_code)}
                                </option>
                            ))}
                        </select>
                        {fromCashbox && (
                            <p className="mt-1 text-xs text-slate-500">
                                Current balance:{' '}
                                <span className={'tabular-nums font-medium ' + (Number(fromCashbox.balance) < 0 ? 'text-red-600' : 'text-slate-700')}>
                                    {fmtAmount(fromCashbox.balance, fromCashbox.currency_code)}
                                </span>
                                {!fromCashbox.allow_negative_balance && (
                                    <span className="ml-1 text-slate-400">· no negative permitted</span>
                                )}
                            </p>
                        )}
                    </FormField>

                    <FormField label="To cashbox" name="to_cashbox_id" error={errors.to_cashbox_id} required>
                        <select
                            id="to_cashbox_id"
                            value={data.to_cashbox_id}
                            onChange={(e) => setData('to_cashbox_id', e.target.value)}
                            className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                            required
                        >
                            <option value="">— Pick destination —</option>
                            {cashboxes
                                .filter((c) => String(c.id) !== String(data.from_cashbox_id))
                                .map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name} ({c.currency_code})
                                    </option>
                                ))}
                        </select>
                    </FormField>

                    <FormField
                        label="Amount"
                        name="amount"
                        type="number"
                        value={data.amount}
                        onChange={(v) => setData('amount', v)}
                        error={errors.amount}
                        required
                        hint={fromCashbox ? `In ${fromCashbox.currency_code}.` : undefined}
                    />

                    <FormField
                        label="Occurred at"
                        name="occurred_at"
                        type="date"
                        value={data.occurred_at}
                        onChange={(v) => setData('occurred_at', v)}
                        error={errors.occurred_at}
                        required
                    />

                    <FormField label="Reason" name="reason" error={errors.reason} className="sm:col-span-2">
                        <textarea
                            id="reason"
                            rows={2}
                            value={data.reason ?? ''}
                            onChange={(e) => setData('reason', e.target.value)}
                            placeholder="e.g. End-of-day cash deposit to bank"
                            className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                        />
                    </FormField>
                </div>

                {wouldOverdraw && (
                    <p className="mt-3 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        Source cashbox does not permit a negative balance and the requested amount exceeds the current balance.
                    </p>
                )}
                {currencyMismatch && (
                    <p className="mt-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
                        Cross-currency transfers are not supported ({fromCashbox.currency_code} → {toCashbox.currency_code}).
                    </p>
                )}
                <InputError message={errors.from_cashbox_id && !errors.amount ? errors.from_cashbox_id : null} className="mt-3" />

                <div className="mt-6 flex justify-end gap-2">
                    <Link href={route('cashbox-transfers.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button
                        type="submit"
                        disabled={processing || wouldOverdraw || currencyMismatch}
                        className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                    >
                        {processing ? 'Transferring…' : 'Record transfer'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
