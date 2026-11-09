import { SectionView, sectionMetadata } from "@/components/sections/SectionView";

/** The first page of the awareness section (components/sections/SectionView). Blocking: its status comes before any byte. */
export const instant = false;

export async function generateMetadata({ searchParams }: PageProps<"/awareness">) {
  return sectionMetadata("awareness", undefined, await searchParams);
}

export default async function AwarenessSection({ searchParams }: PageProps<"/awareness">) {
  return <SectionView slug="awareness" query={await searchParams} />;
}
