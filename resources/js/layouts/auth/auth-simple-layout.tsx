import { Link, usePage } from '@inertiajs/react';
import { SimulationBanner } from '@/components/simulation-banner';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { environment, name } = usePage().props;

    return (
        <div className="min-h-svh bg-background">
            <SimulationBanner {...environment} compact />
            <main className="mx-auto grid min-h-[calc(100svh-2.5rem)] w-full max-w-6xl items-stretch lg:grid-cols-[1.05fr_0.95fr] lg:p-8">
                <section className="relative hidden overflow-hidden rounded-l-xl bg-[#063650] p-12 text-white lg:flex lg:flex-col lg:justify-between">
                    <div>
                        <Link
                            href={home()}
                            className="inline-flex flex-col items-start gap-3"
                        >
                            <img
                                src="/brand/ueu-wordmark.png"
                                alt="Universitas Esa Unggul"
                                decoding="async"
                                className="h-20 w-auto max-w-[15rem] object-contain object-left"
                            />
                            <p className="font-display text-sm font-semibold tracking-wide text-sky-100">
                                {name}
                            </p>
                        </Link>
                    </div>

                    <div className="relative z-10 max-w-md">
                        <p className="mb-3 text-xs font-bold tracking-[0.14em] text-orange-300 uppercase">
                            Reference teaching environment
                        </p>
                        <p className="font-display text-4xl leading-tight font-semibold">
                            Satu alur klinis. Banyak perspektif pembelajaran.
                        </p>
                        <p className="mt-5 max-w-sm text-sm leading-6 text-sky-100">
                            Ruang simulasi terintegrasi untuk Kedokteran,
                            Keperawatan, RMIK, dan Farmasi. Seluruh identitas
                            dan encounter adalah data sintetis.
                        </p>
                    </div>

                    <div className="relative" aria-hidden="true">
                        <div className="h-px w-full bg-sky-300/50" />
                        <span className="absolute top-1/2 left-[18%] size-3 -translate-y-1/2 rounded-full border-2 border-sky-100 bg-[#063650]" />
                        <span className="absolute top-1/2 left-[48%] size-3 -translate-y-1/2 rounded-full border-2 border-sky-100 bg-[#063650]" />
                        <span className="absolute top-1/2 left-[78%] size-4 -translate-y-1/2 rounded-full border-[3px] border-white bg-signal shadow-[0_0_0_5px_rgb(240_88_40_/_0.18)]" />
                    </div>
                </section>

                <section className="flex items-center justify-center bg-white px-6 py-12 lg:rounded-r-xl lg:border lg:border-l-0 lg:border-border lg:px-14">
                    <div className="w-full max-w-sm">
                        <div className="mb-8 flex flex-col items-start gap-2 lg:hidden">
                            <img
                                src="/brand/ueu-wordmark.png"
                                alt="Universitas Esa Unggul"
                                decoding="async"
                                className="h-14 w-auto max-w-[12rem] object-contain object-left"
                            />
                            <p className="font-display text-sm font-semibold text-foreground">
                                {name}
                            </p>
                        </div>
                        <div className="mb-8 space-y-2">
                            <h1 className="font-display text-2xl font-semibold">
                                {title}
                            </h1>
                            <p className="text-sm leading-6 text-muted-foreground">
                                {description}
                            </p>
                        </div>
                        {children}
                    </div>
                </section>
            </main>
        </div>
    );
}
