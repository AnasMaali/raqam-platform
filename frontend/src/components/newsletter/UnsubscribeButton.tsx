"use client";

import { useEffect, useRef, useState } from "react";

/**
 * The unsubscribe page's one button: nothing changes until the visitor presses it (a link opened by a mail scanner
 * must never unsubscribe anyone). The answer replaces the button and takes the focus.
 */
export function UnsubscribeButton({ s, t }: { s: string; t: string }) {
  const [state, setState] = useState<"ready" | "busy" | "done">("ready");
  const [error, setError] = useState<string | null>(null);
  const done = useRef<HTMLParagraphElement>(null);

  // Once the answer is drawn, the focus moves to it.
  useEffect(() => {
    if (state === "done") done.current?.focus();
  }, [state]);

  async function confirm() {
    if (state !== "ready") return;
    setState("busy");
    setError(null);
    try {
      const response = await fetch("/api/newsletter/unsubscribe", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ s, t }) });
      const body = await response.json().catch(() => null);
      if (response.ok) {
        setState("done");
        return;
      }
      setError(body?.error?.message ?? "تعذّر إلغاء الاشتراك الآن. حاول بعد قليل.");
    } catch {
      setError("تعذّر الاتصال. تأكّد من الشبكة وحاول مرة أخرى.");
    }
    setState("ready");
  }

  if (state === "done") {
    return (
      <p ref={done} tabIndex={-1} className="text-lead font-bold text-success focus:outline-none">
        ألغينا اشتراكك. لن تصلك النشرة بعد اليوم.
      </p>
    );
  }

  return (
    <div className="flex flex-col items-start gap-3">
      <button
        type="button"
        onClick={confirm}
        aria-disabled={state === "busy" || undefined}
        className="inline-flex min-h-11 items-center rounded-sm bg-brand px-6 font-bold text-on-brand hover:bg-brand/90 aria-disabled:cursor-progress aria-disabled:opacity-70"
      >
        {state === "busy" ? "جارٍ الإلغاء…" : "ألغِ اشتراكي"}
      </button>
      <div aria-live="polite">{error && <p className="font-medium text-error">{error}</p>}</div>
    </div>
  );
}
