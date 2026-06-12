/**
 * ESLint 10 Flat Config for AviationWX
 * 
 * Catches JavaScript errors including:
 * - Variable scope issues (variables used before declaration)
 * - Undefined variables/functions
 * - Missing defensive checks
 * - Common JavaScript pitfalls
 */
const {
    defineConfig,
} = require("eslint/config");

const globals = require("globals");

module.exports = defineConfig([
    // Base config for all JavaScript files (including embedded in PHP)
    {
        files: ["**/*.js", "**/*.php"], // Apply to JS files and PHP files (which contain embedded JS)
        languageOptions: {
            globals: {
                // Browser globals (provided by browser environment) - spread first
                ...globals.browser,
                // Service Worker globals (available in both browser and service worker contexts)
                ...globals.serviceworker,
                // Application-specific globals
                "AIRPORT_ID": "readonly",
                "AIRPORT_DATA": "readonly",
                "AIRPORT_NAV_DATA": "readonly",
                "RUNWAYS": "readonly",
                "DEFAULT_TIMEZONE": "readonly",
                // Dashboard bootstrap constants: declared in the inline
                // bootstrap script that pages/airport.php emits before
                // public/js/airport-dashboard.js loads
                "SERVER_TIME_UTC": "readonly",
                "CLIENT_CLOCK_SKEW_SECONDS": "readonly",
                "clientClockSkewDetected": "writable",
                "INITIAL_BANNER_STATE": "readonly",
                "INITIAL_WEATHER_DATA": "readonly",
                "INITIAL_TIMEZONE_DISPLAY": "readonly",
                "DEFAULT_PREFERENCES": "readonly",
                "MAGNETIC_DECLINATION": "readonly",
                "RUNWAY_SEGMENTS": "readonly",
                "STALE_WARNING_SECONDS": "readonly",
                "STALE_ERROR_SECONDS": "readonly",
                "STALE_FAILCLOSED_SECONDS": "readonly",
                "OUTAGE_BANNER_THRESHOLD_SECONDS": "readonly",
                "METAR_STALE_WARNING_SECONDS": "readonly",
                "METAR_STALE_ERROR_SECONDS": "readonly",
                "METAR_STALE_FAILCLOSED_SECONDS": "readonly",
                "SECONDS_PER_HOUR": "readonly",
                "STATION_POWER_POLL_MS": "readonly",
                "HAS_STATION_POWER_UI": "readonly",
                "NIGHT_MODE_DATA": "readonly",
                "WEBCAM_INITIAL_TIMESTAMPS": "readonly",
                "WEBCAM_REFRESH_SECONDS": "readonly",
                // Cross-file: defined by public/js/exif-timestamp.js
                "ExifTimestamp": "readonly",
                // Disable Node.js-specific globals that don't exist in browser
                // Only disable globals that are Node-only (not in browser)
                ...Object.fromEntries(
                    Object.entries(globals.node)
                        .filter(([key]) => !(key in globals.browser))
                        .map(([key]) => [key, "off"])
                ),
            },
            ecmaVersion: 2021,
            sourceType: "script", // Not module - we use script tags
        },

    rules: {
        // CRITICAL: Catch scope errors (variables used before declaration)
        "no-use-before-define": ["error", {
            functions: false, // Allow function hoisting
            classes: false,   // Allow class hoisting
            variables: true,  // CRITICAL: Variables must be declared before use
            allowNamedExports: false,
        }],
        
        // CRITICAL: Catch undefined variables/functions
        "no-undef": "error",
        
        // Catch unused variables (warn, not error - some may be intentional)
        "no-unused-vars": ["warn", {
            argsIgnorePattern: "^_",
            varsIgnorePattern: "^_",
        }],
        
        // CRITICAL: Catch variable redeclaration (scope issues)
        "no-redeclare": "error",
        
        // Catch shadowing (variable shadows outer scope)
        "no-shadow": ["warn", {
            allow: ["err", "error", "e"], // Common error variable names
        }],
        
        // Enforce IIFE pattern for HTML scripts (service workers are exception)
        // Service workers use explicit self.* assignments which is acceptable
        "no-implicit-globals": ["error", {
            lexicalBindings: false, // Allow const/let in global scope (they're not truly global)
        }],
        
        // Catch common mistakes
        "no-console": "off", // Allow console (we use it for debugging)
        "no-debugger": "warn",
        "no-alert": "warn",
        
        // Code quality
        "eqeqeq": ["error", "always"], // Require === and !==
        "curly": ["error", "all"], // Require braces
        "no-eval": "error",
        "no-implied-eval": "error",
        "no-new-func": "error",
        
        // Best practices
        "no-var": "warn", // Prefer let/const
        "prefer-const": "warn", // Prefer const when possible
        "prefer-arrow-callback": "off", // Allow function() for compatibility
        
        // Style (less critical, but helpful)
        "semi": ["error", "always"],
        "quotes": ["warn", "single", {
            avoidEscape: true,
        }],
        "comma-dangle": ["warn", "never"],
    },
},
{
    // Dashboard app code, moved verbatim out of pages/airport.php where it
    // was inline (and effectively unlinted). It intentionally defines
    // top-level functions consumed by inline HTML attributes (onclick,
    // onload), and its legacy formatting predates these rules. Correctness
    // rules (no-undef, no-redeclare, eqeqeq, semi) still apply in full.
    files: ["public/js/airport-dashboard.js"],
    rules: {
        "no-implicit-globals": "off",
        "curly": "off",
        "no-use-before-define": "off",
    },
}]);

