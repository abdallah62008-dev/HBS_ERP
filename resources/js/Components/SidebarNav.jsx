import { Link, usePage } from '@inertiajs/react';
import useCan from '@/Hooks/useCan';
import { sidebarSections } from '@/Config/sidebar';

/**
 * Permission-aware sidebar.
 *
 * Visibility logic mirrors the backend: an item without a permission is
 * always shown; an item with a string permission shows when the user has
 * that slug; an item with an array of slugs shows when the user has ANY
 * one of them.
 */
function isActiveHref(currentUrl, href) {
    if (href === '/') return currentUrl === '/';
    return currentUrl === href || currentUrl.startsWith(href + '/');
}

export default function SidebarNav() {
    const { url } = usePage();
    const can = useCan();

    return (
        <nav className="flex flex-col gap-6 px-3 py-4 text-sm">
            {sidebarSections.map((section, sIdx) => {
                const visibleItems = section.items.filter((item) => {
                    if (!item.permission) return true;
                    return Array.isArray(item.permission)
                        ? can.any(item.permission)
                        : can(item.permission);
                });

                if (visibleItems.length === 0) return null;

                return (
                    <div key={sIdx}>
                        {section.title && (
                            <div className="mb-2 px-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                {section.title}
                            </div>
                        )}
                        <ul className="flex flex-col gap-0.5">
                            {visibleItems.map((item) => {
                                const active = isActiveHref(url, item.href);
                                return (
                                    <li key={item.href}>
                                        <Link
                                            href={item.href}
                                            className={
                                                'group flex items-center gap-3 rounded-md px-2.5 py-2 transition-colors ' +
                                                (active
                                                    ? 'bg-slate-800 text-white'
                                                    : 'text-slate-300 hover:bg-slate-800/60 hover:text-white')
                                            }
                                        >
                                            <svg
                                                xmlns="http://www.w3.org/2000/svg"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                strokeWidth={1.6}
                                                stroke="currentColor"
                                                className={
                                                    'h-5 w-5 shrink-0 ' +
                                                    (active ? 'text-white' : 'text-slate-400 group-hover:text-white')
                                                }
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d={item.icon}
                                                />
                                            </svg>
                                            <span className="truncate">{item.label}</span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                );
            })}
        </nav>
    );
}
