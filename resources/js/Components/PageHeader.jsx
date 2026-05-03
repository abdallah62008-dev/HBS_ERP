/**
 * Page header with optional right-side action slot. Used by every list /
 * detail / form page so titles + buttons line up consistently.
 */
export default function PageHeader({ title, subtitle, actions }) {
    return (
        <div className="mb-5 flex items-start justify-between gap-3">
            <div>
                <h1 className="text-xl font-semibold text-slate-800">{title}</h1>
                {subtitle && <p className="mt-0.5 text-sm text-slate-500">{subtitle}</p>}
            </div>
            {actions && <div className="flex items-center gap-2">{actions}</div>}
        </div>
    );
}
