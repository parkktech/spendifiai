import { ButtonHTMLAttributes } from 'react';

export default function SecondaryButton({
    type = 'button',
    className = '',
    disabled,
    children,
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement>) {
    return (
        <button
            {...props}
            type={type}
            className={
                `inline-flex items-center rounded-lg border border-sw-border bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-sw-text-secondary shadow-sm transition duration-150 ease-in-out hover:bg-sw-card-hover focus:outline-none focus:ring-2 focus:ring-sw-accent focus:ring-offset-2 disabled:opacity-25 ${
                    disabled && 'opacity-25'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
