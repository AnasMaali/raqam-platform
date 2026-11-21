import { test, expect } from '../support/fixtures';
import { panel } from '../support/fixtures';

/*
 * What a stranger learns from the back end's answers (QA-8, the outside scan): no answer names the language or its
 * version (X-Powered-By: PHP/8.x tells an attacker which flaws to try), whatever PHP's own setting. The server here
 * runs with expose_php on, as PHP does without its production ini.
 */
test('no answer of the back end says it runs PHP, nor which version', async ({ request, app }) => {
    const answers = [
        ['the API', await request.get(`${app.url}/api/v1/posts`)],
        ['an API error', await request.get(`${app.url}/api/v1/posts/999999999`)],
        ['a missing page', await request.get(`${app.url}/no-such-page`)],
        ['the sign-in page', await request.get(`${app.url}${panel('/login')}`)],
        ['the panel, signed out', await request.get(`${app.url}${panel()}`, { maxRedirects: 0 })],
        ['the container check', await request.get(`${app.url}/livez`)],
    ] as const;
    expect(answers.map(([, a]) => a.status())).toEqual([200, 404, 404, 200, 302, 200]);
    for (const [name, answer] of answers) {
        expect(answer.headers()['x-powered-by'], name).toBeUndefined();
        expect(answer.headers()['x-content-type-options'], name).toBe('nosniff');
    }
});
