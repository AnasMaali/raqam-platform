// Captures the fixtures of the fake API from a local platform filled with `php artisan raqam:demo` (made-up content).
// Run from frontend/: node tests/fake-api/capture.mjs  (reads API_BASE_URL and API_SITE_TOKEN from .env.local).
import { mkdirSync, readFileSync, writeFileSync } from "node:fs";

const env = Object.fromEntries(
  readFileSync(new URL("../../.env.local", import.meta.url), "utf8")
    .split("\n")
    .filter((line) => line.includes("=") && !line.startsWith("#"))
    .map((line) => [line.slice(0, line.indexOf("=")), line.slice(line.indexOf("=") + 1).trim()]),
);
const base = env.API_BASE_URL.replace(/\/+$/, "");
const storage = new URL(base).origin + "/storage/";

const endpoints = {
  site: "/site",
  menus: "/menus",
  currencies: "/currencies",
  ticker: "/currencies/ticker",
  weather: "/weather/widget",
  ads: "/ads",
  breaking: "/posts?breaking=1",
  home: "/home",
  sections: "/sections",
};

// One post of every kind, for the article template.
const kinds = ["news", "report", "opinion", "infographic", "episode", "story", "product", "history", "initiative", "roundtable", "awareness"];

// Pictures point at the fake API's one placeholder; only the sizes the site asks for are kept.
const media = (value) => {
  if (Array.isArray(value)) return value.map(media);
  if (value && typeof value === "object") {
    const out = {};
    for (const [key, inner] of Object.entries(value)) {
      if (key === "variants") {
        out.variants = Object.fromEntries(Object.entries(inner).filter(([size]) => ["sm", "md", "lg"].includes(size)).map(([k, v]) => [k, media(v)]));
      } else if (key === "click_url" && typeof inner === "string") {
        // The signature belongs to the local platform's key: the fixture keeps the address's shape only.
        out.click_url = `http://127.0.0.1:4010${new URL(inner).pathname.replace(/[^/]+$/, "fixture")}`;
      } else out[key] = media(inner);
    }
    return out;
  }
  // Addresses inside text too (an article's body holds its pictures' addresses).
  if (typeof value === "string" && value.includes(storage)) return value.replaceAll(new RegExp(`${storage.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}[^"\\s]*/([^/"\\s]+)`, "g"), "http://127.0.0.1:4010/media/$1");
  return value;
};

const get = async (path) => (await fetch(base + path, { headers: { Accept: "application/json", "X-Raqam-Site-Token": env.API_SITE_TOKEN } })).json();

for (const [name, path] of Object.entries(endpoints)) {
  const response = await fetch(base + path, { headers: { Accept: "application/json", "X-Raqam-Site-Token": env.API_SITE_TOKEN } });
  let body = await response.json();
  // The home answer is large: three items per block are enough for every state.
  if (name === "home") body = { ...body, data: body.data.map((b) => ({ ...b, ...(b.posts ? { posts: b.posts.slice(0, 3) } : {}), ...(b.videos ? { videos: b.videos.slice(0, 3) } : {}) })) };
  if (name === "breaking") body = { ...body, data: body.data.slice(0, 1) };
  writeFileSync(new URL(`./fixtures/${name}.json`, import.meta.url), JSON.stringify(media(body)) + "\n");
  console.log(name, response.status);
}

// The posts: each kind's newest item as the platform answers /posts/{id}, and their list.
mkdirSync(new URL("./fixtures/posts/", import.meta.url), { recursive: true });
const summaries = [];
for (const kind of kinds) {
  const item = (await get(`/posts?kind=${kind}&per_page=1`)).data[0];
  if (!item) continue;
  const post = media(await get(`/posts/${item.id}`));
  writeFileSync(new URL(`./fixtures/posts/${item.id}.json`, import.meta.url), JSON.stringify(post) + "\n");
  summaries.push(media(item));
  console.log("post", kind, item.id);
}

