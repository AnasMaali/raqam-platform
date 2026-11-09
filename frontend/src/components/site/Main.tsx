import type { ReactNode } from "react";

/** The page's content: the skip link's target. Each page has exactly one, after its strip and breaking bar. */
export function Main({ children }: { children: ReactNode }) {
  return (
    <main id="main" tabIndex={-1} className="flex-1 focus:outline-none">
      {children}
    </main>
  );
}
