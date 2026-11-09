import { RatesStrip } from "@/components/markets/RatesStrip";
import { BreakingBar } from "@/components/site/BreakingBar";
import { Main } from "@/components/site/Main";
import { getBreaking, getTicker } from "@/lib/server/site-data";

/**
 * Every public page but the home page: the thin currency strip under the header (the home page has the board beside
 * its main story), then the breaking bar when there is breaking news, then the page.
 */
export default async function PagesLayout({ children }: LayoutProps<"/">) {
  const [ticker, breaking] = await Promise.all([getTicker(), getBreaking()]);

  return (
    <>
      <RatesStrip data={ticker} />
      <BreakingBar posts={breaking} />
      <Main>{children}</Main>
    </>
  );
}
