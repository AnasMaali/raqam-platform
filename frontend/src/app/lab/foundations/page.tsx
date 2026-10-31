import type { Metadata } from "next";
import { Suspense, type ReactNode } from "react";

import { AdSlot } from "@/components/ads/AdSlot";
import { CompactCard, FeatureCard, LeadCard, RankedCard } from "@/components/cards/PostCard";
import { ChapterEdge, ChapterRow } from "@/components/chapters/ChapterIndex";
import { RatesBoard } from "@/components/markets/RatesBoard";
import { RatesStrip } from "@/components/markets/RatesStrip";
import { BreakingBar } from "@/components/site/BreakingBar";
import { Main } from "@/components/site/Main";
import { SiteHeader } from "@/components/site/SiteHeader";
import { ThemeChoice, ThemeToggle } from "@/components/theme/ThemeControls";
import { WeatherWidget } from "@/components/weather/WeatherWidget";

import { loadSamples } from "./samples";

export const metadata: Metadata = { title: "الأساس" };

const colours: { token: string; use: string }[] = [
  { token: "ground", use: "خلفية الصفحة" },
  { token: "surface", use: "سطح هادئ، إطار الصور، الفوتر" },
  { token: "tint", use: "الألسنة، تمرير المؤشر" },
  { token: "ink", use: "النص" },
  { token: "muted", use: "نص ثانوي" },
  { token: "line", use: "الخطوط الرفيعة" },
  { token: "line-strong", use: "حدود عناصر التحكم" },
  { token: "brand", use: "الروابط وأسماء الأبواب" },
  { token: "rise", use: "صعود" },
  { token: "fall", use: "هبوط" },
  { token: "breaking", use: "عاجل" },
  { token: "breaking-surface", use: "شريط العاجل" },
  { token: "success", use: "نجاح" },
  { token: "error", use: "خطأ" },
  { token: "past", use: "شكل الماضي (أزرق)" },
  { token: "future", use: "شكل المستقبل (سماوي)" },
];

const sizes: { token: string; sample: string }[] = [
  { token: "text-display", sample: "عنوان المادة الرئيسية" },
  { token: "text-title", sample: "عنوان صفحة" },
  { token: "text-heading", sample: "اسم باب" },
  { token: "text-subheading", sample: "عنوان بطاقة" },
  { token: "text-lead", sample: "ملخص المادة تحت عنوانها، بخط أكبر قليلاً من النص." },
  { token: "text-body", sample: "نص المادة: 16 بكسل على الجوال و18 على الشاشات الكبيرة، بتباعد أسطر 1.8." },
  { token: "text-small", sample: "سطر المعلومات، القوائم، الجداول" },
  { token: "text-tiny", sample: "كلمة «إعلان» ونسبة بيانات الطقس" },
  { token: "text-figure", sample: "120" },
];

function Section({ id, title, children }: { id: string; title: string; children: ReactNode }) {
  return (
    <section aria-labelledby={id} className="border-t border-line py-10">
      <h2 id={id} className="text-heading font-bold text-brand">
        {title}
      </h2>
      <div className="mt-6 space-y-10">{children}</div>
    </section>
  );
}

function State({ title, note, children }: { title: string; note?: string; children: ReactNode }) {
  return (
    <div>
      <h3 className="text-small font-bold text-muted">{title}</h3>
      {note && <p className="text-small text-muted">{note}</p>}
      <div className="mt-3">{children}</div>
    </div>
  );
}

function Nothing({ what }: { what: string }) {
  return <p className="border border-dashed border-line-strong px-4 py-3 text-small text-muted">{what}: لا يُرسم شيء، ولا تُحجز مساحة.</p>;
}

