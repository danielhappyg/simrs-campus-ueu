import { Link, usePage } from '@inertiajs/react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage().props;

    return (
        <div className="relative flex min-h-svh flex-col bg-background">
            <main className="mx-auto grid w-full max-w-6xl flex-1 items-stretch lg:grid-cols-[1.05fr_0.95fr] lg:p-8">
                <section className="relative hidden overflow-hidden rounded-l-xl bg-[#063650] p-12 text-white lg:flex lg:flex-col lg:justify-center">
                    <Link
                        href={home()}
                        className="inline-flex max-w-sm flex-col items-start gap-4"
                    >
                        <img
                            src="/brand/ueu-wordmark.png"
                            alt="Universitas Esa Unggul"
                            decoding="async"
                            className="h-24 w-auto max-w-[16rem] object-contain object-left"
                        />
                        <div className="space-y-1">
                            <p className="font-display text-xl font-semibold tracking-wide">
                                {name}
                            </p>
                            <p className="text-sm text-sky-100">
                                Hospital Information Management System
                            </p>
                        </div>
                    </Link>
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
