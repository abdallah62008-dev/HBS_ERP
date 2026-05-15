import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportFilters from '@/Components/ReportFilters';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, usePage } from '@inertiajs/react';

/**
 * Returns analytics — Phase 7.
 *
 * Two axes of breakdown are intentionally kept distinct:
 *   - return_status (lifecycle: Pending → Restocked / Damaged / Closed)
 *   - product_condition (verdict: Good / Damaged / Missing Parts / Unknown)
 *
 * A return can have any combination — e.g. status=Inspected, condition=Good —
 * so flattening them into one column would lose signal. The page surfaces
 * both, side by side.
 *
 * Refund exposure and shipping loss are shown SEPARATELY (not summed) so
 * managers can read them as the two distinct money concerns they are:
 *   - refund exposure is what the company has *promised in intent* — the
 *     `refund_amount` field on the return, NOT a posted refund
 *   - shipping loss is the courier cost the business absorbed on failed
 *     deliveries — also intent, not a finance posting
 */
function fmt(n) {
    return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function pct(num, denom) {
    if (!denom) return '—';
    return ((Number(num) / Number(denom)) * 100).toFixed(1) + '%';
}

export default function ReturnsReport({
    from,
    to,
    totals,
    by_reason,
    by_status,
    by_condition,
    top_products,
}) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const total = Number(totals?.total ?? 0);
    const active = Number(totals?.active ?? 0);
    const resolved = Number(totals?.resolved ?? 0);
    const restocked = Number(totals?.restocked ?? 0);
    const damaged = Number(totals?.damaged ?? 0);

    return (
        <AuthenticatedLayout header="Returns report">
            <Head title="Returns report" />
            <PageHeader
                title="Returns"
                subtitle={`${from} to ${to}`}
                actions={
                    <Link href={route('reports.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">
                        ← Reports
                    </Link>
                }
            />
            <ReportFilters routeName="reports.returns" from={from} to={to} />

            {/* Row 1 — lifecycle volume. */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Stat label="Total returns" value={total} />
                <Stat label="Active" value={active} hint={total ? `${pct(active, total)} of period` : undefined} />
                <Stat label="Resolved" value={resolved} tone="emerald" hint={total ? `${pct(resolved, total)} of period` : undefined} />
                <Stat label="Damaged returns" value={damaged} tone={damaged > 0 ? 'red' : 'slate'} hint={total ? `${pct(damaged, total)} of period` : undefined} />
            </div>

            {/* Row 2 — money exposure. Refund + Shipping loss intentionally
                split — they are different concerns (intent vs. absorbed
                shipping cost). Restock rate sits next to them so the
                ratio is read alongside the absolute exposure. */}
            <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Stat
                    label="Refund exposure"
                    value={`${sym}${fmt(totals?.refund_total)}`}
                    hint="Sum of refund_amount (intent — not a posted refund)"
                />
                <Stat
                    label="Shipping loss"
                    value={`${sym}${fmt(totals?.shipping_loss_total)}`}
                    hint="Sum of absorbed shipping cost"
                />
                <Stat label="Restocked" value={restocked} tone="emerald" hint={total ? `${pct(restocked, total)} of period` : undefined} />
                <Stat
                    label="Restock rate"
                    value={pct(restocked, restocked + damaged)}
                    hint="Of inspection-decided returns"
                />
            </div>

            {/* Breakdown row — by-status and by-condition side by side so
                the lifecycle axis and verdict axis can be compared. */}
            <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Panel title="By status" subtitle="Lifecycle position">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-2.5">Status</th>
                                <th className="px-4 py-2.5">Bucket</th>
                                <th className="px-4 py-2.5 text-right">Count</th>
                                <th className="px-4 py-2.5 text-right">Share</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {(by_status ?? []).map((row) => (
                                <tr key={row.status} className={row.count === 0 ? 'opacity-60' : ''}>
                                    <td className="px-4 py-2"><StatusBadge value={row.status} /></td>
                                    <td className="px-4 py-2 text-xs text-slate-500 uppercase">{row.bucket}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{row.count}</td>
                                    <td className="px-4 py-2 text-right tabular-nums text-xs text-slate-500">{pct(row.count, total)}</td>
                                </tr>
                            ))}
                            {total === 0 && (
                                <tr><td colSpan={4} className="px-4 py-8 text-center text-sm text-slate-400">No returns in range.</td></tr>
                            )}
                        </tbody>
                    </table>
                </Panel>

                <Panel title="By condition" subtitle="Inspector verdict">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-2.5">Condition</th>
                                <th className="px-4 py-2.5 text-right">Count</th>
                                <th className="px-4 py-2.5 text-right">Share</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {(by_condition ?? []).map((row) => (
                                <tr key={row.condition} className={row.count === 0 ? 'opacity-60' : ''}>
                                    <td className="px-4 py-2"><StatusBadge value={row.condition} /></td>
                                    <td className="px-4 py-2 text-right tabular-nums">{row.count}</td>
                                    <td className="px-4 py-2 text-right tabular-nums text-xs text-slate-500">{pct(row.count, total)}</td>
                                </tr>
                            ))}
                            {total === 0 && (
                                <tr><td colSpan={3} className="px-4 py-8 text-center text-sm text-slate-400">No returns in range.</td></tr>
                            )}
                        </tbody>
                    </table>
                </Panel>
            </div>

            <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Panel title="By reason" subtitle="Why returns are coming back">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-2.5">Reason</th>
                                <th className="px-4 py-2.5 text-right">Count</th>
                                <th className="px-4 py-2.5 text-right">Share</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {(by_reason ?? []).length === 0 && (
                                <tr><td colSpan={3} className="px-4 py-8 text-center text-sm text-slate-400">No returns in range.</td></tr>
                            )}
                            {(by_reason ?? []).map((r) => (
                                <tr key={r.reason}>
                                    <td className="px-4 py-2 text-slate-700">{r.reason}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{r.count}</td>
                                    <td className="px-4 py-2 text-right tabular-nums text-xs text-slate-500">{pct(r.count, total)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Panel>

                <Panel title="Top returned products" subtitle="By distinct return count">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-2.5">SKU</th>
                                <th className="px-4 py-2.5">Product</th>
                                <th className="px-4 py-2.5 text-right">Returns</th>
                                <th className="px-4 py-2.5 text-right">Units</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {(top_products ?? []).length === 0 && (
                                <tr><td colSpan={4} className="px-4 py-8 text-center text-sm text-slate-400">No returned products in range.</td></tr>
                            )}
                            {(top_products ?? []).map((p) => (
                                <tr key={p.product_id}>
                                    <td className="px-4 py-2 font-mono text-xs text-slate-600">{p.sku}</td>
                                    <td className="px-4 py-2 text-slate-700">{p.name}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{p.return_count}</td>
                                    <td className="px-4 py-2 text-right tabular-nums text-xs text-slate-500">{p.unit_count}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Panel>
            </div>
        </AuthenticatedLayout>
    );
}

function Stat({ label, value, tone, hint }) {
    const palette = { emerald: 'border-emerald-200 bg-emerald-50', red: 'border-red-200 bg-red-50' }[tone] ?? 'border-slate-200 bg-white';
    return (
        <div className={`rounded-lg border p-5 ${palette}`}>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 text-2xl font-semibold tabular-nums text-slate-800">{value}</div>
            {hint && <div className="mt-1 text-[11px] text-slate-500">{hint}</div>}
        </div>
    );
}

function Panel({ title, subtitle, children }) {
    return (
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div className="border-b border-slate-200 px-5 py-3">
                <div className="text-sm font-semibold text-slate-700">{title}</div>
                {subtitle && <div className="text-[11px] text-slate-500">{subtitle}</div>}
            </div>
            {children}
        </div>
    );
}
