import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import { Head, Link, useForm, router } from '@inertiajs/react';

export default function RatesIndex({ company, rates }) {
    const create = useForm({
        country: 'Egypt', governorate: '', city: '',
        base_cost: 0, cod_fee: 0, return_fee: 0, estimated_days: 3, status: 'Active',
    });

    const submitCreate = (e) => {
        e.preventDefault();
        create.post(route('shipping-companies.rates.store', company.id), {
            onSuccess: () => create.reset('city', 'base_cost'),
        });
    };

    const remove = (rate) => {
        if (!confirm(`Delete rate for ${rate.city}?`)) return;
        router.delete(route('shipping-companies.rates.destroy', [company.id, rate.id]));
    };

    return (
        <AuthenticatedLayout header={`Rates · ${company.name}`}>
            <Head title={`Rates · ${company.name}`} />

            <PageHeader
                title={`Rates · ${company.name}`}
                subtitle={`${rates.total} rate${rates.total === 1 ? '' : 's'}`}
                actions={<Link href={route('shipping-companies.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Companies</Link>}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <form onSubmit={submitCreate} className="rounded-lg border border-slate-200 bg-white p-5 space-y-2">
                    <h2 className="text-sm font-semibold text-slate-700">Add rate</h2>
                    <input value={create.data.country} onChange={(e) => create.setData('country', e.target.value)} placeholder="Country" className="block w-full rounded-md border-slate-300 text-sm" />
                    <input value={create.data.governorate} onChange={(e) => create.setData('governorate', e.target.value)} placeholder="Governorate (optional)" className="block w-full rounded-md border-slate-300 text-sm" />
                    <input value={create.data.city} onChange={(e) => create.setData('city', e.target.value)} placeholder="City" className="block w-full rounded-md border-slate-300 text-sm" />
                    {create.errors.city && <p className="text-xs text-red-600">{create.errors.city}</p>}
                    <input type="number" step="0.01" min={0} value={create.data.base_cost} onChange={(e) => create.setData('base_cost', e.target.value)} placeholder="Base cost" className="block w-full rounded-md border-slate-300 text-sm" />
                    <input type="number" step="0.01" min={0} value={create.data.cod_fee} onChange={(e) => create.setData('cod_fee', e.target.value)} placeholder="COD fee" className="block w-full rounded-md border-slate-300 text-sm" />
                    <input type="number" step="0.01" min={0} value={create.data.return_fee} onChange={(e) => create.setData('return_fee', e.target.value)} placeholder="Return fee" className="block w-full rounded-md border-slate-300 text-sm" />
                    <input type="number" min={0} value={create.data.estimated_days} onChange={(e) => create.setData('estimated_days', e.target.value)} placeholder="Estimated days" className="block w-full rounded-md border-slate-300 text-sm" />
                    <button type="submit" disabled={create.processing} className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">Add</button>
                </form>

                <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-2.5">Country</th>
                                <th className="px-4 py-2.5">Gov.</th>
                                <th className="px-4 py-2.5">City</th>
                                <th className="px-4 py-2.5 text-right">Base</th>
                                <th className="px-4 py-2.5 text-right">COD</th>
                                <th className="px-4 py-2.5 text-right">Return</th>
                                <th className="px-4 py-2.5 text-right">Days</th>
                                <th className="px-4 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rates.data.length === 0 && (
                                <tr><td colSpan={8} className="px-4 py-10 text-center text-sm text-slate-400">No rates yet.</td></tr>
                            )}
                            {rates.data.map((r) => (
                                <tr key={r.id} className="hover:bg-slate-50">
                                    <td className="px-4 py-2 text-slate-700">{r.country}</td>
                                    <td className="px-4 py-2 text-slate-500">{r.governorate ?? '—'}</td>
                                    <td className="px-4 py-2 text-slate-800">{r.city}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{Number(r.base_cost).toFixed(2)}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{Number(r.cod_fee).toFixed(2)}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{Number(r.return_fee).toFixed(2)}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{r.estimated_days ?? '—'}</td>
                                    <td className="px-4 py-2 text-right">
                                        <button onClick={() => remove(r)} className="text-xs text-red-600 hover:underline">delete</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Pagination links={rates.links} />
        </AuthenticatedLayout>
    );
}
