import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';

/**
 * Wraps label + input + error message so each field is a single component.
 * Pass either `children` (custom input) or use as a regular text input.
 */
export default function FormField({
    label,
    name,
    type = 'text',
    value,
    onChange,
    error,
    required = false,
    placeholder,
    autoComplete,
    children,
    hint,
    className = '',
}) {
    return (
        <div className={className}>
            {label && (
                <InputLabel htmlFor={name} value={label + (required ? ' *' : '')} />
            )}
            {children ? (
                children
            ) : (
                <input
                    id={name}
                    name={name}
                    type={type}
                    value={value ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder={placeholder}
                    autoComplete={autoComplete}
                    className="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                />
            )}
            {hint && !error && (
                <p className="mt-1 text-xs text-slate-500">{hint}</p>
            )}
            <InputError message={error} className="mt-1" />
        </div>
    );
}
