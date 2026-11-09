import { SectionView, sectionMetadata } from "@/components/sections/SectionView";

/** The first page of the podcast section (components/sections/SectionView). Blocking: its status comes before any byte. */
export const instant = false;

export async function generateMetadata({ searchParams }: PageProps<"/podcast">) {
  return sectionMetadata("podcast", undefined, await searchParams);
}

export default async function PodcastSection({ searchParams }: PageProps<"/podcast">) {
  return <SectionView slug="podcast" query={await searchParams} />;
}
