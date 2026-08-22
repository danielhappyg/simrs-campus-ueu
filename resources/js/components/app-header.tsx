import { Link } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { useState  } from 'react';
import type {ReactNode} from 'react';
import AppLogo from '@/components/app-logo';
import { AppUserMenu } from '@/components/app-user-menu';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useCurrentUrl } from '@/hooks/use-current-url';
import {
    isLiveModule,
    moduleHref,
    SIMRS_MODULE_CATEGORIES,
} from '@/lib/simrs-modules';
import { cn } from '@/lib/utils';

function NavLink({
    href,
    children,
    onNavigate,
}: {
    href: string;
    children: ReactNode;
    onNavigate?: () => void;
}) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const active = isCurrentOrParentUrl(href);

    return (
        <Link
            href={href}
            prefetch
            onClick={onNavigate}
            aria-current={active ? 'page' : undefined}
            className={cn(
                'rounded-md px-2.5 py-1.5 text-sm font-medium whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-white/80 focus-visible:outline-none',
                active
                    ? 'bg-white/15 text-white'
                    : 'text-sky-100/90 hover:bg-white/10 hover:text-white',
            )}
        >
            {children}
        </Link>
    );
}

function SoonModuleLabel({ label }: { label: string }) {
    return (
        <span
            title="Modul berikutnya — belum aktif di demo"
            className="inline-flex cursor-default items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium whitespace-nowrap text-sky-100/45"
            aria-disabled="true"
        >
            {label}
            <span
                className="text-[0.6875rem] font-medium tracking-wide text-sky-100/40"
                aria-label="Belum tersedia"
            >
                Soon
            </span>
        </span>
    );
}

export function AppHeader() {
    const [mobileOpen, setMobileOpen] = useState(false);
    const closeMobile = () => setMobileOpen(false);

    const liveModules = SIMRS_MODULE_CATEGORIES.filter((category) =>
        isLiveModule(category.slug),
    );
    const soonModules = SIMRS_MODULE_CATEGORIES.filter(
        (category) => !isLiveModule(category.slug),
    );

    return (
        <header className="sticky top-0 z-40 border-b border-[#1b4a73] bg-[#0d2b4a] text-white">
            <div className="flex min-h-16 items-center gap-3 px-3 py-2.5 md:px-4 lg:px-5">
                <div className="flex shrink-0 items-center gap-2">
                    <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
                        <SheetTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="text-white hover:bg-white/10 hover:text-white focus-visible:ring-2 focus-visible:ring-white/80 lg:hidden"
                                aria-label="Buka menu navigasi"
                            >
                                <Menu className="size-5" />
                            </Button>
                        </SheetTrigger>
                        <SheetContent
                            side="left"
                            className="w-[min(100%,20rem)] border-[#1b4a73] bg-[#0d2b4a] p-0 text-white"
                        >
                            <SheetHeader className="border-b border-[#1b4a73] px-4 py-4 text-left">
                                <SheetTitle className="text-white">
                                    Navigasi utama
                                </SheetTitle>
                                <SheetDescription className="text-sky-100/80">
                                    Modul aktif: Pendaftaran, Pemeriksaan, RM.
                                    Lainnya ditandai Soon (belum bisa dibuka).
                                </SheetDescription>
                            </SheetHeader>
                            <nav
                                aria-label="Navigasi modul"
                                className="flex flex-col gap-1 p-3"
                            >
                                <NavLink href="/" onNavigate={closeMobile}>
                                    Beranda
                                </NavLink>
                                {liveModules.map((category) => (
                                    <NavLink
                                        key={category.slug}
                                        href={moduleHref(category.slug)}
                                        onNavigate={closeMobile}
                                    >
                                        {category.label}
                                    </NavLink>
                                ))}
                                <p className="mt-3 mb-1 px-2.5 text-[0.6875rem] font-medium tracking-wide text-sky-100/40 uppercase">
                                    Modul berikutnya
                                </p>
                                {soonModules.map((category) => (
                                    <SoonModuleLabel
                                        key={category.slug}
                                        label={category.label}
                                    />
                                ))}
                            </nav>
                        </SheetContent>
                    </Sheet>

                    <Link
                        href="/"
                        prefetch
                        className="rounded-md focus-visible:ring-2 focus-visible:ring-white/80 focus-visible:outline-none"
                    >
                        <AppLogo />
                    </Link>
                </div>

                <nav
                    aria-label="Navigasi modul"
                    className="hidden min-w-0 flex-1 items-center gap-0.5 overflow-x-auto lg:flex"
                >
                    <NavLink href="/">Beranda</NavLink>
                    {liveModules.map((category) => (
                        <NavLink
                            key={category.slug}
                            href={moduleHref(category.slug)}
                        >
                            {category.label}
                        </NavLink>
                    ))}
                    <span
                        className="mx-1 h-4 w-px shrink-0 bg-white/15"
                        aria-hidden
                    />
                    {soonModules.map((category) => (
                        <SoonModuleLabel
                            key={category.slug}
                            label={category.label}
                        />
                    ))}
                </nav>

                <div className="ml-auto shrink-0">
                    <AppUserMenu />
                </div>
            </div>
        </header>
    );
}
