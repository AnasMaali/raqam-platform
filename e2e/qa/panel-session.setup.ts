import { test } from '@playwright/test';

import { signIn } from './panel-session';

test('the QA admin is signed in to the panel', async () => {
    test.setTimeout(60_000);
    await signIn();
});
