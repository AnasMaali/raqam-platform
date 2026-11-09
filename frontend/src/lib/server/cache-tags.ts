import "server-only";

/*
 * The cache tags of the platform's answers, and which of them each webhook type refreshes (docs/api/openapi.yaml,
 * the webhook section). Every cached answer also carries `everything`, for `{"type": "all"}`.
 */

export const tags = {
  everything: "api",
  menus: "menus",
  site: "site",
  currencies: "currencies",
  weather: "weather",
  posts: "posts",
  home: "home",
  ads: "ads",
  pages: "pages",
  services: "services",
} as const;

type Tag = (typeof tags)[keyof typeof tags];

/** The tags a webhook of this type refreshes; an unknown type refreshes everything (a signed message means a change). */
export function tagsFor(type: string, id?: unknown): string[] {
  const one = (tag: Tag, extra: string[] = []) => [tag, ...extra];
  const own = (prefix: string) => (Number.isSafeInteger(id) && (id as number) > 0 ? [`${prefix}:${id}`] : []);

  switch (type) {
    case "post":
      // A post shows on the home page and in the breaking bar as well as on its own page.
      return [tags.posts, tags.home, ...own("post")];
    case "page":
      return one(tags.pages, own("page"));
    case "home":
      return one(tags.home);
    case "ads":
      return one(tags.ads);
    case "currencies":
      return one(tags.currencies);
    case "menus":
      return one(tags.menus);
    case "site":
      return one(tags.site);
    case "services":
      return one(tags.services);
    default:
      return [tags.everything];
  }
}
