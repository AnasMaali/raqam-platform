import { notFound, permanentRedirect } from "next/navigation";

import { getPost, newestPostPaths, postPrefixes } from "@/lib/server/posts";

/** An address without its slug (/news/300) goes to the post's full address; the platform answers which it is. */
export const instant = false;

export async function generateStaticParams() {
  return (await newestPostPaths()).slice(0, 1).map(({ section, id }) => ({ section, id }));
}

export default async function BarePostAddress({ params }: PageProps<"/[section]/[id]">) {
  const { section, id } = await params;
  if (!(postPrefixes as readonly string[]).includes(section) || !/^[1-9]\d{0,9}$/.test(id)) notFound();
  // "-" is never a slug, so the platform answers with a move to the current address.
  const answer = await getPost(Number(id), "-");
  if (answer.status === "gone") notFound();
  permanentRedirect(encodeURI(answer.status === "moved" ? answer.path : answer.post.path));
}
