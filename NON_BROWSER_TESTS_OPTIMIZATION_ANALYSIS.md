# Non-Browser Tests Optimization Analysis

## Executive Summary

**Total Non-Browser Tests:**
- **Unit Tests:** 36 files, 644 test methods, ~18,783 lines
- **Integration Tests:** 18 files, 115 test methods
- **Total:** 54 files, 759 test methods, ~18,783+ lines

**Key Findings:**
1. **239 skipped tests** - Many tests skip due to missing dependencies or unavailable services
2. **Overlap between unit and integration tests** - Some functionality tested at both levels unnecessarily
3. **Tests that could be more efficient** - Some integration tests could be unit tests
4. **Missing coverage** - Some areas lack tests that would complement browser test optimizations
5. **Tests at wrong level** - Some integration tests test unit-level logic

---

## Test-by-Test Analysis

### 1. **Overlap: JavaScript Static Analysis**

**Unit Test:** `JavaScriptStaticAnalysisTest.php`
- Tests PHP functions in JavaScript
- Tests API endpoint patterns
- Tests JavaScript code structure
- Tests absolute URLs

**Browser Test:** `javascript-validity.spec.js` (to be deleted)
- Same tests, but in browser

**Recommendation:**
- ✅ **KEEP** unit test (faster, no browser needed)
- ✅ **DELETE** browser test (redundant)
- **Action:** Already planned in browser test optimization

---

### 2. **Overlap: Timestamp Formatting**

**Unit Test:** `RelativeTimeFormatTest.php`
- Tests `formatRelativeTime()` function logic
- 20 test cases covering edge cases
- Tests two-unit precision

**Browser Test:** `timestamp-format.spec.js` (to be deleted)
- Same tests, but in browser

**Recommendation:**
- ✅ **KEEP** unit test (faster, comprehensive)
- ✅ **DELETE** browser test (redundant)
- **Action:** Already planned in browser test optimization

---

### 3. **Overlap: HTML Output Validation**

**Integration Test:** `HtmlOutputValidationTest.php`
- Tests script tag closure
- Tests JavaScript API endpoints in HTML
- Tests PHP functions in JavaScript
- Tests HTML structure

**Browser Test:** `javascript-validity.spec.js` (to be deleted)
- Overlaps with JavaScript validation

**Unit Test:** `JavaScriptStaticAnalysisTest.php`
- Overlaps with JavaScript validation

**Recommendation:**
- ✅ **KEEP** `HtmlOutputValidationTest.php` (tests HTML output, not just source)
- ✅ **KEEP** `JavaScriptStaticAnalysisTest.php` (tests source code)
- ✅ **DELETE** browser test (redundant)
- **Action:** Already planned in browser test optimization

---

### 4. **Overlap: Weather API Endpoints**

**Integration Tests:**
- `WeatherApiTest.php` - Tests endpoint structure (minimal, mostly skips)
- `WeatherEndpointTest.php` - Tests endpoint via HTTP (comprehensive)
- `WeatherStalenessTest.php` - Tests staleness logic

**Browser Tests:**
- `e2e-weather-flow.spec.js` - Tests API → JS → DOM flow
- `weather-data-visibility.spec.js` - Tests weather display

