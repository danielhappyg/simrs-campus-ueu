import { usePage } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import { cn } from '@/lib/utils';

type Props = {
    className?: string;
};

export function AppUserMenu({ className }: Props) {
    const { auth } = usePage().props;

    if (!auth.user) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                className={cn(
                    'flex items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-white transition-colors outline-none hover:bg-white/10 focus-visible:ring-2 focus-visible:ring-white/80 [&_span]:text-white',
                    className,
                )}
                data-test="header-user-menu"
                aria-label="Menu pengguna"
            >
                <UserInfo user={auth.user} />
                <ChevronsUpDown className="size-4 shrink-0 opacity-80" />
            </DropdownMenuTrigger>
            <DropdownMenuContent
                className="min-w-56 rounded-lg"
                align="end"
                sideOffset={8}
            >
                <UserMenuContent user={auth.user} />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
