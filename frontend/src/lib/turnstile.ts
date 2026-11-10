"use client";

import { useRef } from "react";

/*
 * Cloudflare Turnstile, loaded only when a visitor reaches a form (focus or pointer), so it costs a page nothing
 * before that. The platform checks the token with the form's action.
 */

export type Turnstile = {
  render: (el: HTMLElement, options: Record<string, unknown>) => string;
  reset: (id: string) => void;
};

declare global {
  interface Window {
    turnstile?: Turnstile;
  }
}

const turnstileScript = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
let loading: Promise<Turnstile> | null = null;

export function loadTurnstile(): Promise<Turnstile> {
  if (window.turnstile) return Promise.resolve(window.turnstile);
  loading ??= new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = turnstileScript;
    script.async = true;
    script.onload = () => (window.turnstile ? resolve(window.turnstile) : reject(new Error("turnstile")));
    script.onerror = () => {
      loading = null;
      reject(new Error("turnstile"));
    };
    document.head.append(script);
  });

  return loading;
}

/** One form's check: `prepare` when the visitor reaches the form, `token` when it is sent, `reset` after (a token is good once). */
export function useTurnstile(siteKey: string, action: string) {
  const box = useRef<HTMLDivElement>(null);
  const widget = useRef<string | null>(null);
  const current = useRef<string | null>(null);
  const waiters = useRef<((t: string | null) => void)[]>([]);

  function prepare() {
    if (widget.current !== null || !box.current) return;
    widget.current = "";
    loadTurnstile()
      .then((turnstile) => {
        widget.current = turnstile.render(box.current!, {
          sitekey: siteKey,
          action,
          appearance: "interaction-only",
          language: "ar",
          callback: (t: string) => {
            current.current = t;
            waiters.current.splice(0).forEach((resolve) => resolve(t));
          },
          "expired-callback": () => (current.current = null),
          "error-callback": () => waiters.current.splice(0).forEach((resolve) => resolve(null)),
        });
      })
      .catch(() => {
        widget.current = null;
        waiters.current.splice(0).forEach((resolve) => resolve(null));
      });
  }

  function token(): Promise<string | null> {
    if (current.current) return Promise.resolve(current.current);
    prepare();

    return new Promise((resolve) => {
      waiters.current.push(resolve);
      setTimeout(() => resolve(null), 30_000);
    });
  }

  function reset() {
    current.current = null;
    if (widget.current) window.turnstile?.reset(widget.current);
  }

  return { box, prepare, token, reset };
}
