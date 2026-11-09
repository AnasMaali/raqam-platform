import { ListView, listMetadata } from "@/components/lists/ListView";
import { listParams } from "@/lib/server/lists";

/** A further page of a category's list, /page/{n}: its own canonical page; past the last one, a 404. */
export const instant = false;

export async function generateStaticParams() {
  return (await listParams("category")).slice(0, 1).map((p) => ({ ...p, n: "2" }));
}

export async function generateMetadata({ params }: PageProps<"/category/[slug]/page/[n]">) {
  const { slug, n } = await params;

  return listMetadata("category", slug, n);
}

export default async function CategoryListPage({ params }: PageProps<"/category/[slug]/page/[n]">) {
  const { slug, n } = await params;

  return <ListView kind="category" rawSlug={slug} rawPage={n} />;
}
