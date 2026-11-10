"use client";

import { useRef, useState, type FormEvent } from "react";

import { useTurnstile } from "@/lib/turnstile";

/*
 * The contact form (20.13): name, e-mail, an optional phone, subject and message. It posts to the site's own route
 * (/api/contact), which adds the site token and the visitor's address; the platform checks Turnstile (action
 * "contact"), the honeypot `website`, the fields and its limits, and its Arabic messages are shown on their fields.
 */

type Field = "name" | "email" | "phone" | "subject" | "message";
type Errors = Partial<Record<Field, string>>;

// The platform's rules (ContactInbox), checked here too so an empty field needs no round trip.
const required: Record<Exclude<Field, "phone">, string> = {
  name: "اكتب اسمك (2–120 حرفاً).",
  email: "البريد الإلكتروني غير صحيح.",
  subject: "اكتب موضوع الرسالة (2–200 حرف، سطر واحد).",
  message: "اكتب رسالتك (2–5000 حرف).",
};

const fieldClass =
  "w-full rounded-sm border border-line-strong bg-ground px-3 text-body text-ink placeholder:text-muted aria-invalid:border-error";

export function ContactForm({ siteKey }: { siteKey: string }) {
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Errors>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [sent, setSent] = useState(false);
  const done = useRef<HTMLParagraphElement>(null);
  const form = useRef<HTMLFormElement>(null);
  const { box, prepare, token: turnstileToken, reset } = useTurnstile(siteKey, "contact");

  function focusFirst(found: Errors) {
    const first = (["name", "email", "phone", "subject", "message"] as Field[]).find((f) => found[f]);
    if (first) form.current?.querySelector<HTMLElement>(`[name="${first}"]`)?.focus();
  }

  async function send(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    const data = new FormData(event.currentTarget);
    const value = (key: string) => String(data.get(key) ?? "").trim();
    const found: Errors = {};
    for (const [key, message] of Object.entries(required) as [Exclude<Field, "phone">, string][]) if (value(key).length < 2) found[key] = message;
    if (value("email") && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(value("email"))) found.email = required.email;
    setErrors(found);
    setFailure(null);
    if (Object.keys(found).length) return focusFirst(found);

    setBusy(true);
    const token = await turnstileToken();
    if (!token) {
      setBusy(false);
      setFailure("تعذّر التحقق من أنك لست روبوتاً. أعد المحاولة بعد قليل.");
      return;
    }
    let answer: { ok: boolean; error?: { message?: string; details?: Record<string, string[]> } };
    try {
      const response = await fetch("/api/contact", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: value("name"),
          email: value("email"),
          phone: value("phone"),
          subject: value("subject"),
          message: value("message"),
          website: String(data.get("website") ?? ""),
          turnstile_token: token,
        }),
      });
      const json = await response.json().catch(() => ({}));
      answer = { ok: response.ok, error: json.error };
    } catch {
      answer = { ok: false, error: { message: "تعذّر الاتصال. تأكّد من الشبكة وحاول مرة أخرى." } };
    }
    reset();
    setBusy(false);

    if (answer.ok) {
      setSent(true);
      requestAnimationFrame(() => done.current?.focus());
      return;
    }
    const details = answer.error?.details ?? {};
    const onFields: Errors = {};
    for (const key of ["name", "email", "phone", "subject", "message"] as Field[]) if (details[key]?.[0]) onFields[key] = details[key][0];
    setErrors(onFields);
    if (Object.keys(onFields).length) focusFirst(onFields);
    else setFailure(answer.error?.message ?? "تعذّر إرسال الرسالة الآن. حاول بعد قليل.");
  }

  if (sent) {
    return (
      <p ref={done} tabIndex={-1} className="text-lead font-bold text-success focus:outline-none">
        وصلت رسالتك. سنردّ عليك على بريدك الإلكتروني في أقرب وقت.
      </p>
    );
  }

  const describe = (field: Field, hint?: string) => [errors[field] ? `contact-${field}-error` : null, hint].filter(Boolean).join(" ") || undefined;
  const problem = (field: Field) =>
    errors[field] ? (
      <p id={`contact-${field}-error`} className="text-small font-medium text-error">
        {errors[field]}
      </p>
    ) : null;

  return (
    <form ref={form} noValidate onSubmit={send} onFocus={prepare} onPointerEnter={prepare} className="relative flex flex-col gap-5">
      <div className="grid gap-5 sm:grid-cols-2">
        <div className="flex flex-col gap-1">
          <label htmlFor="contact-name" className="font-medium">
            الاسم
          </label>
          <input id="contact-name" name="name" autoComplete="name" maxLength={120} required aria-invalid={errors.name ? true : undefined} aria-describedby={describe("name")} className={`${fieldClass} min-h-11`} />
          {problem("name")}
        </div>
        <div className="flex flex-col gap-1">
          <label htmlFor="contact-email" className="font-medium">
            البريد الإلكتروني
          </label>
          <input id="contact-email" name="email" type="email" dir="ltr" autoComplete="email" spellCheck={false} maxLength={254} required aria-invalid={errors.email ? true : undefined} aria-describedby={describe("email")} className={`${fieldClass} min-h-11 text-end`} />
          {problem("email")}
        </div>
      </div>
      <div className="flex flex-col gap-1">
        <label htmlFor="contact-phone" className="font-medium">
          الهاتف <span className="font-normal text-muted">(اختياري)</span>
        </label>
        <input id="contact-phone" name="phone" type="tel" dir="ltr" autoComplete="tel" inputMode="tel" maxLength={30} placeholder="+970599123456" aria-invalid={errors.phone ? true : undefined} aria-describedby={describe("phone", "contact-phone-hint")} className={`${fieldClass} min-h-11 text-end sm:max-w-80`} />
        <p id="contact-phone-hint" className="text-small text-muted">
          بالصيغة الدولية، مع رمز البلد.
        </p>
        {problem("phone")}
      </div>
      <div className="flex flex-col gap-1">
        <label htmlFor="contact-subject" className="font-medium">
          الموضوع
        </label>
        <input id="contact-subject" name="subject" maxLength={200} required aria-invalid={errors.subject ? true : undefined} aria-describedby={describe("subject")} className={`${fieldClass} min-h-11`} />
        {problem("subject")}
      </div>
      <div className="flex flex-col gap-1">
        <label htmlFor="contact-message" className="font-medium">
          الرسالة
        </label>
        <textarea id="contact-message" name="message" rows={6} maxLength={5000} required aria-invalid={errors.message ? true : undefined} aria-describedby={describe("message")} className={`${fieldClass} py-2`} />
        {problem("message")}
      </div>
      <div aria-hidden="true" className="absolute -start-[200vw] size-px overflow-hidden">
        <label>
          اترك هذا الحقل فارغاً
          <input type="text" name="website" tabIndex={-1} autoComplete="off" defaultValue="" />
        </label>
      </div>
      <div>
        <div ref={box} />
        <div aria-live="polite" className="text-small">
          {failure && <p className="font-medium text-error">{failure}</p>}
        </div>
      </div>
      <button
        type="submit"
        aria-disabled={busy || undefined}
        className="inline-flex min-h-11 items-center justify-center self-start rounded-sm bg-brand px-6 font-bold text-on-brand hover:bg-brand/90 aria-disabled:cursor-progress aria-disabled:opacity-70"
      >
        {busy ? "جارٍ الإرسال…" : "أرسل الرسالة"}
      </button>
    </form>
  );
}
