import { formatPercent } from "@/lib/format";

/** Up, down or flat, drawn: never a typed glyph. */
export function Arrow({ direction }: { direction: "up" | "down" | "same" }) {
  return (
    <svg viewBox="0 0 10 10" width="10" height="10" aria-hidden="true" focusable="false" className="shrink-0">
      {direction === "same" ? (
        <rect x="1" y="4" width="8" height="2" rx="1" fill="currentColor" />
      ) : (
        <path d={direction === "up" ? "M5 1.5 9 8.5H1z" : "M5 8.5 1 1.5h8z"} fill="currentColor" />
      )}
    </svg>
  );
}

const spoken = { up: "ارتفاع", down: "انخفاض", same: "بدون تغيّر" };
const tone = { up: "text-rise", down: "text-fall", same: "text-muted" };

/**
 * A change in percent: the arrow, the word for screen readers, and the signed number kept left to right. Colour is
 * never the only sign of a rise or a fall. An unknown change shows a dash.
 */
export function Change({ value, className = "" }: { value: string | number | null; className?: string }) {
  if (value === null || value === "" || Number.isNaN(Number(value))) {
    return (
      <span className={`text-muted ${className}`}>
        <span aria-hidden="true">–</span>
        <span className="sr-only">التغيّر غير معروف</span>
      </span>
    );
  }
  const number = Number(value);
  const direction = number > 0 ? "up" : number < 0 ? "down" : "same";

  return (
    <span className={`inline-flex items-center gap-1 whitespace-nowrap ${tone[direction]} ${className}`}>
      <Arrow direction={direction} />
      <span className="sr-only">{spoken[direction]}</span>
      <span dir="ltr">{formatPercent(number)}</span>
    </span>
  );
}
