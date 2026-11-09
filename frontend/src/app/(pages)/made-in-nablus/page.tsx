import { SectionView, sectionMetadata } from "@/components/sections/SectionView";

/** The first page of the made-in-nablus section (components/sections/SectionView). Blocking: its status comes before any byte. */
export const instant = false;

export async function generateMetadata({ searchParams }: PageProps<"/made-in-nablus">) {
  return sectionMetadata("made-in-nablus", undefined, await searchParams);
}

export default async function MadeInNablusSection({ searchParams }: PageProps<"/made-in-nablus">) {
  return <SectionView slug="made-in-nablus" query={await searchParams} />;
}
