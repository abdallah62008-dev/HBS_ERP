import FormField from '@/Components/FormField';

export default function CampaignForm({ data, setData, errors, products, platforms }) {
    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField label="Name" name="name" value={data.name} onChange={(v) => setData('name', v)} error={errors.name} required hint="Orders with source = this name are attributed to this campaign" />

            <FormField label="Platform" name="platform" error={errors.platform} required>
                <select id="platform" value={data.platform ?? ''} onChange={(e) => setData('platform', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                    <option value="">— Pick platform —</option>
                    {platforms.map((p) => <option key={p} value={p}>{p}</option>)}
                </select>
            </FormField>

            <FormField label="Product (optional)" name="product_id" error={errors.product_id}>
                <select id="product_id" value={data.product_id ?? ''} onChange={(e) => setData('product_id', e.target.value || null)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                    <option value="">— Any product —</option>
                    {products.map((p) => <option key={p.id} value={p.id}>{p.sku} — {p.name}</option>)}
                </select>
            </FormField>

            <FormField label="Status" name="status" error={errors.status}>
                <select id="status" value={data.status ?? 'Active'} onChange={(e) => setData('status', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                    <option>Active</option><option>Paused</option><option>Ended</option>
                </select>
            </FormField>

            <FormField label="Start date" name="start_date" type="date" value={data.start_date} onChange={(v) => setData('start_date', v)} error={errors.start_date} required />
            <FormField label="End date" name="end_date" type="date" value={data.end_date} onChange={(v) => setData('end_date', v)} error={errors.end_date} />

            <FormField label="Budget" name="budget" type="number" value={data.budget} onChange={(v) => setData('budget', v)} error={errors.budget} />
            <FormField label="Spend (manual override)" name="spend" type="number" value={data.spend} onChange={(v) => setData('spend', v)} error={errors.spend} hint="Leave 0 to derive spend from related expenses" />
        </div>
    );
}
