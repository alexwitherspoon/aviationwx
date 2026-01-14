/**
 * Unit Conversion Tests - Safety Critical (JavaScript)
 * 
 * CRITICAL: These values MUST match tests/Unit/SafetyCriticalReferenceTest.php
 * Both PHP and JS implementations must produce identical results.
 * 
 * Sources:
 * - BIPM SI Brochure (exact metric definitions)
 * - ICAO Doc 8400 (Abbreviations and Codes)
 * - US Code Title 15 Section 205 (legal definitions)
 * - International Yard and Pound Agreement (1959)
 * - NOAA/NWS conversion tables
 * 
 * Run with: node tests/js/unit-conversion.test.js
 * Or in browser console after loading units.js
 */

// Mock window for Node.js environment
if (typeof window === 'undefined') {
    global.window = global;
}

// Test runner
const tests = [];
let passed = 0;
let failed = 0;

function test(name, fn) {
    tests.push({ name, fn });
}

function assertEqual(actual, expected, message) {
    if (actual !== expected) {
        throw new Error(`${message}: expected ${expected}, got ${actual}`);
    }
}

function assertEqualsWithDelta(actual, expected, delta, message) {
    if (Math.abs(actual - expected) > delta) {
        throw new Error(`${message}: expected ${expected} ± ${delta}, got ${actual} (diff: ${Math.abs(actual - expected)})`);
    }
}

// ============================================================================
// PRESSURE CONVERSION TESTS (hPa ↔ inHg)
// Factor: 1 inHg = 33.8639 hPa (ICAO standard)
// ============================================================================

test('Pressure: 1013.25 hPa = 29.9213 inHg (ISA standard)', () => {
    const hpa = 1013.25;
    const expectedInhg = 29.9213;
    const result = AviationWX.units.hpaToInhg(hpa);
    assertEqualsWithDelta(result, expectedInhg, 0.001, 'hPa to inHg');
});

test('Pressure: 29.92 inHg = 1013.21 hPa (ISA standard)', () => {
    const inhg = 29.92;
    const expectedHpa = 1013.2089;
    const result = AviationWX.units.inhgToHpa(inhg);
    assertEqualsWithDelta(result, expectedHpa, 0.01, 'inHg to hPa');
});

test('Pressure: 950 hPa = 28.0534 inHg (low pressure)', () => {
    const hpa = 950.00;
    const expectedInhg = 28.0534;
    const result = AviationWX.units.hpaToInhg(hpa);
    assertEqualsWithDelta(result, expectedInhg, 0.001, 'Low pressure conversion');
});

test('Pressure: 1030 hPa = 30.4157 inHg (high pressure)', () => {
    const hpa = 1030.00;
    const expectedInhg = 30.4157;
    const result = AviationWX.units.hpaToInhg(hpa);
    assertEqualsWithDelta(result, expectedInhg, 0.001, 'High pressure conversion');
});

test('Pressure: Round-trip conversion preserves value', () => {
    const original = 1013.25;
    const converted = AviationWX.units.inhgToHpa(AviationWX.units.hpaToInhg(original));
    assertEqualsWithDelta(converted, original, 0.01, 'Round-trip preservation');
});

// ============================================================================
// VISIBILITY CONVERSION TESTS (meters ↔ statute miles)
// Factor: 1 statute mile = 1609.344 meters (exact, US Code Title 15 §205)
// ============================================================================

test('Visibility: 10 SM = 16093.44 meters (unrestricted)', () => {
    const miles = 10.0;
    const expectedMeters = 16093.44;
    const result = AviationWX.units.statuteMilesToMeters(miles);
    assertEqualsWithDelta(result, expectedMeters, 0.01, '10 SM conversion');
});

test('Visibility: 1 SM = 1609.344 meters (exact definition)', () => {
    const miles = 1.0;
    const expectedMeters = 1609.344;
    const result = AviationWX.units.statuteMilesToMeters(miles);
    assertEqual(result, expectedMeters, '1 SM exact conversion');
});

