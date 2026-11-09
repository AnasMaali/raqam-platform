import { ListView, listMetadata } from "@/components/lists/ListView";
import { listParams } from "@/lib/server/lists";

/** The first page of a guest's list (components/lists/ListView). Blocking: its status comes before any byte. */
export const instant = false;

export async function generateStaticParams() {
  return listParams("guest");
}

export async function generateMetadata({ params }: PageProps<"/guest/[slug]">) {
  const { slug } = await params;

  return listMetadata("guest", slug);
}

export default async function GuestPage({ params }: PageProps<"/guest/[slug]">) {
  const { slug } = await params;

  return <ListView kind="guest" rawSlug={slug} />;
}
