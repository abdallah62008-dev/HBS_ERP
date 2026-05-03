import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import useCan from '@/Hooks/useCan';
import { Head, Link } from '@inertiajs/react';

const REPORTS = [
    { title: 'Sales', href: '/reports/sales', desc: 'Daily series + total revenue, delivered, returned', perm: 'reports.sales' },
    { title: 'Profit', href: '/reports/profit', desc: 'Revenue − COGS − expenses', perm: 'reports.profit' },
    { title: 'Product profitability', href: '/reports/product-profitability', desc: 'Per-product revenue, profit, return rate', perm: 'reports.profit' },
    { title: 'Unprofitable products', href: '/reports/unprofitable-products', desc: 'Products that sell but lose money', perm: 'reports.profit' },
    { title: 'Inventory', href: '/reports/inventory', desc: 'Per-product on-hand, reserved, available', perm: 'reports.inventory' },
    { title: 'Stock forecast', href: '/reports/stock-forecast', desc: 'Days-of-stock based on recent burn rate', perm: 'reports.inventory' },
    { title: 'Shipping performance', href: '/reports/shipping', desc: 'Delivery rate + return rate per carrier', perm: 'reports.shipping' },
    { title: 'Collections', href: '/reports/collections', desc: 'Outstanding COD by status and carrier', perm: 'reports.cash_flow' },
    { title: 'Returns', href: '/reports/returns', desc: 'Returns by reason, refund totals', perm: 'reports.profit' },
    { title: 'Marketers', href: '/reports/marketers', desc: 'Per-marketer revenue, profit, return rate', perm: 'reports.marketers' },
    { title: 'Staff', href: '/reports/staff', desc: 'Confirmations / shipments / deliveries per user', perm: 'reports.staff' },
    { title: 'Ads', href: '/reports/ads', desc: 'Campaign-level ROAS and net profit', perm: 'reports.ads' },
    { title: 'Cash flow', href: '/reports/cash-flow', desc: 'Inflows vs outflows, net for the period', perm: 'reports.cash_flow' },
];

export default function ReportsIndex() {
    const can = useCan();
    return (
        <AuthenticatedLayout header="Reports">
            <Head title="Reports" />
            <PageHeader title="Reports" subtitle="Pick a report to drill in." />

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {REPORTS.filter((r) => can(r.perm)).map((r) => (
                    <Link key={r.href} href={r.href} className="group rounded-lg border border-slate-200 bg-white p-5 transition hover:-translate-y-0.5 hover:shadow-sm">
                        <div className="text-sm font-semibold text-slate-800 group-hover:text-indigo-600">{r.title}</div>
                        <p className="mt-1 text-xs text-slate-500">{r.desc}</p>
                    </Link>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
