import { SectionView, sectionMetadata } from "@/components/sections/SectionView";

/** The first page of the success-stories section (components/sections/SectionView). Blocking: its status comes before any byte. */
export const instant = false;

export async function generateMetadata({ searchParams }: PageProps<"/success-stories">) {
  return sectionMetadata("success-stories", undefined, await searchParams);
}

export default async function SuccessStoriesSection({ searchParams }: PageProps<"/success-stories">) {
  return <SectionView slug="success-stories" query={await searchParams} />;
}
