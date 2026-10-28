import { Alexandria, Cairo, IBM_Plex_Sans_Arabic, Noto_Naskh_Arabic, Readex_Pro } from "next/font/google";

// The candidates of the type study, served from this site (next/font downloads them at build time). Not preloaded:
// this page compares five families and should not ask the browser for all of them before it paints. The options must
// be written out literally for each call: the font loader reads them at build time.
const alexandria = Alexandria({ subsets: ["arabic", "latin"], display: "swap", preload: false });
const readexPro = Readex_Pro({ subsets: ["arabic", "latin"], display: "swap", preload: false });
const ibmPlexSansArabic = IBM_Plex_Sans_Arabic({ subsets: ["arabic", "latin"], display: "swap", preload: false, weight: ["400", "500", "700"] });
const notoNaskhArabic = Noto_Naskh_Arabic({ subsets: ["arabic", "latin"], display: "swap", preload: false });
const cairo = Cairo({ subsets: ["arabic", "latin"], display: "swap", preload: false });

export const candidates = [
  { family: "Alexandria", font: alexandria, note: "هندسي، بنفس روح «ق» في الشعار." },
  { family: "Readex Pro", font: readexPro, note: "هندسي ناعم، مبني لقراءة الشاشات." },
  { family: "IBM Plex Sans Arabic", font: ibmPlexSansArabic, note: "محايد ومؤسسي، أوزان ثابتة." },
  { family: "Noto Naskh Arabic", font: notoNaskhArabic, note: "نسخ تقليدي، الأقرب لخط الكتب والصحف." },
  { family: "Cairo", font: cairo, note: "شائع جداً؛ نقارنه فقط لو تفوّق بوضوح." },
] as const;
