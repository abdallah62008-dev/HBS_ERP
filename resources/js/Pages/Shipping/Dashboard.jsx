import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link } from '@inertiajs/react';

function Card({ to, label, value, tone }) {
    const palette = {
        indigo: 'border-indigo-200 bg-indigo-50 text-indigo-700',
        amber: 'border-amber-200 bg-amber-50 text-amber-700',
        emerald: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        red: 'border-red-200 bg-red-50 text-red-700',
    }[tone] ?? 'border-slate-200 bg-white text-slate-700';

    return (
        <Link href={to} className={`block rounded-lg border p-5 transition hover:-translate-y-0.5 hover:shadow-sm ${palette}`}>
            <div className="text-xs font-medium uppercase tracking-wide opacity-70">{label}</div>
            <div className="mt-2 text-3xl font-semibold tabular-nums">{value}</div>
        </Link>
    );
}

export default function ShippingDashboard({ kpis }) {
    return (
        <AuthenticatedLayout header="Shipping">
            <Head title="Shipping" />
            <PageHeader title="Shipping" subtitle="Worklists, shipments, labels, and delayed deliveries." />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card to={route('shipping.ready-to-pack')} label="Ready to pack" value={kpis.ready_to_pack} tone="indigo" />
                <Card to={route('shipping.ready-to-ship')} label="Ready to ship" value={kpis.ready_to_ship} tone="indigo" />
                <Card to={route('shipping.shipments')} label="In transit" value={kpis.in_transit} tone="emerald" />
                <Card to={route('shipping.delayed')} label="Delayed" value={kpis.delayed} tone="amber" />
            </div>

            <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Link href={route('shipping-companies.index')} className="rounded-lg border border-slate-200 bg-white p-5 hover:shadow-sm">
                    <div className="text-sm font-semibold text-slate-700">Shipping companies & rates</div>
                    <p className="mt-1 text-xs text-slate-500">Manage carriers, contact info, and per-city rates.</p>
                </Link>
                <Link href={route('shipping-labels.index')} className="rounded-lg border border-slate-200 bg-white p-5 hover:shadow-sm">
                    <div className="text-sm font-semibold text-slate-700">Printed labels</div>
                    <p className="mt-1 text-xs text-slate-500">History of every 4×6 label that has been generated.</p>
                </Link>
            </div>
        </AuthenticatedLayout>
    );
}
