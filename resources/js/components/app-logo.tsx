import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <AppLogoIcon className="size-9 shrink-0" />
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
