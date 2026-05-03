import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import CampaignForm from './Form';
import { Head, Link, useForm } from '@inertiajs/react';

export default function CampaignCreate({ products, platforms }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        platform: 'Facebook',
        product_id: null,
        status: 'Active',
        start_date: new Date().toISOString().slice(0, 10),
        end_date: '',
        budget: 0,
        spend: 0,
    });

    const submit = (e) => { e.preventDefault(); post(route('ads.store')); };

    return (
        <AuthenticatedLayout header="New campaign">
            <Head title="New campaign" />
            <PageHeader title="New ad campaign" />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5">
                <CampaignForm data={data} setData={setData} errors={errors} products={products} platforms={platforms} />
                <div className="mt-6 flex justify-end gap-2">
                    <Link href={route('ads.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {processing ? 'Saving…' : 'Create campaign'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
