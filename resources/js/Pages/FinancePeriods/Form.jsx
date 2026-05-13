import { useForm } from '@inertiajs/react';

/**
 * Shared form body for FinancePeriods Create / Edit. The wrapper page
 * passes the route + initial values; this component owns the field
 * state.
 */
export default function FinancePeriodForm({ initial, action, method = 'post', submitLabel = 'Save' }) {
    const form = useForm({
        name: initial?.name ?? '',
        start_date: initial?.start_date ?? '',
        end_date: initial?.end_date ?? '',
        notes: initial?.notes ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        if (method === 'put') form.put(action);
        else form.post(action);
    };

    return (
        <form onSubmit={submit} className="max-w-xl space-y-4 rounded-lg border border-slate-200 bg-white p-5">
            <label className="block text-sm font-medium text-slate-700">
                Name
                <input
                    type="text"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    placeholder="e.g. May 2026"
                    className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                />
                {form.errors.name && <p className="mt-1 text-xs text-rose-600">{form.errors.name}</p>}
            </label>

            <div className="grid grid-cols-2 gap-3">
                <label className="block text-sm font-medium text-slate-700">
                    Start date
                    <input
                        type="date"
                        value={form.data.start_date}
                        onChange={(e) => form.setData('start_date', e.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                    />
                    {form.errors.start_date && <p className="mt-1 text-xs text-rose-600">{form.errors.start_date}</p>}
                </label>
                <label className="block text-sm font-medium text-slate-700">
                    End date
                    <input
                        type="date"
                        value={form.data.end_date}
                        onChange={(e) => form.setData('end_date', e.target.value)}
                        className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                    />
                    {form.errors.end_date && <p className="mt-1 text-xs text-rose-600">{form.errors.end_date}</p>}
                </label>
            </div>

            <label className="block text-sm font-medium text-slate-700">
                Notes <span className="font-normal text-slate-400">(optional)</span>
                <textarea
                    rows={3}
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                    className="mt-1 block w-full rounded-md border-slate-300 text-sm"
                />
            </label>

            <div className="flex justify-end">
                <button type="submit" disabled={form.processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                    {form.processing ? 'Saving…' : submitLabel}
                </button>
            </div>
        </form>
    );
}
