// The handwritten typefaces, served with the app rather than from a font
// CDN: Caveat and Permanent Marker for the Latin letters, Mali and Sriracha
// for the Thai ones they lack (all under the SIL Open Font Licence).
//
// A canvas measures and draws with whatever font it has at the moment, and
// one that is still on its way is drawn in a stand-in -- wrapped wrongly,
// and never measured again. So a label in one of them waits until they have
// come, and every board is drawn again once they have.
import caveatLatin400 from '@fontsource/caveat/files/caveat-latin-400-normal.woff2?url';
import caveatLatin700 from '@fontsource/caveat/files/caveat-latin-700-normal.woff2?url';
import caveatExt400 from '@fontsource/caveat/files/caveat-latin-ext-400-normal.woff2?url';
import caveatExt700 from '@fontsource/caveat/files/caveat-latin-ext-700-normal.woff2?url';
import maliThai400 from '@fontsource/mali/files/mali-thai-400-normal.woff2?url';
import maliThai700 from '@fontsource/mali/files/mali-thai-700-normal.woff2?url';
import markerLatin from '@fontsource/permanent-marker/files/permanent-marker-latin-400-normal.woff2?url';
import srirachaThai from '@fontsource/sriracha/files/sriracha-thai-400-normal.woff2?url';
import Konva from 'konva';
import { ref } from 'vue';
import { holdPrint } from '@/lib/printReady';
import type { FontFamily } from '../model/items';
import { HAND_FONTS } from '../model/items';
import { forgetLayouts } from './labels';

// The letters each file covers, as Fontsource splits them
const LATIN =
    'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD';
const LATIN_EXT =
    'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF';
const THAI = 'U+02D7,U+0303,U+0331,U+0E01-0E5B,U+200C-200D,U+25CC';

/**
 * Where a font file is, on the page's own origin. The dev server writes the
 * files it serves as at its own address (http://0.0.0.0:5173/node_modules/...),
 * which only a browser on this machine reaches; the same path on the page's
 * origin is passed to it (docker/nginx/app.conf) for everyone else -- a phone,
 * and the browser that prints boards. A built app's are there already.
 */
const here = (url: string) => url.replace(/^https?:\/\/[^/]+(?=\/)/, '');

// family, weight, file, the letters it covers
const FILES: [string, string, string, string][] = [
    ['Caveat', '400', caveatLatin400, LATIN],
    ['Caveat', '700', caveatLatin700, LATIN],
    ['Caveat', '400', caveatExt400, LATIN_EXT],
    ['Caveat', '700', caveatExt700, LATIN_EXT],
    ['Mali', '400', maliThai400, THAI],
    ['Mali', '700', maliThai700, THAI],
    ['Permanent Marker', '400', markerLatin, LATIN],
    ['Sriracha', '400', srirachaThai, THAI],
];

// Said to the page once, so the label editor's own text box has them too
const faces =
    typeof document !== 'undefined' && 'fonts' in document
        ? FILES.map(([family, weight, url, unicodeRange]) => {
              const face = new FontFace(family, `url(${here(url)})`, {
                  weight,
                  unicodeRange,
                  display: 'swap',
              });

              document.fonts.add(face);

              return face;
          })
        : [];

/** Whether the handwritten fonts are here, so labels in them can be drawn. */
// (Asked of the browser each time a page starts: they are cached by then
// after the first, and come back at once)
export const handFontsReady = ref(
    typeof document === 'undefined' || !('fonts' in document),
);

let loading: Promise<void> | null = null;

/**
 * Fetch the handwritten fonts, once: when they are in, laid-out labels are
 * forgotten and every board drawn again. A PDF or a picture of the board
 * waits for them.
 */
export const loadHandFonts = (): Promise<void> => {
    if (handFontsReady.value) {
        return Promise.resolve();
    }

    loading ??= (async () => {
        const release = holdPrint();

        try {
            // (the Latin extras only come when a label has such letters)
            await Promise.all(
                faces
                    .filter((_, at) => FILES[at][3] !== LATIN_EXT)
                    .map((face) => face.load().catch(() => face)),
            );
        } finally {
            forgetLayouts();
            handFontsReady.value = true;
            Konva.stages.forEach((stage) => stage.batchDraw());
            release();
        }
    })();

    return loading;
};

/**
 * Whether words in this font can be drawn yet: at once in a font every
 * system has; in a handwritten one once it has come -- asking for it if it
 * has not.
 */
export const fontReady = (family: FontFamily): boolean => {
    if (!HAND_FONTS.includes(family) || handFontsReady.value) {
        return true;
    }

    void loadHandFonts();

    return false;
};
