<?php
/**
 * Weather Snapshot - Complete weather state from a single source
 * 
 * Represents all weather data from one source at one point in time.
 * Each adapter returns a WeatherSnapshot after parsing its API response.
 * 
 * The aggregator combines multiple snapshots into an AggregatedWeather object.
 * 
 * Internal Standard Units (ICAO):
 * - Temperature: Celsius (°C)
 * - Pressure: hectoPascals (hPa)
 * - Visibility: statute miles (SM) - FAA standard for US aviation
 * - Precipitation: inches (in) - US standard
 * - Wind speed: knots (kt)
 * - Altitude/Ceiling: feet (ft) - ICAO standard
 * 
 * Each WeatherReading carries its unit explicitly for safety.
 * Use WeatherReading factory methods (celsius, hPa, statuteMiles, etc.) 
 * to ensure correct unit tracking.
 * 
 * @package AviationWX\Weather\Data
 */

namespace AviationWX\Weather\Data;

class WeatherSnapshot {
    
    /** @var string Source identifier (e.g., 'tempest', 'metar') */
    public readonly string $source;
    
    /** @var int When this snapshot was fetched */
    public readonly int $fetchTime;
    
    /** @var WeatherReading Temperature in Celsius (unit: 'C') */
    public readonly WeatherReading $temperature;
    
    /** @var WeatherReading Dewpoint in Celsius (unit: 'C') */
    public readonly WeatherReading $dewpoint;
    
    /** @var WeatherReading Relative humidity as percentage (unit: '%') */
    public readonly WeatherReading $humidity;
    
    /** @var WeatherReading Barometric pressure in inHg (unit: 'inHg') */
    public readonly WeatherReading $pressure;
    
    /** @var WeatherReading Precipitation accumulation in inches (unit: 'in') */
    public readonly WeatherReading $precipAccum;
    
    /** @var WindGroup Wind measurements - speed/gust in knots, direction in degrees */
    public readonly WindGroup $wind;
    
    /** @var WeatherReading Visibility in statute miles (unit: 'SM') */
    public readonly WeatherReading $visibility;
    
    /** @var WeatherReading Ceiling in feet AGL (unit: 'ft') */
    public readonly WeatherReading $ceiling;
    
    /** @var WeatherReading Cloud cover code (SKC, FEW, SCT, BKN, OVC) (unit: 'text') */
    public readonly WeatherReading $cloudCover;
    
    /** @var string|null Raw METAR string (for METAR source only) */
    public readonly ?string $rawMetar;

    /** @var string|null METAR station ICAO (e.g., KSPB). Set only for metar source. Used to detect neighboring vs local. */
    public readonly ?string $metarStationId;

    /** @var bool Whether this snapshot was successfully parsed */
    public readonly bool $isValid;

    /**
     * Create a new WeatherSnapshot
     *
     * @param string|null $metarStationId For METAR source: station ICAO. Null for non-METAR or when unknown.
     */
    public function __construct(
        string $source,
        int $fetchTime,
        WeatherReading $temperature,
        WeatherReading $dewpoint,
        WeatherReading $humidity,
        WeatherReading $pressure,
        WeatherReading $precipAccum,
        WindGroup $wind,
        WeatherReading $visibility,
        WeatherReading $ceiling,
        WeatherReading $cloudCover,
        ?string $rawMetar = null,
        bool $isValid = true,
        ?string $metarStationId = null
    ) {
        $this->source = $source;
        $this->fetchTime = $fetchTime;
        $this->temperature = $temperature;
        $this->dewpoint = $dewpoint;
        $this->humidity = $humidity;
        $this->pressure = $pressure;
        $this->precipAccum = $precipAccum;
        $this->wind = $wind;
        $this->visibility = $visibility;
        $this->ceiling = $ceiling;
        $this->cloudCover = $cloudCover;
        $this->rawMetar = $rawMetar;
        $this->isValid = $isValid;
        $this->metarStationId = $metarStationId;
    }
    
