import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import SupplierForm from './Form';
import { Head, Link, useForm } from '@inertiajs/react';

export default function SupplierEdit({ supplier }) {
    const { data, setData, put, processing, errors } = useForm({
        name: supplier.name ?? '',
        phone: supplier.phone ?? '',
        email: supplier.email ?? '',
        address: supplier.address ?? '',
        city: supplier.city ?? '',
        country: supplier.country ?? '',
        notes: supplier.notes ?? '',
        status: supplier.status ?? 'Active',
    });

    const submit = (e) => { e.preventDefault(); put(route('suppliers.update', supplier.id)); };

    return (
        <AuthenticatedLayout header={`Edit ${supplier.name}`}>
            <Head title={`Edit ${supplier.name}`} />
            <PageHeader title={`Edit ${supplier.name}`} />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5">
                <SupplierForm data={data} setData={setData} errors={errors} />
                <div className="mt-6 flex justify-end gap-2">
                    <Link href={route('suppliers.show', supplier.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {processing ? 'Saving…' : 'Save changes'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
