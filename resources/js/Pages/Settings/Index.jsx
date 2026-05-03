import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const FRIENDLY = {
    'country': 'Country',
    'currency_code': 'Currency code',
    'currency_symbol': 'Currency symbol',
    'timezone': 'Timezone',
    'tax_enabled': 'Tax enabled',
    'default_tax_rate': 'Default tax rate (%)',
    'tax_mode': 'Tax mode',
    'order_prefix': 'Order number prefix',
    'fiscal_year_enabled': 'Fiscal year enabled',
    'label_size': 'Label size',
    'shipping_photo_required': 'Pre-shipping photo required',
    'label_required_before_ship': 'Print label before ship',
    'allow_negative_stock': 'Allow negative stock',
    'profit_guard_enabled': 'Profit Guard enabled',
    'profit_guard_action': 'Profit Guard action (block/approve)',
    'minimum_profit_required': 'Minimum profit required (per unit)',
    'marketer_profit_after_delivery_only': 'Marketer earns only after delivery',
    'update_cost_on_purchase': 'Update product cost on purchase approval',
    'delay_threshold_days': 'Shipment delay threshold (days)',
};

export default function SettingsIndex({ grouped, all_typed }) {
    // Local state mirrors all_typed; we let the user edit and submit changes.
    const [pending, setPending] = useState({});

    const setVal = (key, value) => setPending((p) => ({ ...p, [key]: value }));
    const display = (key) => (pending[key] !== undefined ? pending[key] : all_typed[key]);

    const submit = () => {
        const changes = Object.entries(pending).map(([key, value]) => ({ key, value }));
        if (changes.length === 0) {
            alert('No changes.');
            return;
        }
        router.put(route('settings.update'), { changes }, {
            onSuccess: () => setPending({}),
        });
    };

    return (
        <AuthenticatedLayout header="Settings">
            <Head title="Settings" />
            <PageHeader
                title="Settings"
                subtitle="System-wide configuration. Changes apply immediately."
                actions={
                    <button onClick={submit} disabled={Object.keys(pending).length === 0} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50">
                        Save {Object.keys(pending).length > 0 ? `(${Object.keys(pending).length})` : ''}
                    </button>
                }
            />

            <div className="space-y-4">
                {Object.entries(grouped).map(([group, items]) => (
                    <div key={group} className="rounded-lg border border-slate-200 bg-white">
                        <div className="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700 uppercase tracking-wide">
                            {group}
                        </div>
                        <div className="divide-y divide-slate-100">
                            {items.map((s) => (
                                <Row key={s.id} setting={s} value={display(s.setting_key)} setValue={(v) => setVal(s.setting_key, v)} dirty={pending[s.setting_key] !== undefined} />
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

function Row({ setting, value, setValue, dirty }) {
    const label = FRIENDLY[setting.setting_key] ?? setting.setting_key;

    let input;
    if (setting.value_type === 'boolean') {
        input = (
            <select value={value ? '1' : '0'} onChange={(e) => setValue(e.target.value === '1')} className="rounded-md border-slate-300 text-sm">
                <option value="1">Yes</option>
                <option value="0">No</option>
            </select>
        );
    } else if (setting.value_type === 'number') {
        input = <input type="number" value={value ?? 0} onChange={(e) => setValue(e.target.value)} className="w-32 rounded-md border-slate-300 text-sm" />;
    } else if (setting.value_type === 'json') {
        input = <textarea value={typeof value === 'string' ? value : JSON.stringify(value ?? {}, null, 2)} onChange={(e) => setValue(e.target.value)} rows={3} className="w-full rounded-md border-slate-300 text-sm font-mono text-xs" />;
    } else {
        input = <input value={value ?? ''} onChange={(e) => setValue(e.target.value)} className="w-64 rounded-md border-slate-300 text-sm" />;
    }

    return (
        <div className={'flex items-start justify-between gap-3 px-5 py-3 ' + (dirty ? 'bg-amber-50' : '')}>
            <div>
                <div className="text-sm text-slate-800">{label}</div>
                <div className="font-mono text-xs text-slate-500">{setting.setting_key} · {setting.value_type}</div>
            </div>
            <div>{input}</div>
        </div>
    );
}
