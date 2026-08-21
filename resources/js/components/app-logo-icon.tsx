import type { ImgHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

type AppLogoIconProps = ImgHTMLAttributes<HTMLImageElement>;

export default function AppLogoIcon({
    className,
    alt = '',
    ...props
}: AppLogoIconProps) {
    return (
        <img
            src="/brand/simrs-campus-mark.png"
            alt={alt}
            decoding="async"
            className={cn('object-contain', className)}
            {...props}
        />
    );
}
