"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from "react";

import { sitePath, whatsappLink, type ChatButton, type ChatConfig, type ChatReply } from "@/lib/chat";

import { Icon } from "../ui/Icon";

import styles from "./ChatWindow.module.css";

type Said = { from: "visitor"; text: string } | { from: "bot"; reply: ChatReply } | { from: "problem"; text: string };
type Line = Said & { id: number };

const unavailable = "تعذّر الوصول إلى المساعد الآن. حاول بعد قليل.";

/**
 * The chat window (18.1, 18.2), loaded only when the visitor presses the chat button. The browser's own modal dialog:
 * the focus stays inside, Escape closes it and the focus goes back to the button. The whole screen on a phone, a panel
 * in the corner from 768 px. Every answer is drawn as text; a link is drawn only for a path of this site, and the only
 * outside link is the WhatsApp one (18.8). The conversation's key is kept in this window only, never stored.
 */
export function ChatWindow({ open, onClose }: { open: boolean; onClose: () => void }) {
  const dialog = useRef<HTMLDialogElement>(null);
  const log = useRef<HTMLDivElement>(null);
  const conversation = useRef<string | null>(null);
  const nextId = useRef(1);
  const pathname = usePathname();
  const [config, setConfig] = useState<ChatConfig | null>(null);
  const [failed, setFailed] = useState(false);
  const [lines, setLines] = useState<Line[]>([]);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const node = dialog.current;
    if (!node) return;
    if (open && !node.open) node.showModal();
    if (!open && node.open) node.close();
  }, [open]);

  useEffect(() => {
    let live = true;
    fetch("/api/chat", { headers: { Accept: "application/json" } })
      .then(async (response) => {
        const body = await response.json();
        if (!response.ok || !body?.data) throw new Error("chat settings");
        if (live) setConfig(body.data as ChatConfig);
      })
      .catch(() => live && setFailed(true));

    return () => {
      live = false;
    };
  }, []);

  useEffect(() => {
    // The newest line in view, at once (no smooth scrolling to wait for).
    log.current?.scrollTo({ top: log.current.scrollHeight });
  }, [lines, busy]);

  const add = (line: Said) => setLines((all) => [...all, { ...line, id: nextId.current++ }]);

  async function send(question: { message: string } | { button: ChatButton }, website = "") {
    if (busy) return;
    add("message" in question ? { from: "visitor", text: question.message } : { from: "visitor", text: question.button.label });
    setBusy(true);
    try {
      const response = await fetch("/api/chat", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ ...("message" in question ? { message: question.message } : { button: question.button.id }), conversation: conversation.current, path: pathname, website }),
      });
      const body = await response.json().catch(() => null);
      if (response.ok && body?.data?.reply) {
        conversation.current = typeof body.data.conversation === "string" ? body.data.conversation : conversation.current;
        add({ from: "bot", reply: body.data.reply as ChatReply });
      } else {
        add({ from: "problem", text: typeof body?.error?.message === "string" ? body.error.message : unavailable });
      }
    } catch {
      add({ from: "problem", text: unavailable });
    } finally {
      setBusy(false);
    }
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const message = String(data.get("message") ?? "").trim();
    if (!message || busy) return;
    event.currentTarget.reset();
    void send({ message }, String(data.get("website") ?? ""));
  }

  const choices = (buttons: ChatButton[]) =>
    buttons.length > 0 && (
      <ul className="mt-2 flex flex-wrap gap-2" aria-label="اختر سؤالاً">
        {buttons.map((button) => (
          <li key={button.id}>
            <button
              type="button"
              disabled={busy}
              onClick={() => void send({ button })}
              className="inline-flex min-h-11 items-center rounded-sm border border-brand px-3 text-small font-medium text-brand hover:bg-tint disabled:opacity-60"
            >
              {button.label}
            </button>
          </li>
        ))}
      </ul>
    );

  return (
    <dialog
      ref={dialog}
      id="chat-window"
      aria-labelledby="chat-window-title"
      className={styles.window}
      onClose={onClose}
      onClick={(event) => {
        // A press on the backdrop lands on the dialog itself; a followed site link closes the chat too.
        const target = event.target as HTMLElement;
        if (target === event.currentTarget || target.closest("a[data-site-link]")) event.currentTarget.close();
      }}
    >
      <div className="flex h-full flex-col">
        <div className="flex items-center justify-between border-b border-line px-4 py-2">
          <h2 id="chat-window-title" className="text-subheading font-bold">
            المساعد
          </h2>
          <button type="button" onClick={() => dialog.current?.close()} className="inline-flex size-11 items-center justify-center rounded-sm hover:bg-tint">
            <Icon name="close" />
            <span className="sr-only">إغلاق المحادثة</span>
          </button>
        </div>

        <div ref={log} role="log" aria-live="polite" aria-busy={busy} className="flex-1 space-y-4 overflow-y-auto overscroll-contain px-4 py-4" data-chat-log>
          {!config && !failed && <p className="text-muted">لحظة…</p>}
          {failed && <p className="text-error">{unavailable}</p>}
          {config && !config.enabled && <p className="text-muted">المساعد غير متاح الآن. تواصل معنا من صفحة «اتصل بنا».</p>}
          {config?.enabled && (
            <div className={styles.bot}>
              <p className="whitespace-pre-line">{config.welcome}</p>
              {choices(config.buttons)}
            </div>
          )}
          {lines.map((line) =>
            line.from === "visitor" ? (
              <p key={line.id} className={`${styles.visitor} whitespace-pre-line`} data-chat-visitor>
                <span className="sr-only">أنت: </span>
                {line.text}
              </p>
            ) : line.from === "problem" ? (
              <p key={line.id} className="font-medium text-error" data-chat-problem>
                {line.text}
              </p>
            ) : (
              <BotLine key={line.id} reply={line.reply} choices={choices} />
            ),
          )}
          {busy && <p className="text-muted">المساعد يكتب…</p>}
        </div>

        {config?.enabled && (
          <form onSubmit={submit} className="relative flex items-center gap-2 border-t border-line px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
            <label htmlFor="chat-message" className="sr-only">
              سؤالك
            </label>
            <input
              id="chat-message"
              name="message"
              type="text"
              dir="auto"
              autoComplete="off"
              enterKeyHint="send"
              maxLength={config.maxLength}
              required
              autoFocus
              placeholder="اكتب سؤالك…"
              className="h-11 min-w-0 flex-1 rounded-sm border border-line-strong bg-ground px-3 text-body"
            />
            <div aria-hidden="true" className="absolute -start-[200vw] size-px overflow-hidden">
              <label>
                اترك هذا الحقل فارغاً
                <input type="text" name="website" tabIndex={-1} autoComplete="off" defaultValue="" />
              </label>
            </div>
            <button type="submit" disabled={busy} className="inline-flex h-11 items-center rounded-sm bg-brand px-4 font-medium text-on-brand hover:bg-brand/90 disabled:opacity-60">
              إرسال
            </button>
          </form>
        )}
      </div>
    </dialog>
  );
}

