import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormField from '@/Components/FormField';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

/**
 * User edit page combines two forms:
 *   1. Profile + role + password (PUT /users/{id})
 *   2. Per-user permission overrides (PUT /users/{id}/permissions)
 *
 * Permission override semantics (mirrors User::hasPermission):
 *   - "inherited_from_role" : permission comes from the role grant
 *   - "allow_override"      : explicit allow on user_permissions pivot
 *                             (useful when you want to grant a slug to
 *                              one user without loosening their role)
 *   - "deny_override"       : explicit deny on user_permissions pivot
 *                             (overrides the role grant)
 *   - "none"                : not granted
 */
export default function UsersEdit({ user, roles, permissions_grouped }) {
    const profile = useForm({
        name: user.name,
        email: user.email,
        phone: user.phone ?? '',
        entry_code: user.entry_code ?? '',
        role_id: user.role_id,
        status: user.status,
        password: '',
    });

    // Build the override state from server-provided grouped data.
    // Map<slug, 'allow' | 'deny'>; absent slugs inherit from role.
    const initialOverrides = useMemo(() => {
        const m = {};
        Object.values(permissions_grouped).flat().forEach((p) => {
            if (p.state === 'allow_override') m[p.slug] = 'allow';
            if (p.state === 'deny_override') m[p.slug] = 'deny';
        });
        return m;
    }, [permissions_grouped]);

    const [overrides, setOverrides] = useState(initialOverrides);
    const [savingPerms, setSavingPerms] = useState(false);

    const setOverride = (slug, value) => {
        setOverrides((prev) => {
            const next = { ...prev };
            if (value === null) delete next[slug];
            else next[slug] = value;
            return next;
        });
    };

    const submitProfile = (e) => {
        e.preventDefault();
        profile.put(route('users.update', user.id), { preserveScroll: true });
    };

    const submitOverrides = (e) => {
        e.preventDefault();
        setSavingPerms(true);
        router.put(route('users.permissions.sync', user.id), { overrides }, {
            preserveScroll: true,
            onFinish: () => setSavingPerms(false),
        });
    };

    return (
        <AuthenticatedLayout header={`Edit ${user.email}`}>
            <Head title={`Edit ${user.email}`} />
            <PageHeader title={`Edit user · ${user.name}`} subtitle={user.email} />

            <form onSubmit={submitProfile} className="mb-5 rounded-lg border border-slate-200 bg-white p-5 space-y-4">
                <h2 className="text-sm font-semibold text-slate-700">Profile &amp; role</h2>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <FormField label="Name" name="name" value={profile.data.name} onChange={(v) => profile.setData('name', v)} error={profile.errors.name} required />
                    <FormField label="Email" type="email" name="email" value={profile.data.email} onChange={(v) => profile.setData('email', v)} error={profile.errors.email} required />
                    <FormField label="Phone" name="phone" value={profile.data.phone} onChange={(v) => profile.setData('phone', v)} error={profile.errors.phone} />
                    <FormField label="Entry code" name="entry_code" value={profile.data.entry_code} onChange={(v) => profile.setData('entry_code', v)} error={profile.errors.entry_code} hint="Used as the per-staff order suffix (display_order_number)" />
                    <FormField label="Role" name="role_id" error={profile.errors.role_id} required>
                        <select id="role_id" value={profile.data.role_id} onChange={(e) => profile.setData('role_id', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            {roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Status" name="status" error={profile.errors.status}>
                        <select id="status" value={profile.data.status} onChange={(e) => profile.setData('status', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            <option>Active</option>
                            <option>Inactive</option>
                            <option>Suspended</option>
                        </select>
                    </FormField>
                    <FormField label="Reset password" type="password" name="password" value={profile.data.password} onChange={(v) => profile.setData('password', v)} error={profile.errors.password} hint="Leave blank to keep the existing password" className="sm:col-span-2" />
                </div>

                <div className="flex justify-end gap-2">
                    <Link href={route('users.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Back</Link>
                    <button type="submit" disabled={profile.processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {profile.processing ? 'Saving…' : 'Save profile'}
                    </button>
                </div>
            </form>

            <form onSubmit={submitOverrides} className="rounded-lg border border-slate-200 bg-white p-5 space-y-4">
                <div className="flex items-center justify-between">
                    <h2 className="text-sm font-semibold text-slate-700">Permission overrides</h2>
                    <span className="text-[11px] text-slate-500">Three states per slug · inherited from role · explicitly allowed · explicitly denied</span>
                </div>

                <div className="space-y-4">
                    {Object.entries(permissions_grouped).map(([module, perms]) => (
                        <div key={module} className="rounded-md border border-slate-200">
                            <div className="border-b border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-slate-600">{module}</div>
                            <table className="min-w-full text-xs">
                                <thead className="text-[10px] uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th scope="col" className="px-3 py-1.5 text-left">Permission</th>
                                        <th scope="col" className="px-3 py-1.5 text-left">Slug</th>
                                        <th scope="col" className="px-3 py-1.5 text-center">Inherit (role)</th>
                                        <th scope="col" className="px-3 py-1.5 text-center">Allow</th>
                                        <th scope="col" className="px-3 py-1.5 text-center">Deny</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {perms.map((p) => {
                                        const ov = overrides[p.slug];
                                        const inherit = ov === undefined;
                                        return (
                                            <tr key={p.slug}>
                                                <td className="px-3 py-1.5 text-slate-700">{p.name}</td>
                                                <td className="px-3 py-1.5 font-mono text-[10px] text-slate-400">{p.slug}</td>
                                                <td className="px-3 py-1.5 text-center">
                                                    <input type="radio" name={`ov-${p.slug}`} checked={inherit} onChange={() => setOverride(p.slug, null)} aria-label={`${p.slug} inherit`} />
                                                    <span className="ml-1 text-[10px] text-slate-400">{p.in_role ? '(granted)' : '(not in role)'}</span>
                                                </td>
                                                <td className="px-3 py-1.5 text-center">
                                                    <input type="radio" name={`ov-${p.slug}`} checked={ov === 'allow'} onChange={() => setOverride(p.slug, 'allow')} aria-label={`${p.slug} allow`} />
                                                </td>
                                                <td className="px-3 py-1.5 text-center">
                                                    <input type="radio" name={`ov-${p.slug}`} checked={ov === 'deny'} onChange={() => setOverride(p.slug, 'deny')} aria-label={`${p.slug} deny`} />
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    ))}
                </div>

                <div className="flex justify-end gap-2">
                    <button type="submit" disabled={savingPerms} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {savingPerms ? 'Saving…' : 'Save overrides'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
