import { SectionView, sectionMetadata } from "@/components/sections/SectionView";

/** A further page of the awareness section, /page/{n}: its own canonical page; past the last one, a 404. */
export const instant = false;

export async function generateMetadata({ params, searchParams }: PageProps<"/awareness/page/[n]">) {
  return sectionMetadata("awareness", (await params).n, await searchParams);
}

export default async function AwarenessSectionPage({ params, searchParams }: PageProps<"/awareness/page/[n]">) {
  return <SectionView slug="awareness" rawPage={(await params).n} query={await searchParams} />;
}
