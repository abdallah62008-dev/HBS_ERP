import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link } from '@inertiajs/react';
import FinancePeriodForm from './Form';

export default function FinancePeriodsEdit({ period }) {
    return (
        <AuthenticatedLayout header={`Edit ${period.name}`}>
            <Head title={`Edit ${period.name}`} />
            <PageHeader
                title={`Edit ${period.name}`}
                subtitle="Closed periods cannot be edited — reopen first if a change is needed."
                actions={<Link href={route('finance-periods.index')} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">← Periods</Link>}
            />
            <FinancePeriodForm
                initial={{
                    name: period.name,
                    start_date: period.start_date,
                    end_date: period.end_date,
                    notes: period.notes ?? '',
                }}
                action={route('finance-periods.update', period.id)}
                method="put"
                submitLabel="Save changes"
            />
        </AuthenticatedLayout>
    );
}
