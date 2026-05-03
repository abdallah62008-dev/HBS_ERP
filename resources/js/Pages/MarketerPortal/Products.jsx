import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import { Head, usePage } from '@inertiajs/react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function MarketerProducts({ marketer, prices }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    return (
        <AuthenticatedLayout header="My products">
            <Head title="My products" />
            <PageHeader title="My products" subtitle={`Prices for the ${marketer.price_group?.name} group`} />

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">SKU</th>
                            <th className="px-4 py-2.5">Product</th>
                            <th className="px-4 py-2.5 text-right">Trade price</th>
                            <th className="px-4 py-2.5 text-right">Min selling</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {prices.data.length === 0 && (
                            <tr><td colSpan={4} className="px-4 py-12 text-center text-sm text-slate-400">No products in your group yet. Ask an admin to set up prices.</td></tr>
                        )}
                        {prices.data.map((p) => (
                            <tr key={p.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2 font-mono text-xs">{p.product?.sku}</td>
                                <td className="px-4 py-2 text-slate-800">{p.product?.name}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{sym}{fmt(p.trade_price)}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{sym}{fmt(p.minimum_selling_price)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={prices.links} />
        </AuthenticatedLayout>
    );
}