test('Visibility: 3 SM = 4828.032 meters (MVFR threshold)', () => {
    const miles = 3.0;
    const expectedMeters = 4828.032;
    const result = AviationWX.units.statuteMilesToMeters(miles);
    assertEqualsWithDelta(result, expectedMeters, 0.01, 'MVFR threshold');
});

test('Visibility: 1609.344 meters = 1 SM (reverse)', () => {
    const meters = 1609.344;
    const expectedMiles = 1.0;
    const result = AviationWX.units.metersToStatuteMiles(meters);
    assertEqualsWithDelta(result, expectedMiles, 0.0001, 'Reverse conversion');
});

test('Visibility: Round-trip conversion preserves value', () => {
    const original = 10.0;
    const converted = AviationWX.units.metersToStatuteMiles(AviationWX.units.statuteMilesToMeters(original));
    assertEqualsWithDelta(converted, original, 0.0001, 'Round-trip preservation');
});

test('Visibility: 10 SM = 16.09344 km (unrestricted metric display)', () => {
    const miles = 10.0;
    const expectedKm = 16.09344;
    const result = AviationWX.units.statuteMilesToKilometers(miles);
    assertEqualsWithDelta(result, expectedKm, 0.0001, '10 SM to km');
});

test('Visibility: 1 SM = 1.609344 km (exact definition)', () => {
    const miles = 1.0;
    const expectedKm = 1.609344;
    const result = AviationWX.units.statuteMilesToKilometers(miles);
    assertEqualsWithDelta(result, expectedKm, 0.0001, '1 SM to km exact');
});

test('Visibility: 3 SM = 4.828032 km (MVFR threshold metric)', () => {
    const miles = 3.0;
    const expectedKm = 4.828032;
    const result = AviationWX.units.statuteMilesToKilometers(miles);
    assertEqualsWithDelta(result, expectedKm, 0.0001, 'MVFR threshold in km');
});

test('Visibility: 1.609344 km = 1 SM (reverse)', () => {
    const km = 1.609344;
    const expectedMiles = 1.0;
    const result = AviationWX.units.kilometersToStatuteMiles(km);
    assertEqualsWithDelta(result, expectedMiles, 0.0001, 'km to SM reverse');
});

test('Visibility: SM ↔ km round-trip preserves value', () => {
    const original = 10.0;
    const converted = AviationWX.units.kilometersToStatuteMiles(AviationWX.units.statuteMilesToKilometers(original));
    assertEqualsWithDelta(converted, original, 0.0001, 'SM-km round-trip');
});

// ============================================================================
// PRECIPITATION CONVERSION TESTS (mm ↔ inches)
// Factor: 1 inch = 25.4 mm (exact, International Yard and Pound Agreement 1959)
// ============================================================================

test('Precipitation: 1 inch = 25.4 mm (exact definition)', () => {
    const inches = 1.0;
    const expectedMm = 25.4;
    const result = AviationWX.units.inchesToMm(inches);
    assertEqual(result, expectedMm, '1 inch exact conversion');
});

test('Precipitation: 0.01 inches = 0.254 mm (trace)', () => {
    const inches = 0.01;
    const expectedMm = 0.254;
    const result = AviationWX.units.inchesToMm(inches);
    assertEqualsWithDelta(result, expectedMm, 0.001, 'Trace precipitation');
});

test('Precipitation: 2 inches = 50.8 mm (heavy rain)', () => {
    const inches = 2.0;
    const expectedMm = 50.8;
    const result = AviationWX.units.inchesToMm(inches);
    assertEqual(result, expectedMm, 'Heavy rain conversion');
});

test('Precipitation: 25.4 mm = 1 inch (reverse)', () => {
    const mm = 25.4;
    const expectedInches = 1.0;
    const result = AviationWX.units.mmToInches(mm);
    assertEqual(result, expectedInches, 'Reverse conversion');
});

test('Precipitation: Round-trip conversion preserves value', () => {
    const original = 1.5;
    const converted = AviationWX.units.mmToInches(AviationWX.units.inchesToMm(original));
    assertEqualsWithDelta(converted, original, 0.0001, 'Round-trip preservation');
});

