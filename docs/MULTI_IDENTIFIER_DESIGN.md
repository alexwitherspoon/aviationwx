# Multi-Identifier System Design: ICAO, IATA, and FAA Support

## Problem Statement

Currently, AviationWX uses a single airport identifier system based on ICAO codes (3-4 lowercase alphanumeric). However, many airports don't have ICAO codes, especially smaller airports that only have FAA identifiers (e.g., Sandy River Airport: `03S`).

**Example:** Sandy River Airport (03S)
- No ICAO code
- FAA ID: `03S` (starts with number, 3 characters)
- Common pattern for small US airports

## Current System Analysis

### Current Airport ID System
- **Format**: 3-4 lowercase alphanumeric characters (ICAO-like)
- **Validation**: `validateAirportId()` - `/^[a-z0-9]{3,4}$/`
- **Storage**: Airport ID is the key in `config['airports']` object
- **Routing**: Extracted from subdomain or query parameter
- **Config Field**: Only `icao` field exists

### Current Usage Points
1. **Routing** (`index.php`, `getAirportIdFromRequest()`)
   - Subdomain: `kspb.aviationwx.org`
   - Query param: `/?airport=kspb`
   - Validates format before lookup

2. **API Endpoints** (`api/weather.php`, `api/webcam.php`)
   - Accept `?airport=kspb` parameter
   - Validate and lookup by airport ID

3. **Configuration** (`config['airports']`)
   - Airport ID is the object key
   - Each airport has `icao` field

4. **URL Generation** (`lib/seo.php`, `pages/airport.php`)
   - Subdomain URLs: `{airportId}.aviationwx.org`
   - Canonical URLs use airport ID

5. **Sitemap** (`api/sitemap.php`)
   - Generates URLs using airport IDs

6. **Homepage** (`pages/homepage.php`)
   - Lists airports, links use airport IDs

## Identifier Type Characteristics

### ICAO Codes
- **Format**: 3-4 uppercase letters (sometimes with numbers)
- **Examples**: `KSPB`, `KPDX`, `EGLL`
- **Scope**: International standard
- **Coverage**: Most commercial and many general aviation airports
- **Limitation**: Many small airports don't have ICAO codes

### IATA Codes
- **Format**: 3 uppercase letters
- **Examples**: `PDX`, `LAX`, `JFK`
- **Scope**: Commercial aviation (airlines)
- **Coverage**: Primarily commercial airports
- **Limitation**: Many general aviation airports don't have IATA codes

### FAA Identifiers
- **Format**: 3-4 alphanumeric (can start with number)
- **Examples**: `03S`, `KSPB`, `PDX`
- **Scope**: US airports (FAA jurisdiction)
- **Coverage**: All US airports (including small private fields)
- **Limitation**: US-only, but most comprehensive for US airports
- **Note**: Can overlap with ICAO (e.g., `KSPB` is both FAA and ICAO)

## Design Considerations

### 1. Primary Identifier Strategy

**Selected: Priority-Based System (ICAO > IATA > FAA > Custom)**

- **Primary Key**: Best available identifier following priority:
  1. ICAO (preferred) - International standard
  2. IATA (fallback) - Commercial aviation
  3. FAA (fallback) - US airports
  4. Custom (rare) - Informal airports
- **Lookup**: Support lookup by any identifier type
- **Display**: Show all available identifiers
- **Pros**: 
  - Uses official identifiers when available (better SEO)
  - Clear priority system
  - Supports all airport types
  - Backward compatible (existing IDs still work)
- **Cons**: Requires migration for existing airports

### 2. Routing Strategy

**Current**: `{airportId}.aviationwx.org` or `/?airport={airportId}`

**Options:**
- **A**: Keep current system, lookup by any identifier type
  - `kspb.aviationwx.org` (works if airport ID is ICAO)
  - `03s.aviationwx.org` (works if airport ID is FAA)
  - `/?airport=KSPB` (lookup by ICAO)
  - `/?airport=03S` (lookup by FAA)

- **B**: Support multiple subdomain formats
  - `kspb.aviationwx.org` (ICAO)
  - `03s.aviationwx.org` (FAA)
  - Requires DNS wildcard support

- **C**: Use path-based routing
  - `aviationwx.org/airport/KSPB`
  - `aviationwx.org/airport/03S`
  - More flexible, but breaks current subdomain model

**Recommendation**: Option A - Keep current system, enhance lookup

### 3. Configuration Schema

