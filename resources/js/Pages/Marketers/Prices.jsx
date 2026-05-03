import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function MarketerPrices({ marketer, prices, products }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const create = useForm({ product_id: '', product_variant_id: null, trade_price: 0, minimum_selling_price: 0 });

    const submit = (e) => {
        e.preventDefault();
        create.post(route('marketers.prices.store', marketer.id), { onSuccess: () => create.reset() });
    };

    const remove = (price) => {
        if (!confirm(`Remove price for ${price.product?.sku}?`)) return;
        router.delete(route('marketers.prices.destroy', [marketer.id, price.id]));
    };

    return (
        <AuthenticatedLayout header={`Prices · ${marketer.code}`}>
            <Head title={`Prices ${marketer.code}`} />
            <PageHeader
                title={`Prices · group ${marketer.price_group?.name}`}
                subtitle="These prices apply to every marketer in this group."
                actions={<Link href={route('marketers.show', marketer.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Marketer</Link>}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5 space-y-2">
                    <h2 className="text-sm font-semibold text-slate-700">Add / update price</h2>
                    <select value={create.data.product_id} onChange={(e) => create.setData('product_id', e.target.value)} className="block w-full rounded-md border-slate-300 text-sm">
                        <option value="">— Pick a product —</option>
                        {products.map((p) => <option key={p.id} value={p.id}>{p.sku} — {p.name}</option>)}
                    </select>
                    {create.errors.product_id && <p className="text-xs text-red-600">{create.errors.product_id}</p>}
                    <input type="number" step="0.01" min={0} value={create.data.trade_price} onChange={(e) => create.setData('trade_price', e.target.value)} placeholder="Trade price (what marketer pays)" className="block w-full rounded-md border-slate-300 text-sm" />
                    <input type="number" step="0.01" min={0} value={create.data.minimum_selling_price} onChange={(e) => create.setData('minimum_selling_price', e.target.value)} placeholder="Minimum selling price" className="block w-full rounded-md border-slate-300 text-sm" />
                    <button type="submit" disabled={create.processing} className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">Save</button>
                </form>

                <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-2.5">SKU</th>
                                <th className="px-4 py-2.5">Product</th>
                                <th className="px-4 py-2.5 text-right">Retail</th>
                                <th className="px-4 py-2.5 text-right">Trade</th>
                                <th className="px-4 py-2.5 text-right">Min selling</th>
                                <th className="px-4 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {prices.data.length === 0 && (
                                <tr><td colSpan={6} className="px-4 py-12 text-center text-sm text-slate-400">No marketer prices yet.</td></tr>
                            )}
                            {prices.data.map((p) => (
                                <tr key={p.id} className="hover:bg-slate-50">
                                    <td className="px-4 py-2 font-mono text-xs">{p.product?.sku}</td>
                                    <td className="px-4 py-2">{p.product?.name}</td>
                                    <td className="px-4 py-2 text-right tabular-nums text-slate-500">{sym}{fmt(p.product?.selling_price)}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{sym}{fmt(p.trade_price)}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{sym}{fmt(p.minimum_selling_price)}</td>
                                    <td className="px-4 py-2 text-right">
                                        <button onClick={() => remove(p)} className="text-xs text-red-600 hover:underline">remove</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <Pagination links={prices.links} />
        </AuthenticatedLayout>
    );
}
