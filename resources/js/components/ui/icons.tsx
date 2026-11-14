import type { SVGProps } from 'react';

/*
 * Small stroke icons (24-grid, currentColor) for UI chrome that has no icon library dependency.
 * Decorative by default: pair them with a visible text label or an accessible name on the control.
 */
function base(props: SVGProps<SVGSVGElement>, size: number) {
    return {
        width: size,
        height: size,
        viewBox: '0 0 24 24',
        fill: 'none',
        stroke: 'currentColor',
        strokeWidth: 2,
        strokeLinecap: 'round' as const,
        strokeLinejoin: 'round' as const,
        'aria-hidden': true,
        focusable: false,
        ...props,
    };
}

/** Test tube / flask: the Test Mode symbol. */
export function FlaskConicalIcon({ size = 14, ...props }: SVGProps<SVGSVGElement> & { size?: number }) {
    return (
        <svg {...base(props, size)}>
            <path d="M10 2v7.527a2 2 0 0 1-.211.896L4.72 20.55a1 1 0 0 0 .9 1.45h12.76a1 1 0 0 0 .9-1.45l-5.069-10.127A2 2 0 0 1 14 9.527V2" />
            <path d="M8.5 2h7" />
            <path d="M7 16h10" />
        </svg>
    );
}

/** Circle with an exclamation mark: explains why an action is unavailable. */
export function CircleAlertIcon({ size = 16, ...props }: SVGProps<SVGSVGElement> & { size?: number }) {
    return (
        <svg {...base(props, size)}>
            <circle cx="12" cy="12" r="10" />
            <line x1="12" x2="12" y1="8" y2="12" />
            <line x1="12" x2="12.01" y1="16" y2="16" />
        </svg>
    );
}
