import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const TYPE_TONES = {
    'Low Stock': 'bg-amber-100 text-amber-800',
    'Delayed Shipment': 'bg-amber-100 text-amber-800',
    'High Risk Customer': 'bg-red-100 text-red-700',
    'Unprofitable Campaign': 'bg-red-100 text-red-700',
    'Pending Collection': 'bg-blue-100 text-blue-700',
    'Approval Needed': 'bg-indigo-100 text-indigo-700',
    'Backup Failed': 'bg-red-100 text-red-700',
};

export default function NotificationsIndex({ notifications, filters, types }) {
    const [type, setType] = useState(filters?.type ?? '');
    const [unreadOnly, setUnreadOnly] = useState(!!filters?.unread_only);

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('notifications.index'), {
            type: type || undefined,
            unread_only: unreadOnly ? '1' : undefined,
        }, { preserveState: true, replace: true });
    };

    const refresh = () => router.post(route('notifications.refresh'));
    const markAll = () => router.post(route('notifications.mark-all-read'));

    return (
        <AuthenticatedLayout header="Notifications">
            <Head title="Notifications" />
            <PageHeader
                title="Notifications"
                subtitle={`${notifications.total} total · ${filters?.unread_only ? 'unread only' : 'all'}`}
                actions={
                    <div className="flex gap-2">
                        <button onClick={refresh} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">Refresh alerts</button>
                        <button onClick={markAll} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">Mark all read</button>
                    </div>
                }
            />

            <form onSubmit={apply} className="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <select value={type} onChange={(e) => setType(e.target.value)} className="rounded-md border-slate-300 text-sm">
                    <option value="">Any type</option>
                    {types.map((t) => <option key={t} value={t}>{t}</option>)}
                </select>
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={unreadOnly} onChange={(e) => setUnreadOnly(e.target.checked)} className="rounded border-slate-300" />
                    Unread only
                </label>
                <button type="submit" className="rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <ul className="divide-y divide-slate-100">
                    {notifications.data.length === 0 && (
                        <li className="px-4 py-12 text-center text-sm text-slate-400">No notifications.</li>
                    )}
                    {notifications.data.map((n) => (
                        <li key={n.id} className={'px-4 py-3 ' + (n.read_at === null ? 'bg-slate-50' : '')}>
                            <div className="flex items-start justify-between gap-3">
                                <div className="flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className={'rounded-full px-2 py-0.5 text-[11px] font-medium ' + (TYPE_TONES[n.type] ?? 'bg-slate-100 text-slate-700')}>{n.type}</span>
                                        {n.read_at === null && <span className="rounded-full bg-indigo-500 px-1.5 py-0.5 text-[10px] font-medium text-white">NEW</span>}
                                        <span className="text-xs text-slate-400">{n.created_at?.replace('T', ' ').slice(0, 16)}</span>
                                    </div>
                                    <div className="mt-1 text-sm font-medium text-slate-800">{n.title}</div>
                                    <p className="text-sm text-slate-600">{n.message}</p>
                                    {n.action_url && (
                                        <Link href={n.action_url} className="mt-1 inline-block text-xs font-medium text-indigo-600 hover:underline">Open →</Link>
                                    )}
                                </div>
                                {n.read_at === null && (
                                    <Link href={route('notifications.mark-read', n.id)} method="post" as="button" className="text-xs text-slate-500 hover:underline">
                                        mark read
                                    </Link>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            </div>

            <Pagination links={notifications.links} />
        </AuthenticatedLayout>
    );
}
