import { ListView, listMetadata } from "@/components/lists/ListView";
import { listParams } from "@/lib/server/lists";

/** A further page of a writer's list, /page/{n}: its own canonical page; past the last one, a 404. */
export const instant = false;

export async function generateStaticParams() {
  return (await listParams("author")).slice(0, 1).map((p) => ({ ...p, n: "2" }));
}

export async function generateMetadata({ params }: PageProps<"/author/[slug]/page/[n]">) {
  const { slug, n } = await params;

  return listMetadata("author", slug, n);
}

export default async function AuthorListPage({ params }: PageProps<"/author/[slug]/page/[n]">) {
  const { slug, n } = await params;

  return <ListView kind="author" rawSlug={slug} rawPage={n} />;
}