async function Foundations() {
  const s = await loadSamples();

  return (
    <Main>
      <div className="mx-auto max-w-page px-4 py-10 sm:px-6 lg:px-8 xl:grid xl:grid-cols-[1fr_13rem] xl:gap-10">
        <div className="min-w-0">
          <h1 className="text-title font-bold">الأساس: كل المكوّنات بكل حالاتها</h1>
          <p className="mt-3 max-w-text text-lead text-muted">
            المحتوى من بيانات التجربة؛ الحالات الناقصة (ممول، بدون صورة، قراءة طقس قديمة، تغيّر غير معروف) مصنوعة منها لهذه الصفحة فقط.
          </p>

          <Section id="colours" title="الألوان">
            <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
              {colours.map((c) => (
                <li key={c.token}>
                  <span aria-hidden="true" className="block h-14 rounded-sm border border-line" style={{ background: `var(--${c.token})` }} />
                  <p className="mt-2 text-small font-bold" dir="ltr">
                    {c.token}
                  </p>
                  <p className="text-small text-muted">{c.use}</p>
                </li>
              ))}
            </ul>
          </Section>

          <Section id="type" title="الخط والأحجام">
            <ul className="space-y-5">
              {sizes.map((t) => (
                <li key={t.token}>
                  <p className="text-tiny text-muted" dir="ltr">
                    {t.token}
                  </p>
                  <p className={`${t.token} font-bold`}>{t.sample}</p>
                </li>
              ))}
            </ul>
          </Section>

          <Section id="space" title="المسافات والزوايا">
            <State title="سلّم المسافات (4 بكسل للدرجة)">
              <ul className="flex flex-wrap items-end gap-4">
                {[1, 2, 3, 4, 6, 8, 10, 12, 16].map((n) => (
                  <li key={n} className="text-center text-tiny text-muted">
                    <span aria-hidden="true" className="block bg-brand" style={{ width: `calc(var(--spacing) * ${n})`, height: `calc(var(--spacing) * ${n})` }} />
                    <span dir="ltr">{n * 4}px</span>
                  </li>
                ))}
              </ul>
            </State>
            <State title="الزوايا">
              <ul className="flex flex-wrap gap-6 text-tiny text-muted">
                {["rounded-xs", "rounded-sm", "rounded-md"].map((r) => (
                  <li key={r}>
                    <span aria-hidden="true" className={`block size-16 border-2 border-brand ${r}`} />
                    <span dir="ltr">{r}</span>
                  </li>
                ))}
              </ul>
            </State>
            <State title="الميلان (55°)">
              <span className="slant-end inline-flex min-h-11 items-center bg-brand ps-4 pe-10 font-bold text-on-brand">باب</span>
            </State>
          </Section>

          <Section id="header" title="الهيدر">
            <State title="بدون القوائم" note="لما الـ API مش متاح: الشعار والبحث والوضع الداكن بس.">
              <div className="border border-line">
                <SiteHeader menus={null} />
              </div>
            </State>
            <State title="أزرار التحكم">
              <div className="flex flex-wrap items-end gap-8">
                <ThemeToggle />
                <ThemeChoice />
              </div>
            </State>
          </Section>

          <Section id="breaking" title="شريط العاجل">
            <State title="خبر عاجل">
              <BreakingBar posts={s.breaking} />
            </State>
            <State title="عنوان طويل">{s.long && <BreakingBar posts={[s.long]} />}</State>
            <State title="بدون عاجل">
              <BreakingBar posts={[]} />
              <Nothing what="شريط العاجل" />
            </State>
          </Section>

          <Section id="strip" title="شريط العملات">
            <State title="العادي">
              <RatesStrip data={s.ticker} />
            </State>
            <State title="تغيّر غير معروف وسعر ناقص">
              <RatesStrip data={s.unknownTicker} />
            </State>
          </Section>

          <Section id="board" title="لوحة العملات المصغّرة">
            <State title="3 عملات تحت 1024 بكسل، وكلها من 1024 وفوق">
              <div className="max-w-text">
                <RatesBoard data={s.board} headingLevel={3} />
              </div>
            </State>
          </Section>

          <Section id="weather" title="الطقس">
            <div className="grid gap-10 md:grid-cols-3">
              <State title="العادي">
                <WeatherWidget data={s.weather} headingLevel={3} />
              </State>
              <State title="قراءة قديمة">
                <WeatherWidget data={s.staleWeather} headingLevel={3} others={2} />
              </State>
              <State title="مدينة وحدة">
                <WeatherWidget data={s.lonelyWeather} headingLevel={3} />
              </State>
            </div>
          </Section>

          <Section id="ads" title="الإعلان">
            <State title="حملة (أعلى الصفحة)">
              <AdSlot slot={s.leaderboard} label={s.adLabel} />
            </State>
            <State title="بدون حملة: «أعلن معنا»">
              <AdSlot slot={s.houseOnly} label={s.adLabel} />
            </State>
            <State title="بين الأبواب">
              <AdSlot slot={s.between} label={s.adLabel} />
            </State>
            <State title="مربع جانبي">
              <AdSlot slot={s.mpu} label={s.adLabel} />
            </State>
            <State title="بدون حملة والخانة مخفية">
              <AdSlot slot={s.hidden} label={s.adLabel} />
              <Nothing what="خانة الإعلان" />
            </State>
          </Section>

          <Section id="cards" title="الكروت">
            <State title="المادة الرئيسية">{s.lead && <LeadCard post={s.lead} level={3} />}</State>
            <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
              <State title="بصورة">{s.feature && <FeatureCard post={s.feature} level={4} />}</State>
              <State title="قصة نجاح برقمها">{s.figure && <FeatureCard post={s.figure} level={4} />}</State>
              <State title="بدون صورة">{s.noPicture && <FeatureCard post={s.noPicture} level={4} />}</State>
              <State title="محتوى ممول">{s.sponsored && <FeatureCard post={s.sponsored} level={4} />}</State>
              <State title="عنوان طويل جداً">{s.long && <FeatureCard post={s.long} level={4} />}</State>
            </div>
            <div className="grid gap-8 md:grid-cols-2">
              <State title="مختصرة">
                <div className="divide-y divide-line border-y border-line">
                  {s.latest.map((p) => (
                    <CompactCard key={p.id} post={p} level={4} />
                  ))}
                </div>
              </State>
              <State title="مرتّبة (الأكثر قراءة)">
                <ol className="divide-y divide-line border-y border-line">
                  {s.mostRead.map((p, i) => (
                    <li key={p.id}>
                      <RankedCard post={p} rank={i + 1} level={4} />
                    </li>
                  ))}
                </ol>
              </State>
            </div>
          </Section>

          <Section id="chapters" title="الأبواب">
            <State title="الصف المختصر (تحت 1280 بكسل، بعد أول شاشة)" note="من 1280 وفوق: الفهرس على حافة الصفحة، على جنب.">
              <ChapterRow chapters={s.chapters} id="foundations-row" />
            </State>
          </Section>
        </div>
        <ChapterEdge chapters={s.chapters} id="foundations-edge" />
      </div>
    </Main>
  );
}

export default function FoundationsPage() {
  return (
    <Suspense fallback={<p className="p-8">جاري التحميل…</p>}>
      <Foundations />
    </Suspense>
  );
}
