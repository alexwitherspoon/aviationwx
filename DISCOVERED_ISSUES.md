# Discovered Issues During Test Optimization

## Issue #1: False Positive in JavaScriptStaticAnalysisTest
**File:** `tests/Unit/JavaScriptStaticAnalysisTest.php`  
**Status:** False positive - test needs improvement  
**Description:** Test is detecting `preg_match` in JavaScript code, but it's actually in PHP code that processes JavaScript strings (lines 3599, 3609, 3700, 3705 in `pages/airport.php`). The PHP code uses `preg_match` to extract JavaScript from strings, which is valid PHP code, not JavaScript.  
**Impact:** Test failure - false positive  
**Action Required:** Improve test to better distinguish PHP code that processes JavaScript strings vs actual JavaScript code. This is a test logic issue, not an app issue.

---

