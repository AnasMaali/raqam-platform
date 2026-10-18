import { test, expect, submitPassword, submitCode } from '../support/fixtures';
import { ADMIN_EMAIL } from '../support/app';
test.use({ appOptions: { env: { APP_DEBUG: 'true' } } });
test('peek', async ({ page, app, adminPassword, context }) => {
    page.on('request', (q) => { if (q.method()==='POST') console.log('REQ', (q.postData()||'').slice(0,0), (q.postData()||'').match(/"(method|name)":"[^"]*"/g)); });
    page.on('response', async (r) => { if (r.status()===419) console.log((await r.text()).slice(0,1500)); console.log(r.status(), r.request().method(), r.url().replace(app.url, ''), JSON.stringify(await r.headersArray().then(h=>h.filter(x=>/set-cookie/i.test(x.name)).map(x=>x.value.slice(0,60))))); });
    await submitPassword(page, ADMIN_EMAIL, adminPassword);
    console.log(JSON.stringify((await context.cookies()).map(c=>c.name)));
    await submitCode(page, app.lastCode());
    await page.waitForTimeout(2000); console.log(app.log().split('\n').filter(l=>/EXC|ESL/.test(l)).join('\n'));
    console.log(app.sql("select failure_reason, count(*) from login_attempts group by 1"));
});
