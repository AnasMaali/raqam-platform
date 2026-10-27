import type { Metadata } from "next";
import { notFound } from "next/navigation";

// Design explorations: development only, never indexed, a 404 in any production build.
export const metadata: Metadata = {
  title: "مختبر",
  robots: { index: false, follow: false },
};

export default function LabLayout({ children }: LayoutProps<"/lab">) {
  if (process.env.NODE_ENV === "production") {
    notFound();
  }

  return <div className="flex flex-1 flex-col">{children}</div>;
}
