import { Link, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
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

function ItemIcon({ d, active, large = false }) {
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            strokeWidth={1.6}
            stroke="currentColor"
            className={
                (large ? 'h-6 w-6 shrink-0 ' : 'h-5 w-5 shrink-0 ') +
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

export default function SidebarNav({ showLabels = true }) {
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

    /* ============================================================== */
    /* Compact (showLabels === false) — ONE icon per group, with a     */
    /* hover/click flyout that lists the group's children.             */
    /*                                                                 */
    /* This avoids rendering 25-30 indistinguishable item icons in a   */
    /* tall rail. The rail now has ~10-12 group icons total. Children  */
    /* are revealed on demand in a side panel ("flyout").              */
    /*                                                                 */
    /* Top-level items that live in an untitled section (Dashboard)   */
    /* render as direct icon links — no flyout, click navigates.       */
    /* ============================================================== */
    const [flyoutSection, setFlyoutSection] = useState(null);
    const [flyoutTop, setFlyoutTop] = useState(0);
    const enterTimer = useRef(null);
    const leaveTimer = useRef(null);

    const openFlyout = (title, anchorEl) => {
        clearTimeout(leaveTimer.current);
        const rect = anchorEl.getBoundingClientRect();
        clearTimeout(enterTimer.current);
        enterTimer.current = setTimeout(() => {
            setFlyoutTop(rect.top);
            setFlyoutSection(title);
        }, 80);
    };
    const scheduleClose = () => {
        clearTimeout(enterTimer.current);
        clearTimeout(leaveTimer.current);
        leaveTimer.current = setTimeout(() => setFlyoutSection(null), 200);
    };
    const cancelClose = () => {
        clearTimeout(leaveTimer.current);
    };
    const toggleFlyout = (title, anchorEl) => {
        if (flyoutSection === title) {
            setFlyoutSection(null);
            return;
        }
        const rect = anchorEl.getBoundingClientRect();
        clearTimeout(enterTimer.current);
        clearTimeout(leaveTimer.current);
        setFlyoutTop(rect.top);
        setFlyoutSection(title);
    };

    // Close the flyout when the route changes (Inertia visit finishes).
    useEffect(() => {
        setFlyoutSection(null);
        clearTimeout(enterTimer.current);
        clearTimeout(leaveTimer.current);
    }, [url]);

    // Close on Escape, or on click outside the rail/flyout (covers touch
    // users where mouseleave isn't reliable).
    useEffect(() => {
        if (!flyoutSection) return;
        const onKey = (e) => {
            if (e.key === 'Escape') setFlyoutSection(null);
        };
        const onDocPointerDown = (e) => {
            const t = e.target;
            if (!t || typeof t.closest !== 'function') return;
            if (t.closest('[data-sidebar-rail]') || t.closest('[data-sidebar-flyout]')) return;
            setFlyoutSection(null);
        };
        window.addEventListener('keydown', onKey);
        document.addEventListener('mousedown', onDocPointerDown);
        document.addEventListener('touchstart', onDocPointerDown);
        return () => {
            window.removeEventListener('keydown', onKey);
            document.removeEventListener('mousedown', onDocPointerDown);
            document.removeEventListener('touchstart', onDocPointerDown);
        };
    }, [flyoutSection]);

    if (!showLabels) {
        const activeFlyoutSection = visibleSections.find((s) => s.title === flyoutSection);
        return (
            <>
                <nav
                    data-sidebar-rail
                    className="flex flex-col items-center gap-1 px-1 py-3 text-sm"
                    aria-label="Primary navigation (compact)"
                >
                    {visibleSections.map((section) => {
                        // Top-level untitled section (e.g. Dashboard) — render
                        // its items as direct icon links. No flyout.
                        if (!section.title) {
                            return section.items.map((item) => {
                                const active = isActiveHref(url, item.href);
                                return (
                                    <Link
                                        key={item.href}
                                        href={item.href}
                                        title={item.label}
                                        aria-label={item.label}
                                        className={
                                            'group flex h-11 w-11 items-center justify-center rounded-md transition-colors ' +
                                            (active
                                                ? 'bg-slate-800 text-white'
                                                : 'text-slate-300 hover:bg-slate-800/60 hover:text-white')
                                        }
                                    >
                                        <ItemIcon d={item.icon} active={active} large />
                                        <span className="sr-only">{item.label}</span>
                                    </Link>
                                );
                            });
                        }

                        // Titled group — single icon button that opens the
                        // flyout. Active state mirrors `hasActive` so the
                        // user can see which group owns the current page.
                        const groupActive = section.hasActive;
                        const isFlyoutOpen = flyoutSection === section.title;
                        return (
                            <button
                                key={section.title}
                                type="button"
                                onMouseEnter={(e) => openFlyout(section.title, e.currentTarget)}
                                onMouseLeave={scheduleClose}
                                onFocus={(e) => openFlyout(section.title, e.currentTarget)}
                                onClick={(e) => toggleFlyout(section.title, e.currentTarget)}
                                aria-label={section.title}
                                title={section.title}
                                aria-haspopup="menu"
                                aria-expanded={isFlyoutOpen}
                                className={
                                    'group relative flex h-11 w-11 items-center justify-center rounded-md transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 ' +
                                    (groupActive
                                        ? 'bg-slate-800 text-white'
                                        : 'text-slate-300 hover:bg-slate-800/60 hover:text-white') +
                                    (isFlyoutOpen ? ' bg-slate-800/80' : '')
                                }
                            >
                                {section.icon && (
                                    <ItemIcon d={section.icon} active={groupActive} large />
                                )}
                                {/* Tiny dot indicating the active group when the icon  */}
                                {/* itself isn't already highlighted (e.g. user is on a */}
                                {/* child route but the group icon's hover state is    */}
                                {/* not currently engaged).                            */}
                                {groupActive && !isFlyoutOpen && (
                                    <span
                                        aria-hidden="true"
                                        className="absolute right-1 top-1 h-1.5 w-1.5 rounded-full bg-indigo-400"
                                    />
                                )}
                                <span className="sr-only">{section.title}</span>
                            </button>
                        );
                    })}
                </nav>

                {/* Flyout panel — rendered into document.body via a portal  */}
                {/* so it escapes the aside's transform-induced containing    */}
                {/* block AND the inner scroll-container's overflow clipping. */}
                {/* `left-20` matches the rail width (w-20 = 5rem = 80px).    */}
                {/* `top` is the anchor button's viewport y at open time.     */}
                {activeFlyoutSection && typeof document !== 'undefined' && createPortal(
                    <div
                        role="menu"
                        aria-label={activeFlyoutSection.title}
                        data-sidebar-flyout
                        onMouseEnter={cancelClose}
                        onMouseLeave={scheduleClose}
                        style={{ top: flyoutTop }}
                        className="fixed left-20 z-40 w-60 max-h-[80vh] overflow-y-auto rounded-r-lg border border-slate-700/60 bg-slate-900 text-slate-100 shadow-2xl ring-1 ring-black/20"
                    >
                        <div className="flex items-center gap-2 border-b border-slate-800 px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-300">
                            {activeFlyoutSection.icon && (
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    strokeWidth={1.6}
                                    stroke="currentColor"
                                    className="h-4 w-4 shrink-0 text-slate-400"
                                >
                                    <path strokeLinecap="round" strokeLinejoin="round" d={activeFlyoutSection.icon} />
                                </svg>
                            )}
                            <span>{activeFlyoutSection.title}</span>
                        </div>
                        <ul className="flex flex-col gap-0.5 p-1.5">
                            {activeFlyoutSection.items.map((item) => {
                                const active = isActiveHref(url, item.href);
                                return (
                                    <li key={item.href}>
                                        <Link
                                            href={item.href}
                                            role="menuitem"
                                            className={
                                                'group flex items-center gap-2.5 rounded-md px-2.5 py-1.5 text-sm transition-colors ' +
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
                    </div>,
                    document.body
                )}
            </>
        );
    }

    /* ------------------------------------------------------------------ */
    /* Expanded mode — full layout with collapsible group sections.       */
    /* ------------------------------------------------------------------ */
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
