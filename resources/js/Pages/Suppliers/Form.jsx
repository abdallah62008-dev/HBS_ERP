import FormField from '@/Components/FormField';

export default function SupplierForm({ data, setData, errors }) {
    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField label="Name" name="name" value={data.name} onChange={(v) => setData('name', v)} error={errors.name} required />
            <FormField label="Phone" name="phone" value={data.phone} onChange={(v) => setData('phone', v)} error={errors.phone} />
            <FormField label="Email" type="email" name="email" value={data.email} onChange={(v) => setData('email', v)} error={errors.email} />
            <FormField label="City" name="city" value={data.city} onChange={(v) => setData('city', v)} error={errors.city} />
            <FormField label="Country" name="country" value={data.country} onChange={(v) => setData('country', v)} error={errors.country} />
            <FormField label="Status" name="status" error={errors.status}>
                <select id="status" value={data.status ?? 'Active'} onChange={(e) => setData('status', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm">
                    <option>Active</option>
                    <option>Inactive</option>
                </select>
            </FormField>
            <FormField label="Address" name="address" error={errors.address} className="sm:col-span-2">
                <textarea id="address" rows={2} value={data.address ?? ''} onChange={(e) => setData('address', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm" />
            </FormField>
            <FormField label="Notes" name="notes" error={errors.notes} className="sm:col-span-2">
                <textarea id="notes" rows={2} value={data.notes ?? ''} onChange={(e) => setData('notes', e.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm" />
            </FormField>
        </div>
    );
}
