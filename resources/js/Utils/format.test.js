import { describe, expect, it } from 'vitest';
import { formatMoney, humanize, initials, trimRate } from './format';

describe('format utils', () => {
    it('formats money with symbol and decimals', () => {
        expect(formatMoney('1250.5', { symbol: 'Rs', decimal_places: 2 })).toBe('Rs 1,250.50');
        expect(formatMoney('1250', { symbol: '¥', decimal_places: 0 })).toBe('¥ 1,250');
    });

    it('trims exchange rate zeros', () => {
        expect(trimRate('280.00000000')).toBe('280');
        expect(trimRate('0.00350000')).toBe('0.0035');
        expect(trimRate('300')).toBe('300');
    });

    it('builds initials and humanizes audit actions', () => {
        expect(initials('Branch Manager')).toBe('BM');
        expect(humanize('branch.created')).toBe('Branch created');
    });
});

import { formatQty } from './format';

describe('formatQty', () => {
    it('trims trailing zeros', () => {
        expect(formatQty('12.5000')).toBe('12.5');
        expect(formatQty('3.0000')).toBe('3');
        expect(formatQty('1200.2500')).toBe('1,200.25');
    });
    it('keeps the sign of negative quantities', () => {
        expect(formatQty('-0.5000')).toBe('-0.5');
        expect(formatQty('-4.0000')).toBe('-4');
    });
    it('handles missing values', () => {
        expect(formatQty(null)).toBe('0');
    });
});
