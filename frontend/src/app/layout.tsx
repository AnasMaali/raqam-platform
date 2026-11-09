import type { Metadata, Viewport } from "next";

import { BackToTop } from "@/components/site/BackToTop";
import { SiteFooter } from "@/components/site/SiteFooter";
import { SiteHeader } from "@/components/site/SiteHeader";
import { SkipLink } from "@/components/site/SkipLink";
import { themeScript } from "@/components/theme/theme";
import { getMenus, getSite } from "@/lib/server/site-data";

import { alexandria } from "./fonts";
import "./globals.css";

export const metadata: Metadata = {
  metadataBase: new URL(process.env.SITE_URL || "http://localhost:3000"),
  title: { default: "رقم: تاريخ الاقتصاد ومستقبله", template: "%s | رقم" },
  description: "منصة إعلام اقتصادي فلسطيني من نابلس: أخبار الاقتصاد وأسعار العملات وقصص الشركات وتاريخ الاقتصاد.",
};

export const viewport: Viewport = {
  themeColor: [
    { media: "(prefers-color-scheme: light)", color: "#ffffff" },
    { media: "(prefers-color-scheme: dark)", color: "#0b0e14" },
  ],
};

/**
 * Every page's frame: the skip link, the header, the footer and «للأعلى». Each page brings its own <Main>; every page
 * but the home page also gets the thin currency strip and the breaking bar from the (pages) group.
 */
export default async function RootLayout({ children }: LayoutProps<"/">) {
  const [menus, site] = await Promise.all([getMenus(), getSite()]);

  return (
    // The theme script sets data-theme on this element before React runs.
    <html lang="ar" dir="rtl" className={alexandria.variable} suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: themeScript }} />
      </head>
      <body className="relative flex min-h-dvh flex-col">
        <SkipLink />
        <SiteHeader menus={menus} />
        {children}
        <SiteFooter menus={menus} site={site} />
        <BackToTop />
      </body>
    </html>
  );
}
