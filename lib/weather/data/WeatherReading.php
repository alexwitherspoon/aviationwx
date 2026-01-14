<?php
/**
 * Weather Reading - A single field measurement with metadata
 * 
 * Represents a single weather measurement (e.g., temperature, pressure) with:
 * - The measured value (or null if unavailable)
 * - The unit of measurement (e.g., 'C', 'hPa', 'm')
 * - When the observation was made
 * - Which source provided it
 * - Validation status
 * 
 * Immutable by design - create new instances rather than modifying.
 * Use factory methods (celsius, hPa, meters, etc.) to ensure correct units.
 * 
 * @package AviationWX\Weather\Data
 */

namespace AviationWX\Weather\Data;

require_once __DIR__ . '/../../units.php';

class WeatherReading {
    
    /** @var float|int|string|null The measured value */
    public readonly mixed $value;
    
    /** @var string The unit of measurement (e.g., 'C', 'hPa', 'm', 'mm', 'kt', 'ft', '%') */
    public readonly string $unit;
    
    /** @var int|null Unix timestamp when this was observed */
    public readonly ?int $observationTime;
    
    /** @var string|null Source identifier (e.g., 'tempest', 'metar', 'synopticdata') */
    public readonly ?string $source;
    
    /** @var bool Whether the value passes validation checks */
    public readonly bool $isValid;
    
    /**
     * Create a new WeatherReading
     * 
     * @param mixed $value The measured value (null if unavailable)
     * @param string $unit The unit of measurement
     * @param int|null $observationTime Unix timestamp of observation
     * @param string|null $source Source identifier
     * @param bool $isValid Whether value passes validation
     */
    public function __construct(
        mixed $value,
        string $unit = '',
        ?int $observationTime = null,
        ?string $source = null,
        bool $isValid = true
    ) {
        $this->value = $value;
        $this->unit = $unit;
        $this->observationTime = $observationTime;
        $this->source = $source;
        $this->isValid = $isValid;
    }
    
    // ========================================================================
    // FACTORY METHODS - Use these to create readings with correct units
    // ========================================================================
    
    /**
     * Create an empty/null reading
     * 
     * @param string|null $source Optional source attribution
     * @return self
     */
    public static function null(?string $source = null): self {
        return new self(null, '', null, $source, false);
    }
    
    /**
     * Create a reading from a value with current timestamp (legacy method)
     * 
     * Note: Prefer using unit-specific factory methods (celsius, hPa, etc.)
     * for explicit unit tracking.
     * 
     * @param mixed $value The value
     * @param string $source Source identifier
     * @param int|null $observationTime Observation time (defaults to now)
     * @param string $unit Optional unit (default empty for backward compatibility)
     * @return self
     */
    public static function from(mixed $value, string $source, ?int $observationTime = null, string $unit = ''): self {
        if ($value === null) {
            return self::null($source);
        }
        return new self($value, $unit, $observationTime ?? time(), $source, true);
    }
    
    /**
     * Create a temperature reading in Celsius
     * 
     * @param float|null $value Temperature in Celsius
     * @param string $source Source identifier
     * @param int|null $observationTime Observation time (defaults to now)
     * @return self
     */
    public static function celsius(?float $value, string $source, ?int $observationTime = null): self {
        if ($value === null) {
            return self::null($source);
        }
        return new self($value, 'C', $observationTime ?? time(), $source, true);
    }
    
    /**
     * Create a pressure reading in hectoPascals
     * 
     * @param float|null $value Pressure in hPa
     * @param string $source Source identifier
     * @param int|null $observationTime Observation time (defaults to now)
     * @return self
     */
    public static function hPa(?float $value, string $source, ?int $observationTime = null): self {
        if ($value === null) {
            return self::null($source);
        }
        return new self($value, 'hPa', $observationTime ?? time(), $source, true);
    }
    
