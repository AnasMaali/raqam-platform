// Captures the services and the panel's pages for the fake API from a local platform (run from frontend/:
// node tests/fake-api/capture-pages.mjs; reads API_BASE_URL and API_SITE_TOKEN from .env.local). Apart from
// capture.mjs, so these can be captured again without touching the content fixtures.
//
// The demo has no contact settings, no service questions and an offer that ends soon; the fixture fills those with
// made-up values (a fictional phone number, example.org) so every state is drawn, and the offer never ends in CI.
import { readFileSync, writeFileSync } from "node:fs";

const env = Object.fromEntries(
  readFileSync(new URL("../../.env.local", import.meta.url), "utf8")
    .split("\n")
    .filter((line) => line.includes("=") && !line.startsWith("#"))
    .map((line) => [line.slice(0, line.indexOf("=")), line.slice(line.indexOf("=") + 1).trim()]),
);
const base = env.API_BASE_URL.replace(/\/+$/, "");
const storage = new URL(base).origin + "/storage/";
const get = async (path) => (await fetch(base + path, { headers: { Accept: "application/json", "X-Raqam-Site-Token": env.API_SITE_TOKEN } })).json();

// Pictures point at the fake API's one placeholder; only the sizes the site asks for are kept.
const media = (value) => {
  if (Array.isArray(value)) return value.map(media);
  if (value && typeof value === "object") {
    const out = {};
    for (const [key, inner] of Object.entries(value)) {
      if (key === "variants") out.variants = Object.fromEntries(Object.entries(inner).filter(([size]) => ["sm", "md", "lg"].includes(size)).map(([k, v]) => [k, media(v)]));
      else out[key] = media(inner);
    }
    return out;
  }
  if (typeof value === "string" && value.startsWith(storage)) return `http://127.0.0.1:4010/media/${value.split("/").pop()}`;
  return value;
};

// Made up: a number from the range kept for fiction, and example.org.
const phone = "+15555550123";
const email = "contact@example.org";
const links = (topic) => ({
  whatsapp: `https://wa.me/${phone.slice(1)}?text=${encodeURIComponent(`مرحباً، أود الاستفسار عن «${topic}»`)}`,
  phone: `tel:${phone}`,
  email: `mailto:${email}?subject=${encodeURIComponent(`استفسار عن خدمة: ${topic}`)}`,
});

const services = media(await get("/services"));
const detail = {};
for (const group of services.data.groups) {
  for (const service of group.services) {
    service.contact = links(service.name);
    if (service.price.offer) service.price.offer.ends_at = "2030-12-31T21:59:59Z";
    const one = media(await get(`/services/${encodeURIComponent(service.slug)}`));
    one.data.contact = service.contact;
    one.data.price = service.price;
    one.data.examples = one.data.examples.slice(0, 3);
    detail[service.slug] = one;
    console.log("service", service.slug);
  }
}
// One service with its own questions and a description.
const first = detail[services.data.groups[0].services[0].slug].data;
first.faqs = [
  { question: "كم تستغرق الحلقة؟", answer: "بين 30 و45 دقيقة، ويُنشر الفيديو خلال أسبوع من التسجيل." },
  { question: "هل يمكن التسجيل خارج نابلس؟", answer: "نعم، بعد الاتفاق على المكان والموعد." },
];
first.description_html = "<p>نسجّل الحلقة في الاستوديو أو في مكانك، ونجهّز المحاور معك قبل التسجيل.</p>";
// One service whose contact settings are all empty: the page offers the contact page instead.
const last = services.data.groups.at(-1).services.at(-1);
last.contact = { whatsapp: null, phone: null, email: null };
detail[last.slug].data.contact = last.contact;

const pageList = await get("/pages");
const pages = {};
for (const p of pageList.data) pages[p.slug] = (await get(`/pages/${encodeURIComponent(p.slug)}`)).data;
// A page the admin added later, and its old address (a renamed page leaves a move in the redirects).
pages["شروط-المسابقة"] = { ...pages.terms, slug: "شروط-المسابقة", title: "شروط المسابقة", system_key: null, body_html: "<p>شروط مسابقة تجريبية.</p>" };
pageList.data.push({ slug: "شروط-المسابقة", title: "شروط المسابقة", system_key: null });
const redirects = {
  "/شروط-قديمة": { status: 301, to: "/شروط-المسابقة" },
  "/services/old-podcast": { status: 301, to: "/services/podcast-episode" },
  "/حملة": { status: 302, to: "https://example.org/campaign" },
  "/صفحة-محذوفة": { status: 410 },
};

writeFileSync(new URL("./fixtures/pages.json", import.meta.url), JSON.stringify({ services, detail, pageList, pages, redirects }) + "\n");

// The site's contact details, made up the same way.
const site = JSON.parse(readFileSync(new URL("./fixtures/site.json", import.meta.url), "utf8"));
site.data.contact = {
  ...site.data.contact,
  phone,
  whatsapp: phone,
  whatsapp_url: `https://wa.me/${phone.slice(1)}`,
  email,
  address: "نابلس، فلسطين",
  map_url: "https://www.openstreetmap.org/?mlat=32.2211&mlon=35.2544#map=15/32.2211/35.2544",
};
writeFileSync(new URL("./fixtures/site.json", import.meta.url), JSON.stringify(site) + "\n");
console.log("pages", Object.keys(pages).length);
