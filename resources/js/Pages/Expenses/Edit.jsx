import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ExpenseForm from './Form';
import useCan from '@/Hooks/useCan';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

function fmtAmount(value, currency = 'EGP') {
    const n = Number(value ?? 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    return `${currency} ${n}`;
}

export default function ExpenseEdit({ expense, categories, campaigns, cashboxes, payment_methods }) {
    const can = useCan();
    const isPosted = !!expense.cashbox_transaction_id;

    const { data, setData, put, processing, errors } = useForm({
        title: expense.title,
        expense_category_id: expense.expense_category_id,
        amount: expense.amount,
        currency_code: expense.currency_code,
        expense_date: expense.expense_date?.slice(0, 10),
        payment_method_id: expense.payment_method_id ?? '',
        cashbox_id: expense.cashbox_id ?? '',
        related_campaign_id: expense.related_campaign_id,
        related_order_id: expense.related_order_id,
        notes: expense.notes ?? '',
    });

    const submit = (e) => { e.preventDefault(); put(route('expenses.update', expense.id)); };

    /* Retro-post form (for null-cashbox historical expenses). */
    const showPostForm = !isPosted && can('expenses.post_to_cashbox');
    const [postingOpen, setPostingOpen] = useState(false);
    const p = useForm({
        cashbox_id: expense.cashbox_id ?? '',
        payment_method_id: expense.payment_method_id ?? '',
        amount: expense.amount ?? '',
        occurred_at: expense.expense_date?.slice(0, 10) ?? new Date().toISOString().slice(0, 10),
    });
    const submitPost = (e) => {
        e.preventDefault();
        p.post(route('expenses.post-to-cashbox', expense.id));
    };

    return (
        <AuthenticatedLayout header={`Edit · ${expense.title}`}>
            <Head title={`Edit ${expense.title}`} />
            <PageHeader
                title={`Edit · ${expense.title}`}
                subtitle={
                    isPosted ? (
                        <span>
                            Posted{expense.cashbox ? ` to ${expense.cashbox.name}` : ''} ·{' '}
                            <span className="tabular-nums font-medium">
                                {fmtAmount(expense.amount, expense.currency_code)}
                            </span>
                            {expense.cashbox_posted_at && (
                                <span className="text-slate-400"> · {expense.cashbox_posted_at.slice(0, 10)}</span>
                            )}
                        </span>
                    ) : (
                        <span className="text-amber-700">Not posted to any cashbox.</span>
                    )
                }
            />

            <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5">
                <ExpenseForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    categories={categories}
                    campaigns={campaigns}
                    cashboxes={cashboxes}
                    payment_methods={payment_methods}
                    isPosted={isPosted}
                    isEdit={true}
                />
                <div className="mt-6 flex justify-end gap-2">
                    <Link href={route('expenses.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {processing ? 'Saving…' : 'Save changes'}
                    </button>
                </div>
            </form>

            {/* Retro-post for unposted historical expenses. */}
            {showPostForm && (
                <div className="mt-6 rounded-lg border border-slate-200 bg-white p-5">
                    <div className="flex items-center justify-between">
                        <div>
                            <h3 className="text-sm font-semibold text-slate-800">Post to cashbox</h3>
                            <p className="text-xs text-slate-500">
                                Records the OUT cashbox transaction for this historical expense.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => setPostingOpen((v) => !v)}
                            className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs"
                        >
                            {postingOpen ? 'Hide' : 'Open posting form'}
                        </button>
                    </div>

                    {postingOpen && (
                        <form onSubmit={submitPost} className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-4">
                            <select required value={p.data.cashbox_id ?? ''} onChange={(e) => p.setData('cashbox_id', e.target.value)} className="rounded-md border-slate-300 text-sm">
                                <option value="">— Pick cashbox —</option>
                                {cashboxes.filter((c) => c.is_active).map((c) => (
                                    <option key={c.id} value={c.id}>{c.name} ({c.currency_code})</option>
                                ))}
                            </select>
                            <select required value={p.data.payment_method_id ?? ''} onChange={(e) => p.setData('payment_method_id', e.target.value)} className="rounded-md border-slate-300 text-sm">
                                <option value="">— Pick method —</option>
                                {payment_methods.filter((m) => m.is_active).map((m) => (
                                    <option key={m.id} value={m.id}>{m.name}</option>
                                ))}
                            </select>
                            <input type="number" step="0.01" min={0.01} value={p.data.amount ?? ''} onChange={(e) => p.setData('amount', e.target.value)} placeholder="Amount" className="rounded-md border-slate-300 text-sm" />
                            <input type="date" value={p.data.occurred_at ?? ''} onChange={(e) => p.setData('occurred_at', e.target.value)} className="rounded-md border-slate-300 text-sm" />
                            <div className="sm:col-span-4 flex justify-end">
                                <button type="submit" disabled={p.processing} className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white disabled:opacity-60">
                                    {p.processing ? 'Posting…' : 'Post to cashbox'}
                                </button>
                            </div>
                        </form>
                    )}
                </div>
            )}
        </AuthenticatedLayout>
    );
}
