import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

function fmt(n) {
    return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export default function MarketerPayoutsCreate({ marketers = [] }) {
    const { props } = usePage();
    const currency = props.app?.currency_code ?? 'EGP';

    const form = useForm({
        marketer_id: marketers[0]?.id ?? '',
        amount: marketers[0]?.balance ?? '',
        notes: '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('marketer-payouts.store'));
    };

    const selected = marketers.find((m) => Number(m.id) === Number(form.data.marketer_id));

    return (
        <AuthenticatedLayout header="Request marketer payout">
            <Head title="Request marketer payout" />
            <PageHeader
                title="Request marketer payout"
                subtitle="Creates a requested payout. Approval and payment happen separately."
                actions={
                    <Link href={route('marketer-payouts.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Payouts</Link>
                }
            />

            <form onSubmit={submit} className="max-w-xl space-y-4 rounded-lg border border-slate-200 bg-white p-5">
                <label className="block text-sm font-medium text-slate-700">
                    Marketer
                    <select
                        value={form.data.marketer_id}
                        onChange={(e) => {
                            const id = e.target.value;
                            const m = marketers.find((mm) => Number(mm.id) === Number(id));
                            form.setData((prev) => ({ ...prev, marketer_id: id, amount: m?.balance ?? prev.amount }));
                        }}
                        className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                    >
                        {marketers.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.code} — {m.name} (balance {currency} {fmt(m.balance)})
                            </option>
                        ))}
                    </select>
                    {form.errors.marketer_id && <p className="mt-1 text-xs text-rose-600">{form.errors.marketer_id}</p>}
                </label>

                <label className="block text-sm font-medium text-slate-700">
                    Amount ({currency})
                    <input
                        type="number"
                        step="0.01"
                        min="0.01"
                        value={form.data.amount}
                        onChange={(e) => form.setData('amount', e.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                    />
                    {selected && Number(form.data.amount) > Number(selected.balance) && (
                        <p className="mt-1 text-xs text-amber-700">
                            Requested amount ({currency} {fmt(form.data.amount)}) exceeds the marketer's current balance ({currency} {fmt(selected.balance)}).
                            Approval may be blocked until adjustments are recorded.
                        </p>
                    )}
                    {form.errors.amount && <p className="mt-1 text-xs text-rose-600">{form.errors.amount}</p>}
                </label>

                <label className="block text-sm font-medium text-slate-700">
                    Notes <span className="text-slate-400 font-normal">(optional)</span>
                    <textarea
                        rows={3}
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                        placeholder="Reason / settlement reference…"
                        className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                    />
                </label>

                <div className="flex justify-end">
                    <button type="submit" disabled={form.processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {form.processing ? 'Submitting…' : 'Request payout'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