**Current:**
```json
{
  "airports": {
    "kspb": {
      "icao": "KSPB",
      "name": "Scappoose Industrial Airpark",
      ...
    }
  }
}
```

**Proposed (Priority-Based):**
```json
{
  "airports": {
    "KSPB": {  // Primary key = ICAO (preferred)
      "icao": "KSPB",
      "iata": null,
      "faa": "KSPB",
      "name": "Scappoose Industrial Airpark",
      ...
    },
    "PDX": {  // Primary key = IATA (no ICAO)
      "icao": null,
      "iata": "PDX",
      "faa": "KPDX",
      "name": "Portland International Airport",
      ...
    },
    "03S": {  // Primary key = FAA (no ICAO/IATA)
      "icao": null,
      "iata": null,
      "faa": "03S",
      "name": "Sandy River Airport",
      ...
    },
    "sandy-river": {  // Primary key = Custom (informal)
      "icao": null,
      "iata": null,
      "faa": null,
      "name": "Sandy River Private Strip",
      ...
    }
  }
}
```

**Note**: Primary key selection follows priority: ICAO > IATA > FAA > Custom

### 4. Lookup Strategy

**Current**: Direct key lookup `$config['airports'][$airportId]`

**Proposed**: Multi-step lookup
1. Direct key lookup (backward compatibility)
2. Search by ICAO (case-insensitive)
3. Search by IATA (case-insensitive)
4. Search by FAA (case-insensitive)

**Implementation:**
```php
function findAirportByIdentifier($identifier): ?array {
    $config = loadConfig();
    if (!$config || !isset($config['airports'])) {
        return null;
    }
    
    $identifier = trim($identifier);
    $identifierUpper = strtoupper($identifier);
    $identifierLower = strtolower($identifier);
    
    // 1. Direct key lookup (primary identifier - backward compatibility)
    if (isset($config['airports'][$identifierUpper])) {
        return $config['airports'][$identifierUpper];
    }
    if (isset($config['airports'][$identifierLower])) {
        return $config['airports'][$identifierLower];
    }
    if (isset($config['airports'][$identifier])) {
        return $config['airports'][$identifier];
    }
    
    // 2. Search by identifier type (priority: ICAO > IATA > FAA)
    foreach ($config['airports'] as $airportId => $airport) {
        // Check ICAO (preferred)
        if (isset($airport['icao']) && strtoupper($airport['icao']) === $identifierUpper) {
            return $airport;
        }
        // Check IATA
        if (isset($airport['iata']) && strtoupper($airport['iata']) === $identifierUpper) {
            return $airport;
        }
        // Check FAA
        if (isset($airport['faa']) && strtoupper($airport['faa']) === $identifierUpper) {
            return $airport;
        }
    }
    
    return null;
}
```

### 5. Validation Strategy

**Current**: Single validator for ICAO-like format

**Proposed**: Separate validators for each type
- **ICAO**: 3-4 alphanumeric, typically letters
- **IATA**: Exactly 3 letters
- **FAA**: 3-4 alphanumeric (can start with number)

**Airport ID Validation**: Keep current (3-4 lowercase alphanumeric) for backward compatibility

### 6. URL Generation Strategy

**Current**: Always uses airport ID for subdomain

**Proposed**: 
- **Subdomain**: Continue using airport ID (internal identifier)
- **Canonical URL**: Use best available identifier (ICAO > IATA > FAA > Airport ID)
- **Display**: Show all available identifiers in UI

### 7. Backward Compatibility

**Critical**: Must maintain backward compatibility with existing:
- Airport IDs in config
- Existing URLs/subdomains
- API endpoints
- Bookmarked links

**Strategy**:
- **Phase 1**: Add new fields (`iata`, `faa`) without breaking existing
  - Existing airport IDs continue to work
  - Lookup supports both old and new identifiers
- **Phase 2**: Gradual migration to priority-based primary keys
  - Migration script to identify airports that should use ICAO/IATA/FAA
  - Support both old and new primary keys during transition
  - Redirect old URLs to new primary key URLs (optional)
- **Phase 3**: Full migration complete
  - All airports use priority-based primary keys
  - Old identifiers still work via lookup
  - Legacy support maintained indefinitely

## Implementation Plan

### Phase 1: Configuration Schema Update
1. Add `iata` and `faa` fields to config schema
2. Update validation to accept these fields
3. Update example config file
4. Update documentation

### Phase 2: Lookup Enhancement
1. Create `findAirportByIdentifier()` function
2. Update `getAirportIdFromRequest()` to use new lookup
3. Update API endpoints to use new lookup
4. Add caching for identifier lookups

