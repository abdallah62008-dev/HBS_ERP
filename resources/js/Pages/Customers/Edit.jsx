import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import CustomerForm from './Form';
import useUnsavedChangesWarning from '@/Hooks/useUnsavedChangesWarning';
import { Head, useForm, Link } from '@inertiajs/react';

export default function CustomerEdit({ customer, tags, locations = [] }) {
    const { data, setData, put, processing, errors, isDirty } = useForm({
        name: customer.name ?? '',
        primary_phone: customer.primary_phone ?? '',
        secondary_phone: customer.secondary_phone ?? '',
        // O-2: hydrate the phone triple from the saved customer. Falls
        // back to +20 when the customer pre-dates O-2 (backfill not yet
        // run for that record).
        country_code: customer.country_code ?? '+20',
        secondary_country_code: customer.secondary_country_code ?? '+20',
        normalized_phone: customer.normalized_phone ?? null,
        primary_phone_whatsapp: customer.primary_phone_whatsapp !== false,
        email: customer.email ?? '',
        city: customer.city ?? '',
        governorate: customer.governorate ?? '',
        country: customer.country ?? 'Egypt',
        default_address: customer.default_address ?? '',
        customer_type: customer.customer_type ?? 'Normal',
        notes: customer.notes ?? '',
        tags: tags ?? [],
    });

    useUnsavedChangesWarning(isDirty);

    const submit = (e) => {
        e.preventDefault();
        put(route('customers.update', customer.id));
    };

    return (
        <AuthenticatedLayout header="Edit customer">
            <Head title={`Edit ${customer.name}`} />
            <PageHeader title={`Edit ${customer.name}`} subtitle={customer.primary_phone} />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5">
                <CustomerForm data={data} setData={setData} errors={errors} initialTags={tags} locations={locations} />

                <div className="mt-6 flex items-center justify-end gap-2">
                    <Link
                        href={route('customers.show', customer.id)}
                        className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                    >
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        disabled={processing}
                        className="inline-flex items-center rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                    >
                        {processing ? 'Saving…' : 'Save changes'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
