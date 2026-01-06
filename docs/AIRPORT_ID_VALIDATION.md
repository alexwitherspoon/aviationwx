# Supporting Airports Without ICAO Codes

## Current State

### What Works
- **Subdomain routing**: Already supports 3-50 character IDs with hyphens (`/^([a-z0-9-]{3,50})\.`)
- **File system paths**: All cache paths use `strtolower($airportId)`, making them filesystem-safe
- **URL routing**: `findAirportByIdentifier()` can look up airports by ICAO, IATA, FAA, or airport ID
- **Example config**: Shows "cust" as a custom airport without standard identifiers

### What Doesn't Work
- **Validation**: `validateAirportId()` only allows 3-4 lowercase alphanumeric characters (ICAO format)
- **Config loading**: Rejects airport keys longer than 4 characters or containing hyphens
- **API endpoints**: Some endpoints validate airport IDs and reject longer formats

## The Problem

Some airports don't have ICAO codes. These airports may:
- Only have FAA codes (which can be 3-4 characters, but some are longer)
- Have custom identifiers (e.g., "private-strip-1", "helipad-downtown")
- Be international airports with non-standard codes
- Be temporary or seasonal facilities

**Current limitation**: The validation function `validateAirportId()` enforces ICAO format (3-4 chars), preventing these airports from being configured.

## Solution Overview

Extend `validateAirportId()` to support:
- **Length**: 3-50 characters (matches subdomain regex)
- **Characters**: Lowercase alphanumeric + hyphens (filesystem-safe)
- **Format**: Must start and end with alphanumeric (no leading/trailing hyphens)
- **Backward compatibility**: Existing 3-4 character ICAO codes continue to work

## Implementation Plan

### 1. Update `validateAirportId()` Function

**Location**: `lib/config.php`

**Current**:
```php
function validateAirportId(?string $id): bool {
    if ($id === null || empty($id)) {
        return false;
    }
    // Check for whitespace BEFORE trimming (reject IDs with whitespace)
    if (preg_match('/\s/', $id)) {
        return false;
    }
    return preg_match('/^[a-z0-9]{3,4}$/', strtolower(trim($id))) === 1;
}
```

**Proposed**:
```php
function validateAirportId(?string $id): bool {
    if ($id === null || empty($id)) {
        return false;
    }
    // Check for whitespace BEFORE trimming (reject IDs with whitespace)
    if (preg_match('/\s/', $id)) {
        return false;
    }
    $normalized = strtolower(trim($id));
    
    // Support 3-50 characters (matches subdomain regex)
    // Allow lowercase alphanumeric + hyphens (filesystem-safe)
    // Must start and end with alphanumeric (no leading/trailing hyphens)
    // No consecutive hyphens (prevents "--")
    return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $normalized) === 1
        && strlen($normalized) >= 3
        && strlen($normalized) <= 50
        && strpos($normalized, '--') === false;
}
```

**Validation rules**:
- ✅ `kspb` (3-4 chars, backward compatible)
- ✅ `private-strip-1` (longer with hyphens)
- ✅ `helipad-downtown` (descriptive names)
- ✅ `03s` (FAA codes with numbers)
- ❌ `-airport` (leading hyphen)
- ❌ `airport-` (trailing hyphen)
- ❌ `air--port` (consecutive hyphens)
- ❌ `air port` (spaces)
- ❌ `airport!` (special chars)

### 2. Update Error Messages

**Location**: `lib/config.php` (line ~1298)

**Current**:
```php
$errors[] = "Airport key '{$aid}' is invalid (3-4 lowercase alphanumerics)";
```

**Proposed**:
```php
$errors[] = "Airport key '{$aid}' is invalid (must be 3-50 lowercase alphanumeric characters, hyphens allowed)";
```

### 3. Update Documentation/Comments

Update PHPDoc comments and error messages throughout the codebase to reflect the new validation rules.

### 4. Update Tests

**Location**: `tests/Unit/ConfigValidationTest.php`

Add test cases for:
- Valid longer IDs: `private-strip-1`, `helipad-downtown`
- Valid with hyphens: `test-airport`, `a-b-c`
- Invalid: leading/trailing hyphens, consecutive hyphens, too long (>50 chars)

### 5. Filesystem Safety Considerations

**Already safe**:
- All cache paths use `strtolower($airportId)` - no case issues
- Directory names: `cache/webcams/{airportId}/` - hyphens are valid in directory names
- File names: `{airportId}.json` - hyphens are valid in filenames
- Subdomain routing: Already supports hyphens in regex

