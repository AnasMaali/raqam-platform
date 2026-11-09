import { NewsletterForm } from "./NewsletterForm";

/**
 * The newsletter's block: a short promise and the form. It needs the Turnstile site key (TURNSTILE_SITE_KEY, public
 * by design, read on the server); without it the platform would refuse every request, so the block is left out.
 */
export function NewsletterBlock({ title, placement, path }: { title: string; placement: "home" | "footer" | "post_end" | "newsletter_page"; path: string }) {
  const siteKey = process.env.TURNSTILE_SITE_KEY?.trim();
  if (!siteKey) {
    console.error("[site] the newsletter form is left out: TURNSTILE_SITE_KEY is not set");

    return null;
  }

  return (
    <section aria-labelledby="newsletter-title" className="bg-tint px-4 py-8 sm:px-8 lg:grid lg:grid-cols-12 lg:gap-8 lg:py-10">
      <div className="lg:col-span-5">
        <h2 id="newsletter-title" className="text-heading font-bold text-brand">
          {title}
        </h2>
        <p className="mt-2 max-w-text text-lead text-muted">أهم أخبار الاقتصاد في بريدك، مرة في الأسبوع.</p>
      </div>
      <div className="mt-5 lg:col-span-7 lg:mt-1">
        <NewsletterForm siteKey={siteKey} placement={placement} path={path} />
      </div>
    </section>
  );
}
