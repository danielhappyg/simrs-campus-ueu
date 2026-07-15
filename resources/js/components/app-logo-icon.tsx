import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 48 48"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
        >
            <path
                d="M24 5.5a18.5 18.5 0 1 0 17.52 24.48h-7.08A12 12 0 1 1 24 12V5.5Z"
                fill="#0070B8"
            />
            <path
                d="M29 5.5h6.5V12H42v6.5h-6.5V25H29v-6.5h-6.5V12H29V5.5Z"
                fill="#F05828"
            />
            <circle cx="24" cy="30" r="3.25" fill="#0070B8" />
        </svg>
    );
}
