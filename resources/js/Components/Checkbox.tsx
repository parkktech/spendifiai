import { InputHTMLAttributes } from 'react';

export default function Checkbox({
    className = '',
    ...props
}: InputHTMLAttributes<HTMLInputElement>) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'h-4 w-4 rounded border-sw-border text-sw-accent shadow-sm transition-colors focus:ring-2 focus:ring-sw-accent/20 focus:ring-offset-0 hover:border-sw-border-strong ' +
                className
            }
        />
    );
}
