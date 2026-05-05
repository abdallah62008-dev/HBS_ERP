import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormField from '@/Components/FormField';
import { Head, Link, useForm } from '@inertiajs/react';

export default function MarketerEdit({ marketer, price_groups, marketer_tiers = [] }) {
    const { data, setData, put, processing, errors } = useForm({
        code: marketer.code,
        price_group_id: marketer.price_group_id,
        marketer_price_tier_id: marketer.marketer_price_tier_id ?? '',
        phone: marketer.phone ?? '',
        status: marketer.status,
        shipping_deducted: !!marketer.shipping_deducted,
        tax_deducted: !!marketer.tax_deducted,
        commission_after_delivery_only: !!marketer.commission_after_delivery_only,
        settlement_cycle: marketer.settlement_cycle,
        notes: marketer.notes ?? '',
    });

    const submit = (e) => { e.preventDefault(); put(route('marketers.update', marketer.id)); };

    return (
        <AuthenticatedLayout header={`Edit ${marketer.code}`}>
            <Head title={`Edit ${marketer.code}`} />
            <PageHeader title={`Edit marketer · ${marketer.code}`} subtitle={marketer.user?.name} />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5 space-y-4">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <FormField label="Code" name="code" value={data.code} onChange={(v) => setData('code', v)} error={errors.code} required />
                    <FormField label="Phone" name="phone" value={data.phone} onChange={(v) => setData('phone', v)} error={errors.phone} />
                    <FormField label="Price group" name="price_group_id" error={errors.price_group_id}>
                        <select id="price_group_id" value={data.price_group_id} onChange={(e) => setData('price_group_id', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            {price_groups.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Pricing tier" name="marketer_price_tier_id" error={errors.marketer_price_tier_id} hint="Affects future orders only — past wallet entries unchanged.">
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
                    <FormField label="Status" name="status" error={errors.status}>
                        <select id="status" value={data.status} onChange={(e) => setData('status', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            <option>Active</option><option>Inactive</option><option>Suspended</option>
                        </select>
                    </FormField>
                    <FormField label="Settlement cycle" name="settlement_cycle" error={errors.settlement_cycle}>
                        <select id="settlement_cycle" value={data.settlement_cycle} onChange={(e) => setData('settlement_cycle', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            <option>Daily</option><option>Weekly</option><option>Monthly</option>
                        </select>
                    </FormField>
                </div>

                <div className="grid grid-cols-1 gap-2 sm:grid-cols-3 rounded-md bg-slate-50 p-3">
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

                <FormField label="Notes" name="notes" error={errors.notes}>
                    <textarea id="notes" rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                </FormField>

                <div className="flex justify-end gap-2">
                    <Link href={route('marketers.show', marketer.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">{processing ? 'Saving…' : 'Save changes'}</button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
