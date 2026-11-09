import { SectionView, sectionMetadata } from "@/components/sections/SectionView";

/** The first page of the round-table section (components/sections/SectionView). Blocking: its status comes before any byte. */
export const instant = false;

export async function generateMetadata({ searchParams }: PageProps<"/round-table">) {
  return sectionMetadata("round-table", undefined, await searchParams);
}

export default async function RoundTableSection({ searchParams }: PageProps<"/round-table">) {
  return <SectionView slug="round-table" query={await searchParams} />;
}
