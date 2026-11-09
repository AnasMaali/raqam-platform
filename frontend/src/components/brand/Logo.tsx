import Link from "next/link";

import { logoFuture, logoPast, logoViewBox } from "./logo-paths";

/**
 * The header's logo: the name without the slogan, drawn inline so it paints with the page and follows the theme
 * (blue and cyan on light pages, the official white version on dark ones).
 */
export function HeaderLogo({ className = "h-10 w-auto" }: { className?: string }) {
  return (
    <Link href="/" className="-my-1 inline-flex shrink-0 items-center py-1">
      <svg viewBox={logoViewBox} className={className} role="img" aria-label="رقم، الصفحة الرئيسية">
        <path fill="var(--logo-past)" d={logoPast} />
        <path fill="var(--logo-future)" d={logoFuture} />
      </svg>
    </Link>
  );
}

/** The full logo with the slogan, from the brand files, light or dark. Below the first screen, so it loads lazily. */
export function FullLogo({ className = "h-16 w-auto" }: { className?: string }) {
  return (
    <>
      {/* eslint-disable-next-line @next/next/no-img-element -- the brand file as it is */}
      <img src="/brand/logo-ar-horizontal.svg" alt="رقم: تاريخ الاقتصاد ومستقبله" width={372} height={135} loading="lazy" className={`${className} dark:hidden`} />
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img src="/brand/logo-ar-horizontal-white.svg" alt="رقم: تاريخ الاقتصاد ومستقبله" width={372} height={135} loading="lazy" className={`${className} hidden dark:block`} />
    </>
  );
}
