<?php

namespace Tests\Unit;

use App\Support\TaxCalculator;
use PHPUnit\Framework\TestCase;

class TaxCalculatorTest extends TestCase
{
    private function tax(string $rate, bool $inclusive, string $name = 'T'): array
    {
        return ['name' => $name, 'rate' => $rate, 'is_inclusive' => $inclusive];
    }

    public function test_no_taxes_returns_amount_unchanged(): void
    {
        $r = (new TaxCalculator)->calculate('250', []);

        $this->assertSame('250.0000', $r['net']);
        $this->assertSame('0.0000', $r['tax_total']);
        $this->assertSame('250.0000', $r['gross']);
    }

    public function test_exclusive_tax_is_added_on_top(): void
    {
        $r = (new TaxCalculator)->calculate('100', [$this->tax('18', false)]);

        $this->assertSame('100.0000', $r['net']);
        $this->assertSame('18.0000', $r['tax_total']);
        $this->assertSame('118.0000', $r['gross']);
    }

    public function test_inclusive_tax_is_extracted_from_the_price(): void
    {
        $r = (new TaxCalculator)->calculate('118', [$this->tax('18', true)]);

        $this->assertSame('100.0000', $r['net']);
        $this->assertSame('18.0000', $r['tax_total']);
        $this->assertSame('118.0000', $r['gross']);
    }

    public function test_inclusive_rounding_remainder_keeps_net_plus_tax_equal_to_price(): void
    {
        $r = (new TaxCalculator)->calculate('100', [$this->tax('18', true)]);

        $this->assertSame('84.7458', $r['net']);
        $this->assertSame('15.2542', $r['tax_total']);
        $this->assertSame('100.0000', $r['gross']);
    }

    public function test_multiple_inclusive_taxes_split_the_price(): void
    {
        $r = (new TaxCalculator)->calculate('118', [$this->tax('5', true, 'A'), $this->tax('13', true, 'B')]);

        $this->assertSame('100.0000', $r['net']);
        $this->assertSame('5.0000', $r['lines'][0]['amount']);
        $this->assertSame('13.0000', $r['lines'][1]['amount']);
        $this->assertSame('118.0000', $r['gross']);
    }

    public function test_mixed_inclusive_and_exclusive_taxes(): void
    {
        $r = (new TaxCalculator)->calculate('105', [$this->tax('5', true), $this->tax('18', false)]);

        $this->assertSame('100.0000', $r['net']);
        $this->assertSame('23.0000', $r['tax_total']);
        $this->assertSame('123.0000', $r['gross']); // 105 entered + 18 exclusive
    }

    public function test_zero_rate_tax_is_a_no_op(): void
    {
        $r = (new TaxCalculator)->calculate('99.99', [$this->tax('0', false)]);

        $this->assertSame('99.9900', $r['gross']);
        $this->assertSame('0.0000', $r['tax_total']);
    }
}
