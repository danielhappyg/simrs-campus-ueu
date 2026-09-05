import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';

type Item = {
    href: string;
    label: string;
    active?: boolean;
};

type Props = {
    items: Item[];
    className?: string;
};

export function CareSettingSubnav({ items, className }: Props) {
    return (
        <nav
            aria-label="Care setting"
            className={cn(
                'flex flex-wrap gap-1 rounded-lg border border-[#e2e8f0] bg-white p-1',
                className,
            )}
        >
            {items.map((item) => (
                <Link
                    key={item.href}
                    href={item.href}
                    prefetch
                    aria-current={item.active ? 'page' : undefined}
                    className={cn(
                        'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                        item.active
                            ? 'bg-[#123b63] text-white'
                            : 'text-[#475569] hover:bg-[#f1f5f9] hover:text-[#0f172a]',
                    )}
                >
                    {item.label}
                </Link>
            ))}
        </nav>
    );
}
