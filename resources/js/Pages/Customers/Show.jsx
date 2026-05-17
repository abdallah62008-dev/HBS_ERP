import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

function Field({ label, value }) {
    return (
        <div>
            <div className="text-[11px] font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-0.5 text-sm text-slate-800">{value || <span className="text-slate-400">—</span>}</div>
        </div>
    );
}

/**
 * C-2: compact stat card. Money values are formatted with the system
 * currency symbol; count values use `tabular-nums` so the numbers line
 * up cleanly across cards.
 */
function StatCard({ label, value, hint, tone = 'default' }) {
    const toneClasses = {
        default: 'border-slate-200 bg-white',
        amber: 'border-amber-200 bg-amber-50',
        emerald: 'border-emerald-200 bg-emerald-50',
        slate: 'border-slate-200 bg-slate-50',
    }[tone] ?? 'border-slate-200 bg-white';
    return (
        <div className={`rounded-lg border ${toneClasses} p-3`}>
            <div className="text-[10px] font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className="mt-1 text-lg font-semibold tabular-nums text-slate-900">
                {value === null || value === undefined || value === '' ? <span className="text-slate-400">—</span> : value}
            </div>
            {hint && <div className="mt-0.5 text-[10px] text-slate-400">{hint}</div>}
        </div>
    );
}

