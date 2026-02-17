import {
    forwardRef,
    InputHTMLAttributes,
    useEffect,
    useImperativeHandle,
    useRef,
} from 'react';

export default forwardRef(function TextInput(
    {
        type = 'text',
        className = '',
        isFocused = false,
        ...props
    }: InputHTMLAttributes<HTMLInputElement> & { isFocused?: boolean },
    ref,
) {
    const localRef = useRef<HTMLInputElement>(null);

    useImperativeHandle(ref, () => ({
        focus: () => localRef.current?.focus(),
    }));

    useEffect(() => {
        if (isFocused) {
            localRef.current?.focus();
        }
    }, [isFocused]);

    return (
        <input
            {...props}
            type={type}
            className={
                'rounded-lg border border-sw-border bg-white px-3.5 py-2.5 text-sm text-sw-text placeholder:text-sw-placeholder shadow-sm transition-all duration-150 focus:border-sw-accent focus:ring-2 focus:ring-sw-accent/20 focus:outline-none hover:border-sw-border-strong ' +
                className
            }
            ref={localRef}
        />
    );
});