### Phase 3: Validation Enhancement
1. Create separate validators for ICAO, IATA, FAA
2. Update config validation to validate all identifier types
3. Add validation for identifier uniqueness (within type)

### Phase 4: UI/Display Updates
1. Update airport pages to show all identifiers
2. Update homepage to show identifiers
3. Update search to support all identifier types
4. Update SEO metadata to include all identifiers

### Phase 5: Documentation
1. Update configuration guide
2. Update API documentation
3. Update deployment guide
4. Migration guide for existing airports

## Open Questions

1. **Identifier Conflicts**: What if two airports have the same FAA ID? (Shouldn't happen, but need validation)
2. **Subdomain Strategy**: Should we support subdomains for all identifier types, or just airport ID?
3. **Migration**: How to migrate existing airports that only have ICAO to include FAA/IATA?
4. **International**: How to handle non-US airports that don't have FAA identifiers?
5. **Search**: Should search prioritize certain identifier types?
6. **API Response**: Should API responses include all identifiers?

## Recommended Approach

**Priority-Based Identifier System**

### Identifier Priority (for Primary Key)
1. **ICAO** (preferred) - International standard, most comprehensive
2. **IATA** (fallback) - Commercial aviation standard
3. **FAA** (fallback) - US airports without ICAO/IATA
4. **Custom** (rare) - Informal airports without official codes

### Design Principles
1. **Primary key in config** = Best available identifier (ICAO > IATA > FAA > custom)
2. **Add `icao`, `iata`, `faa` fields** to each airport config (all optional)
3. **Enhance lookup** to search by any identifier type (with priority)
4. **Subdomain routing** uses primary identifier (best available)
5. **Query parameter lookup** supports any identifier type
6. **Display all identifiers** in UI where relevant
7. **Validate identifier formats** separately for each type

### Configuration Schema

**Example with ICAO (preferred):**
```json
{
  "airports": {
    "KSPB": {
      "icao": "KSPB",
      "iata": null,
      "faa": "KSPB",
      "name": "Scappoose Industrial Airpark",
      ...
    }
  }
}
```

**Example with IATA (no ICAO):**
```json
{
  "airports": {
    "PDX": {
      "icao": null,
      "iata": "PDX",
      "faa": "KPDX",
      "name": "Portland International Airport",
      ...
    }
  }
}
```

**Example with FAA only (small airport):**
```json
{
  "airports": {
    "03S": {
      "icao": null,
      "iata": null,
      "faa": "03S",
      "name": "Sandy River Airport",
      ...
    }
  }
}
```

**Example with custom identifier (informal airport):**
```json
{
  "airports": {
    "sandy-river": {
      "icao": null,
      "iata": null,
      "faa": null,
      "name": "Sandy River Private Strip",
      ...
    }
  }
}
```

### Lookup Priority Logic

When searching for an airport by identifier:
1. Direct key lookup (primary identifier)
2. Search by ICAO (case-insensitive)
3. Search by IATA (case-insensitive)
4. Search by FAA (case-insensitive)
5. Search by custom name (if implemented)

### Primary Key Selection Rules

When adding a new airport, select primary key based on priority:
1. If ICAO exists → use ICAO (uppercase)
2. Else if IATA exists → use IATA (uppercase)
3. Else if FAA exists → use FAA (uppercase, preserve format like "03S")
4. Else → use custom identifier (lowercase, URL-friendly)

### Migration Strategy

**For existing airports:**
- Keep current airport ID as-is (backward compatibility)
- Add `icao`, `iata`, `faa` fields
- Gradually migrate to use ICAO/IATA/FAA as primary key when available
- Custom migration script can help identify airports that should use different primary keys

**Example migration:**
```json
// Before
"kspb": {
  "icao": "KSPB",
  ...
}

// After (preferred)
"KSPB": {
  "icao": "KSPB",
  "faa": "KSPB",
  ...
}
```

### Benefits of This Approach
- ✅ Uses official identifiers when available (better SEO, discoverability)
- ✅ Maintains backward compatibility (existing IDs still work)
- ✅ Supports all airport types (ICAO, IATA, FAA, custom)
- ✅ Clear priority system (ICAO preferred)
- ✅ Flexible for informal airports (custom identifiers)
- ✅ Works for international and US airports
- ✅ Better URL structure (KSPB.aviationwx.org vs kspb.aviationwx.org)

