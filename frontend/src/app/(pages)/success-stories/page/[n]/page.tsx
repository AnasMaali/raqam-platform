import { SectionView, sectionMetadata } from "@/components/sections/SectionView";

/** A further page of the success-stories section, /page/{n}: its own canonical page; past the last one, a 404. */
export const instant = false;

export async function generateMetadata({ params, searchParams }: PageProps<"/success-stories/page/[n]">) {
  return sectionMetadata("success-stories", (await params).n, await searchParams);
}

export default async function SuccessStoriesSectionPage({ params, searchParams }: PageProps<"/success-stories/page/[n]">) {
  return <SectionView slug="success-stories" rawPage={(await params).n} query={await searchParams} />;
}