function fmtMoney(n, sym = '') {
    if (n === null || n === undefined) return null;
    return `${sym}${Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}
function fmtPercent(n) {
    if (n === null || n === undefined) return null;
    return `${Number(n).toFixed(1)}%`;
}
function fmtDate(iso) {
    if (!iso) return null;
    return String(iso).split('T')[0];
}

/**
 * C-3: timestamp formatter for the activity timeline. Shows date +
 * 24h time without seconds. Falls back to the raw ISO string when
 * the value is unparseable so we never render "Invalid Date".
 */
function fmtTimelineTimestamp(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return String(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/**
 * C-3: tone → Tailwind class mapping for the timeline event dot.
 * Keeps the colour palette consistent with the stats cards.
 */
const TIMELINE_TONES = {
    default: 'bg-slate-300',
    slate: 'bg-slate-400',
    emerald: 'bg-emerald-500',
    amber: 'bg-amber-500',
    red: 'bg-red-500',
};

/**
 * C-3: type → icon glyph mapping. Lucide-ish glyphs available in the
 * project's font; falls back to a neutral dot otherwise.
 */
const TIMELINE_TYPE_LABELS = {
    customer_created: 'Profile',
    order_created: 'Order',
    order_status_changed: 'Status',
    return_created: 'Return',
    refund_created: 'Refund',
    refund_approved: 'Refund',
    refund_rejected: 'Refund',
    refund_paid: 'Refund',
};

export default function CustomerShow({
    customer,
    risk_breakdown,
    // C-1 quick-action props. Defaults keep the page safe when an older
    // controller payload is rendered (e.g. cached SSR output).
    latest_order_id = null,
    total_orders = 0,
    whatsapp_url = null,
    can_create_order = false,
    can_view_orders = false,
    // C-2: stats + duplicate alert + risk recommendation.
    stats = null,
    duplicate_customers = [],
    risk_recommendation = null,
    // C-3: read-only activity timeline (capped at 30 events).
    timeline = [],
    // C-4A: structured customer notes (latest 50 from the new
    // `customer_notes` table). Separate from the legacy
    // `customer.notes` free-text column.
    customer_notes = [],
    can_delete_customer = false,
}) {
    const can = useCan();
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';

    const handleDelete = () => {
        if (!confirm(`Delete customer "${customer.name}"? This is a soft delete and can be restored.`)) return;
        router.delete(route('customers.destroy', customer.id));
    };

    /* C-4A: customer notes inline form state. Uses Inertia useForm so
       validation errors land on `noteForm.errors.note`. The submit
       handler resets only the note body after a successful POST so the
       internal/external preference stays sticky. */
    const noteForm = useForm({ note: '', is_internal: true });
    const submitNote = (e) => {
        e.preventDefault();
        if (!noteForm.data.note.trim() || noteForm.processing) return;
        noteForm.post(route('customers.notes.store', customer.id), {
            preserveScroll: true,
            onSuccess: () => noteForm.reset('note'),
        });
    };
    const deleteNote = (noteId) => {
        if (!confirm('Delete this note? This cannot be undone.')) return;
        router.delete(route('customers.notes.destroy', [customer.id, noteId]), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout header={customer.name}>
            <Head title={customer.name} />

            <PageHeader
                title={customer.name}
                subtitle={`Customer #${customer.id} · ${customer.primary_phone}${total_orders > 0 ? ` · ${total_orders} order${total_orders === 1 ? '' : 's'}` : ''}`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {/* C-1: Quick action bar. Each button is a Link
                            (no POST) so we cannot accidentally create an
                            order. Operator still has to click Save on
                            the resulting Order Create page. */}
                        {can_create_order && (
                            <Link
                                href={route('orders.create', { customer_id: customer.id })}
                                className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
                            >
                                + Add Order
                            </Link>
                        )}
                        {can_view_orders && (
                            <Link
                                href={route('orders.index', { customer_id: customer.id })}
                                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50"
                            >
                                View Orders
                            </Link>
                        )}
                        {can_create_order && latest_order_id && (
                            <Link
                                href={route('orders.create', { duplicate_from: latest_order_id })}
                                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50"
                                title="Pre-fill a new order from this customer's most recent order"
                            >
                                Duplicate Last Order
                            </Link>
                        )}
                        {whatsapp_url && (
                            <a
                                href={whatsapp_url}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center gap-1 rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-100"
                            >
                                <span aria-hidden="true">🟢</span> WhatsApp
                            </a>
                        )}
                        {can('customers.edit') && (
                            <Link
                                href={route('customers.edit', customer.id)}
                                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50"
                            >
                                Edit
                            </Link>
                        )}
                        {can('customers.delete') && (
                            <button
                                type="button"
                                onClick={handleDelete}
                                className="rounded-md border border-red-200 bg-white px-3 py-2 text-sm text-red-600 hover:bg-red-50"
                            >
                                Delete
                            </button>
                        )}
                    </div>
                }
            />

            {/* C-2: duplicate-customer alert. Read-only — clicking the
                link navigates to the other customer's profile. No merge
                in this phase. */}
            {Array.isArray(duplicate_customers) && duplicate_customers.length > 0 && (
                <div className="mb-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm" role="status">
                    <div className="font-semibold text-amber-900">
                        Possible duplicate customer{duplicate_customers.length === 1 ? '' : 's'} found
                    </div>
                    <ul className="mt-1.5 list-disc pl-5 text-[12px] text-amber-900">
                        {duplicate_customers.map((d) => (
                            <li key={d.id}>
                                <Link href={route('customers.show', d.id)} className="font-medium hover:underline">
                                    {d.name}
                                </Link>{' '}
                                <span className="text-amber-700">· {d.primary_phone}</span>
                            </li>
                        ))}
                    </ul>
                    <p className="mt-1.5 text-[11px] text-amber-700">
                        Same normalized phone as this customer. Review and merge manually if these are the same person.
                    </p>
                </div>
            )}

            {/* C-4A: Customer notes panel. Add-note form + list of the
                latest 50 notes (newest first). Internal/external badge
                per row. Delete gated by `can_delete_customer`. */}
            {can('customers.view') && (
                <div className="mb-4 rounded-lg border border-slate-200 bg-white">
                    <div className="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                        <h2 className="text-sm font-semibold text-slate-700">Notes</h2>
                        <span className="text-xs text-slate-400">
                            {customer_notes.length === 0
                                ? 'No notes yet'
                                : `${customer_notes.length} note${customer_notes.length === 1 ? '' : 's'}`}
                        </span>
                    </div>

                    {can('customers.edit') && (
                        <form onSubmit={submitNote} className="border-b border-slate-100 px-5 py-3">
                            <label htmlFor="customer-note-body" className="sr-only">Add a note</label>
                            <textarea
                                id="customer-note-body"
                                rows={2}
                                value={noteForm.data.note}
                                onChange={(e) => noteForm.setData('note', e.target.value)}
                                placeholder="Add a note about this customer (delivery preference, do-not-call, address quirk…)"
                                maxLength={5000}
                                className="block w-full rounded-md border-slate-300 text-sm"
                                disabled={noteForm.processing}
                            />
                            {noteForm.errors.note && (
                                <p className="mt-1 text-xs text-red-600">{noteForm.errors.note}</p>
                            )}
                            <div className="mt-2 flex items-center justify-between gap-2">
                                <label className="flex items-center gap-2 text-xs text-slate-600">
                                    <input
                                        type="checkbox"
                                        checked={noteForm.data.is_internal}
                                        onChange={(e) => noteForm.setData('is_internal', e.target.checked)}
                                        className="rounded border-slate-300"
                                    />
                                    Internal only (not customer-facing)
                                </label>
                                <button
                                    type="submit"
                                    disabled={!noteForm.data.note.trim() || noteForm.processing}
                                    className="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                                >
                                    {noteForm.processing ? 'Saving…' : 'Add note'}
                                </button>
                            </div>
                        </form>
                    )}

                    {customer_notes.length === 0 ? (
                        <div className="px-5 py-6 text-center text-xs text-slate-400">
                            No notes recorded yet.
                        </div>
                    ) : (
                        <ul className="divide-y divide-slate-100">
                            {customer_notes.map((n) => (
                                <li key={n.id} className="px-5 py-3">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0 flex-1">
                                            <div className="mb-1 flex flex-wrap items-center gap-2">
                                                <span className={
                                                    'rounded-full px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide ' +
                                                    (n.is_internal
                                                        ? 'bg-slate-200 text-slate-700'
                                                        : 'bg-amber-100 text-amber-800')
                                                }>
                                                    {n.is_internal ? 'Internal' : 'External'}
                                                </span>
                                                {n.created_by?.name && (
                                                    <span className="text-[11px] text-slate-500">by {n.created_by.name}</span>
                                                )}
                                                <span className="text-[11px] text-slate-400" title={n.created_at || ''}>
                                                    {fmtTimelineTimestamp(n.created_at)}
                                                </span>
                                            </div>
                                            <div className="whitespace-pre-wrap text-sm text-slate-700">{n.note}</div>
                                        </div>
                                        {can_delete_customer && (
                                            <button
                                                type="button"
                                                onClick={() => deleteNote(n.id)}
                                                className="shrink-0 text-[11px] text-red-600 hover:underline"
                                            >
                                                Delete
                                            </button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {/* C-2: Customer 360 stats cards. Compact grid right above the
                profile + risk panel. Money values use the system currency
                symbol; rates display percentages; nulls show as "—". */}
            {stats && (
                <div className="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                    <StatCard label="Total orders" value={stats.total_orders} />
                    <StatCard label="Delivered" value={stats.delivered_orders} tone="emerald" />
                    <StatCard label="Returned" value={stats.returned_orders} tone={stats.returned_orders > 0 ? 'amber' : 'default'} />
                    <StatCard label="Cancelled" value={stats.cancelled_orders} tone="slate" />
                    <StatCard label="Last order" value={fmtDate(stats.last_order_at)} />
                    <StatCard label="Total spent" value={fmtMoney(stats.total_spent, sym)} hint="Delivered orders only" />
                    <StatCard
                        label="Estimated outstanding"
                        value={fmtMoney(stats.outstanding_balance, sym)}
                        hint="Open COD balances"
                        tone={stats.outstanding_balance > 0 ? 'amber' : 'default'}
                    />
                    <StatCard
                        label="COD success"
                        value={fmtPercent(stats.cod_success_rate)}
                        hint={stats.cod_orders > 0 ? `${stats.cod_collected_orders}/${stats.cod_orders} collected` : 'No COD orders'}
                    />
                    <StatCard
                        label="Return rate"
                        value={fmtPercent(stats.return_rate)}
                        hint={stats.return_rate !== null ? 'Returned / (Delivered + Returned)' : null}
                        tone={stats.return_rate !== null && stats.return_rate >= 20 ? 'amber' : 'default'}
                    />
                    <StatCard
                        label="Avg order value"
                        value={fmtMoney(stats.average_order_value, sym)}
                        hint="Delivered avg"
                    />
                </div>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                {/* Profile card */}
                <div className="lg:col-span-2 rounded-lg border border-slate-200 bg-white p-5">
                    <div className="mb-4 flex items-center gap-2">
                        <StatusBadge value={customer.customer_type} />
                        <StatusBadge value={customer.risk_level} />
                        {customer.tags?.map((t) => (
                            <span key={t.id} className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">
                                {t.tag}
                            </span>
                        ))}
                    </div>

                    <div className="grid grid-cols-2 gap-x-6 gap-y-3">
                        <div>
                            <Field label="Primary phone" value={customer.primary_phone} />
                            {/* O-2: display the normalized E.164 form + a
                                WhatsApp click-to-chat link when reachable. */}
                            {customer.normalized_phone && (
                                <div className="mt-0.5 flex items-center gap-2 text-[11px] text-slate-500">
                                    <span className="font-mono">{customer.normalized_phone}</span>
                                    {customer.primary_phone_whatsapp && (
                                        <a
                                            href={`https://wa.me/${customer.normalized_phone.replace(/^\+/, '')}`}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-700 hover:bg-emerald-200"
                                        >
                                            <span aria-hidden="true">🟢</span> WhatsApp
                                        </a>
                                    )}
                                </div>
                            )}
                        </div>
                        <Field label="Secondary phone" value={customer.secondary_phone} />
                        <Field label="Email" value={customer.email} />
                        <Field label="Country" value={customer.country} />
                        <Field label="Governorate" value={customer.governorate} />
                        <Field label="City" value={customer.city} />
                        <div className="col-span-2">
                            <Field label="Default address" value={customer.default_address} />
                        </div>
                        {customer.notes && (
                            <div className="col-span-2">
                                <Field label="Notes" value={customer.notes} />
                            </div>
                        )}
                    </div>
                </div>

                {/* Risk panel */}
                {can('customers.view_risk') && (
                    <div className="rounded-lg border border-slate-200 bg-white p-5">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-sm font-semibold text-slate-700">Risk score</h2>
                            <StatusBadge value={risk_breakdown.level} />
                        </div>
                        <div className="text-3xl font-semibold tabular-nums text-slate-800">
                            {risk_breakdown.score}<span className="text-base text-slate-400">/100</span>
                        </div>
                        {/* C-2: operational guidance copy. Pure read-only
                            — the order flow is NEVER blocked by this. */}
                        {risk_recommendation && (
                            <div className="mt-2 rounded-md bg-slate-50 px-2.5 py-1.5 text-[11px] text-slate-600">
                                {risk_recommendation}
                            </div>
                        )}
                        <div className="mt-3 space-y-1 text-xs text-slate-500">
                            {Object.keys(risk_breakdown.breakdown).length === 0 && (
                                <div className="text-slate-400">No history yet — score is 0.</div>
                            )}
                            {Object.entries(risk_breakdown.breakdown).map(([k, v]) => (
                                <div key={k} className="flex justify-between">
                                    <span className="capitalize">{k.replaceAll('_', ' ')}</span>
                                    <span className={v >= 0 ? 'text-red-600' : 'text-green-600'}>
                                        {v > 0 ? `+${v}` : v}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Recent orders */}
            <div className="mt-6 rounded-lg border border-slate-200 bg-white">
                <div className="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                    <h2 className="text-sm font-semibold text-slate-700">Recent orders</h2>
                    <div className="flex items-center gap-3">
                        <span className="text-xs text-slate-400">{customer.orders?.length ?? 0} most recent</span>
                        {/* C-1: shortcut to the filtered orders index. */}
                        {can_view_orders && total_orders > (customer.orders?.length ?? 0) && (
                            <Link
                                href={route('orders.index', { customer_id: customer.id })}
                                className="text-xs font-medium text-indigo-600 hover:underline"
                            >
                                View all {total_orders} →
                            </Link>
                        )}
                    </div>
                </div>

                {(!customer.orders || customer.orders.length === 0) ? (
                    <div className="px-5 py-10 text-center text-sm text-slate-400">No orders yet.</div>
                ) : (
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-5 py-2">Order #</th>
                                <th className="px-5 py-2">Status</th>
                                <th className="px-5 py-2">Total</th>
                                <th className="px-5 py-2">Created</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {customer.orders.map((o) => (
                                <tr key={o.id} className="hover:bg-slate-50">
                                    <td className="px-5 py-2 font-medium">
                                        <Link href={route('orders.show', o.id)} className="text-slate-700 hover:text-indigo-600">
                                            {o.order_number}
                                        </Link>
                                    </td>
                                    <td className="px-5 py-2"><StatusBadge value={o.status} /></td>
                                    <td className="px-5 py-2 tabular-nums">{o.total_amount}</td>
                                    <td className="px-5 py-2 text-slate-500">{o.created_at?.split('T')[0]}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {/* C-3: read-only customer activity timeline. Merges
                customer_created + order_created + order_status_changed
                + return_created + refund_(created|approved|rejected|paid)
                events. Capped at 30 newest-first. */}
            <div className="mt-6 rounded-lg border border-slate-200 bg-white">
                <div className="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                    <h2 className="text-sm font-semibold text-slate-700">Activity timeline</h2>
                    <span className="text-xs text-slate-400">
                        {Array.isArray(timeline) && timeline.length > 0
                            ? `${timeline.length} event${timeline.length === 1 ? '' : 's'}`
                            : 'No activity yet'}
                    </span>
                </div>
                {(!Array.isArray(timeline) || timeline.length === 0) ? (
                    <div className="px-5 py-8 text-center text-sm text-slate-400">No activity yet.</div>
                ) : (
                    <ol className="divide-y divide-slate-100">
                        {timeline.map((evt) => {
                            const dotClass = TIMELINE_TONES[evt.tone] ?? TIMELINE_TONES.default;
                            const typeLabel = TIMELINE_TYPE_LABELS[evt.type] ?? 'Event';
                            const TitleEl = evt.href ? Link : 'span';
                            const titleProps = evt.href ? { href: evt.href, className: 'font-medium text-slate-800 hover:text-indigo-600' } : { className: 'font-medium text-slate-800' };
                            return (
                                <li key={evt.id} className="flex items-start gap-3 px-5 py-3">
                                    <div className="mt-1.5 flex h-5 w-5 shrink-0 items-center justify-center">
                                        <span className={`inline-block h-2 w-2 rounded-full ${dotClass}`} aria-hidden="true" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-baseline gap-2">
                                            <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-600">
                                                {typeLabel}
                                            </span>
                                            <TitleEl {...titleProps}>{evt.title}</TitleEl>
                                            {evt.actor_name && (
                                                <span className="text-[11px] text-slate-400">· by {evt.actor_name}</span>
                                            )}
                                        </div>
                                        {evt.subtitle && (
                                            <div className="mt-0.5 text-xs text-slate-500">{evt.subtitle}</div>
                                        )}
                                    </div>
                                    <div className="shrink-0 text-[11px] tabular-nums text-slate-400" title={evt.timestamp || ''}>
                                        {fmtTimelineTimestamp(evt.timestamp)}
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
