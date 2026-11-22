// Code 128 (subset B) barcode as SVG bars. Handles printable ASCII (space to ~), which covers invoice numbers.
const PATTERNS = [
    '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
    '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
    '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313', '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
    '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
    '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
    '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
    '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412', '211214', '211232',
];
const STOP = '2331112';
const START_B = 104;

/** Returns the module widths (alternating bar, space, bar...) for the text, or null if it has unsupported characters. */
export function code128Widths(text) {
    const s = String(text ?? '');
    if (s === '' || /[^\x20-\x7e]/.test(s)) return null;
    const codes = [START_B, ...[...s].map((c) => c.charCodeAt(0) - 32)];
    const checksum = codes.reduce((sum, c, i) => sum + c * (i === 0 ? 1 : i), 0) % 103;
    codes.push(checksum);

    return [...codes.map((c) => PATTERNS[c]), STOP].join('').split('').map(Number);
}

/** Bars as {x, w} rectangles in module units, plus the total width (including 10-module quiet zones). */
export function code128Bars(text) {
    const widths = code128Widths(text);
    if (!widths) return null;
    const bars = [];
    let x = 10;
    widths.forEach((w, i) => {
        if (i % 2 === 0) bars.push({ x, w });
        x += w;
    });

    return { bars, width: x + 10 };
}