import { SectionView, sectionMetadata } from "@/components/sections/SectionView";

/** A further page of the history section, /page/{n}: its own canonical page; past the last one, a 404. */
export const instant = false;

export async function generateMetadata({ params, searchParams }: PageProps<"/history/page/[n]">) {
  return sectionMetadata("history", (await params).n, await searchParams);
}

export default async function HistorySectionPage({ params, searchParams }: PageProps<"/history/page/[n]">) {
  return <SectionView slug="history" rawPage={(await params).n} query={await searchParams} />;
}
