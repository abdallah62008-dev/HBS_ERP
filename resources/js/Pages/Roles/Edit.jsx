import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export default function RolesEdit({ role, permissions_grouped, user_count }) {
    const isSuperAdmin = role.slug === 'super-admin';

    // Initial set of granted slugs across all modules.
    const initialGranted = useMemo(() => {
        const s = new Set();
        Object.values(permissions_grouped).flat().forEach((p) => {
            if (p.granted) s.add(p.slug);
        });
        return s;
    }, [permissions_grouped]);

    const [granted, setGranted] = useState(initialGranted);
    const [saving, setSaving] = useState(false);

    const toggle = (slug) => {
        setGranted((prev) => {
            const next = new Set(prev);
            if (next.has(slug)) next.delete(slug); else next.add(slug);
            return next;
        });
    };

    const toggleModule = (perms, on) => {
        setGranted((prev) => {
            const next = new Set(prev);
            perms.forEach((p) => {
                if (on) next.add(p.slug); else next.delete(p.slug);
            });
            return next;
        });
    };

    const submit = (e) => {
        e.preventDefault();
        setSaving(true);
        router.put(route('roles.update', role.id), { permissions: Array.from(granted) }, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        });
    };

    return (
        <AuthenticatedLayout header={`Edit role · ${role.name}`}>
            <Head title={`Edit role · ${role.name}`} />
            <PageHeader
                title={`Role · ${role.name}`}
                subtitle={`${user_count} user${user_count === 1 ? '' : 's'} carry this role · slug ${role.slug}`}
                actions={<Link href={route('roles.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Back to roles</Link>}
            />

            {isSuperAdmin && (
                <div className="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Super Admin permissions are computed dynamically (User::hasPermission short-circuits to true). The check-list below is read-only for this role.
                </div>
            )}

            <form onSubmit={submit} className="space-y-4">
                {Object.entries(permissions_grouped).map(([module, perms]) => {
                    const grantedInModule = perms.filter((p) => granted.has(p.slug)).length;
                    const allOn = grantedInModule === perms.length;
                    return (
                        <section key={module} className="rounded-lg border border-slate-200 bg-white">
                            <header className="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-4 py-2">
                                <h2 className="text-xs font-semibold uppercase tracking-wide text-slate-700">{module}</h2>
                                <div className="flex items-center gap-2 text-xs">
                                    <span className="text-slate-500">{grantedInModule}/{perms.length} granted</span>
                                    {!isSuperAdmin && (
                                        <button
                                            type="button"
                                            onClick={() => toggleModule(perms, !allOn)}
                                            className="rounded-md border border-slate-300 bg-white px-2 py-0.5 text-[11px] text-slate-600 hover:bg-slate-50"
                                        >
                                            {allOn ? 'Clear all' : 'Select all'}
                                        </button>
                                    )}
                                </div>
                            </header>
                            <ul className="divide-y divide-slate-100">
                                {perms.map((p) => (
                                    <li key={p.slug} className="flex items-center justify-between px-4 py-1.5 text-sm">
                                        <label className="flex flex-1 items-center gap-2 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={granted.has(p.slug)}
                                                onChange={() => toggle(p.slug)}
                                                disabled={isSuperAdmin}
                                                className="rounded border-slate-300"
                                            />
                                            <span className="text-slate-700">{p.name}</span>
                                            <span className="ml-2 font-mono text-[10px] text-slate-400">{p.slug}</span>
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    );
                })}

                <div className="flex items-center justify-end gap-2 rounded-md border border-slate-200 bg-white p-3">
                    <span className="mr-auto text-xs text-slate-500">{granted.size} permission{granted.size === 1 ? '' : 's'} selected</span>
                    <Link href={route('roles.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button
                        type="submit"
                        disabled={saving || isSuperAdmin}
                        className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                    >
                        {saving ? 'Saving…' : 'Save permissions'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
