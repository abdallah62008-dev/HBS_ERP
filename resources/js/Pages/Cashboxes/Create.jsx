import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import CashboxForm from './Form';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

export default function CashboxCreate({ types }) {
    const { props } = usePage();
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        type: '',
        currency_code: props.app?.currency_code ?? 'EGP',
        opening_balance: 0,
        allow_negative_balance: true,
        is_active: true,
        description: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('cashboxes.store'));
    };

    return (
        <AuthenticatedLayout header="New cashbox">
            <Head title="New cashbox" />
            <PageHeader
                title="New cashbox"
                subtitle="Opening balance writes a single ledger entry. It cannot be edited afterward."
            />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5">
                <CashboxForm data={data} setData={setData} errors={errors} types={types} isEdit={false} />
                <div className="mt-6 flex justify-end gap-2">
                    <Link
                        href={route('cashboxes.index')}
                        className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                    >
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                    >
                        {processing ? 'Creating…' : 'Create cashbox'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
