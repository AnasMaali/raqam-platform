import Link from "next/link";

import type { MenuItem, Menus } from "@/lib/types";

import { HeaderLogo } from "../brand/Logo";
import { ThemeChoice, ThemeToggle } from "../theme/ThemeControls";
import { Icon } from "../ui/Icon";

import { MobileMenu } from "./MobileMenu";
import { NavDisclosure } from "./NavDisclosure";

/** A menu link: inside the site through the router, outside it as a plain link (in a new tab only if the admin said so). */
function MenuLink({ item, className }: { item: MenuItem; className: string }) {
  if (item.external) {
    return (
      <a href={item.href} className={className} {...(item.opens_new_tab ? { target: "_blank", rel: "noopener" } : {})}>
        {item.label}
      </a>
    );
  }

  return (
    <Link href={item.href} className={className}>
      {item.label}
    </Link>
  );
}

/** The menu without its first "home" entry: the logo is the way home. */
const withoutHome = (items: MenuItem[]) => items.filter((item) => item.href !== "/");

/** From 1024 px: the header's second row, the platform's menu in one line. */
function DesktopNav({ items }: { items: MenuItem[] }) {
  return (
    <nav aria-label="القائمة الرئيسية" className="hidden border-t border-line lg:block">
      <ul className="mx-auto flex max-w-page flex-wrap items-center gap-x-6 px-8 text-small font-medium">
        {withoutHome(items).map((item) => (
          <li key={item.label}>
            {item.children.length > 0 ? (
              <NavDisclosure label={item.label}>
                <ul>
                  {item.children.map((child) => (
                    <li key={child.label}>
                      <MenuLink item={child} className="flex min-h-11 items-center px-4 hover:bg-tint hover:text-brand" />
                    </li>
                  ))}
                </ul>
              </NavDisclosure>
            ) : (
              <MenuLink item={item} className="inline-flex min-h-11 min-w-11 items-center justify-center hover:text-brand" />
            )}
          </li>
        ))}
      </ul>
    </nav>
  );
}

/** Below 1024 px: the same menu in the sheet, its groups written out, and the theme choice. */
function MenuSheet({ items }: { items: MenuItem[] }) {
  const singles = withoutHome(items).filter((item) => item.children.length === 0);
  const groups = items.filter((item) => item.children.length > 0);
  const row = "flex min-h-12 items-center border-b border-line hover:text-brand";

  return (
    <nav aria-label="القائمة الرئيسية">
      <ul className="font-medium">
        <li>
          <Link href="/" className={row}>
            الرئيسية
          </Link>
        </li>
        {singles.map((item) => (
          <li key={item.label}>
            <MenuLink item={item} className={row} />
          </li>
        ))}
      </ul>
      {groups.map((group, i) => (
        <section key={group.label} aria-labelledby={`menu-group-${i}`} className="mt-6">
          <h3 id={`menu-group-${i}`} className="text-small font-bold text-brand">
            {group.label}
          </h3>
          <ul className="mt-1 font-medium">
            {group.children.map((child) => (
              <li key={child.label}>
                <MenuLink item={child} className={row} />
              </li>
            ))}
          </ul>
        </section>
      ))}
    </nav>
  );
}

/**
 * The header of every page. Its first row holds the logo (without the slogan), search, the theme switch and, below
 * 1024 px, the menu button. From 1024 px the menu is a second row. Without the menus (the API is down) it still
 * shows the logo, search and the theme switch.
 */
export function SiteHeader({ menus }: { menus: Menus | null }) {
  const items = menus?.header ?? [];

  return (
    <header id="top" data-site-frame className="border-b border-line">
      <div className="mx-auto flex max-w-page items-center justify-between gap-4 px-4 py-2 sm:px-6 lg:px-8 lg:py-3">
        <HeaderLogo className="h-10 w-auto lg:h-12" />
        <div className="flex items-center gap-1">
          <Link href="/search" className="inline-flex size-11 items-center justify-center rounded-sm hover:bg-tint">
            <Icon name="search" />
            <span className="sr-only">بحث</span>
          </Link>
          <ThemeToggle />
          {items.length > 0 && (
            <MobileMenu>
              <MenuSheet items={items} />
              <div className="mt-8">
                <ThemeChoice />
              </div>
            </MobileMenu>
          )}
        </div>
      </div>
      {items.length > 0 && <DesktopNav items={items} />}
    </header>
  );
}
