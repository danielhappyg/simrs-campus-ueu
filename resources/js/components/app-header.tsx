import { Link } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { useState, type ReactNode } from 'react';
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
    muted = false,
}: {
    href: string;
    children: ReactNode;
    onNavigate?: () => void;
    muted?: boolean;
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
                    : muted
                      ? 'text-sky-100/55 hover:bg-white/10 hover:text-sky-100/90'
                      : 'text-sky-100/90 hover:bg-white/10 hover:text-white',
            )}
        >
            {children}
        </Link>
    );
}

function ModuleNavLabel({
    label,
    live,
}: {
    label: string;
    live: boolean;
}) {
    return (
        <span className="inline-flex items-center gap-1.5">
            {label}
            {!live ? (
                <span className="rounded bg-white/10 px-1 py-0.5 text-[10px] font-semibold tracking-wide text-sky-100/70 uppercase">
                    Soon
                </span>
            ) : null}
        </span>
    );
}

export function AppHeader() {
    const [mobileOpen, setMobileOpen] = useState(false);
    const closeMobile = () => setMobileOpen(false);

    return (
        <header className="sticky top-0 z-40 border-b border-[#1b4a73] bg-[#0d2b4a] text-white shadow-sm">
            <div className="flex h-14 items-center gap-3 px-3 md:px-4 lg:px-5">
                <div className="flex shrink-0 items-center gap-2">
                    <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
                        <SheetTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="text-white hover:bg-white/10 hover:text-white lg:hidden"
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
                                    Lainnya masih penanda tempat.
                                </SheetDescription>
                            </SheetHeader>
                            <nav
                                aria-label="Navigasi modul"
                                className="flex flex-col gap-1 p-3"
                            >
                                <NavLink href="/" onNavigate={closeMobile}>
                                    Beranda
                                </NavLink>
                                {SIMRS_MODULE_CATEGORIES.map((category) => {
                                    const live = isLiveModule(category.slug);

                                    return (
                                        <NavLink
                                            key={category.slug}
                                            href={moduleHref(category.slug)}
                                            onNavigate={closeMobile}
                                            muted={!live}
                                        >
                                            <ModuleNavLabel
                                                label={category.label}
                                                live={live}
                                            />
                                        </NavLink>
                                    );
                                })}
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
                    {SIMRS_MODULE_CATEGORIES.map((category) => {
                        const live = isLiveModule(category.slug);

                        return (
                            <NavLink
                                key={category.slug}
                                href={moduleHref(category.slug)}
                                muted={!live}
                            >
                                <ModuleNavLabel
                                    label={category.label}
                                    live={live}
                                />
                            </NavLink>
                        );
                    })}
                </nav>

                <div className="ml-auto shrink-0">
                    <AppUserMenu />
                </div>
            </div>
        </header>
    );
}
