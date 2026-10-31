"use client";

import { useRef, useState, type ReactNode } from "react";

import { Icon } from "../ui/Icon";

import styles from "./MobileMenu.module.css";

/**
 * The phone and tablet menu: the browser's own modal dialog, so the focus stays inside it, Escape closes it and the
 * focus returns to the button. It slides in from the start side in CSS (no motion library); with reduced motion it
 * only fades. Following a link or pressing outside it closes it. Its content is drawn on the server.
 */
export function MobileMenu({ children }: { children: ReactNode }) {
  const dialog = useRef<HTMLDialogElement>(null);
  const [open, setOpen] = useState(false);

  return (
    <>
      <button
        type="button"
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-controls="site-menu"
        // The browser's own command opens the menu before the page's script has loaded, where it is supported.
        {...{ commandfor: "site-menu", command: "show-modal" }}
        onClick={() => {
          if (!dialog.current?.open) dialog.current?.showModal();
        }}
        className="inline-flex size-11 items-center justify-center rounded-sm hover:bg-tint lg:hidden"
      >
        <Icon name="menu" />
        <span className="sr-only">القائمة</span>
      </button>
      <dialog
        ref={dialog}
        id="site-menu"
        aria-labelledby="site-menu-title"
        className={styles.sheet}
        onToggle={(event) => setOpen(event.currentTarget.open)}
        onClick={(event) => {
          // A press on the backdrop lands on the dialog itself; a followed link should close it too.
          const target = event.target as HTMLElement;
          if (target === event.currentTarget || target.closest("a")) event.currentTarget.close();
        }}
      >
        <div className="flex items-center justify-between border-b border-line px-4 py-2">
          <h2 id="site-menu-title" className="text-subheading font-bold">
            القائمة
          </h2>
          <button
            type="button"
            onClick={() => dialog.current?.close()}
            className="inline-flex size-11 items-center justify-center rounded-sm hover:bg-tint"
          >
            <Icon name="close" />
            <span className="sr-only">إغلاق القائمة</span>
          </button>
        </div>
        <div className="px-4 pb-8">{children}</div>
      </dialog>
    </>
  );
}