/** One answer: its text, its links (a link only for a path of this site), WhatsApp, and the buttons it offers next. */
function BotLine({ reply, choices }: { reply: ChatReply; choices: (buttons: ChatButton[]) => ReactNode }) {
  const whatsapp = whatsappLink(reply.whatsapp);
  const links = Array.isArray(reply.links) ? reply.links : [];

  return (
    <div className={styles.bot} data-chat-answer>
      <span className="sr-only">المساعد: </span>
      {typeof reply.text === "string" && reply.text && <p className="whitespace-pre-line">{reply.text}</p>}
      {links.length > 0 && (
        <ul className="mt-2 space-y-1">
          {links.map((link, i) => {
            const path = sitePath(link.path);
            return (
              <li key={i}>
                {path ? (
                  <Link href={path} data-site-link className="inline-flex min-h-11 items-center font-medium text-brand underline underline-offset-4">
                    {link.label}
                  </Link>
                ) : (
                  <span>{link.label}</span>
                )}
              </li>
            );
          })}
        </ul>
      )}
      {whatsapp && (
        <a href={whatsapp} target="_blank" rel="noopener noreferrer" className="mt-2 inline-flex min-h-11 items-center gap-2 font-medium text-brand underline underline-offset-4">
          <Icon name="phone" size={18} />
          كلّمنا على واتساب
        </a>
      )}
      {choices(Array.isArray(reply.buttons) ? reply.buttons : [])}
    </div>
  );
}
