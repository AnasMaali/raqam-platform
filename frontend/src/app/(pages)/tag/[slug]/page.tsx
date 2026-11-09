import { ListView, listMetadata } from "@/components/lists/ListView";
import { listParams } from "@/lib/server/lists";

/** The first page of a tag's list (components/lists/ListView). Blocking: its status comes before any byte. */
export const instant = false;

export async function generateStaticParams() {
  return listParams("tag");
}

export async function generateMetadata({ params }: PageProps<"/tag/[slug]">) {
  const { slug } = await params;

  return listMetadata("tag", slug);
}

export default async function TagPage({ params }: PageProps<"/tag/[slug]">) {
  const { slug } = await params;

  return <ListView kind="tag" rawSlug={slug} />;
}
