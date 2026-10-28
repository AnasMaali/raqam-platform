import type { Metadata } from "next";
import { Suspense } from "react";
import { connection } from "next/server";

import { apiGet } from "@/lib/server/api";

import facts from "./font-facts.json";
import { candidates } from "./fonts";

export const metadata: Metadata = { title: "الخطوط" };

type Summary = { id: number; title: string; excerpt: string | null; published_at: string; category: { name: string } | null; authors: { name: string }[] };
type Detail = Summary & { body_html: string };
type Rate = { code: string; name: string; buy: string | null; sell: string | null; change_pct: string | null; direction: "up" | "down" | "same" | null; rate_date: string | null };

const minus = "−";
const oneDecimal = new Intl.NumberFormat("ar-u-nu-latn", { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const twoDecimals = new Intl.NumberFormat("ar-u-nu-latn", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const kb = (bytes: number) => `${oneDecimal.format(bytes / 1024)} ك.ب`;

/** The files this page loads for each family: every weight of a static family listed in fonts.ts, one file for a variable one. */
function loadedBytes(family: string) {
  const entry = facts.fonts.find((f) => f.family === family)!;
  const files = entry.variable ? entry.files : entry.files.filter((f) => ["400", "500", "700"].includes(f.weight));
  const arabic = files.reduce((sum, f) => sum + f.arabic, 0);
  const latin = files.reduce((sum, f) => sum + f.latin, 0);

  return { arabic, latin, total: arabic + latin, count: files.length };
}

function tabularNote(font: (typeof facts.fonts)[number]) {
  if (font.digitsTabularByDefault) return "نعم، الأرقام جدولية أصلاً";
  if (font.tabularAvailable) return "نعم، بخاصية tnum (الأرقام متناسبة بدونها)";

  return "لا: أرقام متناسبة وبدون tnum";
}

/** A signed change with its arrow, kept left-to-right inside the Arabic line. */
function Change({ value }: { value: number }) {
  const sign = value > 0 ? "+" : value < 0 ? minus : "";
  const arrow = value > 0 ? "▲" : value < 0 ? "▼" : "●";

  return (
    <span className="inline-flex items-baseline gap-1 whitespace-nowrap">
      <span aria-hidden="true" className="text-[0.7em]">{arrow}</span>
      <span dir="ltr" className="tabular-nums">{`${sign}${twoDecimals.format(Math.abs(value))}%`}</span>
    </span>
  );
}

function HeaderMock({ dark }: { dark: boolean }) {
  return (
    <div className={dark ? "bg-[#0b0e14] text-[#eef1f6]" : "bg-white text-[#10131a]"}>
      <div className="flex items-center justify-between gap-4 px-4 py-3">
        {/* eslint-disable-next-line @next/next/no-img-element -- the logo file as it is */}
        <img src={dark ? "/brand/logo-ar-horizontal-white.svg" : "/brand/logo-ar-horizontal.svg"} alt="رقم" width={372} height={135} className="h-10 w-auto" />
        <ul className="hidden gap-6 text-[15px] font-medium whitespace-nowrap md:flex">
          {["الأخبار", "البودكاست", "قصص نجاح", "صُنع في نابلس", "العملات"].map((item) => (
            <li key={item}>{item}</li>
          ))}
        </ul>
        <span className="text-sm font-medium md:hidden">القائمة</span>
      </div>
    </div>
  );
}

function Ticker({ rates, dark }: { rates: Rate[]; dark: boolean }) {
  return (
    <ul className={`flex justify-between gap-4 whitespace-nowrap border-t px-4 py-2 text-sm lg:justify-start lg:gap-6 ${dark ? "border-white/15 bg-[#0b0e14] text-[#eef1f6]" : "border-black/10 bg-white text-[#10131a]"}`}>
      {rates.slice(0, 4).map((r, i) => (
        <li key={r.code} className={`items-baseline gap-2 ${i < 2 ? "flex" : "hidden lg:flex"}`}>
          <span>{r.name}</span>
          <span dir="ltr" className="font-medium tabular-nums">{r.buy}</span>
          {r.change_pct !== null && <Change value={Number(r.change_pct)} />}
        </li>
      ))}
    </ul>
  );
}

function paragraphs(html: string) {
  return [...html.matchAll(/<p[^>]*>([\s\S]*?)<\/p>/g)].map((m) => m[1].replace(/<[^>]+>/g, "").trim()).filter(Boolean);
}

async function Specimens() {
  await connection();
  const [list, board] = await Promise.all([
    apiGet<{ data: Summary[] }>("/posts?kind=news", { cache: "no-store" }),
    apiGet<{ data: Rate[] }>("/currencies", { cache: "no-store" }),
  ]);
  // A headline with a number in it, so the digits are tested inside a title too.
  const pick = list.data.find((p) => /\d/.test(p.title)) ?? list.data[0];
  const post = (await apiGet<{ data: Detail }>(`/posts/${pick.id}`, { cache: "no-store" })).data;
  const rates = board.data;
  const usd = rates.find((r) => r.code === "USD") ?? rates[0];
  const today = new Date(`${usd.rate_date ?? post.published_at.slice(0, 10)}T12:00:00Z`);
  const levantine = new Intl.DateTimeFormat("ar-PS-u-nu-latn", { day: "numeric", month: "long", year: "numeric", timeZone: "Asia/Hebron" }).format(today);
  const egyptian = new Intl.DateTimeFormat("ar-u-nu-latn", { day: "numeric", month: "long", year: "numeric", timeZone: "Asia/Hebron" }).format(today);
  const published = new Intl.DateTimeFormat("ar-PS-u-nu-latn", { day: "numeric", month: "long", year: "numeric", hour: "numeric", minute: "2-digit", timeZone: "Asia/Hebron" }).format(new Date(post.published_at));
  const body = paragraphs(post.body_html);

  return candidates.map(({ family, font, note }) => (
    <section key={family} id={family.replaceAll(" ", "-")} aria-labelledby={`${family}-title`} className={`${font.className} border-t border-black/10 py-10 dark:border-white/15`}>
      <h2 id={`${family}-title`} className="text-2xl font-bold">
        {family}
      </h2>
      <p className="mt-1 text-sm opacity-75">{note}</p>

      <h3 className="mt-8 mb-3 text-sm font-medium opacity-75">بجانب الشعار، فاتح وداكن</h3>
      <div className="grid gap-4">
        {[false, true].map((dark) => (
          <div key={String(dark)} className="overflow-hidden rounded-lg border border-black/10 dark:border-white/15">
            <HeaderMock dark={dark} />
            <Ticker rates={rates} dark={dark} />
          </div>
        ))}
      </div>

      <h3 className="mt-10 mb-3 text-sm font-medium opacity-75">خبر من بيانات التجربة</h3>
      <article className="max-w-[68ch]">
        <p className="text-sm font-medium text-brand-blue dark:text-[#89a7ff]">{post.category?.name}</p>
        <p className="mt-2 text-[clamp(1.5rem,1.1rem+2vw,2.5rem)] leading-snug font-bold text-balance">{post.title}</p>
        {post.excerpt && <p className="mt-3 text-lg leading-relaxed opacity-90">{post.excerpt}</p>}
        <p className="mt-3 text-sm opacity-75">
          {post.authors.map((a) => a.name).join("، ") || "فريق رقم"} · {published}
        </p>
        <div className="mt-6 space-y-4 text-base leading-[1.8]">
          {body.map((text, i) => (
            <p key={i}>{text}</p>
          ))}
        </div>
      </article>

      <h3 className="mt-10 mb-3 text-sm font-medium opacity-75">الأرقام</h3>
      <div className="grid gap-6 lg:grid-cols-2">
        <div role="region" aria-labelledby={`${family}-rates`} tabIndex={0} className="overflow-x-auto rounded-lg border border-black/10 dark:border-white/15">
          <table className="w-full text-start text-sm sm:text-[15px]">
            <caption id={`${family}-rates`} className="px-3 pt-3 text-start text-sm font-medium">
              أسعار العملات مقابل الشيكل ({levantine})
            </caption>
            <thead>
              <tr className="border-b border-black/10 dark:border-white/15">
                <th scope="col" className="px-2 py-2 text-start font-medium sm:px-3">العملة</th>
                <th scope="col" className="px-2 py-2 text-end font-medium sm:px-3">شراء</th>
                <th scope="col" className="px-2 py-2 text-end font-medium sm:px-3">بيع</th>
                <th scope="col" className="px-2 py-2 text-end font-medium sm:px-3">التغيّر</th>
              </tr>
            </thead>
            <tbody>
              {rates.map((r) => (
                <tr key={r.code} className="border-b border-black/5 last:border-0 dark:border-white/10">
                  <th scope="row" className="px-2 py-2 text-start font-normal sm:px-3">{r.name}</th>
                  <td dir="ltr" className="px-2 py-2 text-start tabular-nums sm:px-3">{r.buy}</td>
                  <td dir="ltr" className="px-2 py-2 text-start tabular-nums sm:px-3">{r.sell}</td>
                  <td className="px-2 py-2 text-end sm:px-3">{r.change_pct === null ? "—" : <Change value={Number(r.change_pct)} />}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <dl className="space-y-4 text-base leading-[1.8]">
          <div>
            <dt className="text-sm opacity-75">نسب</dt>
            <dd className="flex flex-wrap gap-x-6 text-xl">
              <Change value={1.25} />
              <Change value={-0.4} />
              <Change value={12.5} />
            </dd>
          </div>
          <div>
            <dt className="text-sm opacity-75">تاريخ بأسماء الأشهر الشامية / بالأسماء الأخرى</dt>
            <dd>
              {levantine} / {egyptian}
            </dd>
          </div>
          <div>
            <dt className="text-sm opacity-75">أرقام داخل جملة</dt>
            <dd>
              سجّل {usd.name} اليوم <span dir="ltr">{usd.buy}</span> شيكل للشراء و<span dir="ltr">{usd.sell}</span> للبيع،
              {usd.change_pct !== null && (
                <>
                  {" "}
                  بتغيّر <Change value={Number(usd.change_pct)} /> عن النشرة السابقة،
                </>
              )}{" "}
              فيما انضمت 317 منشأة صغيرة إلى البرنامج منذ 1 تموز 2026، بقيمة 2.5 مليون شيكل.
            </dd>
          </div>
          <div>
            <dt className="text-sm opacity-75">النسبة بدون عزل اتجاه الكتابة (للمقارنة)</dt>
            <dd>
              ارتفع المؤشر {minus}0.40% ثم +1.25% خلال الأسبوع.
            </dd>
          </div>
        </dl>
      </div>
    </section>
  ));
}

export default function FontsLab() {
  return (
    <main className="mx-auto w-full max-w-6xl px-4 py-10">
      <h1 className="text-2xl font-bold">الخطوط</h1>
      <p className="mt-2 max-w-[68ch] opacity-90">
        خمسة خطوط مصممة للعربي، كلها برخصة OFL، ومحمّلة من نفس الموقع. كل خط معروض بجانب الشعار بالهيدر (فاتح وداكن)، ثم خبر
        حقيقي من بيانات التجربة بحجم 16 بكسل، ثم الأرقام: جدول العملات، النسب، التواريخ، وأرقام داخل جملة.
      </p>
      <nav aria-label="الخطوط في هذه الصفحة" className="mt-4">
        <ul className="flex flex-wrap gap-x-4">
          {candidates.map(({ family }) => (
            <li key={family}>
              <a href={`#${family.replaceAll(" ", "-")}`} className="inline-flex min-h-11 items-center underline underline-offset-4">
                {family}
              </a>
            </li>
          ))}
          <li>
            <a href="#recommendation" className="inline-flex min-h-11 items-center underline underline-offset-4">
              التوصية
            </a>
          </li>
        </ul>
      </nav>

      <h2 id="facts-caption" className="mt-8 text-sm font-medium">
        مقاسة من الملفات نفسها (woff2 كما يصل للمتصفح، العربي واللاتيني فقط، محور الوزن فقط)، بتاريخ{" "}
        {new Intl.DateTimeFormat("ar-PS-u-nu-latn", { day: "numeric", month: "long", year: "numeric", timeZone: "UTC" }).format(new Date(facts.measured))}
      </h2>
      <div role="region" aria-labelledby="facts-caption" tabIndex={0} className="mt-2 overflow-x-auto rounded-lg border border-black/10 dark:border-white/15">
        <table aria-labelledby="facts-caption" className="w-full min-w-[40rem] text-[15px]">
          <thead>
            <tr className="border-b border-black/10 dark:border-white/15">
              <th scope="col" className="px-3 py-2 text-start font-medium">الخط</th>
              <th scope="col" className="px-3 py-2 text-start font-medium">الأوزان</th>
              <th scope="col" className="px-3 py-2 text-start font-medium">متغيّر</th>
              <th scope="col" className="px-3 py-2 text-start font-medium">عربي</th>
              <th scope="col" className="px-3 py-2 text-start font-medium">لاتيني</th>
              <th scope="col" className="px-3 py-2 text-start font-medium">المجموع</th>
              <th scope="col" className="px-3 py-2 text-start font-medium">أرقام جدولية</th>
            </tr>
          </thead>
          <tbody>
            {facts.fonts.map((font) => {
              const size = loadedBytes(font.family);

              return (
                <tr key={font.family} className="border-b border-black/5 align-top last:border-0 dark:border-white/10">
                  <th scope="row" className="px-3 py-2 text-start font-medium" dir="ltr">{font.family}</th>
                  <td className="px-3 py-2 tabular-nums">{font.weights}</td>
                  <td className="px-3 py-2">{font.variable ? "نعم (ملف واحد لكل الأوزان)" : `لا (ملف لكل وزن؛ هنا ${size.count} ملفات لكل لغة)`}</td>
                  <td className="px-3 py-2 tabular-nums">{kb(size.arabic)}</td>
                  <td className="px-3 py-2 tabular-nums">{kb(size.latin)}</td>
                  <td className="px-3 py-2 font-medium tabular-nums">{kb(size.total)}{font.variable ? "" : " (3 أوزان)"}</td>
                  <td className="px-3 py-2">{tabularNote(font)}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <p className="mt-2 max-w-[68ch] text-sm opacity-75">
        الأرقام 0-9 موجودة بالملف اللاتيني، فأي صفحة فيها رقم بتحمّل الملفين. {facts.method}
      </p>

      <Suspense fallback={<p className="py-10">جاري تحميل بيانات التجربة…</p>}>
        <Specimens />
      </Suspense>

      <section id="recommendation" aria-labelledby="recommendation-title" className="border-t border-black/10 py-10 dark:border-white/15">
        <h2 id="recommendation-title" className="text-2xl font-bold">
          التوصية
        </h2>
        <div className="mt-4 max-w-[68ch] space-y-4 leading-[1.8]">
          <p className="text-lg font-medium">
            خط واحد يكفي: Alexandria للعناوين والنص والأرقام. الحجم الكلي {kb(loadedBytes("Alexandria").total)} لكل الأوزان (
            {kb(loadedBytes("Alexandria").arabic)} عربي و{kb(loadedBytes("Alexandria").latin)} لاتيني).
          </p>
          <ul className="list-disc space-y-2 ps-5">
            <li>
              <strong>بيشبه الشعار.</strong> حروفه هندسية زي «ق» الشعار بدائرتها المفرّغة، فالعناوين بتحكي نفس لغة الهوية بدون أي
              زخرفة إضافية.
            </li>
            <li>
              <strong>بيبان كبير وواضح على 16 بكسل.</strong> على 375 هو وReadex Pro أوضح الخمسة بنفس الحجم؛ IBM Plex وNoto Naskh
              بيبانوا أصغر بشكل واضح وبدهم 18 بكسل ليوصلوا لنفس الراحة. هاد مهم لجمهور الأعمال الأكبر بالعمر.
            </li>
            <li>
              <strong>ملف واحد متغيّر لكل الأوزان (100–900).</strong> العناوين والنص والأزرار بأوزان مختلفة بدون أي ملف زيادة، وهو
              أخف من IBM Plex بثلاث مرات (هداك ملف لكل وزن).
            </li>
            <li>
              <strong>أرقامه جدولية فعلاً بخاصية tnum.</strong> الافتراضي متناسب (أحلى داخل الجمل)، وبنفعّل
              <code dir="ltr" translate="no" className="mx-1">tabular-nums</code>
              بجداول العملات والشريط فالأعمدة بتصطف.
            </li>
          </ul>
          <h3 className="pt-2 font-bold">ليش مش الباقي</h3>
          <ul className="list-disc space-y-2 ps-5">
            <li>
              <strong>Readex Pro:</strong> قريب بالشكل وأخف بالعربي، بس أرقامه متناسبة وما فيه tnum أبداً، فجدول العملات ما بيصطف.
              وصفحة العملات من أهم صفحات الموقع.
            </li>
            <li>
              <strong>IBM Plex Sans Arabic:</strong> جدّي ومحايد، بس أوزانه ثابتة: 3 أوزان = {kb(loadedBytes("IBM Plex Sans Arabic").total)}، ثلاث
              أضعاف، وبيبان أصغر.
            </li>
            <li>
              <strong>Noto Naskh Arabic:</strong> جميل للكتب، بس الأصغر على الجوال، وملفه العربي ({kb(loadedBytes("Noto Naskh Arabic").arabic)}) ثلاث أضعاف ملف Alexandria،
              وروحه تقليدية بعيدة عن هندسة الشعار.
            </li>
            <li>
              <strong>Cairo:</strong> ما تفوّق بوضوح، وهو الأكثر انتشاراً بالمواقع العربية، فبيخلّي الموقع يشبه غيره.
            </li>
          </ul>
          <h3 className="pt-2 font-bold">إعدادات مقترحة للخطوة 6</h3>
          <ul className="list-disc space-y-2 ps-5">
            <li>النص 16 بكسل على الجوال و17–18 على الشاشات الكبيرة، وزن 400، تباعد أسطر 1.8.</li>
            <li>العناوين بوزن 700، والأزرار والقوائم 500. على الداكن ممكن نخفف النص لوزن 350 (الخط متغيّر).</li>
            <li>نحمّل الملف العربي مسبقاً (preload) بس؛ اللاتيني بينزل لما تظهر أرقام.</li>
            <li>
              توفير ممكن لاحقاً: الملف اللاتيني فيه كل الحروف اللاتينية وإحنا بنحتاج الأرقام والرموز بس. نسخة مقصوصة على مقاسنا (أرقام
              ورموز) قستها: 9.7 ك.ب بدل 29.4، يعني توفير 20 ك.ب تقريباً.
            </li>
          </ul>
          <h3 className="pt-2 font-bold">سؤال مفتوح</h3>
          <p>
            أسماء الأشهر: «تشرين الأول» (الشامية) ولا «أكتوبر»؟ الصفحة بتعرض الاثنين. اقتراحي الشامية لأنها المستعملة بفلسطين،
            والقرار للزبون.
          </p>
        </div>
      </section>
    </main>
  );
}