    /**
     * Create an empty/invalid snapshot
     * 
     * @param string $source Source identifier
     * @return self
     */
    public static function empty(string $source): self {
        $now = time();
        return new self(
            source: $source,
            fetchTime: $now,
            temperature: WeatherReading::null($source),
            dewpoint: WeatherReading::null($source),
            humidity: WeatherReading::null($source),
            pressure: WeatherReading::null($source),
            precipAccum: WeatherReading::null($source),
            wind: WindGroup::empty(),
            visibility: WeatherReading::null($source),
            ceiling: WeatherReading::null($source),
            cloudCover: WeatherReading::null($source),
            rawMetar: null,
            isValid: false,
            metarStationId: null
        );
    }
    
    /**
     * Check if a specific field has a valid value
     * 
     * @param string $fieldName Field to check
     * @return bool
     */
    public function hasField(string $fieldName): bool {
        return match($fieldName) {
            'temperature' => $this->temperature->hasValue(),
            'dewpoint' => $this->dewpoint->hasValue(),
            'humidity' => $this->humidity->hasValue(),
            'pressure' => $this->pressure->hasValue(),
            'precip_accum' => $this->precipAccum->hasValue(),
            'wind_speed' => $this->wind->speed->hasValue(),
            'wind_direction' => $this->wind->direction->hasValue(),
            'gust_speed' => $this->wind->gust->hasValue(),
            'visibility' => $this->visibility->hasValue(),
            'ceiling' => $this->ceiling->hasValue(),
            'cloud_cover' => $this->cloudCover->hasValue(),
            default => false,
        };
    }
    
    /**
     * Get a specific field reading
     * 
     * @param string $fieldName Field to get
     * @return WeatherReading|null
     */
    public function getField(string $fieldName): ?WeatherReading {
        return match($fieldName) {
            'temperature' => $this->temperature,
            'dewpoint' => $this->dewpoint,
            'humidity' => $this->humidity,
            'pressure' => $this->pressure,
            'precip_accum' => $this->precipAccum,
            'wind_speed' => $this->wind->speed,
            'wind_direction' => $this->wind->direction,
            'gust_speed' => $this->wind->gust,
            'visibility' => $this->visibility,
            'ceiling' => $this->ceiling,
            'cloud_cover' => $this->cloudCover,
            default => null,
        };
    }
    
    /**
     * Get observation time for a field
     * 
     * @param string $fieldName Field to check
     * @return int|null Observation time or null
     */
    public function getFieldObservationTime(string $fieldName): ?int {
        $reading = $this->getField($fieldName);
        return $reading?->observationTime;
    }
    
    /**
     * Check if the wind group is complete
     * 
     * @return bool True if wind has speed and direction
     */
    public function hasCompleteWind(): bool {
        return $this->wind->isComplete();
    }
    
    /**
     * Get all available fields as array
     * 
     * @return array Field values
     */
    public function toArray(): array {
        $data = [
            'source' => $this->source,
            'fetch_time' => $this->fetchTime,
            'temperature' => $this->temperature->value,
            'dewpoint' => $this->dewpoint->value,
            'humidity' => $this->humidity->value,
            'pressure' => $this->pressure->value,
            'precip_accum' => $this->precipAccum->value,
            'visibility' => $this->visibility->value,
            'ceiling' => $this->ceiling->value,
            'cloud_cover' => $this->cloudCover->value,
        ];
        
        // Add wind fields
        $data = array_merge($data, $this->wind->toArray());
        
        // Add raw METAR if present
        if ($this->rawMetar !== null) {
            $data['raw_metar'] = $this->rawMetar;
        }
        
        return $data;
    }
    
    /**
     * Get observation time map for all fields
     * 
     * @return array Field name => observation time
     */
    public function getObservationTimeMap(): array {
        $map = [];
        
        $fields = [
            'temperature' => $this->temperature,
            'dewpoint' => $this->dewpoint,
            'humidity' => $this->humidity,
            'pressure' => $this->pressure,
            'precip_accum' => $this->precipAccum,
            'visibility' => $this->visibility,
            'ceiling' => $this->ceiling,
            'cloud_cover' => $this->cloudCover,
        ];
        
        foreach ($fields as $name => $reading) {
            if ($reading->observationTime !== null) {
                $map[$name] = $reading->observationTime;
            }
        }
        
        // Add wind observation times
        $map = array_merge($map, $this->wind->getObservationTimeMap());
        
        return $map;
    }
}

