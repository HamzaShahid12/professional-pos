/** Money is received from the API as strings; formatting never uses it for arithmetic. */
export function formatMoney(amount, currency = { symbol: '', decimal_places: 2 }) {
    const places = currency?.decimal_places ?? 2;
    const n = Number(amount ?? 0);
    const text = n.toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places });
    return currency?.symbol ? `${currency.symbol} ${text}` : text;
}

/** Trim trailing zeros from a rate such as "280.00000000" -> "280". */
export function trimRate(rate) {
    const s = String(rate ?? '');
    return s.includes('.') ? s.replace(/0+$/, '').replace(/\.$/, '') : s;
}

export function formatDateTime(iso, timezone) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: timezone || undefined });
    } catch {
        return new Date(iso).toLocaleString('en-GB');
    }
}

export function initials(name = '') {
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join('');
}

export function humanize(action = '') {
    return action.replace(/[._]/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}

/** Quantities arrive with 4 decimals ("12.5000"); show them without trailing zeros. */
export function formatQty(value) {
    const text = String(value ?? '0');
    const negative = text.startsWith('-');
    const [int, frac = ''] = text.replace('-', '').split('.');
    const trimmed = frac.replace(/0+$/, '');
    const head = Number(int).toLocaleString('en-US');
    const isZero = Number(int) === 0 && trimmed === '';

    return `${negative && !isZero ? '-' : ''}${head}${trimmed ? `.${trimmed}` : ''}`;
}
