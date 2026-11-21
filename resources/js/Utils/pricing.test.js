import { describe, expect, it } from 'vitest';
import { priceCart, priceLine, toUnits } from './pricing';

const line = (over = {}) => ({ quantity: '1', unit_price: '100', discount: '', taxes: [], ...over });

// These expectations are the same figures asserted by tests/Unit/TaxCalculatorTest.php on the server.
describe('local pricing matches the server tax rules', () => {
    it('no tax', () => {
        const r = priceLine(line({ unit_price: '250' }));
        expect([r.net_amount, r.tax_amount, r.line_total]).toEqual(['250.0000', '0.0000', '250.0000']);
    });
    it('exclusive tax is added on top', () => {
        const r = priceLine(line({ taxes: [{ rate: '18', is_inclusive: false }] }));
        expect([r.net_amount, r.tax_amount, r.line_total]).toEqual(['100.0000', '18.0000', '118.0000']);
    });
    it('inclusive tax is contained in the price (rounded half-up)', () => {
        const r = priceLine(line({ taxes: [{ rate: '18', is_inclusive: true }] }));
        expect([r.net_amount, r.tax_amount, r.line_total]).toEqual(['84.7458', '15.2542', '100.0000']);
    });
    it('two inclusive taxes split the price exactly', () => {
        const r = priceLine(line({ unit_price: '118', taxes: [{ rate: '5', is_inclusive: true }, { rate: '13', is_inclusive: true }] }));
        expect([r.net_amount, r.tax_amount, r.line_total]).toEqual(['100.0000', '18.0000', '118.0000']);
    });
    it('inclusive plus exclusive', () => {
        const r = priceLine(line({ unit_price: '105', taxes: [{ rate: '5', is_inclusive: true }, { rate: '18', is_inclusive: false }] }));
        expect([r.net_amount, r.tax_amount, r.line_total]).toEqual(['100.0000', '23.0000', '123.0000']);
    });
    it('quantity, decimals and discount', () => {
        const r = priceLine(line({ quantity: '2.5', unit_price: '99.99', discount: '10', taxes: [{ rate: '10', is_inclusive: false }] }));
        expect(r.gross).toBe('249.9750'); // 2.5 x 99.99
        expect([r.net_amount, r.tax_amount, r.line_total]).toEqual(['239.9750', '23.9975', '263.9725']);
    });
});

describe('cart totals and guards', () => {
    it('sums lines', () => {
        const c = priceCart([line({ taxes: [{ rate: '10', is_inclusive: false }] }), line({ unit_price: '50' })]);
        expect([c.subtotal, c.tax_total, c.total]).toEqual(['150.0000', '10.0000', '160.0000']);
    });
    it('does not guess: unknown taxes, empty quantity or too large a discount give null', () => {
        expect(priceLine({ quantity: '1', unit_price: '10' })).toBeNull();
        expect(priceLine(line({ quantity: '' }))).toBeNull();
        expect(priceLine(line({ quantity: '0' }))).toBeNull();
        expect(priceLine(line({ discount: '101' }))).toBeNull();
        expect(priceCart([line(), line({ quantity: 'x' })])).toBeNull();
    });
    it('parses decimals strictly and rounds the 5th place half-up', () => {
        expect(toUnits('1.00005')).toBe(10001n);
        expect(toUnits('1,5')).toBeNull();
        expect(toUnits('-1')).toBeNull();
    });
});
