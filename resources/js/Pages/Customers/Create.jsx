import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import CustomerForm from './Form';
import useUnsavedChangesWarning from '@/Hooks/useUnsavedChangesWarning';
import { Head, useForm, Link } from '@inertiajs/react';

export default function CustomerCreate({ locations = [], default_country_code = 'EG' }) {
    // Map default code → existing English name used by the legacy text columns.
    const defaultCountryName = (locations.find((c) => c.code === default_country_code)?.name_en) ?? 'Egypt';

    const { data, setData, post, processing, errors, isDirty } = useForm({
        name: '',
        primary_phone: '',
        secondary_phone: '',
        email: '',
        city: '',
        governorate: '',
        country: defaultCountryName,
        default_address: '',
        customer_type: 'Normal',
        notes: '',
        tags: [],
    });

    useUnsavedChangesWarning(isDirty);

    const submit = (e) => {
        e.preventDefault();
        post(route('customers.store'));
    };

    return (
        <AuthenticatedLayout header="New customer">
            <Head title="New customer" />
            <PageHeader title="New customer" subtitle="Add a customer record" />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5">
                <CustomerForm data={data} setData={setData} errors={errors} locations={locations} />

                <div className="mt-6 flex items-center justify-end gap-2">
                    <Link
                        href={route('customers.index')}
                        className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                    >
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        disabled={processing}
                        className="inline-flex items-center rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                    >
                        {processing ? 'Saving…' : 'Create customer'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
