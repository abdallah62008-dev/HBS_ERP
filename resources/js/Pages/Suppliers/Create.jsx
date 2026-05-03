import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import SupplierForm from './Form';
import { Head, Link, useForm } from '@inertiajs/react';

export default function SupplierCreate() {
    const { data, setData, post, processing, errors } = useForm({
        name: '', phone: '', email: '', address: '', city: '', country: 'Egypt',
        notes: '', status: 'Active',
    });

    const submit = (e) => { e.preventDefault(); post(route('suppliers.store')); };

    return (
        <AuthenticatedLayout header="New supplier">
            <Head title="New supplier" />
            <PageHeader title="New supplier" />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5">
                <SupplierForm data={data} setData={setData} errors={errors} />
                <div className="mt-6 flex justify-end gap-2">
                    <Link href={route('suppliers.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {processing ? 'Saving…' : 'Create supplier'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