**No changes needed** for filesystem paths - they're already safe.

## Example Configurations

### Airport with Custom ID (No ICAO)
```json
{
  "airports": {
    "private-strip-1": {
      "name": "Private Airstrip #1",
      "enabled": true,
      "lat": 45.0,
      "lon": -122.0,
      "elevation_ft": 500,
      "timezone": "America/Los_Angeles",
      "note": "Private airstrip without ICAO code"
    }
  }
}
```

### Airport with Long Descriptive ID
```json
{
  "airports": {
    "helipad-downtown-medical": {
      "name": "Downtown Medical Center Helipad",
      "enabled": true,
      "lat": 45.5,
      "lon": -122.6,
      "elevation_ft": 200,
      "timezone": "America/Los_Angeles"
    }
  }
}
```

## Limitations for Non-Standard Airport IDs

⚠️ **Important**: Airports without standard 3-4 character ICAO/FAA/IATA codes will **NOT** benefit from:

1. **Identifier-based lookups**: 
   - `findAirportByIdentifier()` won't find them via ICAO/IATA/FAA codes
   - `getIcaoFromIdentifier()` won't work (no cached mappings)
   - `detectIdentifierType()` won't recognize them as standard codes
   - Users can't access them via standard codes (e.g., `?airport=KSPB` won't work if ID is `private-strip-1`)

2. **NOTAM location-based queries**:
   - NOTAM API location queries require ICAO/IATA codes
   - **Fallback**: Geospatial NOTAM queries (using lat/lon) **WILL** work if coordinates are provided
   - So NOTAMs can still work, just not via location identifier

3. **METAR data**:
   - METAR lookups typically require ICAO codes
   - Custom weather sources (Tempest, Ambient, etc.) will still work

4. **Cached identifier mappings**:
   - IATA->ICAO and FAA->ICAO mapping files won't contain custom IDs
   - No automatic redirects from standard codes to custom IDs

**What WILL work for custom IDs**:
- ✅ Direct access via custom ID in URLs (`?airport=private-strip-1`)
- ✅ Subdomain routing (`private-strip-1.aviationwx.org`)
- ✅ Webcam functionality (doesn't require codes)
- ✅ Custom weather sources (Tempest, Ambient, SynopticData)
- ✅ Geospatial NOTAM queries (if lat/lon provided)
- ✅ Dashboard display
- ✅ All cache paths and file operations

## Backward Compatibility

✅ **Fully backward compatible**:
- All existing 3-4 character ICAO codes continue to work
- No changes needed to existing airport configurations
- Subdomain routing already supports the extended format
- File system paths already handle any valid identifier

## Testing Checklist

- [ ] Existing 3-4 character IDs still validate
- [ ] Longer IDs (5-50 chars) validate correctly
- [ ] IDs with hyphens validate correctly
- [ ] Leading/trailing hyphens are rejected
- [ ] Consecutive hyphens are rejected
- [ ] Spaces are rejected
- [ ] Special characters are rejected
- [ ] Subdomain routing works with longer IDs
- [ ] Cache paths are created correctly
- [ ] API endpoints accept longer IDs
- [ ] Config validation shows correct error messages

## Migration Notes

**No migration needed** - this is a pure extension:
- Existing airports continue to work
- New airports can use longer IDs
- No database or cache changes required
- No breaking changes to APIs

## Security Considerations

**Filesystem safety**:
- Hyphens are safe in directory and file names
- No path traversal risk (validation prevents `../`)
- No command injection risk (validation prevents special shell chars)

**URL safety**:
- Hyphens are valid in subdomains
- No XSS risk (IDs are always lowercased and validated)
- No SQL injection risk (IDs are not used in SQL queries)

## Related Code Locations

1. **Validation function**: `lib/config.php:validateAirportId()`
2. **Config validation**: `lib/config.php:loadConfig()` (line ~1297)
3. **Request parsing**: `lib/config.php:getAirportIdFromRequest()` (line ~1422)
4. **Subdomain routing**: `index.php` (line ~160)
5. **API validation**: `api/notam.php` (line ~23)
6. **Worker validation**: `scripts/fetch-webcam.php`, `scripts/fetch-weather.php`
7. **Config generator**: `pages/config-generator.php` (line ~101)
8. **Tests**: `tests/Unit/ConfigValidationTest.php`

