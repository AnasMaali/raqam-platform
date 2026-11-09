import { SectionView, sectionMetadata } from "@/components/sections/SectionView";

/** The first page of the history section (components/sections/SectionView). Blocking: its status comes before any byte. */
export const instant = false;

export async function generateMetadata({ searchParams }: PageProps<"/history">) {
  return sectionMetadata("history", undefined, await searchParams);
}

export default async function HistorySection({ searchParams }: PageProps<"/history">) {
  return <SectionView slug="history" query={await searchParams} />;
}
