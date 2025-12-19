# Discovered Issues During Test Optimization

## Issue #1: False Positive in JavaScriptStaticAnalysisTest
**File:** `tests/Unit/JavaScriptStaticAnalysisTest.php`  
**Status:** False positive - test needs improvement  
**Description:** Test is detecting `preg_match` in JavaScript code, but it's actually in PHP code that processes JavaScript strings (lines 3599, 3609, 3700, 3705 in `pages/airport.php`). The PHP code uses `preg_match` to extract JavaScript from strings, which is valid PHP code, not JavaScript.  
**Impact:** Test failure - false positive  
**Action Required:** Improve test to better distinguish PHP code that processes JavaScript strings vs actual JavaScript code. This is a test logic issue, not an app issue.

## Issue #2: Unclosed Script Tag in Airport Page
**File:** `pages/airport.php`  
**Status:** CRITICAL - Real app bug discovered  
**Description:** HTML output has 4 opening `<script>` tags but only 3 closing `</script>` tags. The script tag closing logic (lines 3596-3900) attempts to add closing tags but is not working correctly in all cases.  
**Impact:** Invalid HTML, potential JavaScript parsing errors, browser compatibility issues  
**Action Required:** Fix script tag closing logic to ensure all script tags are properly closed. The code has multiple fallback paths to add closing tags, but one path is not working correctly.

---

