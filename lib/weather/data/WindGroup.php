<?php
/**
 * Wind Group - Grouped wind measurements from a single source
 * 
 * Wind speed, direction, and gust must come from the same source to be
 * meaningful. Mixing wind speed from one source with direction from another
 * at different times could be misleading to pilots.
 * 
 * This class ensures wind data is kept together as a unit.
 * 
 * @package AviationWX\Weather\Data
 */

namespace AviationWX\Weather\Data;

class WindGroup {
    
    /** @var WeatherReading Wind speed (knots) */
    public readonly WeatherReading $speed;
    
    /** @var WeatherReading Wind direction (degrees, 0-360) */
    public readonly WeatherReading $direction;
    
    /** @var WeatherReading Gust speed (knots), may be null for calm conditions */
    public readonly WeatherReading $gust;
    
    /** @var string|null Source that provided this wind group */
    public readonly ?string $source;
    
    /**
     * Create a new WindGroup
     * 
     * @param WeatherReading $speed Wind speed
     * @param WeatherReading $direction Wind direction
     * @param WeatherReading $gust Gust speed (can be null reading for calm)
     * @param string|null $source Source identifier
     */
    public function __construct(
        WeatherReading $speed,
        WeatherReading $direction,
        WeatherReading $gust,
        ?string $source = null
    ) {
        $this->speed = $speed;
        $this->direction = $direction;
        $this->gust = $gust;
        $this->source = $source;
    }
    
    /**
     * Create an empty wind group (no data available)
     * 
     * @return self
     */
    public static function empty(): self {
        return new self(
            WeatherReading::null(),
            WeatherReading::null(),
            WeatherReading::null(),
            null
        );
    }
    
    /**
     * Create a wind group from values
     * 
     * @param float|int|null $speed Wind speed in knots
     * @param float|int|null $direction Wind direction in degrees
     * @param float|int|null $gust Gust speed in knots (null for no gusts)
     * @param string $source Source identifier
     * @param int|null $observationTime Observation timestamp
     * @return self
     */
    public static function from(
        float|int|null $speed,
        float|int|null $direction,
        float|int|null $gust,
        string $source,
        ?int $observationTime = null
    ): self {
        $obsTime = $observationTime ?? time();
        
        return new self(
            $speed !== null ? new WeatherReading($speed, $obsTime, $source, true) : WeatherReading::null($source),
            $direction !== null ? new WeatherReading($direction, $obsTime, $source, true) : WeatherReading::null($source),
            $gust !== null ? new WeatherReading($gust, $obsTime, $source, true) : WeatherReading::null($source),
            $source
        );
    }
    
    /**
     * Check if this wind group is complete (has speed and direction)
     * 
     * A complete wind group has at minimum:
     * - Valid wind speed
     * - Valid wind direction
     * 
     * Gust is optional (calm conditions have no gusts).
     * 
     * @return bool True if complete
     */
    public function isComplete(): bool {
        return $this->speed->hasValue() && $this->direction->hasValue();
    }
    
    /**
     * Check if this wind group has any data
     * 
     * @return bool True if at least one field has a value
     */
    public function hasAnyData(): bool {
        return $this->speed->hasValue() || $this->direction->hasValue() || $this->gust->hasValue();
    }
    
    /**
     * Check if this wind group is stale
     * 
     * @param int $maxAge Maximum acceptable age in seconds
     * @param int|null $now Current timestamp
     * @return bool True if any field is stale
     */
    public function isStale(int $maxAge, ?int $now = null): bool {
        // If we don't have complete data, consider it stale
        if (!$this->isComplete()) {
            return true;
        }
        
        return $this->speed->isStale($maxAge, $now) || $this->direction->isStale($maxAge, $now);
    }
    
    /**
     * Get the observation time (uses speed's observation time)
     * 
     * @return int|null Observation timestamp
     */
    public function getObservationTime(): ?int {
        return $this->speed->observationTime;
    }
    
    /**
     * Calculate gust factor (gust - speed)
     * 
     * @return int|null Gust factor or null if not calculable
     */
    public function getGustFactor(): ?int {
        if (!$this->speed->hasValue() || !$this->gust->hasValue()) {
            return null;
        }
        
        $factor = (int)$this->gust->value - (int)$this->speed->value;
        return max(0, $factor);
    }
    
    /**
     * Convert to array for JSON serialization
     * 
     * @return array
     */
    public function toArray(): array {
        return [
            'wind_speed' => $this->speed->value,
            'wind_direction' => $this->direction->value,
            'gust_speed' => $this->gust->value,
            'gust_factor' => $this->getGustFactor(),
        ];
    }
    
    /**
     * Get observation times for field map
     * 
     * @return array Field name => observation time
     */
    public function getObservationTimeMap(): array {
        $map = [];
        
        if ($this->speed->observationTime !== null) {
            $map['wind_speed'] = $this->speed->observationTime;
        }
        if ($this->direction->observationTime !== null) {
            $map['wind_direction'] = $this->direction->observationTime;
        }
        if ($this->gust->observationTime !== null) {
            $map['gust_speed'] = $this->gust->observationTime;
        }
        if ($this->speed->observationTime !== null && $this->gust->observationTime !== null) {
            $map['gust_factor'] = $this->speed->observationTime;
        }
        
        return $map;
    }
}

