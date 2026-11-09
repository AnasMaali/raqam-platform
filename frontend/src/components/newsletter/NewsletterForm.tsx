"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";

import { loadTurnstile } from "@/lib/turnstile";

/*
 * The newsletter form: the address, then the 6-digit code the platform mails, then done. Every request goes to the
 * site's own route (/api/newsletter/…), which holds the challenge in an httpOnly cookie. The Turnstile check loads
 * only when the visitor reaches the form (focus or pointer), so it costs the page nothing before that.
 */

/** Digits as typed on an Arabic or Persian keyboard become 0-9. */
const latinDigits = (value: string) =>
  value.replace(/[٠-٩۰-۹]/g, (d) => String((d.codePointAt(0)! & 0xf) % 10)).replace(/\D/g, "");

type Step = { name: "email" } | { name: "code"; email: string; minutes: number } | { name: "done" };
type Answer = { ok: boolean; status: number; data?: Record<string, unknown>; error?: { code?: string; message?: string; details?: Record<string, string[]> } };

async function post(step: string, body: Record<string, unknown>): Promise<Answer> {
  try {
    const response = await fetch(`/api/newsletter/${step}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    const json = await response.json().catch(() => ({}));

    return { ok: response.ok, status: response.status, data: json.data, error: json.error };
  } catch {
    return { ok: false, status: 0, error: { message: "تعذّر الاتصال. تأكّد من الشبكة وحاول مرة أخرى." } };
  }
}

const fieldClass =
  "min-h-11 w-full rounded-sm border border-line-strong bg-ground px-3 text-body text-ink placeholder:text-muted aria-invalid:border-error";
const buttonClass =
  "inline-flex min-h-11 items-center justify-center rounded-sm bg-brand px-5 font-bold text-on-brand hover:bg-brand/90 aria-disabled:cursor-progress aria-disabled:opacity-70";

export function NewsletterForm({ siteKey, placement, path }: { siteKey: string; placement: string; path: string }) {
  const [step, setStep] = useState<Step>({ name: "email" });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [wait, setWait] = useState(0);
  const token = useRef<string | null>(null);
  const widget = useRef<string | null>(null);
  const box = useRef<HTMLDivElement>(null);
  const tokenWaiters = useRef<((t: string | null) => void)[]>([]);
  const focusTarget = useRef<HTMLInputElement | HTMLParagraphElement | null>(null);

  // The countdown before a new code may be asked for.
  useEffect(() => {
    if (wait <= 0) return;
    const timer = setTimeout(() => setWait((w) => w - 1), 1000);

    return () => clearTimeout(timer);
  }, [wait]);

  // Each step change (not the first drawing) moves the focus to what the visitor does next.
  const shown = useRef(step.name);
  useEffect(() => {
    if (shown.current !== step.name) focusTarget.current?.focus();
    shown.current = step.name;
  }, [step.name]);

  /** An error is shown under the field, and the focus goes back to the field to correct it. */
  function fail(text: string) {
    setError(text);
    focusTarget.current?.focus();
  }

  function prepare() {
    if (widget.current !== null || !box.current) return;
    widget.current = "";
    loadTurnstile()
      .then((turnstile) => {
        widget.current = turnstile.render(box.current!, {
          sitekey: siteKey,
          action: "newsletter",
          appearance: "interaction-only",
          language: "ar",
          callback: (t: string) => {
            token.current = t;
            tokenWaiters.current.splice(0).forEach((resolve) => resolve(t));
          },
          "expired-callback": () => (token.current = null),
          "error-callback": () => tokenWaiters.current.splice(0).forEach((resolve) => resolve(null)),
        });
      })
      .catch(() => {
        widget.current = null;
        tokenWaiters.current.splice(0).forEach((resolve) => resolve(null));
      });
  }

  function turnstileToken(): Promise<string | null> {
    if (token.current) return Promise.resolve(token.current);
    prepare();

    return new Promise((resolve) => {
      tokenWaiters.current.push(resolve);
      setTimeout(() => resolve(null), 30_000);
    });
  }

  async function subscribe(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    const form = new FormData(event.currentTarget);
    const email = String(form.get("email") ?? "").trim();
    setBusy(true);
    setError(null);
    const turnstile = await turnstileToken();
    if (!turnstile) {
      setBusy(false);
      fail("تعذّر التحقق من أنك لست برنامجاً آلياً. حدّث الصفحة وحاول مرة أخرى.");

      return;
    }
    const answer = await post("subscribe", { email, website: form.get("website") ?? "", placement, path, turnstile_token: turnstile });
    // A Turnstile token is good once.
    token.current = null;
    if (widget.current) window.turnstile?.reset(widget.current);
    setBusy(false);
    if (!answer.ok) {
      fail(answer.error?.details?.email?.[0] ?? answer.error?.message ?? "تعذّر الاشتراك الآن. حاول بعد قليل.");

      return;
    }
    setWait(Number(answer.data?.resend_after) || 60);
    setStep({ name: "code", email, minutes: Math.round((Number(answer.data?.expires_in) || 1800) / 60) });
  }

  async function confirm(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    const form = new FormData(event.currentTarget);
    setBusy(true);
    setError(null);
    setNotice(null);
    const answer = await post("confirm", { code: latinDigits(String(form.get("code") ?? "")), website: form.get("website") ?? "" });
    setBusy(false);
    if (answer.ok) return setStep({ name: "done" });
    if (answer.error?.code === "expired") {
      setStep({ name: "email" });
      setError(answer.error.message ?? "انتهت صلاحية الرمز. اطلب رمزاً جديداً.");

      return;
    }
    fail(answer.error?.message ?? "تعذّر التأكيد الآن. حاول بعد قليل.");
  }

  async function resend() {
    if (busy || wait > 0) return;
    setBusy(true);
    setError(null);
    setNotice(null);
    const answer = await post("resend", {});
    setBusy(false);
    if (!answer.ok) {
      if (answer.error?.code === "expired") setStep({ name: "email" });
      setError(answer.error?.message ?? "تعذّر إرسال رمز جديد الآن.");

      return;
    }
    setWait(Number(answer.data?.resend_after) || 60);
    setNotice("أرسلنا رمزاً جديداً إن سمحت الحدود بذلك. يبقى الرمز الأخير هو الصالح.");
  }

  const honeypot = (
    <div aria-hidden="true" className="absolute -start-[200vw] size-px overflow-hidden">
      <label>
        اترك هذا الحقل فارغاً
        <input type="text" name="website" tabIndex={-1} autoComplete="off" defaultValue="" />
      </label>
    </div>
  );

  const message = (
    <div aria-live="polite" className="mt-2 min-h-6 text-small">
      {error && (
        <p id="newsletter-error" className="font-medium text-error">
          {error}
        </p>
      )}
      {notice && <p className="text-muted">{notice}</p>}
    </div>
  );

  if (step.name === "done") {
    return (
      <p ref={(el) => void (focusTarget.current = el)} tabIndex={-1} className="text-lead font-bold text-success focus:outline-none">
        اشتركت في النشرة. ستصلك أول رسالة مع العدد القادم.
      </p>
    );
  }

  if (step.name === "code") {
    return (
      <form onSubmit={confirm} className="relative">
        <p className="text-body">
          أرسلنا رمزاً من 6 أرقام إلى <bdi className="font-bold">{step.email}</bdi>. اكتبه هنا خلال {step.minutes} دقيقة.
        </p>
        {honeypot}
        <label htmlFor="newsletter-code" className="mt-3 block font-medium">
          رمز التأكيد
        </label>
        <div className="mt-1 flex flex-wrap gap-2">
          <input
            ref={(el) => void (focusTarget.current = el)}
            id="newsletter-code"
            name="code"
            required
            inputMode="numeric"
            autoComplete="one-time-code"
            spellCheck={false}
            pattern="[0-9٠-٩۰-۹]{6}"
            maxLength={6}
            dir="ltr"
            aria-invalid={error ? true : undefined}
            aria-describedby={error ? "newsletter-error" : undefined}
            className={`${fieldClass} figures w-40 text-center tracking-widest`}
          />
          <button type="submit" aria-disabled={busy || undefined} className={buttonClass}>
            {busy ? "جارٍ التأكيد…" : "تأكيد"}
          </button>
        </div>
        {message}
        <div className="flex flex-wrap gap-x-6">
          <button type="button" onClick={resend} aria-disabled={busy || wait > 0 || undefined} className="inline-flex min-h-11 items-center text-small font-medium text-brand hover:underline aria-disabled:text-muted aria-disabled:no-underline">
            {wait > 0 ? (
              <>
                رمز جديد بعد <span className="figures ms-1">{wait}</span>&nbsp;ثانية
              </>
            ) : (
              "أرسل الرمز مرة أخرى"
            )}
          </button>
          <button
            type="button"
            onClick={() => {
              setError(null);
              setNotice(null);
              setStep({ name: "email" });
            }}
            className="inline-flex min-h-11 items-center text-small font-medium text-brand hover:underline"
          >
            غيّر البريد
          </button>
        </div>
      </form>
    );
  }

  return (
    <form onSubmit={subscribe} onFocus={prepare} onPointerEnter={prepare} className="relative">
      {honeypot}
      <label htmlFor="newsletter-email" className="block font-medium">
        البريد الإلكتروني
      </label>
      <div className="mt-1 flex flex-wrap gap-2">
        <input
          ref={(el) => void (focusTarget.current = el)}
          id="newsletter-email"
          name="email"
          type="email"
          required
          maxLength={254}
          autoComplete="email"
          spellCheck={false}
          dir="ltr"
          placeholder="name@example.com"
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? "newsletter-error newsletter-note" : "newsletter-note"}
          className={`${fieldClass} min-w-0 flex-1 basis-56 text-start`}
        />
        <button type="submit" aria-disabled={busy || undefined} className={buttonClass}>
          {busy ? "جارٍ الإرسال…" : "اشترك"}
        </button>
      </div>
      {message}
      <p id="newsletter-note" className="text-small text-muted">
        نرسل رمزاً من 6 أرقام لتأكيد الاشتراك، ويمكنك إلغاؤه من أي رسالة.
      </p>
      <div ref={box} className="mt-2 empty:hidden" />
    </form>
  );
}
