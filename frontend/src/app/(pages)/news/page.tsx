import { SectionView, sectionMetadata } from "@/components/sections/SectionView";

/** The first page of /news (everything under it) (components/sections/SectionView). Blocking: its status comes before any byte. */
export const instant = false;

export async function generateMetadata({ searchParams }: PageProps<"/news">) {
  return sectionMetadata("news", undefined, await searchParams);
}

export default async function NewsSection({ searchParams }: PageProps<"/news">) {
  return <SectionView slug="news" query={await searchParams} />;
}