// ============================================================================
// TEMPERATURE CONVERSION TESTS (Celsius ↔ Fahrenheit)
// Formula: °F = (°C × 9/5) + 32, °C = (°F - 32) × 5/9
// ============================================================================

test('Temperature: 0°C = 32°F (freezing point)', () => {
    const celsius = 0.0;
    const expectedFahrenheit = 32.0;
    const result = AviationWX.units.celsiusToFahrenheit(celsius);
    assertEqual(result, expectedFahrenheit, 'Freezing point');
});

test('Temperature: 100°C = 212°F (boiling point)', () => {
    const celsius = 100.0;
    const expectedFahrenheit = 212.0;
    const result = AviationWX.units.celsiusToFahrenheit(celsius);
    assertEqual(result, expectedFahrenheit, 'Boiling point');
});

test('Temperature: -40°C = -40°F (intersection point)', () => {
    const celsius = -40.0;
    const expectedFahrenheit = -40.0;
    const result = AviationWX.units.celsiusToFahrenheit(celsius);
    assertEqual(result, expectedFahrenheit, 'Intersection point');
});

test('Temperature: 15°C = 59°F (ISA standard)', () => {
    const celsius = 15.0;
    const expectedFahrenheit = 59.0;
    const result = AviationWX.units.celsiusToFahrenheit(celsius);
    assertEqual(result, expectedFahrenheit, 'ISA standard');
});

test('Temperature: 32°F = 0°C (reverse freezing)', () => {
    const fahrenheit = 32.0;
    const expectedCelsius = 0.0;
    const result = AviationWX.units.fahrenheitToCelsius(fahrenheit);
    assertEqual(result, expectedCelsius, 'Reverse freezing');
});

test('Temperature: 212°F = 100°C (reverse boiling)', () => {
    const fahrenheit = 212.0;
    const expectedCelsius = 100.0;
    const result = AviationWX.units.fahrenheitToCelsius(fahrenheit);
    assertEqual(result, expectedCelsius, 'Reverse boiling');
});

test('Temperature: Round-trip conversion preserves value', () => {
    const original = 20.0;
    const converted = AviationWX.units.fahrenheitToCelsius(AviationWX.units.celsiusToFahrenheit(original));
    assertEqualsWithDelta(converted, original, 0.0001, 'Round-trip preservation');
});

// ============================================================================
// WIND SPEED CONVERSION TESTS (knots ↔ mph ↔ km/h)
// Factors: 1 kt = 1.852 km/h (exact), 1 kt = 1.15078 mph
// ============================================================================

test('Wind: 1 knot = 1.852 km/h (exact, nautical mile definition)', () => {
    const knots = 1.0;
    const expectedKmh = 1.852;
    const result = AviationWX.units.knotsToKmh(knots);
    assertEqual(result, expectedKmh, 'Knots to km/h exact');
});

test('Wind: 10 knots = 18.52 km/h', () => {
    const knots = 10.0;
    const expectedKmh = 18.52;
    const result = AviationWX.units.knotsToKmh(knots);
    assertEqual(result, expectedKmh, '10 knots to km/h');
});

test('Wind: 1 knot = 1.15078 mph', () => {
    const knots = 1.0;
    const expectedMph = 1.15078;
    const result = AviationWX.units.knotsToMph(knots);
    assertEqualsWithDelta(result, expectedMph, 0.00001, 'Knots to mph');
});

test('Wind: 50 knots = 57.539 mph', () => {
    const knots = 50.0;
    const expectedMph = 57.539;
    const result = AviationWX.units.knotsToMph(knots);
    assertEqualsWithDelta(result, expectedMph, 0.001, '50 knots to mph');
});

test('Wind: 1.852 km/h = 1 knot (reverse)', () => {
    const kmh = 1.852;
    const expectedKnots = 1.0;
    const result = AviationWX.units.kmhToKnots(kmh);
    assertEqualsWithDelta(result, expectedKnots, 0.0001, 'km/h to knots');
});

