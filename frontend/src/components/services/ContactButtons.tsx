"use client";

import Link from "next/link";

import { Icon, type IconName } from "@/components/ui/Icon";
import type { ServiceContactLinks } from "@/lib/types";

const channels: { key: keyof ServiceContactLinks; label: string; icon: IconName }[] = [
  { key: "whatsapp", label: "واتساب", icon: "chat" },
  { key: "phone", label: "اتصال", icon: "phone" },
  { key: "email", label: "بريد إلكتروني", icon: "mail" },
];

/**
 * A service's contact buttons (14.8): WhatsApp with a ready message, a call, an e-mail with a ready subject. A press is
 * reported to the site's own route, which counts it with the platform (14.9); the report never holds the visitor up.
 * A channel the platform has no setting for is left out; with none at all, the contact page.
 */
export function ContactButtons({ slug, links, topic }: { slug: string; links: ServiceContactLinks; topic: string }) {
  const shown = channels.filter((c) => links[c.key]);
  if (shown.length === 0) {
    return (
      <Link href="/contact" className="inline-flex min-h-11 items-center justify-center rounded-sm bg-brand px-5 font-bold text-on-brand hover:bg-brand/90">
        تواصل معنا
      </Link>
    );
  }

  function report(channel: string) {
    try {
      void fetch(`/api/services/${encodeURIComponent(slug)}/contact`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ channel }),
        keepalive: true,
      }).catch(() => undefined);
    } catch {
      // Counting never stands in the visitor's way.
    }
  }

  return (
    <ul className="flex flex-wrap gap-3" aria-label={`تواصل لحجز ${topic}`}>
      {shown.map((c, i) => (
        <li key={c.key}>
          <a
            href={links[c.key]!}
            onClick={() => report(c.key)}
            {...(c.key === "whatsapp" ? { target: "_blank", rel: "noopener" } : {})}
            className={`inline-flex min-h-11 items-center gap-2 rounded-sm px-4 font-bold ${
              i === 0 ? "bg-brand text-on-brand hover:bg-brand/90" : "border border-line-strong hover:border-brand hover:text-brand"
            }`}
          >
            <Icon name={c.icon} size={20} />
            {c.label}
            {c.key === "whatsapp" && <span className="sr-only">(يفتح في نافذة جديدة)</span>}
          </a>
        </li>
      ))}
    </ul>
  );
}
