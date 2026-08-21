import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <span className="flex min-w-0 items-center gap-2">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-white p-1 shadow-sm">
                <AppLogoIcon className="size-7" />
            </span>
            <span className="grid min-w-0 text-left leading-none">
                <span className="truncate font-display text-[0.9375rem] font-bold tracking-wide text-white">
                    SIMRS Campus UEU
                </span>
                <span className="mt-0.5 truncate text-[0.6875rem] font-medium text-sky-100/90">
                    Universitas Esa Unggul
                </span>
            </span>
        </span>
    );
}
