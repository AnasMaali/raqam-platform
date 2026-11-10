// Captures the search's answers for the fake API from a local platform with its search engine running (run from
// frontend/: node tests/fake-api/capture-search.mjs; reads API_BASE_URL and API_SITE_TOKEN from .env.local).
//
// A few queries as the platform answers them: results over several pages, a filter, the newest first, a writer's
// name, and a query that finds nothing. The engine tolerates typos so well that the demo has no query with close
// words to offer; the fixture gives that one made-up suggestions so the state is drawn.
import { readFileSync, writeFileSync } from "node:fs";

const env = Object.fromEntries(
  readFileSync(new URL("../../.env.local", import.meta.url), "utf8")
    .split("\n")
    .filter((line) => line.includes("=") && !line.startsWith("#"))
    .map((line) => [line.slice(0, line.indexOf("=")), line.slice(line.indexOf("=") + 1).trim()]),
);
const base = env.API_BASE_URL.replace(/\/+$/, "");
const storage = new URL(base).origin + "/storage/";
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
const order = ["q", "category", "section", "kind", "from", "to", "sort", "page"];
const key = (params) => new URLSearchParams(order.flatMap((k) => (params[k] ? [[k, params[k]]] : []))).toString();

const queries = [
  { q: "الطاقة" },
  { q: "الطاقة", page: "2" },
  { q: "الطاقة", kind: "news" },
  { q: "الطاقة", sort: "latest" },
  { q: "محمد" },
];
const answers = {};
for (const params of queries) {
  const response = await fetch(`${base}/search?${key(params)}`, { headers: { Accept: "application/json", "X-Raqam-Site-Token": env.API_SITE_TOKEN, "X-Raqam-Visitor-Ip": "127.0.0.1" } });
  answers[key(params)] = media(await response.json());
  console.log(key(params), answers[key(params)].meta.total);
}
const nothing = await fetch(`${base}/search?q=${encodeURIComponent("طاقةةة")}`, { headers: { Accept: "application/json", "X-Raqam-Site-Token": env.API_SITE_TOKEN, "X-Raqam-Visitor-Ip": "127.0.0.1" } });
const empty = media(await nothing.json());
empty.data.suggestions = ["الطاقة", "طاقة شمسية"];

// A made-up answer for a title with «<», «&» and quotes, escaped as the platform escapes it (e() in PHP), with the
// matched word in <mark>: the page must show the title as written, escaped once.
const sample = structuredClone(answers[key({ q: "الطاقة" })]);
const [first] = sample.data.posts;
sample.data = { ...sample.data, query: "سعر", posts: [{ ...first, title: "النمو < 2% & سعر 'الدولار' <b>", highlight: { title: "النمو &lt; 2% &amp; <mark>سعر</mark> &#039;الدولار&#039; &lt;b&gt;", excerpt: null } }], authors: [], guests: [], tags: [] };
sample.meta = { ...sample.meta, page: 1, total: 1, last_page: 1 };
answers[key({ q: "سعر" })] = sample;

writeFileSync(new URL("./fixtures/search.json", import.meta.url), JSON.stringify({ answers, empty }) + "\n");
