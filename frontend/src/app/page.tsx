import { BreakingBar } from "@/components/site/BreakingBar";
import { Main } from "@/components/site/Main";
import { getBreaking } from "@/lib/server/site-data";

/** The home page. Its content (the directory) is the next package; the frame and the breaking bar are already real. */
export default async function Home() {
  const breaking = await getBreaking();

  return (
    <>
      <BreakingBar posts={breaking} />
      <Main>
        <div className="mx-auto max-w-page px-4 py-16 sm:px-6 lg:px-8">
          <h1 className="text-title font-bold">رقم: تاريخ الاقتصاد ومستقبله</h1>
          <p className="mt-4 max-w-text text-lead text-muted">الموقع قيد البناء.</p>
        </div>
      </Main>
    </>
  );
}
