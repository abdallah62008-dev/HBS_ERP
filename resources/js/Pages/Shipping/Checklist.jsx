import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useRef } from 'react';

export default function ShippingChecklist({ order, result }) {
    const fileRef = useRef(null);

    const upload = useForm({ file: null, attachment_type: 'Pre Shipping Photo' });

    const submitUpload = (e) => {
        e.preventDefault();
        upload.post(route('orders.attachments.store', order.id), {
            forceFormData: true,
            onSuccess: () => {
                upload.reset('file');
                if (fileRef.current) fileRef.current.value = '';
                router.reload({ only: ['result', 'order'] });
            },
        });
    };

    const ship = () => {
        if (!confirm('Confirm shipping?')) return;
        router.post(route('shipping.confirm-shipped', order.id));
    };

    return (
        <AuthenticatedLayout header={`Checklist · ${order.order_number}`}>
            <Head title={`Checklist ${order.order_number}`} />
            <PageHeader
                title={<span className="font-mono">{order.order_number}</span>}
                subtitle="Pre-ship readiness check"
                actions={
                    <div className="flex gap-2">
                        <Link href={route('orders.show', order.id)} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Order</Link>
                        {result.passed && (
                            <button onClick={ship} className="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-500">
                                Confirm Shipped
                            </button>
                        )}
                    </div>
                }
            />

            <div className={'mb-4 rounded-md border p-3 text-sm ' + (result.passed ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-800')}>
                <strong>{result.passed ? 'All checks passed.' : 'Cannot ship yet.'}</strong>{' '}
                {result.passed ? 'Click "Confirm Shipped" to dispatch.' : 'Resolve the items below.'}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="lg:col-span-2 rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Checklist</h2>
                    <ul className="space-y-2.5">
                        {result.checks.map((c) => (
                            <li key={c.key} className="flex items-start gap-3">
                                <span className={'mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full ' + (c.ok ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700')}>
                                    {c.ok ? '✓' : '✗'}
                                </span>
                                <div className="text-sm">
                                    <div className={c.ok ? 'text-slate-800' : 'text-slate-800 font-medium'}>{c.label}</div>
                                    {c.message && <div className="text-xs text-red-600">{c.message}</div>}
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="space-y-4">
                    <div className="rounded-lg border border-slate-200 bg-white p-5">
                        <h2 className="text-sm font-semibold text-slate-700">Order snapshot</h2>
                        <div className="mt-2 text-xs text-slate-500">{order.customer_name}</div>
                        <div className="text-xs text-slate-500">{order.customer_phone}</div>
                        <div className="mt-1 text-xs text-slate-500">{order.customer_address}</div>
                        <div className="text-xs text-slate-500">{order.city}, {order.country}</div>
                        <div className="mt-2 flex gap-1.5">
                            <StatusBadge value={order.status} />
                            <StatusBadge value={order.customer_risk_level} />
                        </div>
                    </div>

                    <form onSubmit={submitUpload} className="rounded-lg border border-slate-200 bg-white p-5 space-y-3">
                        <h2 className="text-sm font-semibold text-slate-700">Upload pre-shipping photo</h2>
                        <p className="text-xs text-slate-500">Photo of the packed goods. Required if "Shipping photo required" setting is on.</p>
                        <input
                            ref={fileRef}
                            type="file"
                            accept="image/*,application/pdf"
                            onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)}
                            className="block w-full text-xs"
                        />
                        {upload.errors.file && <p className="text-xs text-red-600">{upload.errors.file}</p>}
                        <button
                            type="submit"
                            disabled={!upload.data.file || upload.processing}
                            className="rounded-md bg-slate-900 px-3 py-2 text-xs font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                        >
                            {upload.processing ? 'Uploading…' : 'Upload photo'}
                        </button>
                    </form>

                    {order.active_shipment && (
                        <div className="rounded-lg border border-slate-200 bg-white p-5 space-y-2">
                            <h2 className="text-sm font-semibold text-slate-700">Carrier</h2>
                            <div className="text-sm text-slate-700">{order.active_shipment.shipping_company?.name}</div>
                            <div className="font-mono text-xs text-slate-500">{order.active_shipment.tracking_number}</div>
                            <a href={route('shipping-labels.print', order.id)} target="_blank" rel="noreferrer" className="inline-block rounded-md border border-indigo-200 bg-white px-3 py-1.5 text-xs text-indigo-700 hover:bg-indigo-50">
                                Print 4×6 label
                            </a>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
