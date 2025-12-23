<?php
/**
 * Weather Reading - A single field measurement with metadata
 * 
 * Represents a single weather measurement (e.g., temperature, pressure) with:
 * - The measured value (or null if unavailable)
 * - When the observation was made
 * - Which source provided it
 * - Validation status
 * 
 * Immutable by design - create new instances rather than modifying.
 * 
 * @package AviationWX\Weather\Data
 */

namespace AviationWX\Weather\Data;

class WeatherReading {
    
    /** @var float|int|string|null The measured value */
    public readonly mixed $value;
    
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
     * @param int|null $observationTime Unix timestamp of observation
     * @param string|null $source Source identifier
     * @param bool $isValid Whether value passes validation
     */
    public function __construct(
        mixed $value,
        ?int $observationTime = null,
        ?string $source = null,
        bool $isValid = true
    ) {
        $this->value = $value;
        $this->observationTime = $observationTime;
        $this->source = $source;
        $this->isValid = $isValid;
    }
    
    /**
     * Create an empty/null reading
     * 
     * @param string|null $source Optional source attribution
     * @return self
     */
    public static function null(?string $source = null): self {
        return new self(null, null, $source, false);
    }
    
    /**
     * Create a reading from a value with current timestamp
     * 
     * @param mixed $value The value
     * @param string $source Source identifier
     * @param int|null $observationTime Observation time (defaults to now)
     * @return self
     */
    public static function from(mixed $value, string $source, ?int $observationTime = null): self {
        if ($value === null) {
            return self::null($source);
        }
        return new self($value, $observationTime ?? time(), $source, true);
    }
    
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
    
    /**
     * Create a new reading with a different source
     * 
     * @param string $source New source identifier
     * @return self New reading with updated source
     */
    public function withSource(string $source): self {
        return new self($this->value, $this->observationTime, $source, $this->isValid);
    }
    
    /**
     * Create a new reading marked as invalid
     * 
     * @return self New reading marked invalid
     */
    public function asInvalid(): self {
        return new self($this->value, $this->observationTime, $this->source, false);
    }
    
    /**
     * Convert to array for JSON serialization
     * 
     * @param bool $includeMetadata Include source and observation time
     * @return array
     */
    public function toArray(bool $includeMetadata = false): array {
        if (!$includeMetadata) {
            return ['value' => $this->value];
        }
        
        return [
            'value' => $this->value,
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
}

