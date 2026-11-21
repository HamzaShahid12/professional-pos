<?php

namespace App\Support;

class CurrencyCatalog
{
    /** @return array<string, array{name: string, symbol: string, decimals: int}> */
    public static function all(): array
    {
        return [
            'PKR' => ['name' => 'Pakistani Rupee', 'symbol' => 'Rs', 'decimals' => 2],
            'USD' => ['name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2],
            'EUR' => ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2],
            'GBP' => ['name' => 'British Pound', 'symbol' => '£', 'decimals' => 2],
            'AED' => ['name' => 'UAE Dirham', 'symbol' => 'AED', 'decimals' => 2],
            'SAR' => ['name' => 'Saudi Riyal', 'symbol' => 'SAR', 'decimals' => 2],
            'INR' => ['name' => 'Indian Rupee', 'symbol' => '₹', 'decimals' => 2],
            'CNY' => ['name' => 'Chinese Yuan', 'symbol' => '¥', 'decimals' => 2],
            'JPY' => ['name' => 'Japanese Yen', 'symbol' => '¥', 'decimals' => 0],
            'CAD' => ['name' => 'Canadian Dollar', 'symbol' => 'C$', 'decimals' => 2],
            'AUD' => ['name' => 'Australian Dollar', 'symbol' => 'A$', 'decimals' => 2],
        ];
    }

    public static function find(string $code): ?array
    {
        return self::all()[strtoupper($code)] ?? null;
    }
}
