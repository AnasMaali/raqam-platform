import { ListView, listMetadata } from "@/components/lists/ListView";
import { listParams } from "@/lib/server/lists";

/** The first page of a category's list (components/lists/ListView). Blocking: its status comes before any byte. */
export const instant = false;

export async function generateStaticParams() {
  return listParams("category");
}

export async function generateMetadata({ params }: PageProps<"/category/[slug]">) {
  const { slug } = await params;

  return listMetadata("category", slug);
}

export default async function CategoryPage({ params }: PageProps<"/category/[slug]">) {
  const { slug } = await params;

  return <ListView kind="category" rawSlug={slug} />;
}
