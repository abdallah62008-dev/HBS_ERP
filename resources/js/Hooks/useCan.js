import { usePage } from '@inertiajs/react';

/**
 * Permission gate for the React side.
 *
 * The backend (CheckPermission middleware) is the source of truth — this
 * hook only mirrors it for hiding/showing UI. Never rely on UI hiding
 * alone for security.
 *
 * Usage:
 *   const can = useCan();
 *   if (can('orders.view')) ...
 *   if (can.any(['orders.edit', 'orders.create'])) ...
 *
 * Super Admin has the special permission '*' (set server-side in
 * HandleInertiaRequests::share) so any check returns true.
 */
export default function useCan() {
    const user = usePage().props.auth?.user;
    const perms = user?.permissions ?? [];
    const isSuper = perms.includes('*') || user?.is_super_admin === true;

    const has = (slug) => {
        if (!user) return false;
        if (isSuper) return true;
        if (!slug) return true; // empty string => no gate
        return perms.includes(slug);
    };

    has.any = (slugs) => {
        if (!user) return false;
        if (isSuper) return true;
        if (!slugs || slugs.length === 0) return true;
        return slugs.some((s) => perms.includes(s));
    };

    has.all = (slugs) => {
        if (!user) return false;
        if (isSuper) return true;
        if (!slugs || slugs.length === 0) return true;
        return slugs.every((s) => perms.includes(s));
    };

    return has;
}
