<?php
/**
 * AggregationPolicy supplemental outage field list tests.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/AggregationPolicy.php';

use AviationWX\Weather\AggregationPolicy;

class AggregationPolicySupplementalTest extends TestCase
{
    public function testSupplementalOutageHiddenFields_IncludesCoreAndDisplayExtras(): void
    {
        $fields = AggregationPolicy::supplementalOutageHiddenFields();

        $this->assertContains('wind_speed', $fields);
        $this->assertContains('flight_category', $fields);
        $this->assertContains('raw_metar', $fields);
        $this->assertContains('flight_category_class', $fields);
        $this->assertSame(count($fields), count(array_unique($fields)), 'field list must not contain duplicates');
    }
}
