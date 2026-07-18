import type { PendingVisit } from '@inertiajs/core';
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { UnsavedChangesGuard } from '@/components/unsaved-changes-guard';

const inertia = vi.hoisted(() => ({
    on: vi.fn(),
    visit: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: inertia,
}));

type BeforeHandler = (event: {
    detail: { visit: PendingVisit };
}) => boolean | void;

function pendingVisit(overrides: Partial<PendingVisit> = {}): PendingVisit {
    return {
        id: 'visit-1',
        url: new URL('http://localhost/work'),
        method: 'get' as const,
        data: {},
        replace: false,
        preserveScroll: false,
        preserveState: false,
        only: [],
        except: [],
        headers: {},
        errorBag: null,
        forceFormData: false,
        queryStringArrayFormat: 'brackets' as const,
        async: false,
        showProgress: true,
        prefetch: false,
        fresh: false,
        reset: [],
        preserveUrl: false,
        preserveErrors: false,
        invalidateCacheTags: [],
        viewTransition: false,
        optimistic: undefined,
        component: null,
        pageProps: null,
        cached: false,
        completed: false,
        cancelled: false,
        interrupted: false,
        ...overrides,
    } as PendingVisit;
}

function installBeforeListener() {
    let beforeHandler: BeforeHandler | undefined;
    const removeListener = vi.fn();

    inertia.on.mockImplementation((eventName, handler) => {
        if (eventName === 'before') {
            beforeHandler = handler as BeforeHandler;
        }

        return removeListener;
    });

    return {
        get handler() {
            if (!beforeHandler) {
                throw new Error(
                    'The Inertia before listener was not installed.',
                );
            }

            return beforeHandler;
        },
        removeListener,
    };
}

describe('UnsavedChangesGuard', () => {
    beforeEach(() => {
        inertia.on.mockReset();
        inertia.visit.mockReset();
    });

    it('announces dirty state and presents three accessible, explicit choices', async () => {
        const listener = installBeforeListener();
        const user = userEvent.setup();
        const { container } = render(
            <UnsavedChangesGuard
                formLabel="asesmen medis"
                processing={false}
                onSaveDraft={vi.fn()}
            />,
        );

        expect(screen.getByRole('status')).toHaveTextContent(
            'Perubahan belum disimpan',
        );
        act(() => {
            expect(
                listener.handler({ detail: { visit: pendingVisit() } }),
            ).toBe(false);
        });

        const dialog = screen.getByRole('dialog', {
            name: 'Perubahan belum disimpan',
        });

        expect(dialog).toBeInTheDocument();
        expect(within(dialog).getAllByRole('button')).toHaveLength(3);
        expect(
            within(dialog).queryByRole('button', { name: 'Close' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Tetap di halaman' }),
        ).toBeEnabled();
        expect(
            screen.getByRole('button', {
                name: 'Simpan draf lalu keluar',
            }),
        ).toBeEnabled();
        expect(
            screen.getByRole('button', {
                name: 'Keluar tanpa perubahan lokal',
            }),
        ).toBeEnabled();

        const result = await axe.run(container);
        expect(result.violations).toHaveLength(0);

        await user.click(
            screen.getByRole('button', { name: 'Tetap di halaman' }),
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(inertia.visit).not.toHaveBeenCalled();

        act(() => {
            listener.handler({ detail: { visit: pendingVisit() } });
        });
        await user.keyboard('{Escape}');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(inertia.visit).not.toHaveBeenCalled();
    });

    it('saves a draft before replaying the deferred navigation', async () => {
        const listener = installBeforeListener();
        const user = userEvent.setup();
        let continueAfterSave: (() => void) | undefined;
        const onSaveDraft = vi.fn((continueNavigation: () => void) => {
            continueAfterSave = continueNavigation;
        });

        render(
            <UnsavedChangesGuard
                formLabel="asesmen awal keperawatan"
                processing={false}
                onSaveDraft={onSaveDraft}
            />,
        );
        act(() => {
            listener.handler({ detail: { visit: pendingVisit() } });
        });
        await user.click(
            screen.getByRole('button', {
                name: 'Simpan draf lalu keluar',
            }),
        );

        expect(onSaveDraft).toHaveBeenCalledOnce();
        expect(inertia.visit).not.toHaveBeenCalled();
        act(() => continueAfterSave?.());
        expect(inertia.visit).toHaveBeenCalledWith(
            new URL('http://localhost/work'),
            expect.objectContaining({ method: 'get', data: {} }),
        );
    });

    it('requires an explicit discard and ignores submissions or prefetches', async () => {
        const listener = installBeforeListener();
        const user = userEvent.setup();

        render(
            <UnsavedChangesGuard
                formLabel="penutupan encounter"
                processing={false}
                onSaveDraft={vi.fn()}
            />,
        );

        act(() => {
            expect(
                listener.handler({
                    detail: {
                        visit: pendingVisit({ method: 'post' }),
                    },
                }),
            ).toBeUndefined();
            expect(
                listener.handler({
                    detail: {
                        visit: pendingVisit({ prefetch: true }),
                    },
                }),
            ).toBeUndefined();
            listener.handler({ detail: { visit: pendingVisit() } });
        });

        await user.click(
            screen.getByRole('button', {
                name: 'Keluar tanpa perubahan lokal',
            }),
        );
        expect(inertia.visit).toHaveBeenCalledOnce();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('uses the native unload boundary only while the dirty guard is mounted', () => {
        const listener = installBeforeListener();
        const { unmount } = render(
            <UnsavedChangesGuard
                formLabel="asesmen medis"
                processing={false}
                onSaveDraft={vi.fn()}
            />,
        );
        const dirtyUnload = new Event('beforeunload', {
            cancelable: true,
        });

        window.dispatchEvent(dirtyUnload);
        expect(dirtyUnload.defaultPrevented).toBe(true);

        unmount();
        const cleanUnload = new Event('beforeunload', {
            cancelable: true,
        });
        window.dispatchEvent(cleanUnload);
        expect(cleanUnload.defaultPrevented).toBe(false);

        expect(listener.removeListener).toHaveBeenCalled();
    });
});
