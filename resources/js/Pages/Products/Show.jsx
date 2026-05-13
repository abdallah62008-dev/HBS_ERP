import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, usePage } from '@inertiajs/react';

function fmt(n) {
    if (n === null || n === undefined) return '—';
    return Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export default function ProductShow({ product }) {
    const can = useCan();
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const handleDelete = () => {
        if (!confirm(`Delete product "${product.name}"? This is a soft delete.`)) return;
        router.delete(route('products.destroy', product.id));
    };

    return (
        <AuthenticatedLayout header={product.name}>
            <Head title={product.name} />

            <PageHeader
                title={product.name}
                subtitle={`SKU ${product.sku}${product.barcode ? ` · ${product.barcode}` : ''}`}
                actions={
                    <div className="flex gap-2">
                        {can('products.edit') && (
                            <Link href={route('products.edit', product.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">
                                Edit
                            </Link>
                        )}
                        {can('products.delete') && (
                            <button onClick={handleDelete} className="rounded-md border border-red-200 bg-white px-3 py-2 text-sm text-red-600 hover:bg-red-50">
                                Delete
                            </button>
                        )}
                    </div>
                }
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="lg:col-span-2 rounded-lg border border-slate-200 bg-white p-5">
                    <div className="mb-3 flex items-center gap-2">
                        <StatusBadge value={product.status} />
                        {product.category && <span className="text-xs text-slate-500">{product.category.name}</span>}
                        {product.tax_enabled && (
                            <span className="rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-700">
                                Tax {product.tax_rate}%
                            </span>
                        )}
                    </div>

                    <p className="text-sm text-slate-600">{product.description || <span className="text-slate-400">No description.</span>}</p>

                    <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <Money label="Cost" value={fmt(product.cost_price)} sym={sym} />
                        <Money label="Selling" value={fmt(product.selling_price)} sym={sym} />
                        <Money label="Trade" value={fmt(product.marketer_trade_price)} sym={sym} />
                        <Money label="Min selling" value={fmt(product.minimum_selling_price)} sym={sym} />
                    </div>
                </div>

                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="text-sm font-semibold text-slate-700">Reorder</h2>
                    <div className="mt-2 text-3xl font-semibold tabular-nums text-slate-800">{product.reorder_level}</div>
                </div>
            </div>

            {/* Variants */}
            <div className="mt-6 rounded-lg border border-slate-200 bg-white">
                <div className="border-b border-slate-200 px-5 py-3">
                    <h2 className="text-sm font-semibold text-slate-700">Variants</h2>
                </div>
                {(!product.variants || product.variants.length === 0) ? (
                    <div className="px-5 py-8 text-center text-sm text-slate-400">No variants.</div>
                ) : (
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-5 py-2">Variant</th>
                                <th className="px-5 py-2">SKU</th>
                                <th className="px-5 py-2 text-right">Cost</th>
                                <th className="px-5 py-2 text-right">Selling</th>
                                <th className="px-5 py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {product.variants.map((v) => (
                                <tr key={v.id}>
                                    <td className="px-5 py-2">{v.variant_name}</td>
                                    <td className="px-5 py-2 font-mono text-xs">{v.sku}</td>
                                    <td className="px-5 py-2 text-right tabular-nums">{fmt(v.cost_price)}</td>
                                    <td className="px-5 py-2 text-right tabular-nums">{fmt(v.selling_price)}</td>
                                    <td className="px-5 py-2"><StatusBadge value={v.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {/* Price history */}
            <div className="mt-6 rounded-lg border border-slate-200 bg-white">
                <div className="border-b border-slate-200 px-5 py-3">
                    <h2 className="text-sm font-semibold text-slate-700">Price history</h2>
                </div>
                {(!product.price_history || product.price_history.length === 0) ? (
                    <div className="px-5 py-8 text-center text-sm text-slate-400">No price changes recorded.</div>
                ) : (
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-5 py-2">When</th>
                                <th className="px-5 py-2">Cost</th>
                                <th className="px-5 py-2">Selling</th>
                                <th className="px-5 py-2">Trade</th>
                                <th className="px-5 py-2">Reason</th>
                                <th className="px-5 py-2">By</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {product.price_history.map((h) => (
                                <tr key={h.id}>
                                    <td className="px-5 py-2 text-slate-500">{h.created_at?.split('T')[0]}</td>
                                    <td className="px-5 py-2 tabular-nums">{fmt(h.old_cost_price)} → <span className="font-medium">{fmt(h.new_cost_price)}</span></td>
                                    <td className="px-5 py-2 tabular-nums">{fmt(h.old_selling_price)} → <span className="font-medium">{fmt(h.new_selling_price)}</span></td>
                                    <td className="px-5 py-2 tabular-nums">{fmt(h.old_marketer_trade_price)} → <span className="font-medium">{fmt(h.new_marketer_trade_price)}</span></td>
                                    <td className="px-5 py-2 text-slate-600">{h.reason || <span className="text-slate-400">—</span>}</td>
                                    <td className="px-5 py-2 text-slate-500">{h.changed_by?.name ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function Money({ label, value, sym }) {
    return (
        <div className="rounded-md bg-slate-50 p-3">
            <div className="text-[11px] font-medium uppercase text-slate-500">{label}</div>
            <div className="mt-1 text-lg font-semibold tabular-nums text-slate-800">{sym}{value}</div>
        </div>
    );
}
