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
                'rounded border-sw-border text-sw-accent shadow-sm focus:ring-sw-accent ' +
                className
            }
        />
    );
}
