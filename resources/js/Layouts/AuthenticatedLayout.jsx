import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Dropdown from '@/Components/Dropdown';
import SidebarNav from '@/Components/SidebarNav';

/**
 * Admin shell:
 *   - Fixed left sidebar (collapsible on mobile via the topbar hamburger)
 *   - Topbar with current user + flash messages
 *   - <main> content area
 *
 * Sidebar items are filtered by the user's permissions (see SidebarNav.jsx).
 */
export default function AuthenticatedLayout({ header, children }) {
    const { props } = usePage();
    const user = props.auth?.user;
    const flash = props.flash ?? {};
    const appName = props.app?.name ?? props.name ?? 'Hnawbas Operations';

    const [mobileOpen, setMobileOpen] = useState(false);
    const [bell, setBell] = useState({ unread_count: 0, recent: [] });

    /* ============================================================== */
    /* Sidebar — persistent collapse with per-group flyout.            */
    /*                                                                 */
    /* `sidebarCollapsed` is the persistent preference (localStorage). */
    /* When collapsed, SidebarNav renders ONE icon per group and       */
    /* manages its own flyout panel internally. The layout here just  */
    /* needs to size the rail (w-20) and pin main content padding so  */
    /* opening a flyout never reflows the page.                        */
    /*                                                                 */
    /* `isMobile` is tracked so the mobile drawer always shows the    */
    /* full labelled sidebar regardless of the desktop preference.    */
    /* ============================================================== */
    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
    const [isMobile, setIsMobile] = useState(false);

    // Restore persistent collapse from localStorage after mount (SSR-safe).
    useEffect(() => {
        if (typeof window === 'undefined') return;
        try {
            const stored = window.localStorage.getItem('sidebar:collapsed');
            if (stored === '1') setSidebarCollapsed(true);
        } catch { /* localStorage may be disabled (private mode etc.) */ }
    }, []);

    // Track viewport so the mobile drawer always shows the full labelled
    // sidebar regardless of the desktop collapse preference.
    useEffect(() => {
        if (typeof window === 'undefined' || !window.matchMedia) return;
        const mq = window.matchMedia('(max-width: 767px)');
        const update = (e) => setIsMobile(e.matches);
        update(mq);
        if (mq.addEventListener) {
            mq.addEventListener('change', update);
            return () => mq.removeEventListener('change', update);
        }
        // Safari < 14 fallback
        mq.addListener(update);
        return () => mq.removeListener(update);
    }, []);

    const toggleSidebar = () => {
        setSidebarCollapsed((prev) => {
            const next = !prev;
            try {
                window.localStorage.setItem('sidebar:collapsed', next ? '1' : '0');
            } catch { /* ignore */ }
            return next;
        });
    };

    // Mobile always sees the full labelled drawer; on desktop, follow the
    // persistent preference. SidebarNav itself owns flyout state in
    // compact mode.
    const showLabels = isMobile || !sidebarCollapsed;

    // Poll the notifications summary every 60s. Cheap query — one COUNT
    // and a 8-row LIMIT — per 60s for active users only.
    useEffect(() => {
        if (!user) return;
        let cancelled = false;
        const fetchSummary = () => {
            fetch(route('notifications.summary'), { headers: { Accept: 'application/json' } })
                .then((r) => r.ok ? r.json() : null)
                .then((j) => { if (!cancelled && j) setBell(j); })
                .catch(() => {});
        };
        fetchSummary();
        const t = setInterval(fetchSummary, 60_000);
        return () => { cancelled = true; clearInterval(t); };
    }, [user?.id]);

    return (
        <div className="min-h-screen bg-slate-100">
            {/* Sidebar */}
            <aside
                className={
                    'fixed inset-y-0 left-0 z-30 flex flex-col bg-slate-900 text-slate-100 ' +
                    'transition-[width,transform] duration-150 motion-reduce:transition-none ' +
                    'md:translate-x-0 ' +
                    // Mobile baseline is always w-64 (full labelled drawer).
                    // On md+, narrow to w-20 when persistently collapsed.
                    // Children of the collapsed rail render group icons
                    // only; per-group flyouts are owned by SidebarNav.
                    'w-64 ' +
                    (!isMobile && sidebarCollapsed ? 'md:w-20 ' : '') +
                    (mobileOpen ? 'translate-x-0' : '-translate-x-full')
                }
            >
                <div
                    className={
                        'flex border-b border-slate-800 ' +
                        (showLabels
                            ? 'flex-row items-center justify-between gap-3 px-5 py-4'
                            : 'flex-col items-center gap-2 px-2 py-3')
                    }
                >
                    <div className={'flex items-center ' + (showLabels ? 'gap-3 min-w-0' : '')}>
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-indigo-500 font-bold text-white">
                            H
                        </div>
                        {showLabels && (
                            <div className="leading-tight min-w-0">
                                <div className="truncate text-sm font-semibold text-white">{appName}</div>
                                <div className="text-[11px] text-slate-400">E-commerce Operations</div>
                            </div>
                        )}
                    </div>

                    {/* Collapse toggle — desktop only. Mobile keeps the drawer-style hamburger. */}
                    {/* The icon reflects the PERSISTENT preference, not the hover state, so the */}
                    {/* user always sees what their next click will do. */}
                    <button
                        type="button"
                        onClick={toggleSidebar}
                        aria-pressed={sidebarCollapsed}
                        aria-label={sidebarCollapsed ? 'Expand sidebar permanently' : 'Collapse sidebar'}
                        title={sidebarCollapsed ? 'Expand sidebar permanently' : 'Collapse sidebar'}
                        className="hidden md:inline-flex shrink-0 items-center justify-center rounded-md p-1.5 text-slate-400 transition hover:bg-slate-800 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            strokeWidth={1.8}
                            stroke="currentColor"
                            className={'h-4 w-4 transition-transform ' + (sidebarCollapsed ? 'rotate-180' : '')}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M18.75 19.5 11.25 12l7.5-7.5m-7.5 15L3.75 12l7.5-7.5"
                            />
                        </svg>
                    </button>
                </div>

                <div className="flex-1 overflow-y-auto overflow-x-hidden">
                    <SidebarNav showLabels={showLabels} />
                </div>

                {showLabels && (
                    <div className="border-t border-slate-800 px-4 py-3 text-xs text-slate-400">
                        {user?.role?.name && (
                            <div className="truncate">
                                <span className="text-slate-500">Role:</span>{' '}
                                <span className="text-slate-200">{user.role.name}</span>
                            </div>
                        )}
                    </div>
                )}
            </aside>

            {/* Mobile backdrop */}
            {mobileOpen && (
                <div
                    onClick={() => setMobileOpen(false)}
                    className="fixed inset-0 z-20 bg-slate-900/50 md:hidden"
                />
            )}

            {/* Main column */}
            <div
                className={
                    'flex min-h-screen flex-col transition-[padding] duration-200 ' +
                    (sidebarCollapsed ? 'md:pl-20' : 'md:pl-64')
                }
            >
                <header className="sticky top-0 z-10 flex h-14 items-center justify-between border-b border-slate-200 bg-white px-4">
                    <div className="flex items-center gap-3">
                        <button
                            type="button"
                            className="rounded p-1 text-slate-500 hover:bg-slate-100 md:hidden"
                            onClick={() => setMobileOpen((s) => !s)}
                            aria-label="Toggle navigation"
                        >
                            <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>

                        {header && <div className="text-sm font-semibold text-slate-700">{header}</div>}
                    </div>

                    <div className="flex items-center gap-3">
                        {/* Notifications bell */}
                        <Dropdown>
                            <Dropdown.Trigger>
                                <button
                                    type="button"
                                    className="relative rounded-md p-2 text-slate-500 transition hover:bg-slate-100"
                                    aria-label="Notifications"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.6} stroke="currentColor" className="h-5 w-5">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                                    </svg>
                                    {bell.unread_count > 0 && (
                                        <span className="absolute right-1 top-1 inline-flex h-4 min-w-[16px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold text-white">
                                            {bell.unread_count > 9 ? '9+' : bell.unread_count}
                                        </span>
                                    )}
                                </button>
                            </Dropdown.Trigger>
                            <Dropdown.Content width="64">
                                <div className="border-b border-slate-200 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Notifications {bell.unread_count > 0 && `(${bell.unread_count} new)`}
                                </div>
                                {bell.recent.length === 0 ? (
                                    <div className="px-3 py-4 text-xs text-slate-400">No notifications yet.</div>
                                ) : (
                                    <ul className="max-h-72 divide-y divide-slate-100 overflow-y-auto text-xs">
                                        {bell.recent.map((n) => (
                                            <li key={n.id} className={'px-3 py-2 ' + (n.read_at === null ? 'bg-slate-50' : '')}>
                                                <Link href={n.action_url ?? route('notifications.index')} className="block">
                                                    <div className="text-[10px] uppercase tracking-wide text-slate-400">{n.type}</div>
                                                    <div className="text-slate-700 truncate">{n.title}</div>
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <Dropdown.Link href={route('notifications.index')}>See all →</Dropdown.Link>
                            </Dropdown.Content>
                        </Dropdown>

                        <Dropdown>
                            <Dropdown.Trigger>
                                <button
                                    type="button"
                                    className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-slate-600 transition hover:bg-slate-100"
                                >
                                    <span className="flex h-7 w-7 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-700">
                                        {(user?.name ?? '?').charAt(0).toUpperCase()}
                                    </span>
                                    <span className="hidden text-left sm:block">
                                        <span className="block leading-tight">{user?.name}</span>
                                        <span className="block text-[11px] text-slate-400">{user?.email}</span>
                                    </span>
                                    <svg className="h-4 w-4 text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fillRule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 011.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clipRule="evenodd" />
                                    </svg>
                                </button>
                            </Dropdown.Trigger>

                            <Dropdown.Content>
                                <Dropdown.Link href={route('profile.edit')}>Profile</Dropdown.Link>
                                <Dropdown.Link href={route('logout')} method="post" as="button">
                                    Log out
                                </Dropdown.Link>
                            </Dropdown.Content>
                        </Dropdown>
                    </div>
                </header>

                {/* Flash messages */}
                {(flash.success || flash.error || flash.info) && (
                    <div className="border-b border-slate-200 bg-white px-4 py-2">
                        {flash.success && (
                            <div className="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-700">
                                {flash.success}
                            </div>
                        )}
                        {flash.error && (
                            <div className="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                                {flash.error}
                            </div>
                        )}
                        {flash.info && (
                            <div className="rounded border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-700">
                                {flash.info}
                            </div>
                        )}
                    </div>
                )}

                <main className="flex-1">
                    <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">{children}</div>
                </main>
            </div>
        </div>
    );
}
