"use client";

import { useId, useState } from "react";

import { convert, definiteName, formatMoney, readAmount } from "@/lib/markets";
import type { ConverterData } from "@/lib/types";

const rateName = { buy: "سعر الشراء", sell: "سعر البيع" } as const;

const problems = {
  invalid: "اكتب المبلغ بالأرقام، مثل 100 أو 2.5.",
  "too-large": "المبلغ أكبر من أن يُحوَّل هنا.",
} as const;

/**
 * The converter between the shekel and one currency (11.7). It always says which rate it used: from a currency to the
 * shekel the buy rate, the other way the sell rate, with the platform's own sentence. An amount is read in 0-9,
 * Arabic-Indic or Persian digits. The first answer is drawn on the server, so the page shows a result before any script.
 */
export function Converter({ data, initialCode, headingLevel = 2 }: { data: ConverterData; initialCode?: string; headingLevel?: 2 | 3 }) {
  const usable = data.currencies.filter((c) => c.buy !== null && c.sell !== null);
  const [code, setCode] = useState(usable.find((c) => c.code === initialCode)?.code ?? usable[0]?.code);
  const [direction, setDirection] = useState<"to_base" | "from_base">("to_base");
  const [text, setText] = useState("100");
  const id = useId();
  const currency = usable.find((c) => c.code === code);
  if (!currency) return null;

  const Heading = `h${headingLevel}` as const;
  const rule = data.rules[direction];
  const rate = currency[rule.rate];
  const amount = readAmount(text);
  const result = amount.ok ? convert(amount.value, direction, rate) : null;
  const name = definiteName(currency.code, currency.name);
  const [fromUnit, toUnit] = direction === "to_base" ? [currency.name, "شيكل"] : ["شيكل", currency.name];
  const problem = !amount.ok && amount.reason !== "empty" ? problems[amount.reason] : null;

  return (
    <section aria-labelledby={`${id}-title`} className="border-t-2 border-brand pt-3">
      <Heading id={`${id}-title`} className="text-heading font-bold">
        محوّل العملات
      </Heading>
      <form className="mt-4 grid gap-4 sm:grid-cols-2" onSubmit={(event) => event.preventDefault()}>
        <div className="flex flex-col gap-1">
          <label htmlFor={`${id}-amount`} className="text-small font-medium">
            المبلغ
          </label>
          <input
            id={`${id}-amount`}
            value={text}
            onChange={(event) => setText(event.target.value)}
            inputMode="decimal"
            autoComplete="off"
            spellCheck={false}
            dir="ltr"
            aria-invalid={problem ? true : undefined}
            aria-describedby={problem ? `${id}-problem` : undefined}
            className="figures min-h-11 w-full rounded-sm border border-line-strong bg-ground px-3 text-end text-body aria-invalid:border-error"
          />
          {problem && (
            <p id={`${id}-problem`} className="text-small text-error">
              {problem}
            </p>
          )}
        </div>
        <div className="flex flex-col gap-1">
          <label htmlFor={`${id}-currency`} className="text-small font-medium">
            العملة
          </label>
          <select
            id={`${id}-currency`}
            value={currency.code}
            onChange={(event) => setCode(event.target.value)}
            className="min-h-11 w-full rounded-sm border border-line-strong bg-ground px-3 text-body"
          >
            {usable.map((c) => (
              <option key={c.code} value={c.code}>
                {c.name}
              </option>
            ))}
          </select>
        </div>
        <fieldset className="sm:col-span-2">
          <legend className="text-small font-medium">اتجاه التحويل</legend>
          <div className="mt-1 grid grid-cols-2 rounded-sm border border-line-strong">
            {(["to_base", "from_base"] as const).map((d) => (
              <label
                key={d}
                className="flex min-h-11 cursor-pointer items-center justify-center px-2 py-1 text-center text-small not-first:border-s not-first:border-line-strong has-checked:bg-brand has-checked:text-on-brand has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-brand"
              >
                <input type="radio" name={`${id}-direction`} value={d} checked={direction === d} onChange={() => setDirection(d)} className="sr-only" />
                {d === "to_base" ? `من ${name} إلى الشيكل` : `من الشيكل إلى ${name}`}
              </label>
            ))}
          </div>
        </fieldset>
      </form>
      <output htmlFor={`${id}-amount ${id}-currency`} aria-live="polite" className="mt-4 block border-t border-line pt-3">
        {result === null || !amount.ok ? (
          <span className="text-muted">اكتب مبلغاً لترى قيمته.</span>
        ) : (
          <span className="flex flex-wrap items-baseline gap-x-2 text-subheading">
            <span>
              <bdi dir="ltr" className="figures">
                {formatMoney(amount.value)}
              </bdi>{" "}
              {fromUnit} =
            </span>
            <span className="font-bold">
              <bdi dir="ltr" className="figures text-heading">
                {formatMoney(result)}
              </bdi>{" "}
              {toUnit}
            </span>
          </span>
        )}
      </output>
      <p className="mt-2 text-small">
        بحسب {rateName[rule.rate]}: <bdi dir="ltr" className="figures">1</bdi> {currency.name} ={" "}
        <bdi dir="ltr" className="figures font-medium">
          {rate}
        </bdi>{" "}
        شيكل
      </p>
      <p className="text-small text-muted">{rule.text}</p>
    </section>
  );
}
