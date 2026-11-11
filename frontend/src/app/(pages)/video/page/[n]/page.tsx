import { VideoLibrary, videoMetadata } from "@/components/video/VideoLibrary";

/** A further page of the video library (components/video/VideoLibrary). */
export const instant = false;

export async function generateMetadata({ params, searchParams }: PageProps<"/video/page/[n]">) {
  return videoMetadata((await params).n, await searchParams);
}

export default async function VideoLibraryPage({ params, searchParams }: PageProps<"/video/page/[n]">) {
  return <VideoLibrary rawPage={(await params).n} query={await searchParams} />;
}
