"use client";

import { useEffect, useId, useRef, useState, type ReactNode } from "react";

import { Icon } from "../ui/Icon";

/**
 * A header menu item with a list under it (the platform's sections). A button opens it; Escape, a press outside it, or
 * moving the focus out of it closes it. The list is plain links, drawn on the server.
 */
export function NavDisclosure({ label, children }: { label: string; children: ReactNode }) {
  const [open, setOpen] = useState(false);
  const root = useRef<HTMLDivElement>(null);
  const button = useRef<HTMLButtonElement>(null);
  const id = useId();

  useEffect(() => {
    if (!open) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        setOpen(false);
        button.current?.focus();
      }
    };
    const onPointer = (event: PointerEvent) => {
      if (!root.current?.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener("keydown", onKey);
    document.addEventListener("pointerdown", onPointer);

    return () => {
      document.removeEventListener("keydown", onKey);
      document.removeEventListener("pointerdown", onPointer);
    };
  }, [open]);

  return (
    <div
      ref={root}
      className="relative"
      onBlur={(event) => {
        if (!root.current?.contains(event.relatedTarget as Node | null)) setOpen(false);
      }}
    >
      <button
        ref={button}
        type="button"
        aria-expanded={open}
        aria-controls={id}
        onClick={() => setOpen((value) => !value)}
        className="inline-flex min-h-11 items-center gap-1 hover:text-brand aria-expanded:text-brand"
      >
        {label}
        <Icon name="down" size={16} className={open ? "rotate-180" : undefined} />
      </button>
      <div id={id} hidden={!open} className="absolute start-0 top-full z-30 min-w-60 border border-line bg-ground py-2 shadow-raised" onClick={() => setOpen(false)}>
        {children}
      </div>
    </div>
  );
}
