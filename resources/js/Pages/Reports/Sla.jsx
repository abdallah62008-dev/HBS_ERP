import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportFilters from '@/Components/ReportFilters';
import { Head, Link } from '@inertiajs/react';

// Hours → human string. Switches to days once we cross 48h.
function fmtDuration(hours) {
    if (hours === null || hours === undefined) return '—';
    if (hours >= 48) return `${(hours / 24).toFixed(1)} d`;
    return `${Number(hours).toFixed(1)} h`;
}

function rateColor(rate) {
    if (rate === null || rate === undefined) return 'text-slate-400';
    if (rate >= 90) return 'text-emerald-700';
    if (rate >= 70) return 'text-amber-700';
    return 'text-red-700';
}

const STAGES = [
    { key: 'confirm', title: 'Time to confirm', desc: 'Order placed → Confirmed' },
    { key: 'ship', title: 'Time to ship', desc: 'Confirmed → Shipped' },
    { key: 'deliver', title: 'Time to deliver', desc: 'Shipped → Delivered' },
];

function MetricCard({ title, desc, m }) {
    const empty = !m || m.count === 0;

    return (
        <div className="rounded-lg border border-slate-200 bg-white p-5">
            <div className="text-sm font-semibold text-slate-800">{title}</div>
            <p className="mt-0.5 text-xs text-slate-500">{desc}</p>

            {empty ? (
                <p className="mt-6 text-sm text-slate-400">No orders reached this stage in range.</p>
            ) : (
                <>
                    <div className="mt-4 flex items-baseline gap-2">
                        <span className={'text-3xl font-bold tabular-nums ' + rateColor(m.on_time_rate)}>
                            {m.on_time_rate}%
                        </span>
                        <span className="text-xs text-slate-500">
                            on time (≤ {fmtDuration(m.threshold_hours)})
                        </span>
                    </div>

                    <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <div className="flex justify-between">
                            <dt className="text-slate-500">p50 (median)</dt>
                            <dd className="tabular-nums text-slate-800">{fmtDuration(m.p50_hours)}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-500">p95</dt>
                            <dd className="tabular-nums text-slate-800">{fmtDuration(m.p95_hours)}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-500">average</dt>
                            <dd className="tabular-nums text-slate-800">{fmtDuration(m.avg_hours)}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-500">orders</dt>
                            <dd className="tabular-nums text-slate-800">{m.count}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-500">on time</dt>
                            <dd className="tabular-nums text-emerald-700">{m.on_time}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-500">exceeded</dt>
                            <dd className="tabular-nums text-red-700">{m.exceeded}</dd>
                        </div>
                    </dl>
                </>
            )}
        </div>
    );
}

export default function SlaReport({ from, to, metrics }) {
    return (
        <AuthenticatedLayout header="SLA performance">
            <Head title="SLA performance" />
            <PageHeader
                title="SLA performance"
                subtitle={`${from} to ${to}`}
                actions={
                    <Link
                        href={route('reports.index')}
                        className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50"
                    >
                        ← Reports
                    </Link>
                }
            />
            <ReportFilters routeName="reports.sla" from={from} to={to} />

            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                {STAGES.map((s) => (
                    <MetricCard key={s.key} title={s.title} desc={s.desc} m={metrics[s.key]} />
                ))}
            </div>

            <p className="mt-4 text-xs text-slate-400">
                Cohort: orders created in the selected range. Each stage counts only orders that
                reached the later milestone — recent orders still in flight are excluded from that
                stage rather than counted as zero. Percentiles use linear interpolation.
            </p>
        </AuthenticatedLayout>
    );
}
