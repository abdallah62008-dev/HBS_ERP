import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';

/**
 * Phase 1 dashboard.
 *
 * Real metrics (orders, revenue, low stock, etc.) come online in Phase 2+.
 * For now we show an at-a-glance summary of what the foundation seeded so
 * the operator can verify the install is healthy.
 */
function StatCard({ label, value, hint }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">
                {label}
            </div>
            <div className="mt-2 text-2xl font-semibold text-slate-900">{value}</div>
            {hint && <div className="mt-1 text-xs text-slate-400">{hint}</div>}
        </div>
    );
}

function PlaceholderCard({ title, items }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div className="mb-3 text-sm font-semibold text-slate-700">{title}</div>
            <ul className="space-y-2 text-sm text-slate-500">
                {items.map((it) => (
                    <li key={it} className="flex items-center justify-between">
                        <span>{it}</span>
                        <span className="text-xs text-slate-400">— available later</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

export default function Dashboard({ stats }) {
    const { props } = usePage();
    const user = props.auth?.user;

    return (
        <AuthenticatedLayout header="Dashboard">
            <Head title="Dashboard" />

            <div className="mb-6">
                <h1 className="text-xl font-semibold text-slate-800">
                    Welcome back, {user?.name?.split(' ')[0] ?? 'operator'}
                </h1>
                <p className="text-sm text-slate-500">
                    {user?.role?.name} · {props.app?.country} · {props.app?.currency_code}
                </p>
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Roles" value={stats?.roles ?? 0} hint="System + custom" />
                <StatCard label="Permissions" value={stats?.permissions ?? 0} hint="Backend-enforced" />
                <StatCard label="Active users" value={stats?.users ?? 0} hint="Status = Active" />
                <StatCard
                    label="Fiscal year"
                    value={stats?.fiscal_year ?? '—'}
                    hint={stats?.fiscal_year_status ?? ''}
                />
            </div>

            <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <PlaceholderCard
                    title="Operations widgets (Phase 2+)"
                    items={[
                        'Today orders · New / Confirmed / Shipped / Delivered',
                        'Pending collections · Net profit',
                        'High-risk orders · Delayed shipments',
                    ]}
                />
                <PlaceholderCard
                    title="Inventory & growth widgets (Phase 3 / Phase 6)"
                    items={[
                        'Low stock products · Best sellers',
                        'Marketer performance · Shipping company performance',
                        'Unprofitable campaigns · Staff targets',
                    ]}
                />
            </div>

            <div className="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <strong>Phase 1 foundation is live.</strong> Authentication, RBAC,
                settings, audit log foundation, fiscal year, and seed data are
                ready. The sidebar lists every module — items linking to pages
                that haven&apos;t been built yet show a &quot;coming soon&quot; stub.
            </div>
        </AuthenticatedLayout>
    );
}
