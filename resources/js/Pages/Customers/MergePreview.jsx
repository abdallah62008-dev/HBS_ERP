import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, useForm } from '@inertiajs/react';

/**
 * Customer C-5A — read-only duplicate merge preview.
 *
 * Pure read view. The page renders enough information for an operator
 * to choose which of two duplicate customers should survive a future
 * merge (C-5B). No form, no submit, no merge button. Every action on
 * the page is a navigation Link.
 */
export default function MergePreview({
    source,
    target,
    affected_records,
    conflicts = [],
    recommended_target_id,
    warnings = [],
    swap_url,
    // C-5B execute gates.
    can_merge = false,
    can_execute_merge = false,
    merge_blocked_reason = null,
    execute_url = null,
    // C-5B Must-Fix flags.
    feature_enabled = false,
    wrong_direction = false,
}) {
    const sides = [
        { key: 'source', label: 'Source (will be merged away)', row: source, counts: affected_records?.source },
        { key: 'target', label: 'Target (will survive)', row: target, counts: affected_records?.target },
    ];

    /* C-5B execute form state. The page only renders the form when
       the operator has `customers.merge`. The Execute button stays
       disabled until: reason ≥10 chars AND typed `MERGE` exactly AND
       (when wrong-direction) the acknowledge checkbox is ticked. The
       backend re-validates every gate. */
    const mergeForm = useForm({ reason: '', confirmation: '', wrong_direction_ack: '' });
    const submitMerge = (e) => {
        e.preventDefault();
        if (!can_execute_merge || mergeForm.processing) return;
        if (mergeForm.data.reason.trim().length < 10) return;
        if (mergeForm.data.confirmation !== 'MERGE') return;
        if (wrong_direction && mergeForm.data.wrong_direction_ack !== '1') return;
        mergeForm.post(execute_url, { preserveScroll: false });
    };
    const reasonValid = mergeForm.data.reason.trim().length >= 10;
    const confirmValid = mergeForm.data.confirmation === 'MERGE';
    const ackValid = !wrong_direction || mergeForm.data.wrong_direction_ack === '1';
    const submitEnabled = can_execute_merge && reasonValid && confirmValid && ackValid && !mergeForm.processing;

    return (
        <AuthenticatedLayout header="Duplicate merge preview">
            <Head title="Duplicate merge preview" />

            <PageHeader
                title="Duplicate merge preview"
                subtitle="Read-only comparison. No data will be changed."
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <Link href={route('customers.show', source.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">
                            ← Back to source
                        </Link>
                        <Link href={route('customers.show', target.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">
                            ← Back to target
                        </Link>
                        {swap_url && (
                            <Link href={swap_url} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">
                                ⇄ Swap source ↔ target
                            </Link>
                        )}
                    </div>
                }
            />

            {/* M3a Must-Fix: feature-flag banner. When the merge
                workflow is disabled, this banner explains why no
                execute form appears. */}
            {!feature_enabled && (
                <div className="mb-4 rounded-md border border-slate-300 bg-slate-50 p-3 text-sm" role="status">
                    <div className="font-semibold text-slate-800">Merge execution is disabled</div>
                    <p className="mt-1 text-[12px] text-slate-600">
                        The customer merge workflow is currently off (feature flag <span className="font-mono">customer_merge_enabled = false</span>).
                        This page is read-only. Contact an administrator to enable the workflow when you're ready to merge.
                    </p>
                </div>
            )}

            {/* Warnings panel — read-only safety flags. */}
            {warnings.length > 0 && (
                <div className="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3" role="status">
                    <div className="text-sm font-semibold text-amber-900">
                        {warnings.length} warning{warnings.length === 1 ? '' : 's'}
                    </div>
                    <ul className="mt-1.5 list-disc space-y-0.5 pl-5 text-[12px]">
                        {warnings.map((w, i) => (
                            <li key={i} className={w.severity === 'high' ? 'text-red-700' : 'text-amber-900'}>
                                <span className="font-medium uppercase tracking-wide text-[10px]">{w.severity}</span>{' '}
                                {w.message}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* Side-by-side comparison cards. */}
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                {sides.map((side) => {
                    const isRecommended = side.row?.id === recommended_target_id;
                    return (
                        <div
                            key={side.key}
                            className={
                                'rounded-lg border p-5 ' +
                                (isRecommended
                                    ? 'border-emerald-300 bg-emerald-50'
                                    : 'border-slate-200 bg-white')
                            }
                        >
                            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div className="text-[10px] font-medium uppercase tracking-wide text-slate-500">{side.label}</div>
                                    <div className="text-base font-semibold text-slate-800">
                                        <Link href={route('customers.show', side.row.id)} className="hover:underline">
                                            {side.row.name}
                                        </Link>
                                        <span className="ml-2 text-xs font-normal text-slate-400">#{side.row.id}</span>
                                    </div>
                                </div>
                                {isRecommended && (
                                    <span className="rounded-full bg-emerald-200 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-emerald-900">
                                        Recommended survivor
                                    </span>
                                )}
                            </div>

                            <div className="mb-3 flex flex-wrap gap-1.5">
                                <StatusBadge value={side.row.customer_type} />
                                <StatusBadge value={side.row.risk_level} />
                                {side.row.primary_phone_whatsapp && (
                                    <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-700">🟢 WhatsApp</span>
                                )}
                            </div>

                            <dl className="space-y-1 text-xs">
                                <Row label="Primary phone" value={side.row.primary_phone} />
                                <Row label="Normalized" value={side.row.normalized_phone} mono />
                                <Row label="Secondary phone" value={side.row.secondary_phone} />
                                <Row label="Email" value={side.row.email} />
                                <Row label="Country" value={side.row.country} />
                                <Row label="Governorate" value={side.row.governorate} />
                                <Row label="City" value={side.row.city} />
                                <Row label="Default address" value={side.row.default_address} />
                                <Row label="Risk score" value={`${side.row.risk_score}/100`} />
                                <Row label="Created" value={fmtDate(side.row.created_at)} />
                                <Row label="Orders" value={side.row.orders_count} />
                                <Row label="Latest order" value={fmtDate(side.row.latest_order_date)} />
                            </dl>

                            {/* Affected-record counts. */}
                            {side.counts && (
                                <div className="mt-3 rounded-md border border-slate-200 bg-slate-50 p-2">
                                    <div className="mb-1 text-[10px] font-medium uppercase tracking-wide text-slate-500">Related records</div>
                                    <ul className="grid grid-cols-3 gap-x-2 gap-y-0.5 text-[11px] text-slate-700">
                                        <Count label="Orders" n={side.counts.orders} />
                                        <Count label="Returns" n={side.counts.returns} />
                                        <Count label="Refunds" n={side.counts.refunds} />
                                        <Count label="Notes" n={side.counts.customer_notes} />
                                        <Count label="Addresses" n={side.counts.customer_addresses} />
                                        <Count label="Tags" n={side.counts.customer_tags} />
                                    </ul>
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>

            {/* Conflicts strip. */}
            <div className="mt-6 rounded-lg border border-slate-200 bg-white">
                <div className="border-b border-slate-200 px-5 py-3">
                    <h2 className="text-sm font-semibold text-slate-700">Conflicting fields</h2>
                </div>
                {conflicts.length === 0 ? (
                    <div className="px-5 py-6 text-center text-xs text-slate-400">
                        No conflicting non-null fields detected.
                    </div>
                ) : (
                    <table className="min-w-full divide-y divide-slate-100 text-xs">
                        <thead className="bg-slate-50 text-left text-[10px] uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-5 py-2">Field</th>
                                <th className="px-5 py-2">Source value</th>
                                <th className="px-5 py-2">Target value</th>
                                <th className="px-5 py-2">Policy (will apply in C-5B)</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {conflicts.map((c, i) => (
                                <tr key={i}>
                                    <td className="px-5 py-2 font-medium text-slate-700">{c.field.replaceAll('_', ' ')}</td>
                                    <td className="px-5 py-2 text-slate-700">{c.source_value}</td>
                                    <td className="px-5 py-2 text-slate-700">{c.target_value}</td>
                                    <td className="px-5 py-2 text-slate-500">{c.policy}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {/* C-5B: execute form. Renders only when the operator
                has `customers.merge`. Cross-phone merges escalate to
                super-admin — for non-super-admin operators on a
                cross-phone pair, the form renders disabled with a
                clear "blocked" message. */}
            {can_merge ? (
                <form onSubmit={submitMerge} className="mt-6 rounded-lg border border-red-200 bg-red-50 p-4">
                    <h2 className="text-sm font-semibold text-red-800">Execute merge</h2>
                    <p className="mt-1 text-xs text-red-700">
                        This action reassigns the source customer's orders, returns, refunds, notes, addresses, and tags to the target.
                        The source customer becomes a read-only tombstone pointing to the target. Order snapshot columns (name / phone /
                        address at order-create time) are preserved unchanged.
                    </p>
                    {!can_execute_merge && merge_blocked_reason && (
                        <div className="mt-3 rounded-md border border-red-300 bg-white px-3 py-2 text-xs text-red-700">
                            🚫 {merge_blocked_reason}
                        </div>
                    )}

                    {/* M5 Must-Fix: wrong-direction warning. Renders
                        prominently above the form when the URL target
                        is NOT the recommended survivor. Operator must
                        explicitly acknowledge before the Execute
                        button enables. */}
                    {wrong_direction && (
                        <div className="mt-3 rounded-md border border-amber-400 bg-amber-100 p-3 text-xs text-amber-900">
                            <div className="font-semibold uppercase tracking-wide">⚠ Wrong direction</div>
                            <p className="mt-1">
                                The recommended survivor is the OTHER customer. You're about to merge AWAY from it.
                                Click <span className="font-medium">⇄ Swap source ↔ target</span> above to invert,
                                or tick the acknowledgement below to proceed anyway.
                            </p>
                            <label className="mt-2 flex items-start gap-2">
                                <input
                                    type="checkbox"
                                    checked={mergeForm.data.wrong_direction_ack === '1'}
                                    onChange={(e) => mergeForm.setData('wrong_direction_ack', e.target.checked ? '1' : '')}
                                    disabled={!can_execute_merge || mergeForm.processing}
                                    className="mt-0.5 rounded border-amber-500"
                                />
                                <span>
                                    I understand the recommended survivor is the other customer, and I'm intentionally proceeding in this direction.
                                </span>
                            </label>
                            {mergeForm.errors.wrong_direction_ack && (
                                <p className="mt-1 text-red-700">{mergeForm.errors.wrong_direction_ack}</p>
                            )}
                        </div>
                    )}
                    <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label htmlFor="merge-reason" className="text-[11px] font-medium uppercase tracking-wide text-red-700">
                                Reason (required, min 10 characters)
                            </label>
                            <textarea
                                id="merge-reason"
                                rows={2}
                                value={mergeForm.data.reason}
                                onChange={(e) => mergeForm.setData('reason', e.target.value)}
                                placeholder="Why are these the same customer? Used for audit."
                                maxLength={1000}
                                className="mt-1 block w-full rounded-md border-red-300 bg-white text-sm"
                                disabled={!can_execute_merge || mergeForm.processing}
                            />
                            {mergeForm.errors.reason && (
                                <p className="mt-1 text-xs text-red-700">{mergeForm.errors.reason}</p>
                            )}
                        </div>
                        <div>
                            <label htmlFor="merge-confirm" className="text-[11px] font-medium uppercase tracking-wide text-red-700">
                                Type <span className="font-mono">MERGE</span> to confirm
                            </label>
                            <input
                                id="merge-confirm"
                                type="text"
                                value={mergeForm.data.confirmation}
                                onChange={(e) => mergeForm.setData('confirmation', e.target.value)}
                                placeholder="MERGE"
                                className="mt-1 block w-full rounded-md border-red-300 bg-white font-mono text-sm"
                                disabled={!can_execute_merge || mergeForm.processing}
                                autoComplete="off"
                            />
                            {mergeForm.errors.confirmation && (
                                <p className="mt-1 text-xs text-red-700">{mergeForm.errors.confirmation}</p>
                            )}
                        </div>
                    </div>
                    <div className="mt-3 flex items-center justify-end">
                        <button
                            type="submit"
                            disabled={!submitEnabled}
                            className="rounded-md bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {mergeForm.processing ? 'Merging…' : `Execute merge — source #${source.id} into target #${target.id}`}
                        </button>
                    </div>
                </form>
            ) : (
                <div className="mt-6 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-4 text-center text-sm text-slate-500">
                    Merge execution requires the <span className="font-mono">customers.merge</span> permission. Contact an administrator if you need to merge these records.
                </div>
            )}
        </AuthenticatedLayout>
    );
}

function Row({ label, value, mono = false }) {
    return (
        <div className="flex items-baseline justify-between gap-2">
            <dt className="text-[10px] uppercase tracking-wide text-slate-500">{label}</dt>
            <dd className={'text-right text-xs text-slate-700 ' + (mono ? 'font-mono' : '')}>
                {value === null || value === undefined || value === ''
                    ? <span className="text-slate-400">—</span>
                    : value}
            </dd>
        </div>
    );
}

function Count({ label, n }) {
    return (
        <li className="flex items-baseline gap-1">
            <span className="text-slate-500">{label}:</span>
            <span className="font-semibold tabular-nums">{Number(n ?? 0)}</span>
        </li>
    );
}

function fmtDate(iso) {
    if (!iso) return null;
    return String(iso).split('T')[0];
}
