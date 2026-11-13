"use client";

import { useEffect, useRef, type CSSProperties } from "react";

import { announceAd, type FloatingAd } from "@/lib/ads";

import { Icon } from "../ui/Icon";

import styles from "./AdPopup.module.css";

/** When the popup was last shown to this visitor, in this browser only (no cookie). */
const shownKey = "raqam:ad-popup";
const wide = "(min-width: 64rem)";
const interactions = ["scroll", "pointerdown", "keydown"] as const;

/** Whether the frequency allows the popup now; without the browser's storage it never shows (it could not keep the cap). */
function due(capHours: number) {
  try {
    const last = Number(localStorage.getItem(shownKey));
    return !(last > 0 && Date.now() - last < capHours * 3_600_000);
  } catch {
    return false;
  }
}

function remember() {
  try {
    localStorage.setItem(shownKey, String(Date.now()));
  } catch {
    // Without storage, due() never lets it show again.
  }
}

/** The chat, the menu or any other window being open (or opening): the popup waits for the next interaction. */
const busy = () => !!document.querySelector("dialog[open], [aria-haspopup='dialog'][aria-expanded='true']");

/**
 * The popup ad (13.2), from 1024 px only: never on a phone or a tablet. It opens after the visitor's first interaction
 * with the page (a scroll, a press or a key), never on arrival, and at most once in the slot's frequency
 * (`frequency_cap_hours`, kept in this browser). The browser's own modal dialog: the focus starts on «إغلاق الإعلان»,
 * Escape or a press outside closes it, and the focus goes back where it was.
 */
export function AdPopup({ ad }: { ad: FloatingAd }) {
  const dialog = useRef<HTMLDialogElement>(null);
  const link = useRef<HTMLAnchorElement>(null);

  useEffect(() => {
    const node = dialog.current;
    if (!node || !due(ad.capHours)) return;
    let timer: ReturnType<typeof setTimeout> | null = null;

    const arm = () => interactions.forEach((type) => window.addEventListener(type, interacted, { capture: true, passive: true }));
    const disarm = () => interactions.forEach((type) => window.removeEventListener(type, interacted, { capture: true }));
    const open = () => {
      timer = null;
      if (!due(ad.capHours)) return;
      if (!matchMedia(wide).matches || busy()) return arm();
      node.showModal();
      remember();
      if (ad.campaign) announceAd(link.current);
    };
    function interacted() {
      disarm();
      // A moment later, so a press that opens another window (the chat) is seen first.
      timer = setTimeout(open, 600);
    }

    arm();

    return () => {
      disarm();
      if (timer) clearTimeout(timer);
    };
  }, [ad.capHours, ad.campaign]);

  const close = () => dialog.current?.close();
  const box = { "--ad-w": `${ad.size.width}px`, "--ad-ratio": ad.size.width / ad.size.height } as CSSProperties;

  return (
    <dialog
      ref={dialog}
      aria-label={ad.label}
      data-ad-popup
      className={styles.popup}
      style={box}
      // A press on the backdrop lands on the dialog itself.
      onClick={(event) => event.target === event.currentTarget && close()}
    >
      <div className="flex items-center justify-between gap-4 ps-4">
        <p className="text-tiny text-muted">{ad.label}</p>
        <button type="button" autoFocus onClick={close} className="inline-flex size-11 items-center justify-center text-ink hover:text-brand focus-visible:-outline-offset-2">
          <Icon name="close" />
          <span className="sr-only">إغلاق الإعلان</span>
        </button>
      </div>
      <a
        ref={link}
        href={ad.href}
        rel={ad.rel}
        onClick={close}
        className="block aspect-(--ad-ratio) w-full overflow-hidden bg-surface"
        {...(ad.campaign ? { "data-ad-campaign": ad.campaign, "data-ad-placement": ad.placement } : {})}
      >
        {/* eslint-disable-next-line @next/next/no-img-element -- the advertiser's picture, already sized by the platform */}
        <img src={ad.image.url} alt={ad.alt} width={ad.image.width} height={ad.image.height} decoding="async" className="size-full object-cover" />
      </a>
    </dialog>
  );
}
