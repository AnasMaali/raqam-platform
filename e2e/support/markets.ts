import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';

/** What the stand-in answers; a test changes it between steps. */
export type MarketAnswers = {
    /** Bank of Israel: code => rate text (and unit), published at `boiTime`. `boiStatus` other than 200 answers that status. */
    boi: Record<string, string | [string, string]>;
    boiTime: string;
    boiStatus: number;
    /** OpenWeather: the temperature now (°C) and the condition code; `owStatus` other than 200 answers that status. */
    owTemp: number;
    owCode: number;
    owStatus: number;
};

export type FakeMarkets = {
    /** Values for CURRENCY_BOI_BASE and OPENWEATHER_API_BASE. */
    boiBase: string;
    owBase: string;
    answers: MarketAnswers;
    /** Every request: path and query, so a test can say what was asked (and with which key). */
    requests: string[];
    stop(): Promise<void>;
};

/**
 * A stand-in for the Bank of Israel's public API and OpenWeather's free API, shaped like the real answers
 * (checked 2026-10-21). The application under test never reaches either real service.
 */
export async function startFakeMarkets(): Promise<FakeMarkets> {
    const requests: string[] = [];
    const answers: MarketAnswers = {
        boi: { USD: '3.106', GBP: '4.1444', JPY: ['1.9632', '100'], EUR: '3.4822', CHF: '3.7675', JOD: '4.3812', EGP: '0.057' },
        boiTime: new Date(Date.now() - 26 * 60 * 60 * 1000).toISOString().replace(/\.\d+Z$/, '.6546389Z'),
        boiStatus: 200,
        owTemp: 24.3,
        owCode: 800,
        owStatus: 200,
    };

    const server: Server = createServer((req, res) => {
        const url = new URL(req.url ?? '/', 'http://127.0.0.1');
        requests.push(url.pathname + url.search);
        const json = (status: number, body: string) => res.writeHead(status, { 'Content-Type': 'application/json' }).end(body);

        if (url.pathname === '/boi/PublicApi/GetExchangeRates') {
            if (answers.boiStatus !== 200) return json(answers.boiStatus, '{"message":"unavailable"}');
            // Numbers written as the bank writes them, so they arrive as exact text.
            const items = Object.entries(answers.boi).map(([key, rate]) => {
                const [value, unit] = Array.isArray(rate) ? rate : [rate, '1'];
                return `{"key":"${key}","currentExchangeRate":${value},"currentChange":0.1,"unit":${unit},"lastUpdate":"${answers.boiTime}"}`;
            });
            return json(200, `{"exchangeRates":[${items.join(',')}]}`);
        }

        if (url.pathname === '/ow/data/2.5/weather' || url.pathname === '/ow/data/2.5/forecast') {
            if (answers.owStatus !== 200) return json(answers.owStatus, '{"cod":401,"message":"Invalid API key."}');
            const now = Math.floor(Date.now() / 1000);
            const weather = (id: number, day = true) => [{ id, main: 'Clear', description: 'clear', icon: `01${day ? 'd' : 'n'}` }];
            if (url.pathname.endsWith('/weather')) {
                return json(200, JSON.stringify({
                    weather: weather(answers.owCode), main: { temp: answers.owTemp, feels_like: answers.owTemp, temp_min: answers.owTemp, temp_max: answers.owTemp, humidity: 45 },
                    wind: { speed: 2.5, deg: 270 }, dt: now - 300, name: 'Nablus', cod: 200,
                }));
            }
            const start = Math.ceil(now / 10800) * 10800;
            const list = Array.from({ length: 40 }, (_, i) => ({
                dt: start + i * 10800,
                main: { temp: 20, temp_min: 14 + (i % 3), temp_max: 22 + (i % 4), humidity: 50 },
                weather: weather(i % 2 ? 801 : 800), wind: { speed: 2, deg: 200 },
            }));
            return json(200, JSON.stringify({ cod: '200', cnt: 40, list }));
        }

        res.writeHead(404).end();
    });

    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
    const base = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;

    return {
        boiBase: `${base}/boi`,
        owBase: `${base}/ow`,
        answers,
        requests,
        stop: () => new Promise((resolve) => server.close(() => resolve())),
    };
}
