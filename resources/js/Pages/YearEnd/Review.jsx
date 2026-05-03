import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

export default function YearEndReview({ snapshot, expected_confirmation }) {
    const { props } = usePage();
    const sym = props.app?.currency_symbol ?? '';
    const year = snapshot.fiscal_year;
    const lastBackup = snapshot.last_backup;

    const form = useForm({ confirmation: '', notes: '' });

    const submit = (e) => {
        e.preventDefault();
        if (form.data.confirmation !== expected_confirmation) {
            alert(`Type exactly: ${expected_confirmation}`);
            return;
        }
        if (!confirm(`This will close ${year.name} permanently. Continue?`)) return;
        form.post(route('year-end.close', year.id));
    };

    const backupRecent = lastBackup && (Date.now() - new Date(lastBackup.created_at).getTime()) < 24 * 3600 * 1000;
    const c = snapshot.counts;
    const hasOpenItems = c.open_orders + c.pending_collections + c.open_returns + c.unpaid_purchases > 0;

    return (
        <AuthenticatedLayout header={`Year-end · ${year.name}`}>
            <Head title={`Year-end ${year.name}`} />
            <PageHeader
                title={`Close fiscal year ${year.name}`}
                subtitle="Review the snapshot, run a backup if needed, then confirm."
                actions={<Link href={route('year-end.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Year-end</Link>}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Open business items</h2>
                    <ul className="divide-y divide-slate-100 text-sm">
                        <Row label="Open orders" value={c.open_orders} bad={c.open_orders > 0} />
                        <Row label="Pending collections" value={c.pending_collections} bad={c.pending_collections > 0} />
                        <Row label="Open returns" value={c.open_returns} bad={c.open_returns > 0} />
                        <Row label="Unpaid purchases" value={c.unpaid_purchases} bad={c.unpaid_purchases > 0} />
                    </ul>
                    <div className="mt-3 border-t border-slate-200 pt-3 text-sm">
                        <Row label="Marketer outstanding balance" value={`${sym}${fmt(snapshot.marketer_outstanding_balance)}`} />
                    </div>
                    {hasOpenItems && (
                        <p className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                            ⚠ You have open business in this year. Closing is allowed but those items will continue into the new year unchanged.
                        </p>
                    )}
                </div>

                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Backup gate</h2>
                    {lastBackup ? (
                        <div className={'rounded-md p-4 ' + (backupRecent ? 'border border-emerald-200 bg-emerald-50' : 'border border-red-200 bg-red-50')}>
                            <div className="text-sm font-medium text-slate-800">Last successful backup</div>
                            <div className="mt-1 text-xs text-slate-600">{lastBackup.created_at?.replace('T', ' ').slice(0, 16)} · {lastBackup.size}</div>
                            <div className={'mt-1 text-xs ' + (backupRecent ? 'text-emerald-700' : 'text-red-700')}>
                                {backupRecent ? '✓ Within the last 24 hours — closing allowed.' : '✗ More than 24 hours old. Run a fresh backup before closing.'}
                            </div>
                        </div>
                    ) : (
                        <div className="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                            No successful backup found. Run a backup before closing.
                        </div>
                    )}
                    <Link href={route('backups.index')} className="mt-3 inline-block text-xs font-medium text-indigo-600 hover:underline">
                        Open backups →
                    </Link>
                </div>
            </div>

            <form onSubmit={submit} className="mt-6 rounded-lg border border-slate-200 bg-white p-5 space-y-3">
                <h2 className="text-sm font-semibold text-slate-700">Confirm</h2>
                <p className="text-xs text-slate-500">
                    Type <code className="rounded bg-slate-100 px-1 font-mono">{expected_confirmation}</code> exactly to confirm.
                </p>
                <input
                    value={form.data.confirmation}
                    onChange={(e) => form.setData('confirmation', e.target.value)}
                    placeholder={expected_confirmation}
                    className="block w-full rounded-md border-slate-300 font-mono text-sm"
                />
                {form.errors.confirmation && <p className="text-xs text-red-600">{form.errors.confirmation}</p>}

                <textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="Closing notes (optional)" rows={2} className="block w-full rounded-md border-slate-300 text-sm" />

                <button
                    type="submit"
                    disabled={form.processing || form.data.confirmation !== expected_confirmation || !backupRecent}
                    className="w-full rounded-md bg-red-600 px-3 py-3 text-sm font-semibold text-white hover:bg-red-500 disabled:opacity-50"
                >
                    {form.processing ? 'Closing…' : `Close fiscal year ${year.name}`}
                </button>
            </form>
        </AuthenticatedLayout>
    );
}

function Row({ label, value, bad }) {
    return (
        <li className="flex justify-between py-2">
            <span className="text-slate-600">{label}</span>
            <span className={'font-medium tabular-nums ' + (bad ? 'text-amber-700' : 'text-slate-800')}>{value}</span>
        </li>
    );
}
