import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportFilters from '@/Components/ReportFilters';
import { Head, Link, usePage } from '@inertiajs/react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function UnprofitableProducts({ from, to, rows }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    return (
        <AuthenticatedLayout header="Unprofitable products">
            <Head title="Unprofitable products" />
            <PageHeader
                title="Unprofitable products"
                subtitle="Products that sold but lost money — gross profit ≤ 0"
                actions={<Link href={route('reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Reports</Link>}
            />

            <ReportFilters routeName="reports.unprofitable-products" from={from} to={to} />

            {rows.length === 0 ? (
                <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-8 text-center text-sm text-emerald-700">
                    🎉 No unprofitable products in range.
                </div>
            ) : (
                <div className="overflow-hidden rounded-lg border border-red-200 bg-white">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-red-50 text-left text-xs font-medium uppercase tracking-wide text-red-700">
                            <tr>
                                <th className="px-4 py-2.5">SKU</th>
                                <th className="px-4 py-2.5">Product</th>
                                <th className="px-4 py-2.5 text-right">Units sold</th>
                                <th className="px-4 py-2.5 text-right">Revenue</th>
                                <th className="px-4 py-2.5 text-right">Gross profit</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.map((r) => (
                                <tr key={r.product_id} className="bg-red-50/40">
                                    <td className="px-4 py-2 font-mono text-xs">{r.sku}</td>
                                    <td className="px-4 py-2 text-slate-800">{r.product_name}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{r.units_total}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{sym}{fmt(r.revenue)}</td>
                                    <td className="px-4 py-2 text-right tabular-nums text-red-700 font-medium">{sym}{fmt(r.gross_profit)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
