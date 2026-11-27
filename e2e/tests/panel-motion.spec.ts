import { test, expect, panel, signIn } from '../support/fixtures';
import type { Page } from '@playwright/test';

// The panel's motion as the browser computes it (DESIGN.md, "Motion"; panel phase 4): Filament's dialogs, side panels,
// notifications and side menu tuned to 200 ms in and 150 ms out with no bounce; a button gives under the finger; with
// reduced motion everything is near-instant.

/** The computed motion of an element built with Filament's own classes, as Filament's views put them on. */
const motion = (page: Page, html: string) =>
    page.evaluate((markup) => {
        const host = document.createElement('div');
        host.innerHTML = markup;
        document.body.appendChild(host);
        const s = getComputedStyle(host.querySelector('.probe')!);
        const out = { duration: s.transitionDuration, easing: s.transitionTimingFunction, property: s.transitionProperty, scale: s.scale, translate: s.translate };
        host.remove();
        return out;
    }, html);

const MODAL = (state: string) => `<div class="fi-modal"><div class="fi-modal-window-ctn"><div class="fi-modal-window ${state} probe"></div></div></div>`;
const SLIDE = (state: string) => `<div class="fi-modal fi-modal-slide-over fi-modal-slide-over-from-end"><div class="fi-modal-window-ctn"><div class="fi-modal-window ${state} probe"></div></div></div>`;

test('motion as designed: dialogs in 200 ms easing out and out in 150 ms, side panels a little longer, only opacity, scale and translate', async ({ page, app, adminPassword }) => {
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await signIn(page, app, adminPassword);
    await page.goto(panel('/posts'));

    const enter = await motion(page, MODAL('fi-transition-enter fi-transition-enter-start'));
    expect(enter).toMatchObject({ duration: '0.2s', easing: 'cubic-bezier(0.23, 1, 0.32, 1)', property: 'opacity, scale, translate', scale: '0.95' });
    expect(await motion(page, MODAL('fi-transition-leave fi-transition-leave-end'))).toMatchObject({ duration: '0.15s', property: 'opacity, scale, translate' });
    expect((await motion(page, SLIDE('fi-transition-enter fi-transition-enter-start'))).duration).toBe('0.25s');
    expect((await motion(page, SLIDE('fi-transition-leave fi-transition-leave-end'))).duration).toBe('0.18s');
    expect((await motion(page, '<div class="fi-modal-close-overlay probe" style="transition-duration: 300ms"></div>')).duration).toBe('0.2s');
    expect(await motion(page, '<div class="fi-no-notification fi-transition-enter-start probe"></div>')).toMatchObject({ duration: '0.2s', property: 'opacity, translate, scale', translate: '0px -8px' });
    expect(await motion(page, '<div class="fi-sidebar probe"></div>')).toMatchObject({ duration: '0.25s', property: 'translate' });

    // A button gives under the finger (only its size moves with the press; its colours keep their quick change).
    const button = page.getByRole('link', { name: 'مادة جديدة' });
    const box = (await button.boundingBox())!;
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    await expect.poll(() => button.evaluate((e) => getComputedStyle(e).scale)).toBe('0.97');
    await page.mouse.move(0, 0);
    await page.mouse.up();
    await expect.poll(() => button.evaluate((e) => getComputedStyle(e).scale)).toBe('none');
});

test('with reduced motion nothing takes time: dialogs, panels, notifications, the side menu and the press', async ({ page, app, adminPassword }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await signIn(page, app, adminPassword);
    await page.goto(panel('/posts'));

    for (const html of [MODAL('fi-transition-enter fi-transition-enter-start'), MODAL('fi-transition-leave fi-transition-leave-end'),
        SLIDE('fi-transition-enter fi-transition-enter-start'), '<div class="fi-modal-close-overlay probe" style="transition-duration: 300ms"></div>',
        '<div class="fi-no-notification fi-transition-enter-start probe"></div>', '<div class="fi-sidebar probe"></div>',
        '<div class="raqam-session-dialog probe"></div>', '<div class="raqam-restore probe"></div>']) {
        const m = await motion(page, html);
        // Whatever a state's position, it is reached at once.
        expect(Number.parseFloat(m.duration), html).toBeLessThan(0.001);
    }

    const button = page.getByRole('link', { name: 'مادة جديدة' });
    const box = (await button.boundingBox())!;
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    expect(await button.evaluate((e) => getComputedStyle(e).scale)).toBe('none');
    await page.mouse.move(0, 0);
    await page.mouse.up();
});
