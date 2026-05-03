import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function ApprovalShow({ request: req, related }) {
    const can = useCan();
    const { props } = usePage();
    const currentUserId = props.auth?.user?.id;

    const [rejectOpen, setRejectOpen] = useState(false);
    const approve = useForm({ notes: '' });
    const reject = useForm({ notes: '' });

    const submitApprove = () => {
        if (!confirm('Approve this request? The change will be applied immediately.')) return;
        approve.post(route('approvals.approve', req.id));
    };

    const submitReject = (e) => {
        e.preventDefault();
        reject.post(route('approvals.reject', req.id), { onSuccess: () => setRejectOpen(false) });
    };

    const isPending = req.status === 'Pending';
    const sameUser = req.requested_by === currentUserId;

    return (
        <AuthenticatedLayout header={`Approval #${req.id}`}>
            <Head title={`Approval ${req.id}`} />
            <PageHeader
                title={req.approval_type}
                subtitle={`Requested ${req.created_at?.replace('T', ' ').slice(0, 16)} by ${req.requested_by?.name}`}
                actions={<Link href={route('approvals.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← All approvals</Link>}
            />

            <div className="mb-4 flex items-center gap-2">
                <StatusBadge value={req.status} />
                {req.related_type && (
                    <span className="text-xs text-slate-500">{req.related_type.split('\\').pop()} #{req.related_id}</span>
                )}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="lg:col-span-2 space-y-4">
                    <div className="rounded-lg border border-slate-200 bg-white p-5">
                        <h2 className="mb-2 text-sm font-semibold text-slate-700">Reason</h2>
                        <p className="whitespace-pre-line text-sm text-slate-700">{req.reason ?? <span className="text-slate-400">—</span>}</p>
                    </div>

                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <Pane title="Old values" data={req.old_values_json} />
                        <Pane title="New values" data={req.new_values_json} />
                    </div>

                    {req.review_notes && (
                        <div className="rounded-lg border border-slate-200 bg-white p-5">
                            <h2 className="mb-2 text-sm font-semibold text-slate-700">Review notes</h2>
                            <p className="text-sm text-slate-700">{req.review_notes}</p>
                            {req.reviewed_by && (
                                <p className="mt-1 text-xs text-slate-500">— {req.reviewed_by.name}, {req.reviewed_at?.replace('T', ' ').slice(0, 16)}</p>
                            )}
                        </div>
                    )}
                </div>

                <div className="space-y-4">
                    {isPending && can('approvals.manage') && !sameUser && (
                        <div className="rounded-lg border border-slate-200 bg-white p-5 space-y-3">
                            <h2 className="text-sm font-semibold text-slate-700">Decide</h2>
                            <textarea value={approve.data.notes} onChange={(e) => approve.setData('notes', e.target.value)} placeholder="Optional notes (visible in audit log)" rows={3} className="block w-full rounded-md border-slate-300 text-sm" />
                            <button onClick={submitApprove} disabled={approve.processing} className="w-full rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-500 disabled:opacity-60">
                                Approve & apply
                            </button>
                            <button type="button" onClick={() => setRejectOpen(true)} className="w-full rounded-md border border-red-200 bg-white px-3 py-2 text-sm text-red-600 hover:bg-red-50">
                                Reject
                            </button>
                        </div>
                    )}

                    {sameUser && isPending && (
                        <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                            You created this request. Another team member with approvals.manage must review it.
                        </div>
                    )}

                    {related && (
                        <div className="rounded-lg border border-slate-200 bg-white p-5">
                            <h2 className="mb-2 text-sm font-semibold text-slate-700">Target</h2>
                            <pre className="overflow-x-auto rounded-md bg-slate-900 p-3 text-[10px] text-slate-100">{JSON.stringify(related, null, 2)}</pre>
                        </div>
                    )}
                </div>
            </div>

            {rejectOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <form onSubmit={submitReject} className="w-full max-w-md rounded-lg bg-white p-5 shadow-xl space-y-3">
                        <h3 className="text-sm font-semibold text-slate-800">Reject request</h3>
                        <textarea value={reject.data.notes} onChange={(e) => reject.setData('notes', e.target.value)} placeholder="Reason (required)" rows={3} className="block w-full rounded-md border-slate-300 text-sm" />
                        {reject.errors.notes && <p className="text-xs text-red-600">{reject.errors.notes}</p>}
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setRejectOpen(false)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</button>
                            <button type="submit" disabled={reject.processing} className="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-500 disabled:opacity-60">Reject</button>
                        </div>
                    </form>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

function Pane({ title, data }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-5">
            <h2 className="mb-2 text-sm font-semibold text-slate-700">{title}</h2>
            {data && Object.keys(data).length > 0 ? (
                <pre className="overflow-x-auto rounded-md bg-slate-900 p-3 text-[10px] text-slate-100">{JSON.stringify(data, null, 2)}</pre>
            ) : (
                <p className="text-sm text-slate-400">—</p>
            )}
        </div>
    );
}
