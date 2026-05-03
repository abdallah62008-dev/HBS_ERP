/**
 * Coloured pill for enum values. Pass a `tone` to override auto-detection.
 */
const TONES = {
    success: 'bg-green-100 text-green-700 ring-green-200',
    warning: 'bg-amber-100 text-amber-800 ring-amber-200',
    danger: 'bg-red-100 text-red-700 ring-red-200',
    info: 'bg-blue-100 text-blue-700 ring-blue-200',
    neutral: 'bg-slate-100 text-slate-700 ring-slate-200',
    indigo: 'bg-indigo-100 text-indigo-700 ring-indigo-200',
};

const AUTO = {
    // Order statuses
    'New': 'info',
    'Pending Confirmation': 'warning',
    'Confirmed': 'indigo',
    'Ready to Pack': 'indigo',
    'Packed': 'indigo',
    'Ready to Ship': 'indigo',
    'Shipped': 'indigo',
    'Out for Delivery': 'indigo',
    'Delivered': 'success',
    'Returned': 'danger',
    'Cancelled': 'neutral',
    'On Hold': 'warning',
    'Need Review': 'warning',
    // Risk levels
    'Low': 'success',
    'Medium': 'warning',
    'High': 'danger',
    // Customer types
    'VIP': 'indigo',
    'Watchlist': 'warning',
    'Blacklist': 'danger',
    'Normal': 'neutral',
    // Generic
    'Active': 'success',
    'Inactive': 'neutral',
    'Out of Stock': 'warning',
    'Discontinued': 'neutral',
};

export default function StatusBadge({ value, tone, className = '' }) {
    const t = tone || AUTO[value] || 'neutral';
    return (
        <span
            className={
                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ' +
                TONES[t] +
                ' ' +
                className
            }
        >
            {value}
        </span>
    );
}
