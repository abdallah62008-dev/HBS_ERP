import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import useCan from '@/Hooks/useCan';
import { Head, Link, router } from '@inertiajs/react';

function chip(status) {
    const tone = {
        open: 'bg-emerald-50 text-emerald-700',
        closed: 'bg-slate-200 text-slate-700',
    }[status] ?? 'bg-slate-100 text-slate-600';
    return <span className={'rounded-full px-2 py-0.5 text-xs font-medium ' + tone}>{status}</span>;
}

export default function FinancePeriodsIndex({ periods, filters, statuses, totals }) {
    const can = useCan();

    const onClose = (p) => {
        if (!confirm(`Close period "${p.name}" (${p.start_date} → ${p.end_date})?\n\nClosed periods block financial movements dated inside the range. Reports remain readable.`)) return;
        router.post(route('finance-periods.close', p.id), {}, { preserveScroll: true });
    };

    const onReopen = (p) => {
        if (!confirm(`Reopen period "${p.name}" (${p.start_date} → ${p.end_date})?\n\nFinancial writes for dates in this range will be allowed again.`)) return;
        router.post(route('finance-periods.reopen', p.id), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header="Finance Periods">
            <Head title="Finance Periods" />
            <PageHeader
                title="Finance Periods"
                subtitle={`${totals.open_count} open · ${totals.closed_count} closed`}
                actions={
                    can('finance_periods.create') && (
                        <Link href={route('finance-periods.create')} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">
                            + New period
                        </Link>
                    )
                }
            />

            <div className="mb-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                <p>
                    <strong>Closed periods block financial postings</strong> whose date falls inside the range:
                    cashbox adjustments, transfers, collection postings, expense postings, refund payments, and marketer payouts.
                </p>
                <p className="mt-1">Reports and statements stay fully readable for any date.</p>
            </div>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">Name</th>
                            <th className="px-4 py-2.5">Range</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5">Closed</th>
                            <th className="px-4 py-2.5">Reopened</th>
                            <th className="px-4 py-2.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {periods.data.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-12 text-center text-sm text-slate-400">No finance periods yet.</td></tr>
                        )}
                        {periods.data.map((p) => (
                            <tr key={p.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-medium text-slate-800">{p.name}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">{p.start_date} → {p.end_date}</td>
                                <td className="px-4 py-2.5">{chip(p.status)}</td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">
                                    {p.closed_at ? (
                                        <>
                                            <div>{p.closed_at?.replace('T', ' ').slice(0, 16)}</div>
                                            <div className="text-slate-400">{p.closed_by?.name}</div>
                                        </>
                                    ) : '—'}
                                </td>
                                <td className="px-4 py-2.5 text-xs text-slate-500">
                                    {p.reopened_at ? (
                                        <>
                                            <div>{p.reopened_at?.replace('T', ' ').slice(0, 16)}</div>
                                            <div className="text-slate-400">{p.reopened_by?.name}</div>
                                        </>
                                    ) : '—'}
                                </td>
                                <td className="px-4 py-2.5 text-right">
                                    <div className="flex justify-end gap-1.5">
                                        {p.status === 'open' && can('finance_periods.update') && (
                                            <Link href={route('finance-periods.edit', p.id)} className="rounded border border-slate-200 bg-white px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-50">Edit</Link>
                                        )}
                                        {p.status === 'open' && can('finance_periods.close') && (
                                            <button onClick={() => onClose(p)} className="rounded bg-slate-900 px-2 py-1 text-xs font-medium text-white hover:bg-slate-700">Close</button>
                                        )}
                                        {p.status === 'closed' && can('finance_periods.reopen') && (
                                            <button onClick={() => onReopen(p)} className="rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100">Reopen</button>
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={periods.links} />
        </AuthenticatedLayout>
    );
}
