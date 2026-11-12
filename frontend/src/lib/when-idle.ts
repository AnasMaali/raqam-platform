/**
 * Runs a task once the page has loaded and the browser is idle (at most 3 s after the load), so nothing it does
 * competes with the page's own first paint. Returns a function that cancels it.
 */
export function whenIdle(task: () => void): () => void {
  let cancelled = false;
  const run = () => {
    if (cancelled) return;
    if ("requestIdleCallback" in window) window.requestIdleCallback(() => !cancelled && task(), { timeout: 3000 });
    else setTimeout(() => !cancelled && task(), 200);
  };
  if (document.readyState === "complete") run();
  else window.addEventListener("load", run, { once: true });

  return () => {
    cancelled = true;
    window.removeEventListener("load", run);
  };
}
