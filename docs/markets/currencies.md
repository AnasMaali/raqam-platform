# Currency board

Decision 11 (revised 2026-10-12): the board shows the Bank of Israel's official representative rates, free and
without a key, plus buy/sell rates derived from them.

## Source

* `GET https://boi.org.il/PublicApi/GetExchangeRates` answers with every published currency at once:
  `{"exchangeRates": [{"key": "USD", "currentExchangeRate": 3.106, "unit": 1, "lastUpdate": "2026-10-20T12:42:27.6546389Z"}, …]}`
  (shape checked 2026-10-21). The rate is shekels for `unit` units; the stored rate is divided by `unit`.
* The bank publishes once a business day, **Monday to Friday** (none on Sundays or Israeli holidays).
* `currency:fetch` runs every 30 minutes from 12:00 to 16:00 Palestine time on Monday to Friday. A rate is stored
  only when its `lastUpdate` is newer than the last one kept for that currency, so a day adds one row per currency.
  Every row is kept forever (`exchange_rates`).
* AED and SAR are never fetched: `currency_pegs` derives them from the dollar (3.6725 and 3.75).
* ExchangeRate-API Pro is registered as the backup source (`data_providers.exchangerate_api`), switched off. It is a
  paid service: no key, no code that calls it, and the "source" dialog does not let anyone choose it.

## What is not believed

The answer is read with every number kept as its exact text (`DecimalJson`), never a float. Then:

| check | refused |
|---|---|
| size | more than 64 KB, read while downloading |
| redirects | never followed |
| shape | not JSON, no `exchangeRates` list, more than 100 entries, nesting deeper than 16 |
| rate | not a plain decimal of at most 10 integer digits, zero or below after dividing by the unit |
| unit | not a whole number from 1 to 9,999,999 |
| time | not ISO 8601, an impossible date, more than 15 minutes ahead of the database clock |
| change | less than half or more than double the last stored rate of that currency |
| entry | a currency listed twice, a currency on the board missing from the answer |

A refused entry is not stored; the good entries of the same answer are. Any problem counts as a failure in
`provider_health`; the third failure in a row leaves a notification for every active admin (at most once an hour
per source) and an alert on the panel's home, which stays until the source answers again. The last good rates
stay on the board throughout.

## Buy, sell and manual rates

* Each currency has a buy and a sell spread, typed in per cent (0 to 20, two decimals) and kept in basis points:
  buy = official × (1 − spread), sell = official × (1 + spread) (the `rate_board` view).
* A manual rate replaces buy and sell from now until a chosen time (or until it is ended). It must keep
  sell ≥ buy and lie between half and double the official rate. A new manual rate ends the current one at the same
  instant (the `EXCLUDE` constraint forbids overlaps). When its time passes, the board goes back by itself.
* Every change (spreads, list, order, manual rates, the source) is in the audit log.

## API

`/currencies`, `/currencies/ticker`, `/currencies/converter`, `/currencies/{code}/history` (see
`docs/api/openapi.yaml`). They read our tables only; answers are cached until something changes or the next manual
rate starts or ends (at most five minutes), and the site gets a `currencies` webhook on each change.
