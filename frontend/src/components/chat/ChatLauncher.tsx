"use client";

import { useState, type ComponentType } from "react";

import { Icon } from "../ui/Icon";

type ChatWindowType = ComponentType<{ open: boolean; onClose: () => void }>;

/**
 * The chat's button (18.1), drawn on the server on every page while the chatbot is on. It is fixed to the corner, so
 * it moves nothing on the page. The chat itself (its window, its code and its styles) is loaded only when the visitor
 * presses it (18.15); until then this button is all the chat costs a page. The window stays mounted after it closes,
 * so the conversation is still there when it opens again.
 */
export function ChatLauncher() {
  const [Chat, setChat] = useState<ChatWindowType | null>(null);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);

  return (
    <>
      <button
        type="button"
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-busy={loading || undefined}
        data-chat-launcher
        data-site-frame
        onClick={() => {
          setOpen(true);
          if (Chat || loading) return;
          setLoading(true);
          import("./ChatWindow")
            .then((module) => setChat(() => module.ChatWindow))
            // Offline or a failed download: the button stays as it was, and the next press tries again.
            .catch(() => setOpen(false))
            .finally(() => setLoading(false));
        }}
        className="fixed start-4 bottom-[max(1rem,env(safe-area-inset-bottom))] z-40 inline-flex h-12 min-w-12 items-center justify-center gap-2 rounded-sm bg-brand px-3 font-medium text-on-brand shadow-raised hover:bg-brand/90 aria-busy:cursor-progress"
      >
        <Icon name="chat" />
        <span className="max-md:sr-only">اسألنا</span>
      </button>
      {Chat && <Chat open={open} onClose={() => setOpen(false)} />}
    </>
  );
}
