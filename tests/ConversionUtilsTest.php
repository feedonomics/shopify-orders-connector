<?php

namespace ShopifyOrdersConnector\tests;

use PHPUnit\Framework\TestCase;
use ShopifyOrdersConnector\services\ConversionUtils;

class ConversionUtilsTest extends TestCase
{
    public function dp_states(): array
    {
        return [
            'name' => ['Texas', 'TX'],
            'name in lower case' => ['texas', 'TX'],
            'name with spaces' => ['New York', 'NY'],
            'name with punctuation' => ['North-Carolina.', 'NC'],
            'district' => ['District of Columbia', 'DC'],
            'territory' => ['Puerto Rico', 'PR'],
            'code' => ['TX', 'TX'],
            'code in lower case' => ['tx', 'TX'],
            'code with punctuation' => ['N.Y.', 'NY'],
            'unknown' => ['Ontario', 'ONTARIO'],
            'empty' => ['', ''],
        ];
    }

    /**
     * @dataProvider dp_states
     */
    public function test_usa_state_to_2_chars(string $state, string $expected): void
    {
        $this->assertSame($expected, ConversionUtils::convert_usa_state_to_2_chars($state));
    }

    public function dp_countries(): array
    {
        return [
            'iso3' => ['USA', 'US'],
            'iso3 in lower case' => ['gbr', 'GB'],
            'iso2' => ['US', 'US'],
            'iso2 in lower case' => ['de', 'DE'],
            'unknown' => ['XX', 'XX'],
            'empty' => ['', ''],
        ];
    }

    /**
     * @dataProvider dp_countries
     */
    public function test_country_code_to_iso2(string $country_code, string $expected): void
    {
        $this->assertSame($expected, ConversionUtils::convert_country_code_to_ISO2($country_code));
    }

    public function dp_countries_to_iso3(): array
    {
        return [
            'iso2' => ['US', 'USA'],
            'iso2 in lower case' => ['gb', 'GBR'],
            'iso3' => ['USA', 'USA'],
            'unknown' => ['xx', 'XX'],
        ];
    }

    /**
     * @dataProvider dp_countries_to_iso3
     */
    public function test_country_code_to_iso3(string $country_code, string $expected): void
    {
        $this->assertSame($expected, ConversionUtils::convert_country_code_to_ISO3($country_code));
    }

    public function test_every_country_code_converts_back_and_forth(): void
    {
        foreach (ConversionUtils::ISO2_TO_ISO3 as $iso2 => $iso3) {
            $this->assertSame($iso3, ConversionUtils::convert_country_code_to_ISO3($iso2), $iso2);
            $this->assertSame($iso2, ConversionUtils::convert_country_code_to_ISO2($iso3), $iso3);
        }
    }

    public function test_date_to_utc(): void
    {
        $this->assertSame('2025-05-02T10:00:00+00:00', ConversionUtils::convert_date_to_utc_iso_8601('2025-05-02 10:00:00'));
        $this->assertSame('2025-05-02T10:00:00+00:00', ConversionUtils::convert_date_to_utc_iso_8601('2025-05-02T10:00:00Z'));
        $this->assertSame('2025-05-02T10:00:00+00:00', ConversionUtils::convert_date_to_utc_iso_8601('2025-05-02T06:00:00-04:00'));
    }

    public function test_date_in_a_time_zone_to_utc(): void
    {
        $this->assertSame(
            '2025-05-02T14:00:00+00:00',
            ConversionUtils::convert_date_to_utc_iso_8601('2025-05-02 10:00:00', 'America/New_York')
        );
    }

    public function test_dates_that_are_not_dates(): void
    {
        $this->assertSame('', ConversionUtils::convert_date_to_utc_iso_8601(null));
        $this->assertSame('', ConversionUtils::convert_date_to_utc_iso_8601(''));
        $this->assertSame('', ConversionUtils::convert_date_to_utc_iso_8601('not a date'));
    }
}
