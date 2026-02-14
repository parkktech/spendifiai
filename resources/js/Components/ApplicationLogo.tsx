import { HTMLAttributes } from 'react';

/**
 * SpendifiAI logo — ascending bar chart on a curved ledger base
 * with an AI sparkle on the tallest bar.
 * Design inspired by graphics/spendifiai-icon.png, adapted to blue accent palette.
 */

interface LogoIconProps {
    size?: number;
    id?: string;
}

function LogoIcon({ size = 36, id = 'logo-gradient' }: LogoIconProps) {
    return (
        <svg
            viewBox="0 0 40 40"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            style={{ width: size, height: size }}
            className="shrink-0"
        >
            <rect width="40" height="40" rx="10" fill={`url(#${id})`} />

            {/* Curved ledger base */}
            <path
                d="M8 28.5 Q20 31 32 28.5"
                stroke="white"
                strokeWidth="2.2"
                strokeLinecap="round"
                fill="none"
            />

            {/* Bar 1 — short */}
            <rect x="12.5" y="22" width="3.5" height="6.5" rx="1.5" fill="white" fillOpacity="0.55" />
            {/* Bar 2 — medium */}
            <rect x="18" y="17" width="3.5" height="11.5" rx="1.5" fill="white" fillOpacity="0.75" />
            {/* Bar 3 — tall */}
            <rect x="23.5" y="12" width="3.5" height="16.5" rx="1.5" fill="white" />

            {/* AI sparkle/node on tallest bar */}
            <circle cx="25.25" cy="9.5" r="1.6" fill="white" fillOpacity="0.9" />
            <line x1="25.25" y1="6" x2="25.25" y2="7.5" stroke="white" strokeWidth="1" strokeLinecap="round" opacity="0.7" />
            <line x1="22.5" y1="9.5" x2="23.2" y2="9.5" stroke="white" strokeWidth="1" strokeLinecap="round" opacity="0.7" />
            <line x1="27.3" y1="9.5" x2="28" y2="9.5" stroke="white" strokeWidth="1" strokeLinecap="round" opacity="0.7" />

            <defs>
                <linearGradient id={id} x1="0" y1="0" x2="40" y2="40" gradientUnits="userSpaceOnUse">
                    <stop stopColor="#2563eb" />
                    <stop offset="1" stopColor="#7c3aed" />
                </linearGradient>
            </defs>
        </svg>
    );
}

export { LogoIcon };

export default function ApplicationLogo({
    className = '',
    showText = true,
    showTagline = false,
    iconSize = 36,
    gradientId = 'app-logo-gradient',
    ...props
}: HTMLAttributes<HTMLDivElement> & {
    showText?: boolean;
    showTagline?: boolean;
    iconSize?: number;
    gradientId?: string;
}) {
    return (
        <div className={`flex items-center gap-2.5 ${className}`} {...props}>
            <LogoIcon size={iconSize} id={gradientId} />
            {showText && (
                <div>
                    <span className="text-xl font-bold tracking-tight text-sw-text">
                        Spendifi<span className="text-sw-accent">AI</span>
                    </span>
                    {showTagline && (
                        <div className="text-[10px] text-sw-dim font-medium tracking-wide">
                            AI-Powered Financial Intelligence
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
