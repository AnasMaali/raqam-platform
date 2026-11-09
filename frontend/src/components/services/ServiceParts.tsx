import Link from "next/link";

import { Pic } from "@/components/ui/Pic";
import { formatDate } from "@/lib/format";
import type { ServicePrice, ServiceSummary } from "@/lib/types";

/* The parts of the services pages: a price as the platform writes it, a special offer with its end, a service card. */

/** The price (fixed, «يبدأ من», or «السعر عند التواصل»), and a special offer with the old price struck and its end. */
export function PriceTag({ price, large = false }: { price: ServicePrice; large?: boolean }) {
  return (
    <div className="flex flex-col gap-1">
      <p className={`${large ? "text-heading" : "text-subheading"} font-bold ${price.mode === "on_request" ? "text-muted" : ""}`}>{price.text}</p>
      {price.offer && (
        <p className="flex flex-wrap items-baseline gap-x-2 text-small">
          <span className="rounded-xs bg-tint px-1.5 font-bold text-brand">عرض خاص</span>
          <span className="text-muted">
            بدلاً من <s>{price.offer.was_text}</s>
          </span>
          {price.offer.ends_at && (
            <span className="text-muted">
              حتى <time dateTime={price.offer.ends_at}>{formatDate(price.offer.ends_at)}</time>
            </span>
          )}
        </p>
      )}
    </div>
  );
}

/** Booking online: shown only while the platform's booking switch is on (it is off in V1). */
export function BookingLink({ service, on }: { service: ServiceSummary; on: boolean }) {
  if (!on || !service.booking.enabled || !service.booking.path) return null;

  return (
    <Link href={service.booking.path} className="inline-flex min-h-11 items-center rounded-sm border border-line-strong px-4 font-bold hover:border-brand hover:text-brand">
      احجز أونلاين
    </Link>
  );
}

/** A service in its group: picture, name (the card's link, stretched over it), summary, price, «تواصل للحجز» above it. */
export function ServiceCard({ service, bookingOn, level = 3, priority = false }: { service: ServiceSummary; bookingOn: boolean; level?: 2 | 3; priority?: boolean }) {
  const Heading = `h${level}` as const;

  return (
    <article className="relative flex h-full flex-col gap-3 border-t-2 border-line pt-3 has-[h2_a:hover,h3_a:hover]:border-brand">
      <Pic media={service.cover} sizes="(min-width: 64rem) 30vw, (min-width: 40rem) 50vw, 100vw" priority={priority} />
      <Heading className="text-subheading font-bold text-balance">
        <Link href={service.path} className="after:absolute after:inset-0 hover:text-brand">
          {service.name}
        </Link>
      </Heading>
      {service.summary && <p className="text-muted">{service.summary}</p>}
      <div className="mt-auto flex flex-col gap-3 pt-2">
        <PriceTag price={service.price} />
        <div className="relative z-10 flex flex-wrap gap-3">
          <Link
            href={`${service.path}#contact`}
            aria-label={`تواصل لحجز ${service.name}`}
            className="inline-flex min-h-11 items-center rounded-sm bg-brand px-4 font-bold text-on-brand hover:bg-brand/90"
          >
            تواصل للحجز
          </Link>
          <BookingLink service={service} on={bookingOn} />
        </div>
      </div>
    </article>
  );
}
