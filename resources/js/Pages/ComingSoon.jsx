import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

/**
 * Generic placeholder for sidebar destinations that aren't built yet.
 * Replaced module-by-module as later phases land.
 */
export default function ComingSoon({ module, phase, description }) {
    return (
        <AuthenticatedLayout header={module}>
            <Head title={`${module} — Coming soon`} />

            <div className="mx-auto max-w-2xl py-16 text-center">
                <div className="mx-auto mb-6 flex h-14 w-14 items-center justify-center rounded-full bg-indigo-100 text-indigo-600">
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        strokeWidth={1.5}
                        stroke="currentColor"
                        className="h-7 w-7"
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"
                        />
                    </svg>
                </div>

                <h1 className="text-xl font-semibold text-slate-800">
                    {module}
                </h1>

                <p className="mt-2 text-sm text-slate-500">
                    {description ??
                        'This module will be implemented in a later phase.'}
                </p>

                {phase && (
                    <div className="mt-4 inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
                        Scheduled: {phase}
                    </div>
                )}

                <div className="mt-8">
                    <Link
                        href="/dashboard"
                        className="inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700"
                    >
                        ← Back to dashboard
                    </Link>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
