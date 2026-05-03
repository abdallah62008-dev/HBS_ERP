import { Link } from '@inertiajs/react';

/**
 * Renders the `links` array Laravel paginators produce. Disabled-state for
 * out-of-range pages uses span instead of Link so they aren't clickable.
 */
export default function Pagination({ links }) {
    if (!links || links.length <= 3) return null;

    return (
        <nav className="mt-4 flex flex-wrap items-center gap-1 text-sm">
            {links.map((link, idx) => {
                const label = link.label
                    .replace('&laquo;', '«')
                    .replace('&raquo;', '»')
                    .replace('Previous', '«')
                    .replace('Next', '»');

                const base =
                    'rounded border px-2.5 py-1 transition-colors ';

                if (!link.url) {
                    return (
                        <span
                            key={idx}
                            className={base + 'cursor-not-allowed border-slate-200 text-slate-300'}
                            dangerouslySetInnerHTML={{ __html: label }}
                        />
                    );
                }

                return (
                    <Link
                        key={idx}
                        href={link.url}
                        preserveScroll
                        preserveState
                        className={
                            base +
                            (link.active
                                ? 'border-slate-900 bg-slate-900 text-white'
                                : 'border-slate-200 text-slate-600 hover:border-slate-400')
                        }
                        dangerouslySetInnerHTML={{ __html: label }}
                    />
                );
            })}
        </nav>
    );
}
