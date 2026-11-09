import { SectionView, sectionMetadata } from "@/components/sections/SectionView";

/** The first page of the initiatives section (components/sections/SectionView). Blocking: its status comes before any byte. */
export const instant = false;

export async function generateMetadata({ searchParams }: PageProps<"/initiatives">) {
  return sectionMetadata("initiatives", undefined, await searchParams);
}

export default async function InitiativesSection({ searchParams }: PageProps<"/initiatives">) {
  return <SectionView slug="initiatives" query={await searchParams} />;
}
