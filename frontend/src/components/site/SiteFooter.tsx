import { cacheLife } from "next/cache";
import Link from "next/link";

import type { MenuItem, Menus, Site } from "@/lib/types";

import { FullLogo } from "../brand/Logo";
import { ThemeChoice } from "../theme/ThemeControls";

async function Year() {
  "use cache";
  cacheLife("days");

  return <>{new Date().getFullYear()}</>;
}

function LinkList({ id, title, items }: { id: string; title: string; items: MenuItem[] }) {
  if (items.length === 0) return null;

  return (
    <nav aria-labelledby={id}>
      <h2 id={id} className="text-small font-bold text-brand">
        {title}
      </h2>
      <ul className="mt-2 text-small">
        {items.map((item) => (
          <li key={item.label}>
            {item.external ? (
              <a href={item.href} className="inline-flex min-h-11 items-center hover:text-brand hover:underline">
                {item.label}
              </a>
            ) : (
              <Link href={item.href} className="inline-flex min-h-11 items-center hover:text-brand hover:underline">
                {item.label}
              </Link>
            )}
          </li>
        ))}
      </ul>
    </nav>
  );
}

/**
 * The foot of every page: the full logo with its slogan, the platform's line, the about and policy menus, the social
 * accounts the admin made visible, and the full theme choice. Each part is left out when the API has nothing for it.
 */
export function SiteFooter({ menus, site }: { menus: Menus | null; site: Site | null }) {
  const social = site?.social ?? [];

  return (
    <footer data-site-frame className="mt-16 border-t border-line bg-surface">
      <div className="mx-auto grid max-w-page gap-10 px-4 py-10 sm:px-6 md:grid-cols-2 lg:grid-cols-12 lg:px-8 lg:py-14">
        <div className="lg:col-span-4">
          <FullLogo className="h-16 w-auto lg:h-20" />
          <p className="mt-4 max-w-text text-muted">خلف كل رقم حكاية، وخلف كل حكاية اقتصاد{"\u00a0"}يتشكّل.</p>
          {site?.contact.address && <p className="mt-2 text-small text-muted">{site.contact.address}</p>}
        </div>
        <div className="grid gap-8 sm:grid-cols-2 lg:col-span-5">
          <LinkList id="footer-main" title="عن رقم" items={menus?.footer_main ?? []} />
          <LinkList id="footer-legal" title="السياسات" items={menus?.footer_legal ?? []} />
        </div>
        <div className="space-y-8 lg:col-span-3">
          {social.length > 0 && (
            <nav aria-labelledby="footer-social">
              <h2 id="footer-social" className="text-small font-bold text-brand">
                تابعنا
              </h2>
              <ul className="mt-2 flex flex-wrap gap-x-5 text-small">
                {social.map((account) => (
                  <li key={account.url}>
                    <a href={account.url} rel="me noopener" className="inline-flex min-h-11 items-center hover:text-brand hover:underline">
                      {account.name}
                    </a>
                  </li>
                ))}
              </ul>
            </nav>
          )}
          <ThemeChoice />
        </div>
      </div>
      <div className="border-t border-line">
        <p className="mx-auto max-w-page px-4 py-4 text-small text-muted sm:px-6 lg:px-8">
          © <Year /> رقم. جميع الحقوق محفوظة.
        </p>
      </div>
    </footer>
  );
}
