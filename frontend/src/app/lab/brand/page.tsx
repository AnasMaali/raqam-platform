import type { Metadata } from "next";

export const metadata: Metadata = { title: "الشعار" };

const logos = [
  { file: "logo-ar-horizontal", label: "عربي أفقي", width: 372, height: 135 },
  { file: "logo-ar-vertical", label: "عربي عمودي", width: 252, height: 234 },
  { file: "logo-en-horizontal", label: "إنجليزي أفقي", width: 372, height: 135 },
  { file: "logo-en-vertical", label: "إنجليزي عمودي", width: 252, height: 229 },
  { file: "logo-bilingual", label: "ثنائي اللغة", width: 437, height: 90 },
  { file: "mark", label: "الرمز وحده", width: 85, height: 123 },
] as const;

export default function BrandLab() {
  return (
    <main className="mx-auto w-full max-w-6xl px-4 py-10">
      <h1 className="mb-8 text-2xl font-bold">الشعار</h1>
      <ul className="grid gap-6 md:grid-cols-2">
        {logos.map((logo) => (
          <li key={logo.file} className="overflow-hidden rounded-lg border border-black/10 dark:border-white/15">
            <div className="grid grid-cols-2">
              <div className="flex items-center justify-center bg-white p-6">
                {/* eslint-disable-next-line @next/next/no-img-element -- vector files shown as they are */}
                <img src={`/brand/${logo.file}.svg`} alt={`${logo.label}، ملوّن`} width={logo.width} height={logo.height} className="h-auto max-h-32 w-auto max-w-full" />
              </div>
              <div className="flex items-center justify-center bg-[#0b0e14] p-6">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src={`/brand/${logo.file}-white.svg`} alt={`${logo.label}، أبيض`} width={logo.width} height={logo.height} className="h-auto max-h-32 w-auto max-w-full" />
              </div>
            </div>
            <p className="border-t border-black/10 px-4 py-2 text-sm dark:border-white/15">
              {logo.label}
              <code dir="ltr" translate="no" className="block text-end text-xs opacity-75">
                /brand/{logo.file}.svg
              </code>
            </p>
          </li>
        ))}
      </ul>
    </main>
  );
}
