export default function Home() {
  return (
    <main className="flex flex-1 flex-col items-center justify-center gap-6 px-4 py-16">
      <h1>
        {/* eslint-disable-next-line @next/next/no-img-element -- a vector logo needs no image optimisation */}
        <img src="/brand/logo-ar-vertical.svg" alt="رقم" width={252} height={234} className="h-auto w-40 dark:hidden" />
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src="/brand/logo-ar-vertical-white.svg" alt="رقم" width={252} height={234} className="hidden h-auto w-40 dark:block" />
      </h1>
      <p>الموقع قيد البناء.</p>
    </main>
  );
}
