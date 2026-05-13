import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link } from '@inertiajs/react';
import FinancePeriodForm from './Form';

export default function FinancePeriodsCreate() {
    return (
        <AuthenticatedLayout header="New Finance Period">
            <Head title="New Finance Period" />
            <PageHeader
                title="New Finance Period"
                subtitle="Open periods allow financial postings; closed periods block them inside the date range."
                actions={<Link href={route('finance-periods.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Periods</Link>}
            />
            <FinancePeriodForm action={route('finance-periods.store')} submitLabel="Create period" />
        </AuthenticatedLayout>
    );
}
