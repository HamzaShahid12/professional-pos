// Instant cart pricing in the browser. Exact integer maths (BigInt, 4 decimal places), the same rules as the server's
// TaxCalculator and SaleService: gross = qty x price, minus discount, inclusive taxes are contained in the price (the
// rounding remainder goes to the last inclusive tax), exclusive taxes are added on the net. Half-up rounding.
//
// This is only a preview so the till feels instant. The server recalculates everything when the sale is completed
// and its figures are the ones that are charged and stored.

const SCALE = 10000n;

/** "12.345" -> 123450n. Returns null for anything that is not a plain non-negative decimal. */
export function toUnits(value) {
    const s = String(value ?? '').trim();
    if (!/^\d*\.?\d*$/.test(s) || s === '' || s === '.') return null;
    const [int, frac = ''] = s.split('.');
    const padded = (frac + '0000').slice(0, 4);
    let n = BigInt(int || '0') * SCALE + BigInt(padded);
    if (frac.length > 4 && frac[4] >= '5') n += 1n; // half-up on the 5th decimal
    return n;
}

/** 123450n -> "12.3450" */
export function fromUnits(n) {
    const neg = n < 0n;
    const a = neg ? -n : n;
    return `${neg ? '-' : ''}${a / SCALE}.${String(a % SCALE).padStart(4, '0')}`;
}

// round-half-up division of non-negative integers
const div = (n, d) => (2n * n + d) / (2n * d);

/**
 * @param {{quantity:string, unit_price:string, discount?:string, taxes?:{rate:string,is_inclusive:boolean}[]}} line
 * @returns {null | {net_amount:string, tax_amount:string, line_total:string, gross:string}}
 *   null when the line cannot be priced yet (empty quantity, invalid discount, missing tax information).
 */
export function priceLine(line) {
    if (!Array.isArray(line.taxes)) return null;
    const qty = toUnits(line.quantity);
    const price = toUnits(line.unit_price);
    const discount = line.discount ? toUnits(line.discount) : 0n;
    if (qty === null || price === null || discount === null || qty <= 0n) return null;

    const gross = div(qty * price, SCALE);
    if (discount > gross) return null;
    const amount = gross - discount;

    const taxes = line.taxes.map((t) => ({ rate: toUnits(t.rate) ?? 0n, inclusive: !!t.is_inclusive }));
    const inclusiveRate = taxes.filter((t) => t.inclusive).reduce((s, t) => s + t.rate, 0n);

    const net = inclusiveRate === 0n ? amount : div(amount * 100n * SCALE, 100n * SCALE + inclusiveRate);
    const budget = amount - net;
    const lastInclusive = taxes.map((t) => t.inclusive).lastIndexOf(true);

    let inclusiveTotal = 0n;
    let taxTotal = 0n;
    taxes.forEach((t, i) => {
        let tax = div(net * t.rate, 100n * SCALE);
        if (t.inclusive) {
            if (i === lastInclusive) tax = budget - inclusiveTotal;
            inclusiveTotal += tax;
        }
        taxTotal += tax;
    });

    return { gross: fromUnits(gross), discount: fromUnits(discount), net_amount: fromUnits(net), tax_amount: fromUnits(taxTotal), line_total: fromUnits(net + taxTotal) };
}

/** Prices every line and sums them. Returns null if any line cannot be priced. */
export function priceCart(lines) {
    const priced = [];
    let subtotal = 0n; let discountTotal = 0n; let taxTotal = 0n; let total = 0n;
    for (const l of lines) {
        const p = priceLine(l);
        if (!p) return null;
        priced.push(p);
        subtotal += toUnits(p.net_amount); discountTotal += toUnits(p.discount); taxTotal += toUnits(p.tax_amount); total += toUnits(p.line_total);
    }

    return { lines: priced, subtotal: fromUnits(subtotal), discount_total: fromUnits(discountTotal), tax_total: fromUnits(taxTotal), total: fromUnits(total), local: true };
}
