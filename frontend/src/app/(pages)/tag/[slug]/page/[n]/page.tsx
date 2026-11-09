import { ListView, listMetadata } from "@/components/lists/ListView";
import { listParams } from "@/lib/server/lists";

/** A further page of a tag's list, /page/{n}: its own canonical page; past the last one, a 404. */
export const instant = false;

export async function generateStaticParams() {
  return (await listParams("tag")).slice(0, 1).map((p) => ({ ...p, n: "2" }));
}

export async function generateMetadata({ params }: PageProps<"/tag/[slug]/page/[n]">) {
  const { slug, n } = await params;

  return listMetadata("tag", slug, n);
}

export default async function TagListPage({ params }: PageProps<"/tag/[slug]/page/[n]">) {
  const { slug, n } = await params;

  return <ListView kind="tag" rawSlug={slug} rawPage={n} />;
}
