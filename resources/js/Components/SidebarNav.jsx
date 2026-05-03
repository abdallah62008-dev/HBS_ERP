import { Link, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import useCan from '@/Hooks/useCan';
import { sidebarSections, marketerSidebarSections } from '@/Config/sidebar';

/**
 * Permission-aware, collapsible sidebar.
 *
 * Behaviour:
 *   - Items without a `permission` are always shown.
 *   - String `permission` → user must have that slug.
 *   - Array `permission`  → user must have ANY of those slugs.
 *   - A group with zero visible items is hidden entirely.
 *   - Marketer users (auth.user.is_marketer) see `marketerSidebarSections`
 *     instead of the admin layout. Super-admins always see admin layout.
 *   - Each titled group is collapsible. The group containing the active
 *     route auto-opens on mount; users can manually toggle any group.
 *   - Active route highlighting is preserved (matches /href and any sub-path).
 */

function isActiveHref(currentUrl, href) {
    // Strip query string + hash before comparing — Inertia's `url` includes
    // both, but sidebar items are pathname-only.
    const path = (currentUrl || '').split('?')[0].split('#')[0];
    if (href === '/') return path === '/';
    if (href === '/dashboard') return path === '/dashboard';
    return path === href || path.startsWith(href + '/');
}

function ItemIcon({ d, active }) {
    return (
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
            <path strokeLinecap="round" strokeLinejoin="round" d={d} />
        </svg>
    );
}

function ChevronIcon({ open }) {
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            strokeWidth={2}
            stroke="currentColor"
            className={'h-4 w-4 shrink-0 transition-transform ' + (open ? 'rotate-90' : '')}
        >
            <path strokeLinecap="round" strokeLinejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
        </svg>
    );
}

export default function SidebarNav() {
    const { url, props } = usePage();
    const can = useCan();

    const isMarketer = props.auth?.user?.is_marketer === true
        && props.auth?.user?.is_super_admin !== true;

    const sections = isMarketer ? marketerSidebarSections : sidebarSections;

    // Resolve which items are visible (permission-aware) for each section
    // ONCE per render. Used to (a) hide empty groups, (b) compute which
    // group contains the active route for auto-open.
    const visibleSections = useMemo(() => {
        return sections.map((section, idx) => {
            const items = section.items.filter((item) => {
                if (!item.permission) return true;
                return Array.isArray(item.permission)
                    ? can.any(item.permission)
                    : can(item.permission);
            });
            const hasActive = items.some((it) => isActiveHref(url, it.href));
            return { ...section, items, idx, hasActive };
        }).filter((s) => s.items.length > 0);
    }, [sections, url]);

    // Per-group open/close state. Default: groups with the active route
    // are open; ungrouped sections (no `title`) are always considered open.
    // Persists across renders within the same page; resets when navigating
    // to a route that lives in a different group (so the user always lands
    // with the relevant group expanded).
    const initialOpenKeys = useMemo(() => {
        const keys = {};
        for (const s of visibleSections) {
            if (!s.title) continue;
            if (s.hasActive) keys[s.title] = true;
        }
        return keys;
    }, [visibleSections]);

    const [openGroups, setOpenGroups] = useState(initialOpenKeys);

    // When the active route changes (and lands in a different group),
    // re-open that group automatically.
    useEffect(() => {
        setOpenGroups((prev) => {
            const next = { ...prev };
            for (const s of visibleSections) {
                if (s.title && s.hasActive) next[s.title] = true;
            }
            return next;
        });
    }, [url, visibleSections.length]);

    const toggle = (title) => {
        setOpenGroups((prev) => ({ ...prev, [title]: !prev[title] }));
    };

    return (
        <nav className="flex flex-col gap-3 px-3 py-4 text-sm">
            {visibleSections.map((section) => {
                // Sections without a title render as a flat always-open block.
                if (!section.title) {
                    return (
                        <ul key={'top-' + section.idx} className="flex flex-col gap-0.5">
                            {section.items.map((item) => {
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
                                            <ItemIcon d={item.icon} active={active} />
                                            <span className="truncate">{item.label}</span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    );
                }

                const open = openGroups[section.title] ?? false;
                const groupActive = section.hasActive;

                return (
                    <div key={section.title}>
                        <button
                            type="button"
                            onClick={() => toggle(section.title)}
                            aria-expanded={open}
                            aria-controls={`group-${section.title}`}
                            className={
                                'group flex w-full items-center justify-between rounded-md px-2 py-1.5 text-[11px] font-semibold uppercase tracking-wider transition-colors ' +
                                (groupActive
                                    ? 'text-slate-100'
                                    : 'text-slate-400 hover:text-slate-200')
                            }
                        >
                            <span className="flex items-center gap-2">
                                {section.icon && (
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        strokeWidth={1.6}
                                        stroke="currentColor"
                                        className="h-4 w-4 shrink-0 text-slate-500 group-hover:text-slate-300"
                                    >
                                        <path strokeLinecap="round" strokeLinejoin="round" d={section.icon} />
                                    </svg>
                                )}
                                <span>{section.title}</span>
                            </span>
                            <ChevronIcon open={open} />
                        </button>

                        {open && (
                            <ul
                                id={`group-${section.title}`}
                                className="mt-1 flex flex-col gap-0.5 border-l border-slate-800/60 pl-2"
                            >
                                {section.items.map((item) => {
                                    const active = isActiveHref(url, item.href);
                                    return (
                                        <li key={item.href}>
                                            <Link
                                                href={item.href}
                                                className={
                                                    'group flex items-center gap-3 rounded-md px-2.5 py-1.5 transition-colors ' +
                                                    (active
                                                        ? 'bg-slate-800 text-white'
                                                        : 'text-slate-300 hover:bg-slate-800/60 hover:text-white')
                                                }
                                            >
                                                <ItemIcon d={item.icon} active={active} />
                                                <span className="truncate">{item.label}</span>
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>
                );
            })}
        </nav>
    );
}
