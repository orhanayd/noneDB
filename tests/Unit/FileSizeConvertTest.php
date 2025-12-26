<?php

namespace noneDB\Tests\Unit;

use noneDB\Tests\noneDBTestCase;

/**
 * Unit tests for the private fileSizeConvert() method
 *
 * Tests the file size to human-readable format conversion.
 */
class FileSizeConvertTest extends noneDBTestCase
{
    /**
     * @test
     */
    public function zeroBytes(): void
    {
        $method = $this->getPrivateMethod('fileSizeConvert');
        $result = $method->invoke($this->noneDB, 0);

        $this->assertEquals('0 B', $result, 'Zero bytes should return "0 B"');
    }

    /**
     * @test
     */
    public function singleByte(): void
    {
        $method = $this->getPrivateMethod('fileSizeConvert');
        $result = $method->invoke($this->noneDB, 1);

        $this->assertEquals('1 B', $result, 'Single byte should return "1 B"');
    }

    /**
     * @test
     */
    public function bytesRange(): void
    {
        $method = $this->getPrivateMethod('fileSizeConvert');

        $result = $method->invoke($this->noneDB, 500);
        $this->assertEquals('500 B', $result, '500 bytes should return "500 B"');

        $result = $method->invoke($this->noneDB, 1023);
        $this->assertEquals('1023 B', $result, '1023 bytes should return "1023 B"');
    }

    /**
     * @test
     */
    public function exactlyOneKilobyte(): void
    {
        $method = $this->getPrivateMethod('fileSizeConvert');
        $result = $method->invoke($this->noneDB, 1024);

        $this->assertEquals('1 KB', $result, '1024 bytes should return "1 KB"');
    }

    /**
     * @test
     */
    public function kilobytesRange(): void
    {
        $method = $this->getPrivateMethod('fileSizeConvert');

        $result = $method->invoke($this->noneDB, 1024 * 500);
        $this->assertEquals('500 KB', $result, '500 KB');

        $result = $method->invoke($this->noneDB, 1024 * 1023);
        $this->assertEquals('1023 KB', $result, '1023 KB');
    }

    /**
     * @test
     */
    public function exactlyOneMegabyte(): void
    {
        $method = $this->getPrivateMethod('fileSizeConvert');
        $result = $method->invoke($this->noneDB, 1024 * 1024);

        $this->assertEquals('1 MB', $result, '1 MB');
    }

    /**
     * @test
     */
    public function megabytesRange(): void
    {
        $method = $this->getPrivateMethod('fileSizeConvert');

        $result = $method->invoke($this->noneDB, 1024 * 1024 * 50);
        $this->assertEquals('50 MB', $result, '50 MB');

        $result = $method->invoke($this->noneDB, 1024 * 1024 * 512);
        $this->assertEquals('512 MB', $result, '512 MB');
    }

    /**
     * @test
     */
    public function exactlyOneGigabyte(): void
    {
        $method = $this->getPrivateMethod('fileSizeConvert');
        $result = $method->invoke($this->noneDB, 1024 * 1024 * 1024);

        $this->assertEquals('1 GB', $result, '1 GB');
    }

    /**
     * @test
     */
    public function gigabytesRange(): void
    {
        $method = $this->getPrivateMethod('fileSizeConvert');

        $result = $method->invoke($this->noneDB, 1024 * 1024 * 1024 * 5);
        $this->assertEquals('5 GB', $result, '5 GB');
    }

    /**
     * @test
     */
    public function exactlyOneTerabyte(): void
    {
        $method = $this->getPrivateMethod('fileSizeConvert');
        $result = $method->invoke($this->noneDB, pow(1024, 4));

        $this->assertEquals('1 TB', $result, '1 TB');
    }

    /**
     * @test
     */
    public function decimalPrecision(): void
    {
        $method = $this->getPrivateMethod('fileSizeConvert');

        // 1.5 KB = 1536 bytes
        $result = $method->invoke($this->noneDB, 1536);
        $this->assertEquals('1,5 KB', $result, '1.5 KB with comma separator');

        // 2.25 MB
        $result = $method->invoke($this->noneDB, 1024 * 1024 * 2.25);
        $this->assertEquals('2,25 MB', $result, '2.25 MB with comma separator');
    }

    /**
     * @test
     */
    public function usesCommaAsSeparator(): void
    {
        $method = $this->getPrivateMethod('fileSizeConvert');

        // 1.5 KB
        $result = $method->invoke($this->noneDB, 1536);

        $this->assertStringContainsString(',', $result, 'Should use comma as decimal separator');
        $this->assertStringNotContainsString('.', $result, 'Should not use dot as decimal separator');
    }

    /**
     * @test
     */
    public function roundsToTwoDecimalPlaces(): void
    {
        $method = $this->getPrivateMethod('fileSizeConvert');

        // 1.333333 KB
        $result = $method->invoke($this->noneDB, 1365);
        $this->assertEquals('1,33 KB', $result, 'Should round to 2 decimal places');

        // 1.666666 KB
        $result = $method->invoke($this->noneDB, 1706);
        $this->assertEquals('1,67 KB', $result, 'Should round up when needed');
    }

    /**
     * @test
     */
    public function floatInput(): void
    {
        $method = $this->getPrivateMethod('fileSizeConvert');
        $result = $method->invoke($this->noneDB, 1024.5);

        $this->assertIsString($result, 'Should handle float input');
    }

    /**
     * @test
     */
    public function stringNumericInput(): void
    {
        $method = $this->getPrivateMethod('fileSizeConvert');
        $result = $method->invoke($this->noneDB, '1024');

        $this->assertEquals('1 KB', $result, 'Should handle numeric string input');
    }

    /**
     * @test
     */
    public function includesUnitSuffix(): void
    {
        $method = $this->getPrivateMethod('fileSizeConvert');

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $values = [1, 1024, 1024 * 1024, 1024 * 1024 * 1024, pow(1024, 4)];

        foreach ($values as $i => $value) {
            $result = $method->invoke($this->noneDB, $value);
            $this->assertStringEndsWith(' ' . $units[$i], $result, "Should end with {$units[$i]}");
        }
    }
}