    /**
     * Create a pressure reading in inches of mercury
     * 
     * @param float|null $value Pressure in inHg
     * @param string $source Source identifier
     * @param int|null $observationTime Observation time (defaults to now)
     * @return self
     */
    public static function inHg(?float $value, string $source, ?int $observationTime = null): self {
        if ($value === null) {
            return self::null($source);
        }
        return new self($value, 'inHg', $observationTime ?? time(), $source, true);
    }
    
    /**
     * Create a visibility/distance reading in meters
     * 
     * @param float|null $value Distance in meters
     * @param string $source Source identifier
     * @param int|null $observationTime Observation time (defaults to now)
     * @return self
     */
    public static function meters(?float $value, string $source, ?int $observationTime = null): self {
        if ($value === null) {
            return self::null($source);
        }
        return new self($value, 'm', $observationTime ?? time(), $source, true);
    }
    
    /**
     * Create a visibility reading in statute miles
     * 
     * @param float|null $value Visibility in statute miles
     * @param string $source Source identifier
     * @param int|null $observationTime Observation time (defaults to now)
     * @return self
     */
    public static function statuteMiles(?float $value, string $source, ?int $observationTime = null): self {
        if ($value === null) {
            return self::null($source);
        }
        return new self($value, 'SM', $observationTime ?? time(), $source, true);
    }
    
    /**
     * Create a precipitation reading in millimeters
     * 
     * @param float|null $value Precipitation in mm
     * @param string $source Source identifier
     * @param int|null $observationTime Observation time (defaults to now)
     * @return self
     */
    public static function mm(?float $value, string $source, ?int $observationTime = null): self {
        if ($value === null) {
            return self::null($source);
        }
        return new self($value, 'mm', $observationTime ?? time(), $source, true);
    }
    
    /**
     * Create a precipitation reading in inches
     * 
     * @param float|null $value Precipitation in inches
     * @param string $source Source identifier
     * @param int|null $observationTime Observation time (defaults to now)
     * @return self
     */
    public static function inches(?float $value, string $source, ?int $observationTime = null): self {
        if ($value === null) {
            return self::null($source);
        }
        return new self($value, 'in', $observationTime ?? time(), $source, true);
    }
    
    /**
     * Create a wind speed reading in knots
     * 
     * @param float|null $value Wind speed in knots
     * @param string $source Source identifier
     * @param int|null $observationTime Observation time (defaults to now)
     * @return self
     */
    public static function knots(?float $value, string $source, ?int $observationTime = null): self {
        if ($value === null) {
            return self::null($source);
        }
        return new self($value, 'kt', $observationTime ?? time(), $source, true);
    }
    
    /**
     * Create an altitude/ceiling reading in feet
     * 
     * @param float|null $value Altitude in feet
     * @param string $source Source identifier
     * @param int|null $observationTime Observation time (defaults to now)
     * @return self
     */
    public static function feet(?float $value, string $source, ?int $observationTime = null): self {
        if ($value === null) {
            return self::null($source);
        }
        return new self($value, 'ft', $observationTime ?? time(), $source, true);
    }
    
    /**
     * Create a percentage reading (e.g., humidity)
     * 
     * @param float|null $value Percentage value
     * @param string $source Source identifier
     * @param int|null $observationTime Observation time (defaults to now)
     * @return self
     */
    public static function percent(?float $value, string $source, ?int $observationTime = null): self {
        if ($value === null) {
            return self::null($source);
        }
        return new self($value, '%', $observationTime ?? time(), $source, true);
    }
    
    /**
     * Create a direction reading in degrees
     * 
     * @param float|int|string|null $value Direction in degrees (or 'VRB' for variable)
     * @param string $source Source identifier
     * @param int|null $observationTime Observation time (defaults to now)
     * @return self
     */
    public static function degrees(mixed $value, string $source, ?int $observationTime = null): self {
        if ($value === null) {
            return self::null($source);
        }
        return new self($value, 'deg', $observationTime ?? time(), $source, true);
    }
    
