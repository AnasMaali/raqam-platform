import { ListView, listMetadata } from "@/components/lists/ListView";
import { listParams } from "@/lib/server/lists";

/** The first page of a writer's list (components/lists/ListView). Blocking: its status comes before any byte. */
export const instant = false;

export async function generateStaticParams() {
  return listParams("author");
}

export async function generateMetadata({ params }: PageProps<"/author/[slug]">) {
  const { slug } = await params;

  return listMetadata("author", slug);
}

export default async function AuthorPage({ params }: PageProps<"/author/[slug]">) {
  const { slug } = await params;

  return <ListView kind="author" rawSlug={slug} />;
}
