import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import CampaignForm from './Form';
import { Head, Link, useForm } from '@inertiajs/react';

export default function CampaignEdit({ campaign, products, platforms }) {
    const { data, setData, put, processing, errors } = useForm({
        name: campaign.name,
        platform: campaign.platform,
        product_id: campaign.product_id,
        status: campaign.status,
        start_date: campaign.start_date,
        end_date: campaign.end_date ?? '',
        budget: campaign.budget,
        spend: campaign.spend,
    });

    const submit = (e) => { e.preventDefault(); put(route('ads.update', campaign.id)); };

    return (
        <AuthenticatedLayout header={`Edit · ${campaign.name}`}>
            <Head title={`Edit ${campaign.name}`} />
            <PageHeader title={`Edit · ${campaign.name}`} />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5">
                <CampaignForm data={data} setData={setData} errors={errors} products={products} platforms={platforms} />
                <div className="mt-6 flex justify-end gap-2">
                    <Link href={route('ads.show', campaign.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {processing ? 'Saving…' : 'Save changes'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