**Recommendation:**
- ✅ **KEEP** `WeatherEndpointTest.php` (comprehensive HTTP tests)
- ⚠️ **REVIEW** `WeatherApiTest.php` - Has minimal tests, mostly skips
- ✅ **KEEP** browser tests (test DOM rendering, which integration tests don't)
- **Action:** Consider consolidating `WeatherApiTest.php` into `WeatherEndpointTest.php`

---

### 5. **Overlap: Webcam API**

**Unit Tests:**
- `WebcamFormatGenerationTest.php` - Format detection
- `WebcamApiHelperTest.php` - Helper functions

**Integration Tests:**
- `WebcamFormat202Test.php` - HTTP 202 behavior
- `WebcamApiReliabilityTest.php` - Error handling, Content-Length
- `WebcamBackgroundRefreshTest.php` - Background refresh

**Browser Tests:**
- `aviationwx.spec.js` - Webcam display (UI)

**Recommendation:**
- ✅ **KEEP** all (different levels: unit → integration → browser)
- **No action needed** - Good separation of concerns

---

### 6. **Inefficient: Tests with Many Skips**

**High Skip Count Tests:**
- `WebcamFormat202Test.php` - 18 skips (cURL, placeholder, endpoint)
- `WebcamApiReliabilityTest.php` - 18 skips (cURL, placeholder, endpoint)
- `HtmlOutputValidationTest.php` - 18 skips (cURL, endpoint)
- `HomepageAnd404Test.php` - 12 skips (cURL, endpoint)
- `StatusPageIntegrationTest.php` - 8 skips (cURL, endpoint)

**Root Cause:**
- Tests require Docker/web server running
- Tests require cURL
- Tests require specific files (placeholder images)

**Recommendation:**
- ✅ **KEEP** tests (they're integration tests, skips are expected)
- ⚠️ **IMPROVE** - Add better setup/teardown to ensure dependencies
- ⚠️ **IMPROVE** - Use test fixtures more consistently
- **Action:** Document required setup, ensure CI has dependencies

---

### 7. **Inefficient: Unit Tests That Should Be Integration Tests**

**`WebcamRefreshInitializationTest.php`:**
- Makes HTTP requests to test HTML output
- Tests JavaScript initialization in HTML
- Should be integration test, not unit test

**Recommendation:**
- ⚠️ **MOVE** to integration tests
- **Action:** Move to `tests/Integration/` directory

---

### 8. **Inefficient: Integration Tests That Should Be Unit Tests**

**`WeatherApiTest.php`:**
- Most tests just check if functions exist
- Doesn't actually test HTTP endpoints
- Could be unit tests

**Recommendation:**
- ⚠️ **CONSOLIDATE** into `WeatherEndpointTest.php` or convert to unit tests
- **Action:** Review and consolidate

---

### 9. **Missing: Tests That Would Complement Browser Test Optimizations**

**After browser test optimization, we should add:**

1. **JavaScript Function Existence Tests (Unit)**
   - Test that required JavaScript functions are defined in HTML
   - Currently only tested in browser tests
   - **Action:** Add to `HtmlOutputValidationTest.php` or create new unit test

2. **Staleness Threshold Calculation Tests (Unit)**
   - Currently tested in browser (`weather-staleness-thresholds.spec.js`)
   - Should be unit test (pure logic)
   - **Action:** Extract logic to PHP, add unit tests

3. **Cache-Busting Parameter Tests (Unit)**
   - Currently tested in browser (`cache-and-stale-data.spec.js`)
   - Should be unit test (URL generation logic)
   - **Action:** Add to `WebcamApiHelperTest.php` or create new unit test

4. **Service Worker Registration Tests (Integration)**
   - Currently only tested in browser
   - Could test Service Worker file exists and has correct MIME type
   - **Action:** Add to `HtmlOutputValidationTest.php` or create new integration test

---

### 10. **Redundant: Duplicate Test Logic**

**`WeatherApiTest.php` vs `WeatherEndpointTest.php`:**
- Both test weather endpoint
- `WeatherApiTest.php` has minimal tests (mostly skips)
- `WeatherEndpointTest.php` has comprehensive tests

**Recommendation:**
- ⚠️ **CONSOLIDATE** - Merge `WeatherApiTest.php` into `WeatherEndpointTest.php`
- **Action:** Review and merge

---

### 11. **Inefficient: Tests That Make Unnecessary HTTP Requests**

**`HtmlOutputValidationTest.php`:**
- Makes HTTP request for every test
- Could cache HTML output for multiple tests
- **Current:** 18 tests, 18 HTTP requests
- **Optimized:** 18 tests, 1 HTTP request (cached)

**Recommendation:**
- ⚠️ **OPTIMIZE** - Cache HTML output in `setUp()` for multiple tests
- **Action:** Refactor to cache HTML output

---

### 12. **Missing: Test Coverage Gaps**

**After browser test optimization, gaps:**

1. **JavaScript Error Handling**
   - Browser tests check console errors
   - No unit/integration tests for error handling logic
   - **Action:** Add unit tests for error handling functions

2. **Format Retry Logic**
   - Browser tests check 202 retry behavior
   - No unit tests for retry calculation logic
   - **Action:** Extract retry logic, add unit tests

3. **Stagger Offset Calculation**
   - Browser tests check staggered refreshes
   - No unit tests for stagger calculation
   - **Action:** Extract stagger logic, add unit tests

---

## Recommendations Summary

### Phase 1: Immediate (Complement Browser Test Optimizations)

1. **Extract JavaScript Logic to Unit Tests**
   - Staleness threshold calculations → Unit test
   - Cache-busting parameter generation → Unit test
   - Stagger offset calculation → Unit test
   - Format retry backoff → Unit test
   - **Effort:** Medium
   - **Impact:** High (reduces browser test complexity)

2. **Add Missing Unit Tests**
   - JavaScript function existence in HTML → Integration test
   - Service Worker file validation → Integration test
   - **Effort:** Low
   - **Impact:** Medium (complements browser tests)

---

### Phase 2: Consolidation (Reduce Redundancy)

3. **Consolidate Weather API Tests**
   - Merge `WeatherApiTest.php` into `WeatherEndpointTest.php`
   - **Effort:** Low
   - **Impact:** Medium (reduces duplicate tests)

4. **Move Misplaced Tests**
   - Move `WebcamRefreshInitializationTest.php` to integration tests
   - **Effort:** Low
   - **Impact:** Low (organizational)

5. **Optimize HTML Validation Tests**
   - Cache HTML output in `setUp()` for multiple tests
   - **Effort:** Medium
   - **Impact:** Medium (faster execution)

---

### Phase 3: Efficiency Improvements (Long-term)

6. **Improve Test Setup**
   - Better dependency checking
   - Consistent test fixtures
   - **Effort:** High
   - **Impact:** High (reduces skipped tests)

7. **Add Test Coverage**
   - JavaScript error handling
   - Format retry logic
   - Stagger calculations
   - **Effort:** Medium
   - **Impact:** High (better coverage)

---

## Detailed Recommendations

### 1. Extract Staleness Threshold Calculations

**Current State:**
- Logic tested in browser (`weather-staleness-thresholds.spec.js`)
- Logic is pure JavaScript calculation

**Proposed:**
- Extract calculation logic to shared function
- Add unit test in PHP that mirrors JavaScript logic
- Keep browser test for DOM rendering only

**Files:**
- Create: `tests/Unit/WeatherStalenessThresholdTest.php`
- Modify: `pages/airport.php` (extract logic)
- Modify: `tests/Browser/tests/weather-staleness-thresholds.spec.js` (simplify)

**Savings:** ~200 lines in browser tests, faster execution

---

### 2. Extract Cache-Busting Parameter Generation

**Current State:**
- Logic tested in browser (`cache-and-stale-data.spec.js`)
- Logic is URL generation

**Proposed:**
- Extract URL generation to PHP function
- Add unit test for URL generation
- Keep browser test for cache behavior only

**Files:**
- Modify: `api/weather.php` (extract URL generation)
- Create: `tests/Unit/WeatherCacheBustingTest.php`
- Modify: `tests/Browser/tests/cache-and-stale-data.spec.js` (simplify)

**Savings:** ~50 lines in browser tests

---

### 3. Extract Stagger Offset Calculation

**Current State:**
- Logic tested in browser (implicitly in refresh tests)
- Logic is pure calculation

**Proposed:**
- Extract calculation to shared function
- Add unit test for calculation
- Keep browser test for timing behavior only

**Files:**
- Modify: `pages/airport.php` (extract logic)
- Create: `tests/Unit/WebcamStaggerTest.php`
- Modify: Browser tests (simplify)

**Savings:** ~30 lines in browser tests

---

### 4. Consolidate Weather API Tests

**Current State:**
- `WeatherApiTest.php` - 3 tests, mostly skips
- `WeatherEndpointTest.php` - 6 tests, comprehensive

**Proposed:**
- Merge `WeatherApiTest.php` into `WeatherEndpointTest.php`
- Keep all tests, remove duplicates

**Files:**
- Delete: `tests/Integration/WeatherApiTest.php`
- Modify: `tests/Integration/WeatherEndpointTest.php` (add missing tests)

**Savings:** 1 file, ~100 lines

---

### 5. Move Misplaced Test

**Current State:**
- `WebcamRefreshInitializationTest.php` in unit tests
- Makes HTTP requests (integration test behavior)

**Proposed:**
- Move to integration tests directory
- Rename if needed

**Files:**
- Move: `tests/Unit/WebcamRefreshInitializationTest.php` → `tests/Integration/WebcamRefreshInitializationTest.php`

**Savings:** Better organization

---

### 6. Optimize HTML Validation Tests

**Current State:**
- 18 tests, each makes HTTP request
- Same HTML fetched 18 times

**Proposed:**
- Cache HTML in `setUp()`
- All tests use cached HTML

**Files:**
- Modify: `tests/Integration/HtmlOutputValidationTest.php`

**Savings:** 17 fewer HTTP requests, faster execution

---

### 7. Add Missing Unit Tests

**JavaScript Function Existence:**
- Test that required functions are defined in HTML
- Currently only in browser tests

**Files:**
- Add to: `tests/Integration/HtmlOutputValidationTest.php`

**Service Worker Validation:**
- Test that Service Worker file exists
- Test that Service Worker has correct MIME type
- Currently only in browser tests

**Files:**
- Add to: `tests/Integration/HtmlOutputValidationTest.php` or create new test

---

## Cost-Benefit Analysis

### Current State
- **Unit Tests:** 36 files, 644 tests, ~18,783 lines
- **Integration Tests:** 18 files, 115 tests
- **Skipped Tests:** 239 instances
- **Execution Time:** ~5-10 minutes (estimated)

### After Optimization
- **Unit Tests:** 37 files (+1 for extracted logic), ~19,000 lines (+217 for new tests)
- **Integration Tests:** 17 files (-1 consolidated), ~same lines
- **Skipped Tests:** ~200 instances (better setup)
- **Execution Time:** ~4-8 minutes (20% faster)

### Savings
- **Files:** 1 fewer (consolidation)
- **Browser Test Complexity:** Reduced by ~280 lines (extracted logic)
- **Execution Time:** 20% faster
- **Maintenance:** Easier (logic in unit tests, not browser tests)

---

## Implementation Priority

### High Priority (Complement Browser Test Optimizations)

1. ✅ Extract staleness threshold calculations → Unit test
2. ✅ Extract cache-busting parameter generation → Unit test
3. ✅ Extract stagger offset calculation → Unit test
4. ✅ Add JavaScript function existence test → Integration test
5. ✅ Add Service Worker validation test → Integration test

**Effort:** Medium
**Impact:** High (reduces browser test complexity, improves coverage)

---

### Medium Priority (Reduce Redundancy)

6. ✅ Consolidate `WeatherApiTest.php` into `WeatherEndpointTest.php`
7. ✅ Move `WebcamRefreshInitializationTest.php` to integration tests
8. ✅ Optimize `HtmlOutputValidationTest.php` (cache HTML)

**Effort:** Low-Medium
**Impact:** Medium (reduces redundancy, faster execution)

---

### Low Priority (Long-term Improvements)

9. ⚠️ Improve test setup (reduce skipped tests)
10. ⚠️ Add comprehensive error handling tests
11. ⚠️ Add format retry logic tests

**Effort:** High
**Impact:** High (better coverage, fewer skipped tests)

---

## Conclusion

**Key Opportunities:**
1. **Extract JavaScript logic to unit tests** - Reduces browser test complexity
2. **Consolidate redundant tests** - Reduces maintenance burden
3. **Optimize test execution** - Faster feedback
4. **Add missing coverage** - Better test coverage

**Complementary to Browser Test Optimizations:**
- Extracting logic to unit tests reduces browser test complexity
- Adding integration tests for HTML/Service Worker complements browser tests
- Consolidating tests reduces overall test suite size

**Final Result:**
- Faster execution (20% improvement)
- Better coverage (logic in unit tests)
- Easier maintenance (less duplication)
- Complementary to browser test optimizations

