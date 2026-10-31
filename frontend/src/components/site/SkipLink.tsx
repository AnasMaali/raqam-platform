/** The first stop for the keyboard: straight to the page's content, past the header and its menus. */
export function SkipLink() {
  return (
    <a
      href="#main"
      data-site-frame
      className="sr-only focus-visible:not-sr-only focus-visible:fixed focus-visible:start-4 focus-visible:top-2 focus-visible:z-50 focus-visible:rounded-sm focus-visible:bg-brand focus-visible:px-4 focus-visible:py-3 focus-visible:font-bold focus-visible:text-on-brand focus-visible:outline-offset-4"
    >
      انتقل إلى المحتوى
    </a>
  );
}
