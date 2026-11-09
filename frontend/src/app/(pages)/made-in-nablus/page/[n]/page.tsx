import { SectionView, sectionMetadata } from "@/components/sections/SectionView";

/** A further page of the made-in-nablus section, /page/{n}: its own canonical page; past the last one, a 404. */
export const instant = false;

export async function generateMetadata({ params, searchParams }: PageProps<"/made-in-nablus/page/[n]">) {
  return sectionMetadata("made-in-nablus", (await params).n, await searchParams);
}

export default async function MadeInNablusSectionPage({ params, searchParams }: PageProps<"/made-in-nablus/page/[n]">) {
  return <SectionView slug="made-in-nablus" rawPage={(await params).n} query={await searchParams} />;
}
