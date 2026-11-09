/*
 * The article body arrives as HTML the platform has already cleaned (an allow-list) and rendered (its pictures,
 * galleries, «اقرأ أيضاً» and awareness cards). The site only adjusts two things in it.
 */

const voidElements = new Set(["area", "br", "col", "embed", "hr", "img", "input", "link", "meta", "source", "track", "wbr"]);

/**
 * Splits the body after its n-th paragraph at the top level (never inside a quote, a list or a card), for the
 * in-article ad. Without enough paragraphs after that point there is no split, and the ad goes after the body.
 */
export function splitAfterParagraph(html: string, n: number): [string, string] | null {
  let depth = 0;
  let count = 0;
  const tag = /<(\/?)([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*?(\/?)>/g;
  for (let match = tag.exec(html); match; match = tag.exec(html)) {
    const [, closing, rawName, selfClosing] = match;
    const name = rawName.toLowerCase();
    if (voidElements.has(name) || selfClosing) continue;
    depth += closing ? -1 : 1;
    if (closing && name === "p" && depth === 0 && ++count === n) {
      const at = match.index + match[0].length;
      const rest = html.slice(at);
      // At least one more paragraph after the ad, so it sits inside the article and not at its end.
      return /<p\b/i.test(rest) ? [html.slice(0, at), rest] : null;
    }
  }

  return null;
}

/**
 * On sponsored content every link to another site carries rel="sponsored" (Google's rule for paid links), on top of
 * what the platform set (noopener for a new tab).
 */
export function markSponsoredLinks(html: string): string {
  return html.replace(/<a\b([^>]*)>/gi, (whole, attributes: string) => {
    if (!/\bhref\s*=\s*"https?:\/\//i.test(attributes)) return whole;
    const rel = /\brel\s*=\s*"([^"]*)"/i.exec(attributes);
    if (rel) {
      if (/\bsponsored\b/i.test(rel[1])) return whole;

      return `<a${attributes.replace(rel[0], `rel="${`${rel[1]} sponsored`.trim()}"`)}>`;
    }

    return `<a${attributes} rel="sponsored">`;
  });
}
