import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

export default function MarketerPayoutsEdit({ payout }) {
    const { props } = usePage();
    const currency = props.app?.currency_code ?? 'EGP';

    const form = useForm({
        marketer_id: payout.marketer_id,
        amount: payout.amount,
        notes: payout.notes ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.put(route('marketer-payouts.update', payout.id));
    };

    return (
        <AuthenticatedLayout header={`Edit payout #${payout.id}`}>
            <Head title={`Edit payout #${payout.id}`} />
            <PageHeader
                title={`Edit payout #${payout.id}`}
                subtitle={`Marketer ${payout.marketer?.code} · ${payout.marketer?.user?.name} · only requested payouts are editable`}
                actions={
                    <Link href={route('marketer-payouts.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Payouts</Link>
                }
            />

            <form onSubmit={submit} className="max-w-xl space-y-4 rounded-lg border border-slate-200 bg-white p-5">
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
                    {form.errors.amount && <p className="mt-1 text-xs text-rose-600">{form.errors.amount}</p>}
                </label>

                <label className="block text-sm font-medium text-slate-700">
                    Notes
                    <textarea
                        rows={3}
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                    />
                </label>

                <div className="flex justify-end">
                    <button type="submit" disabled={form.processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {form.processing ? 'Saving…' : 'Save changes'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
