import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-9 shrink-0 items-center justify-center rounded-md bg-white p-1 shadow-sm">
                <AppLogoIcon className="size-7" />
            </div>
            <div className="ml-1 grid min-w-0 flex-1 text-left">
                <span className="truncate font-display text-[0.92rem] leading-tight font-bold tracking-wide text-white">
                    SIMRS Campus UEU
                </span>
                <span className="truncate text-[0.64rem] leading-tight text-sky-100">
                    Universitas Esa Unggul
                </span>
            </div>
        </>
    );
}
