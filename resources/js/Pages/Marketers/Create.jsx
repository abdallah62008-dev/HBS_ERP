import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormField from '@/Components/FormField';
import { Head, Link, useForm } from '@inertiajs/react';

export default function MarketerCreate({ price_groups, marketer_tiers = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        user: { name: '', email: '', password: '' },
        code: '',
        price_group_id: price_groups[0]?.id ?? '',
        marketer_price_tier_id: marketer_tiers[0]?.id ?? '',
        phone: '',
        status: 'Active',
        shipping_deducted: true,
        tax_deducted: true,
        commission_after_delivery_only: true,
        settlement_cycle: 'Weekly',
        notes: '',
    });

    const submit = (e) => { e.preventDefault(); post(route('marketers.store')); };

    return (
        <AuthenticatedLayout header="New marketer">
            <Head title="New marketer" />
            <PageHeader title="New marketer" subtitle="Creates a Marketer-role user account and links it to a price group." />

            <form onSubmit={submit} className="space-y-5">
                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">User account</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <FormField label="Name" name="user.name" value={data.user.name} onChange={(v) => setData('user', { ...data.user, name: v })} error={errors['user.name']} required />
                        <FormField label="Email" name="user.email" type="email" value={data.user.email} onChange={(v) => setData('user', { ...data.user, email: v })} error={errors['user.email']} required />
                        <FormField label="Password" name="user.password" type="password" value={data.user.password} onChange={(v) => setData('user', { ...data.user, password: v })} error={errors['user.password']} required hint="Min 8 chars. The marketer will use this to log into their portal." />
                    </div>
                </section>

                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Marketer profile</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <FormField label="Code" name="code" value={data.code} onChange={(v) => setData('code', v)} error={errors.code} required hint="Short identifier shown on orders and reports" />
                        <FormField label="Phone" name="phone" value={data.phone} onChange={(v) => setData('phone', v)} error={errors.phone} />
                        <FormField label="Price group" name="price_group_id" error={errors.price_group_id} required>
                            <select id="price_group_id" value={data.price_group_id} onChange={(e) => setData('price_group_id', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                                {price_groups.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Pricing tier" name="marketer_price_tier_id" error={errors.marketer_price_tier_id} hint="Drives the product tier prices for this marketer.">
                            {marketer_tiers.length === 0 ? (
                                <p className="mt-1 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    No marketer pricing tiers found. Please run seeders.
                                </p>
                            ) : (
                                <select id="marketer_price_tier_id" value={data.marketer_price_tier_id ?? ''} onChange={(e) => setData('marketer_price_tier_id', e.target.value || null)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                                    <option value="">— None —</option>
                                    {marketer_tiers.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                                </select>
                            )}
                        </FormField>
                        <FormField label="Settlement cycle" name="settlement_cycle" error={errors.settlement_cycle}>
                            <select id="settlement_cycle" value={data.settlement_cycle} onChange={(e) => setData('settlement_cycle', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                                <option>Daily</option><option>Weekly</option><option>Monthly</option>
                            </select>
                        </FormField>
                    </div>

                    <div className="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-3">
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={data.shipping_deducted} onChange={(e) => setData('shipping_deducted', e.target.checked)} className="rounded border-slate-300" />
                            Deduct shipping from profit
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={data.tax_deducted} onChange={(e) => setData('tax_deducted', e.target.checked)} className="rounded border-slate-300" />
                            Deduct tax from profit
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={data.commission_after_delivery_only} onChange={(e) => setData('commission_after_delivery_only', e.target.checked)} className="rounded border-slate-300" />
                            Earn only after delivery
                        </label>
                    </div>

                    <FormField label="Notes" name="notes" error={errors.notes} className="mt-3">
                        <textarea id="notes" rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                    </FormField>
                </section>

                <div className="flex justify-end gap-2">
                    <Link href={route('marketers.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {processing ? 'Saving…' : 'Create marketer'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
