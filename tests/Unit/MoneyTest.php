<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_strips_space_thousand_separators(): void
    {
        $this->assertSame(1000.0, Money::toFloat('1 000'));
        $this->assertSame(10000.0, Money::toFloat('10 000'));
        $this->assertSame(100000.0, Money::toFloat('100 000'));
        $this->assertSame(1000000.0, Money::toFloat('1 000 000'));
        $this->assertSame(12500000.0, Money::toFloat('12 500 000'));
        $this->assertSame(150000000.0, Money::toFloat('150 000 000'));
    }

    public function test_accepts_plain_unformatted_numbers(): void
    {
        $this->assertSame(1500000.0, Money::toFloat('1500000'));
        $this->assertSame(1500000.0, Money::toFloat(1500000));
        $this->assertSame(1500000.0, Money::toFloat(1500000.0));
    }

    public function test_strips_fcfa_suffix_and_commas(): void
    {
        $this->assertSame(1250000.0, Money::toFloat('1 250 000 FCFA'));
        $this->assertSame(1250000.0, Money::toFloat('1,250,000'));
    }

    public function test_empty_and_null_values_are_zero(): void
    {
        $this->assertSame(0.0, Money::toFloat(null));
        $this->assertSame(0.0, Money::toFloat(''));
    }

    public function test_negative_amounts_are_preserved(): void
    {
        $this->assertSame(-500.0, Money::toFloat('-500'));
        $this->assertSame(-500000.0, Money::toFloat('-500 000'));
    }

    public function test_is_valid(): void
    {
        $this->assertTrue(Money::isValid('1 000 000'));
        $this->assertTrue(Money::isValid('1000000'));
        $this->assertTrue(Money::isValid(1000000));
        $this->assertFalse(Money::isValid(null));
        $this->assertFalse(Money::isValid(''));
        $this->assertFalse(Money::isValid('abc'));
    }
}
