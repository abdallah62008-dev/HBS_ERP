import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormField from '@/Components/FormField';
import { Head, Link, useForm } from '@inertiajs/react';

export default function UsersCreate({ roles }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        phone: '',
        entry_code: '',
        role_id: roles[0]?.id ?? '',
        status: 'Active',
        password: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('users.store'));
    };

    return (
        <AuthenticatedLayout header="New user">
            <Head title="New user" />
            <PageHeader title="New user" subtitle="Create an account and assign a role" />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5 space-y-4">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <FormField label="Name" name="name" value={data.name} onChange={(v) => setData('name', v)} error={errors.name} required />
                    <FormField label="Email" type="email" name="email" value={data.email} onChange={(v) => setData('email', v)} error={errors.email} required />
                    <FormField label="Phone" name="phone" value={data.phone} onChange={(v) => setData('phone', v)} error={errors.phone} />
                    <FormField label="Entry code" name="entry_code" value={data.entry_code} onChange={(v) => setData('entry_code', v)} error={errors.entry_code} hint="Optional · short identifier shown on orders this user creates" />
                    <FormField label="Role" name="role_id" error={errors.role_id} required>
                        <select id="role_id" value={data.role_id} onChange={(e) => setData('role_id', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            {roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Status" name="status" error={errors.status}>
                        <select id="status" value={data.status} onChange={(e) => setData('status', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            <option>Active</option>
                            <option>Inactive</option>
                            <option>Suspended</option>
                        </select>
                    </FormField>
                    <FormField label="Initial password" type="password" name="password" value={data.password} onChange={(v) => setData('password', v)} error={errors.password} required hint="Minimum 8 characters · user can change later" className="sm:col-span-2" />
                </div>

                <div className="flex justify-end gap-2">
                    <Link href={route('users.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {processing ? 'Saving…' : 'Create user'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
