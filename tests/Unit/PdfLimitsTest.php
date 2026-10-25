<?php

namespace Tests\Unit;

use App\Support\PdfLimits;
use PHPUnit\Framework\TestCase;

class PdfLimitsTest extends TestCase
{
    private string $memory;

    private string $seconds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memory = (string) ini_get('memory_limit');
        $this->seconds = (string) ini_get('max_execution_time');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->memory);
        set_time_limit((int) $this->seconds);
        parent::tearDown();
    }

    public function test_low_limits_are_raised(): void
    {
        ini_set('memory_limit', '128M');
        set_time_limit(30);

        PdfLimits::raise();

        $this->assertSame('512M', ini_get('memory_limit'));
        $this->assertSame('120', ini_get('max_execution_time'));
    }

    public function test_unlimited_or_higher_limits_are_never_lowered(): void
    {
        ini_set('memory_limit', '-1');
        set_time_limit(0);

        PdfLimits::raise();

        $this->assertSame('-1', ini_get('memory_limit'));
        $this->assertSame('0', ini_get('max_execution_time'));

        ini_set('memory_limit', '2G');
        set_time_limit(600);

        PdfLimits::raise();

        $this->assertSame('2G', ini_get('memory_limit'));
        $this->assertSame('600', ini_get('max_execution_time'));
    }
}
