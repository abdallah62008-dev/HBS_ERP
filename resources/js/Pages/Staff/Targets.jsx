import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, router, useForm } from '@inertiajs/react';

function progressBar(pct) {
    const tone = pct >= 100 ? 'bg-emerald-500' : pct >= 70 ? 'bg-indigo-500' : pct >= 30 ? 'bg-amber-500' : 'bg-red-500';
    return (
        <div className="mt-1 h-1.5 w-full rounded-full bg-slate-200">
            <div className={`h-1.5 rounded-full ${tone}`} style={{ width: `${Math.min(100, pct)}%` }}></div>
        </div>
    );
}

export default function StaffTargets({ targets, staff, types, filters }) {
    const can = useCan();

    const create = useForm({
        user_id: '', target_type: 'Confirmed Orders', target_period: 'Monthly',
        target_value: 100, start_date: new Date().toISOString().slice(0, 10),
        end_date: new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10),
    });

    const submit = (e) => { e.preventDefault(); create.post(route('staff-targets.store'), { onSuccess: () => create.reset('user_id') }); };
    const remove = (t) => { if (!confirm('Delete this target?')) return; router.delete(route('staff-targets.destroy', t.id)); };

    return (
        <AuthenticatedLayout header="Staff targets">
            <Head title="Staff targets" />
            <PageHeader title="Staff targets" subtitle="Targets refresh on view." />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                {can('users.manage') && (
                    <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5 space-y-2">
                        <h2 className="text-sm font-semibold text-slate-700">New target</h2>
                        <select value={create.data.user_id} onChange={(e) => create.setData('user_id', e.target.value)} className="block w-full rounded-md border-slate-300 text-sm">
                            <option value="">— Pick staff —</option>
                            {staff.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                        </select>
                        <select value={create.data.target_type} onChange={(e) => create.setData('target_type', e.target.value)} className="block w-full rounded-md border-slate-300 text-sm">
                            {types.map((t) => <option key={t} value={t}>{t}</option>)}
                        </select>
                        <select value={create.data.target_period} onChange={(e) => create.setData('target_period', e.target.value)} className="block w-full rounded-md border-slate-300 text-sm">
                            <option>Daily</option><option>Weekly</option><option>Monthly</option><option>Quarterly</option>
                        </select>
                        <input type="number" min={0} value={create.data.target_value} onChange={(e) => create.setData('target_value', e.target.value)} placeholder="Target value" className="block w-full rounded-md border-slate-300 text-sm" />
                        <div className="grid grid-cols-2 gap-2">
                            <input type="date" value={create.data.start_date} onChange={(e) => create.setData('start_date', e.target.value)} className="block w-full rounded-md border-slate-300 text-sm" />
                            <input type="date" value={create.data.end_date} onChange={(e) => create.setData('end_date', e.target.value)} className="block w-full rounded-md border-slate-300 text-sm" />
                        </div>
                        <button type="submit" disabled={create.processing} className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">Add target</button>
                    </form>
                )}

                <div className="lg:col-span-2 space-y-2">
                    {targets.length === 0 && (
                        <div className="rounded-lg border border-slate-200 bg-white p-10 text-center text-sm text-slate-400">No targets yet.</div>
                    )}
                    {targets.map((t) => {
                        const pct = (t.target_value > 0)
                            ? Math.min(100, Math.round((Number(t.achieved_value) / Number(t.target_value)) * 100))
                            : 0;
                        return (
                            <div key={t.id} className="rounded-lg border border-slate-200 bg-white p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <div className="text-sm font-semibold text-slate-800">{t.user?.name}</div>
                                        <div className="text-xs text-slate-500">{t.target_type} · {t.target_period} · {t.start_date} → {t.end_date}</div>
                                    </div>
                                    <div className="text-right">
                                        <StatusBadge value={t.status} />
                                    </div>
                                </div>
                                <div className="mt-2 flex items-center justify-between text-sm">
                                    <span className="text-slate-700">{Number(t.achieved_value).toLocaleString()} / {Number(t.target_value).toLocaleString()}</span>
                                    <span className="text-xs text-slate-500">{pct}%</span>
                                </div>
                                {progressBar(pct)}
                                {can('users.manage') && (
                                    <div className="mt-2 flex justify-end">
                                        <button onClick={() => remove(t)} className="text-xs text-red-600 hover:underline">delete</button>
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
