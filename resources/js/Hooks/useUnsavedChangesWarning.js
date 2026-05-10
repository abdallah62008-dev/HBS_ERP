import { useEffect } from 'react';
import { router } from '@inertiajs/react';

const DEFAULT_MESSAGE = 'You have unsaved changes. Are you sure you want to leave?';

/**
 * Warn the user before leaving a page with unsaved form changes.
 *
 * Wires two listeners:
 *
 *   1. `window.beforeunload` — covers tab close, browser refresh, URL-bar
 *      navigation, and any external link click. The browser shows its
 *      native confirmation prompt (the `event.returnValue` text is
 *      ignored by modern browsers, but assigning it is required to opt
 *      in to the prompt).
 *
 *   2. Inertia's `router.on('before')` — covers internal Inertia `<Link>`
 *      navigation, sidebar clicks, and programmatic `router.visit()` /
 *      back-button. We use `confirm()` so the user gets the same wording
 *      whether they hit a `<Link>` or refresh the tab.
 *
 * Form submissions are NOT blocked. The hook detects POST/PUT/PATCH/DELETE
 * Inertia visits, marks "we are submitting", and lets both the submit
 * itself AND the immediately-following success redirect (typically a
 * 302 → GET) pass through without prompting. This avoids the post-save
 * "are you sure you want to leave?" annoyance after a successful save.
 *
 * Pass the form's `isDirty` flag from Inertia's `useForm`:
 *
 *   const form = useForm({ name: '' })
 *   useUnsavedChangesWarning(form.isDirty)
 *
 * The hook is a no-op when `isDirty` is false; cleanup runs automatically
 * when the form becomes clean again or the component unmounts.
 *
 * @param {boolean} isDirty — true when the form has unsaved changes
 * @param {string}  [message] — optional custom prompt text
 */
export default function useUnsavedChangesWarning(isDirty, message = DEFAULT_MESSAGE) {
    useEffect(() => {
        if (! isDirty) return undefined;

        // Closed-over flag set when an Inertia non-GET visit fires (the form's
        // own submit). It tells the next GET visit (the success redirect) to
        // pass through silently. Reset on the first GET that follows.
        let submitting = false;

        const beforeUnloadHandler = (event) => {
            if (submitting) return undefined;
            // Modern browsers show their own generic message; we set
            // returnValue purely to opt in to the prompt.
            event.preventDefault();
            event.returnValue = message;
            return message;
        };

        const removeRouterListener = router.on('before', (event) => {
            const method = (event?.detail?.visit?.method ?? '').toString().toLowerCase();

            // Form submission (POST/PUT/PATCH/DELETE) — let it through and
            // remember so the success redirect doesn't double-prompt.
            if (method && method !== 'get') {
                submitting = true;
                return true;
            }

            // GET visit immediately after a submit — allow the redirect to
            // proceed, then re-arm the guard for any subsequent GET.
            if (submitting) {
                submitting = false;
                return true;
            }

            // Genuine navigation while the form is dirty — ask the user.
            if (! window.confirm(message)) {
                event.preventDefault();
                return false;
            }
            return true;
        });

        window.addEventListener('beforeunload', beforeUnloadHandler);

        return () => {
            window.removeEventListener('beforeunload', beforeUnloadHandler);
            removeRouterListener();
        };
    }, [isDirty, message]);
}
