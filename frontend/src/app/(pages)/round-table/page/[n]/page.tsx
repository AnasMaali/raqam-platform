import { SectionView, sectionMetadata } from "@/components/sections/SectionView";

/** A further page of the round-table section, /page/{n}: its own canonical page; past the last one, a 404. */
export const instant = false;

export async function generateMetadata({ params, searchParams }: PageProps<"/round-table/page/[n]">) {
  return sectionMetadata("round-table", (await params).n, await searchParams);
}

export default async function RoundTableSectionPage({ params, searchParams }: PageProps<"/round-table/page/[n]">) {
  return <SectionView slug="round-table" rawPage={(await params).n} query={await searchParams} />;
}
