import { VideoLibrary, videoMetadata } from "@/components/video/VideoLibrary";

/** The video library's first page (components/video/VideoLibrary). Blocking: its status comes before any byte. */
export const instant = false;

export async function generateMetadata({ searchParams }: PageProps<"/video">) {
  return videoMetadata(undefined, await searchParams);
}

export default async function VideoPage({ searchParams }: PageProps<"/video">) {
  return <VideoLibrary query={await searchParams} />;
}
