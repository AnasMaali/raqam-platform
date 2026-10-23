import type { Page } from '@playwright/test';
import type { App } from './app';

/** The messages written to the mail log (MAIL_MAILER=log), with their subject and bodies decoded. */
export type LoggedMail = { to: string; subject: string; text: string; html: string };

function decodeQuotedPrintable(s: string): string {
    const bytes: number[] = [];
    const clean = s.replace(/=\r?\n/g, '');
    for (let i = 0; i < clean.length; i++) {
        if (clean[i] === '=' && /^[0-9A-F]{2}$/i.test(clean.slice(i + 1, i + 3))) {
            bytes.push(parseInt(clean.slice(i + 1, i + 3), 16));
            i += 2;
        } else {
            bytes.push(...Buffer.from(clean[i], 'utf8'));
        }
    }
    return Buffer.from(bytes).toString('utf8');
}

function decodeHeader(value: string): string {
    return value.replace(/\r?\n[ \t]+/g, ' ').replace(/=\?utf-8\?([QB])\?([^?]*)\?=\s*/gi, (_, enc: string, text: string) =>
        enc.toUpperCase() === 'B' ? Buffer.from(text, 'base64').toString('utf8') : decodeQuotedPrintable(text.replace(/_/g, ' ')));
}

function part(message: string, type: string): string {
    const m = message.match(new RegExp(`Content-Type: ${type}[^\\n]*\\r?\\n(?:Content-Transfer-Encoding: ([\\w-]+)\\r?\\n)?\\r?\\n([\\s\\S]*?)(?=\\r?\\n--|$)`, 'i'));
    if (!m) return '';
    return (m[1] ?? '').toLowerCase() === 'quoted-printable' ? decodeQuotedPrintable(m[2]) : m[2];
}

export function mails(app: App): LoggedMail[] {
    return app.log().split(/\[\d{4}-\d\d-\d\d[ T][^\]]*\] \w+\.DEBUG: /).filter((m) => /^From: /m.test(m) && /^Subject: /m.test(m)).map((m) => {
        const header = (name: string) => decodeHeader((m.match(new RegExp(`^${name}: ([^\\r\\n]*(?:\\r?\\n[ \\t][^\\r\\n]*)*)`, 'm')) ?? [])[1] ?? '');
        return { to: header('To'), subject: header('Subject'), text: part(m, 'text/plain'), html: part(m, 'text/html') };
    });
}

export function mailsTo(app: App, address: string): LoggedMail[] {
    return mails(app).filter((m) => m.to.toLowerCase().includes(address.toLowerCase()));
}

/** The 6-digit code of the newest code mail to this address. */
export async function codeFor(app: App, address: string): Promise<string> {
    for (let attempt = 0; attempt < 100; attempt++) {
        const found = mailsTo(app, address).map((m) => m.html.match(/letter-spacing:8px[^>]*>\s*(\d{6})\s*</)?.[1]).filter(Boolean);
        if (found.length) return found[found.length - 1]!;
        await new Promise((r) => setTimeout(r, 100));
    }
    throw new Error(`No code mail to ${address}.`);
}

/** A call of the website's own server to the API: the site token, the visitor's address and browser. */
export function fromSite(ip = '203.0.113.7') {
    return { 'X-Raqam-Site-Token': 'e2e-site-token', 'X-Raqam-Visitor-Ip': ip, 'X-Raqam-Visitor-Ua': 'Mozilla/5.0 (X11; Linux x86_64) Chrome/129.0' };
}

export async function sitePost(page: Page, app: App, path: string, data: unknown, ip?: string) {
    return page.request.post(`${app.url}/api/v1${path}`, { headers: fromSite(ip), data });
}