test('Wind: 1.15078 mph = 1 knot (reverse)', () => {
    const mph = 1.15078;
    const expectedKnots = 1.0;
    const result = AviationWX.units.mphToKnots(mph);
    assertEqualsWithDelta(result, expectedKnots, 0.0001, 'mph to knots');
});

test('Wind: Round-trip km/h conversion preserves value', () => {
    const original = 25.0;
    const converted = AviationWX.units.kmhToKnots(AviationWX.units.knotsToKmh(original));
    assertEqualsWithDelta(converted, original, 0.0001, 'Round-trip km/h');
});

test('Wind: Round-trip mph conversion preserves value', () => {
    const original = 25.0;
    const converted = AviationWX.units.mphToKnots(AviationWX.units.knotsToMph(original));
    assertEqualsWithDelta(converted, original, 0.0001, 'Round-trip mph');
});

// ============================================================================
// ALTITUDE CONVERSION TESTS (feet ↔ meters)
// Factor: 1 foot = 0.3048 meters (exact, International Yard and Pound Agreement 1959)
// ============================================================================

test('Altitude: 1000 feet = 304.8 meters (exact)', () => {
    const feet = 1000.0;
    const expectedMeters = 304.8;
    const result = AviationWX.units.feetToMeters(feet);
    assertEqual(result, expectedMeters, '1000 feet');
});

test('Altitude: 3000 feet = 914.4 meters (pattern altitude)', () => {
    const feet = 3000.0;
    const expectedMeters = 914.4;
    const result = AviationWX.units.feetToMeters(feet);
    assertEqualsWithDelta(result, expectedMeters, 0.0001, 'Pattern altitude');
});

test('Altitude: 10000 feet = 3048 meters (Class B floor)', () => {
    const feet = 10000.0;
    const expectedMeters = 3048.0;
    const result = AviationWX.units.feetToMeters(feet);
    assertEqual(result, expectedMeters, 'Class B floor');
});

test('Altitude: 18000 feet = 5486.4 meters (Class A floor)', () => {
    const feet = 18000.0;
    const expectedMeters = 5486.4;
    const result = AviationWX.units.feetToMeters(feet);
    assertEqualsWithDelta(result, expectedMeters, 0.0001, 'Class A floor');
});

test('Altitude: 304.8 meters = 1000 feet (reverse)', () => {
    const meters = 304.8;
    const expectedFeet = 1000.0;
    const result = AviationWX.units.metersToFeet(meters);
    assertEqual(result, expectedFeet, 'Reverse 1000 feet');
});

test('Altitude: Round-trip conversion preserves value', () => {
    const original = 5000.0;
    const converted = AviationWX.units.metersToFeet(AviationWX.units.feetToMeters(original));
    assertEqualsWithDelta(converted, original, 0.0001, 'Round-trip preservation');
});

// ============================================================================
// TEST RUNNER
// ============================================================================

async function runTests() {
    console.log('Running Unit Conversion Tests (Safety Critical)...\n');
    console.log('These values MUST match PHP SafetyCriticalReferenceTest.php\n');
    
    for (const t of tests) {
        try {
            await t.fn();
            passed++;
            console.log(`✓ ${t.name}`);
        } catch (e) {
            failed++;
            console.log(`✗ ${t.name}`);
            console.log(`  Error: ${e.message}`);
        }
    }
    
    console.log(`\n${'='.repeat(60)}`);
    console.log(`Results: ${passed} passed, ${failed} failed`);
    console.log(`${'='.repeat(60)}`);
    
    if (typeof process !== 'undefined') {
        process.exit(failed > 0 ? 1 : 0);
    }
}

// Auto-run if AviationWX.units is available
if (typeof AviationWX !== 'undefined' && AviationWX.units) {
    runTests();
} else {
    console.log('AviationWX.units not loaded. Load public/js/units.js first.');
    console.log('');
    console.log('For Node.js testing, run:');
    console.log('  node -e "require(\'./public/js/units.js\'); require(\'./tests/js/unit-conversion.test.js\');"');
}
