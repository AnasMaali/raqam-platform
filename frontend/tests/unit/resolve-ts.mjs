// The site's sources import each other without an extension (Next.js resolves them); for node --test, a relative import
// with no extension is tried as a .ts file.
import { registerHooks } from "node:module";

registerHooks({
  resolve(specifier, context, nextResolve) {
    if (/^\.\.?\//.test(specifier) && !/\.[cm]?[jt]sx?$/.test(specifier)) {
      try {
        return nextResolve(`${specifier}.ts`, context);
      } catch {
        // not a .ts file: resolve it as written
      }
    }
    return nextResolve(specifier, context);
  },
});