    /**
     * Create a text/code reading (e.g., cloud cover SKC, FEW, SCT, BKN, OVC)
     * 
     * @param string|null $value Text value
     * @param string $source Source identifier
     * @param int|null $observationTime Observation time (defaults to now)
     * @return self
     */
    public static function text(?string $value, string $source, ?int $observationTime = null): self {
        if ($value === null) {
            return self::null($source);
        }
        return new self($value, 'text', $observationTime ?? time(), $source, true);
    }
    
    // ========================================================================
    // CONVERSION METHOD
    // ========================================================================
    
    /**
     * Convert this reading to a different unit
     * 
     * Uses the centralized conversion library (lib/units.php).
     * Returns a new WeatherReading with the converted value and new unit.
     * 
     * @param string $targetUnit The target unit (e.g., 'F', 'inHg', 'SM')
     * @return self New reading with converted value
     * @throws \InvalidArgumentException If conversion is not supported
     */
    public function convertTo(string $targetUnit): self {
        if ($this->value === null) {
            return self::null($this->source);
        }
        
        // Same unit, return copy
        if ($this->unit === $targetUnit) {
            return new self($this->value, $this->unit, $this->observationTime, $this->source, $this->isValid);
        }
        
        // Use the centralized conversion function
        $convertedValue = \convert($this->value, $this->unit, $targetUnit);
        
        return new self($convertedValue, $targetUnit, $this->observationTime, $this->source, $this->isValid);
    }
    
    // ========================================================================
    // QUERY METHODS
    // ========================================================================
    
    /**
     * Check if this reading has a usable value
     * 
     * A reading is usable if:
     * - Value is not null
     * - Value passes validation
     * - Has an observation time
     * 
     * @return bool
     */
    public function hasValue(): bool {
        return $this->value !== null && $this->isValid && $this->observationTime !== null;
    }
    
    /**
     * Get the age of this reading in seconds
     * 
     * @param int|null $now Current timestamp (defaults to time())
     * @return int|null Age in seconds, or null if no observation time
     */
    public function age(?int $now = null): ?int {
        if ($this->observationTime === null) {
            return null;
        }
        return ($now ?? time()) - $this->observationTime;
    }
    
    /**
     * Check if this reading is stale based on max acceptable age
     * 
     * @param int $maxAge Maximum acceptable age in seconds
     * @param int|null $now Current timestamp (defaults to time())
     * @return bool True if stale (older than maxAge or no observation time)
     */
    public function isStale(int $maxAge, ?int $now = null): bool {
        $age = $this->age($now);
        if ($age === null) {
            return true; // No observation time = stale
        }
        return $age > $maxAge;
    }
    
    // ========================================================================
    // MUTATION METHODS (return new instances)
    // ========================================================================
    
    /**
     * Create a new reading with a different source
     * 
     * @param string $source New source identifier
     * @return self New reading with updated source
     */
    public function withSource(string $source): self {
        return new self($this->value, $this->unit, $this->observationTime, $source, $this->isValid);
    }
    
    /**
     * Create a new reading marked as invalid
     * 
     * @return self New reading marked invalid
     */
    public function asInvalid(): self {
        return new self($this->value, $this->unit, $this->observationTime, $this->source, false);
    }
    
    // ========================================================================
    // SERIALIZATION
    // ========================================================================
    
    /**
     * Convert to array for JSON serialization
     * 
     * @param bool $includeMetadata Include source, observation time, and unit
     * @return array
     */
    public function toArray(bool $includeMetadata = false): array {
        if (!$includeMetadata) {
            return ['value' => $this->value];
        }
        
        return [
            'value' => $this->value,
            'unit' => $this->unit,
            'observation_time' => $this->observationTime,
            'source' => $this->source,
            'is_valid' => $this->isValid,
        ];
    }
    
    /**
     * Get just the value (for simple assignments)
     * 
     * @return mixed
     */
    public function getValue(): mixed {
        return $this->value;
    }
    
    /**
     * Get the unit of this reading
     * 
     * @return string
     */
    public function getUnit(): string {
        return $this->unit;
    }
}