// A made-up variant for the states the demo has none of: sponsored, corrected, without an author or a picture, a long
// title. Its id cannot clash with a real one.
const opinion = JSON.parse(readFileSync(new URL(`./fixtures/posts/${summaries.find((p) => p.kind.code === "opinion").id}.json`, import.meta.url), "utf8"));
const maker = summaries.find((p) => p.kind.code === "product");
const makerPost = JSON.parse(readFileSync(new URL(`./fixtures/posts/${maker.id}.json`, import.meta.url), "utf8"));
const id = 900001;
const slug = "مادة-ممولة-للاختبار";
const variant = {
  data: {
    ...opinion.data,
    id,
    slug,
    path: `/news/${id}/${slug}`,
    title: "مادة ممولة للاختبار بعنوان طويل جداً يمتد على أكثر من سطر واحد ليختبر التفاف العناوين الطويلة في الصفحة على كل المقاسات",
    cover: null,
    authors: [],
    tags: [],
    related: [],
    sponsor: makerPost.data.details.organization,
    correction: { note: "صُحّح رقم في الفقرة الثانية: النسبة 12% لا 21%.", corrected_at: opinion.data.updated_at },
    body_html: `<p>فقرة أولى من مادة ممولة.</p><p>فقرة فيها <a href="https://sponsor.example/offer" target="_blank" rel="noopener">رابط للراعي</a> ورابط <a href="/news">داخلي</a>.</p><p>فقرة ثالثة.</p><p>فقرة رابعة بعد الإعلان.</p>`,
    seo: { ...opinion.data.seo, title: "مادة ممولة للاختبار", share_image: null, noindex: true },
  },
};
writeFileSync(new URL(`./fixtures/posts/${id}.json`, import.meta.url), JSON.stringify(variant) + "\n");
// A list item has only the summary's fields.
const detailOnly = new Set(["body_html", "tags", "video", "awareness_cards", "gallery", "guests", "details", "related", "correction", "seo", "revision"]);
const summary = Object.fromEntries(Object.entries(variant.data).filter(([key]) => !detailOnly.has(key)));
summaries.push(summary);
writeFileSync(new URL("./fixtures/posts.json", import.meta.url), JSON.stringify({ data: summaries }) + "\n");
console.log("posts", summaries.length);

// The list pages: four real lists with every page of their items (a category over two pages, a tag, a writer over
// three, a guest), the indexes the build reads, the category's feed, and a made-up empty category.
const lists = { category: ["طاقة"], tag: ["الطاقة-الشمسية"], author: ["محمد-صالح"], guest: ["عمر-الحسن"] };
const plural = { category: "categories", tag: "tags", author: "authors", guest: "guests" };
const listFixture = { entities: { category: {}, tag: {}, author: {}, guest: {} }, items: {}, index: {}, feeds: {} };
for (const [kind, slugs] of Object.entries(lists)) {
  for (const slug of slugs) {
    listFixture.entities[kind][slug] = media((await get(`/${plural[kind]}/${encodeURIComponent(slug)}`)).data);
    const items = [];
    for (let page = 1; ; page++) {
      const body = await get(`/posts?${kind}=${encodeURIComponent(slug)}&per_page=12&page=${page}`);
      items.push(...media(body.data));
      if (page >= body.meta.last_page) break;
    }
    listFixture.items[`${kind}:${slug}`] = items;
    console.log("list", kind, slug, items.length);
  }
  listFixture.index[plural[kind]] = Object.values(listFixture.entities[kind]).map((e) => ({ name: e.name, slug: e.slug }));
}
listFixture.entities.category["تصنيف-فارغ"] = { id: 900, name: "تصنيف فارغ", slug: "تصنيف-فارغ", description: "تصنيف بلا مواد، للاختبار.", is_highlighted: false, children: [] };
listFixture.items["category:تصنيف-فارغ"] = [];
listFixture.index.categories.push({ name: "تصنيف فارغ", slug: "تصنيف-فارغ", children: [] });
listFixture.feeds["طاقة"] = media((await get(`/feeds/categories/${encodeURIComponent("طاقة")}`)).data);
writeFileSync(new URL("./fixtures/lists.json", import.meta.url), JSON.stringify(listFixture) + "\n");

// The section pages: each section with its filters, all its items (history on its timeline order), the awareness
// characters, the sections' feeds, and the first three pages of /news.
const sectionSlugs = ["podcast", "success-stories", "made-in-nablus", "history", "initiatives", "round-table", "awareness"];
const sectionFixture = { detail: {}, items: {}, feeds: {}, awareness: null, news: [] };
for (const slug of sectionSlugs) {
  sectionFixture.detail[slug] = media((await get(`/sections/${slug}`)).data);
  const items = [];
  for (let page = 1; ; page++) {
    const body = await get(`/posts?section=${slug}&per_page=12&page=${page}${slug === "history" ? "&order=timeline" : ""}`);
    items.push(...media(body.data));
    if (page >= body.meta.last_page) break;
  }
  sectionFixture.items[slug] = items;
  sectionFixture.feeds[slug] = media((await get(`/feeds/sections/${slug}`)).data);
  console.log("section", slug, items.length);
}
sectionFixture.awareness = media((await get("/awareness")).data);
for (let page = 1; page <= 3; page++) sectionFixture.news.push(...media((await get(`/posts?kind=news,report,opinion,infographic&per_page=12&page=${page}`)).data));
writeFileSync(new URL("./fixtures/sections-pages.json", import.meta.url), JSON.stringify(sectionFixture) + "\n");
