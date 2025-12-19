# Discovered Issues During Test Optimization

## Issue #1: False Positive in JavaScriptStaticAnalysisTest
**File:** `tests/Unit/JavaScriptStaticAnalysisTest.php`  
**Status:** Needs investigation  
**Description:** Test is detecting `preg_match` in JavaScript code, but it appears to be in a PHP comment within JavaScript. The context shows: `if (preg_match('/^\s*<script[^>]*>(.*)$/s', $js, $matc`  
**Impact:** Test failure, but may be a false positive  
**Action Required:** Investigate if this is actually PHP code in JavaScript or just a comment

---

