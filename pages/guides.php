<?php
/**
 * Guides Page
 * Renders markdown guides from /guides directory
 */

// Load dependencies
// Note: config.php is already loaded by index.php, so we don't need to load it again
// require_once __DIR__ . '/../lib/config.php'; // Already loaded by index.php
require_once __DIR__ . '/../lib/seo.php';

// Load Parsedown
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    error_log('Composer autoloader not found. Please run "composer install"');
    http_response_code(500);
    die('Configuration error. Please contact the administrator.');
}

// Get request path
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$parsedUri = parse_url($requestUri);
$requestPath = isset($parsedUri['path']) ? trim($parsedUri['path'], '/') : '';

// Determine if this is index or a specific guide
$isIndex = empty($requestPath);
$guideName = $isIndex ? null : $requestPath;

// Guides directory
$guidesDir = __DIR__ . '/../guides';

// Get all markdown files for sidebar
$allGuides = [];
if (is_dir($guidesDir)) {
    $files = scandir($guidesDir);
    foreach ($files as $file) {
        if (preg_match('/^(\d+)-(.+)\.md$/i', $file, $matches)) {
            $guideSlug = preg_replace('/\.md$/i', '', $file);
            $allGuides[] = [
                'file' => $file,
                'slug' => $guideSlug,
                'number' => intval($matches[1])
            ];
        }
    }
    // Sort by number
    usort($allGuides, function($a, $b) {
        return $a['number'] <=> $b['number'];
    });
}

// Determine which file to load
$markdownFile = null;
$pageTitle = 'Guides - AviationWX.org';
$pageDescription = 'Documentation and guides for AviationWX.org';

if ($isIndex) {
    // Index page - use README.md or readme.md
    $readmeFiles = ['README.md', 'readme.md'];
    foreach ($readmeFiles as $readme) {
        $readmePath = $guidesDir . '/' . $readme;
        if (file_exists($readmePath)) {
            $markdownFile = $readmePath;
            break;
        }
    }
    if (!$markdownFile) {
        http_response_code(404);
        include 'error-404-guides.php';
        exit;
    }
} else {
    // Individual guide - look for matching file
    // Strip .md extension if already present to handle both URL formats
    $guideSlug = preg_replace('/\.md$/i', '', $guideName);
    $guideFile = $guidesDir . '/' . $guideSlug . '.md';
    
    if (file_exists($guideFile) && is_file($guideFile)) {
        $markdownFile = $guideFile;
    } else {
        // Guide not found
        http_response_code(404);
        include 'error-404-guides.php';
        exit;
    }
}

// Read and parse markdown
$markdownContent = file_get_contents($markdownFile);
if ($markdownContent === false) {
    error_log('Failed to read markdown file: ' . $markdownFile);
    http_response_code(500);
    die('Error loading guide. Please contact the administrator.');
}

// Get file modification time for cache headers
$fileMtime = filemtime($markdownFile);
$fileAge = time() - $fileMtime;

// Extract title from first H1
$guideDisplayTitle = null;
$titleMatch = [];
if (preg_match('/^#\s+(.+)$/m', $markdownContent, $titleMatch)) {
    $guideDisplayTitle = trim($titleMatch[1]);
    $pageTitle = htmlspecialchars($guideDisplayTitle) . ' - Guides - AviationWX.org';
    // Use automated extraction for meta description
    $pageDescription = extractMetaDescriptionFromMarkdown(
        $markdownContent,
        'AviationWX guide: ' . htmlspecialchars($guideDisplayTitle) . '. Step-by-step documentation for airport weather installations.'
    );
} else {
    // Fallback for index page or if no H1 found
    $pageDescription = extractMetaDescriptionFromMarkdown(
        $markdownContent,
        'Documentation and guides for AviationWX.org airport weather installations.'
    );
}

// Parse markdown
$parsedown = new Parsedown();
$htmlContent = $parsedown->text($markdownContent);

// Set cache headers for CDN
// Guides are documentation that doesn't change frequently, but we want reasonable cache times
// Cache for 1 hour, allow stale-while-revalidate for 4 hours
// This balances freshness with performance
$cacheMaxAge = 3600; // 1 hour
$staleWhileRevalidate = 14400; // 4 hours
$cdnMaxAge = 3600; // 1 hour for CDN

// If file was recently modified (within last hour), use shorter cache
if ($fileAge < 3600) {
    $cacheMaxAge = 300; // 5 minutes for recently updated files
    $cdnMaxAge = 300;
}

header('Cache-Control: public, max-age=' . $cacheMaxAge . ', s-maxage=' . $cdnMaxAge . ', stale-while-revalidate=' . $staleWhileRevalidate);
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $cacheMaxAge) . ' GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $fileMtime) . ' GMT');
header('ETag: "' . md5($markdownFile . $fileMtime) . '"');

// Get base URL
$baseUrl = getBaseUrl();
$canonicalUrl = getCanonicalUrl();

// SEO variables
$ogImage = $baseUrl . '/public/favicons/android-chrome-192x192.png';
?>
<!DOCTYPE html>
<html lang="en">
<script>
// Apply dark mode immediately based on browser preference to prevent flash
(function() {
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.classList.add('dark-mode');
    }
})();
</script>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    
    <?php
    // Favicon and icon tags
    echo generateFaviconTags();
    echo "\n    ";
    
    // Enhanced meta tags
    echo generateEnhancedMetaTags($pageDescription, 'guides, documentation, aviation weather');
    echo "\n    ";
    
    // Canonical URL
    echo generateCanonicalTag($canonicalUrl);
    echo "\n    ";
    
    // Open Graph and Twitter Card tags
    echo generateSocialMetaTags($pageTitle, $pageDescription, $canonicalUrl, $ogImage);
    echo "\n    ";
    
    // Breadcrumb structured data
    echo generateStructuredDataScript(generateGuideBreadcrumbs($guideDisplayTitle));
    ?>
    
    <link rel="stylesheet" href="public/css/styles.css">
    <style>
        /* Smooth scrolling for anchor links */
        html {
            scroll-behavior: smooth;
        }
        
        /* Ensure proper anchor positioning */
        section[id], h1[id], h2[id], h3[id], h4[id], h5[id], h6[id] {
            scroll-margin-top: 2rem;
        }
        
        /* Container needs min-width to match content so everything scrolls together */
        .container {
            min-width: 750px; /* Match content min-width so header/footer scale with content */
        }
        
        .hero {
            background: linear-gradient(135deg, #1a1a1a 0%, #0066cc 100%);
            color: white;
            padding: 4rem 2rem;
            text-align: center;
            margin: -1rem -1rem 3rem -1rem;
            box-sizing: border-box;
        }
        .hero h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .hero p {
            font-size: 1.2rem;
            opacity: 0.95;
            max-width: 700px;
            margin: 0 auto 1rem;
        }
        
        /* Layout for guides with sidebar */
        .guides-layout {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 2rem;
            margin-top: 2rem;
        }
        
        @media (max-width: 768px) {
            .guides-layout {
                grid-template-columns: 1fr;
            }
            .guides-sidebar {
                order: 2;
            }
        }
        
        /* Sidebar */
        .guides-sidebar {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            height: fit-content;
            position: sticky;
            top: 2rem;
        }
        
        .guides-sidebar h3 {
            margin-top: 0;
            color: #0066cc;
            font-size: 1.1rem;
            margin-bottom: 1rem;
        }
        
        .guides-sidebar ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .guides-sidebar li {
            margin-bottom: 0.5rem;
        }
        
        .guides-sidebar a {
            color: #333;
            text-decoration: none;
            display: block;
            padding: 0.5rem 0.75rem;
            border-radius: 4px;
            transition: background 0.2s;
        }
        
        .guides-sidebar a:hover {
            background: #e9ecef;
            color: #0066cc;
        }
        
        .guides-sidebar a.active {
            background: #0066cc;
            color: white;
        }
        
        .guides-sidebar .back-link {
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #ddd;
        }
        
        .guides-sidebar .back-link a {
            color: #0066cc;
            font-weight: 600;
        }
        
        .guides-sidebar .back-link a:hover {
            background: #e9ecef;
        }
        
        /* Markdown content */
        .guides-content {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            min-width: 750px; /* Accommodate wide ASCII diagrams; mobile users can pinch-zoom */
        }
        
        /* Markdown styling */
        .guides-content h1 {
            font-size: 2.5rem;
            color: #333;
            margin-top: 0;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #0066cc;
        }
        
        .guides-content h2 {
            font-size: 2rem;
            color: #0066cc;
            margin-top: 2.5rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e9ecef;
        }
        
        .guides-content h3 {
            font-size: 1.5rem;
            color: #333;
            margin-top: 2rem;
            margin-bottom: 0.75rem;
        }
        
        .guides-content h4 {
            font-size: 1.25rem;
            color: #555;
            margin-top: 1.5rem;
            margin-bottom: 0.5rem;
        }
        
        .guides-content p {
            line-height: 1.8;
            margin-bottom: 1rem;
            color: #333;
        }
        
        /* GitHub-style list rendering - override CSS reset */
        .guides-content ul,
        .guides-content ol {
            margin: 0 0 16px 0 !important;
            padding-left: 2em !important;
            list-style-position: outside !important;
        }
        
        .guides-content ul {
            list-style-type: disc !important;
        }
        
        .guides-content ol {
            list-style-type: decimal !important;
        }
        
        .guides-content li {
            line-height: 1.6;
            margin-top: 0.25em !important;
            margin-bottom: 0 !important;
            padding-left: 0.5em;
            word-wrap: break-word;
        }
        
        .guides-content li:first-child {
            margin-top: 0 !important;
        }
        
        /* Nested lists */
        .guides-content ul ul,
        .guides-content ol ul {
            list-style-type: circle !important;
            margin-top: 0 !important;
            margin-bottom: 0 !important;
        }
        
        .guides-content ul ul ul,
        .guides-content ol ul ul {
            list-style-type: square !important;
        }
        
        .guides-content ol ol,
        .guides-content ul ol {
            list-style-type: lower-roman !important;
            margin-top: 0 !important;
            margin-bottom: 0 !important;
        }
        
        .guides-content code {
            background: #f4f4f4;
            padding: 0.2rem 0.4rem;
            border-radius: 3px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.9em;
            color: #d63384;
        }
        
        .guides-content pre {
            background: #1a1a1a;
            color: #f8f9fa;
            padding: 1rem;
            border-radius: 6px;
            overflow-x: auto;
            margin-bottom: 1.5rem;
        }
        
        .guides-content pre code {
            background: transparent;
            color: inherit;
            padding: 0;
            font-size: 0.9rem;
        }
        
        .guides-content blockquote {
            border-left: 4px solid #0066cc;
            padding-left: 1rem;
            margin-left: 0;
            margin-bottom: 1rem;
            color: #555;
            font-style: italic;
        }
        
        .guides-content table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5rem;
        }
        
        .guides-content table th,
        .guides-content table td {
            padding: 0.75rem;
            border: 1px solid #ddd;
            text-align: left;
        }
        
        .guides-content table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        .guides-content table tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .guides-content a {
            color: #0066cc;
            text-decoration: none;
        }
        
        .guides-content a:hover {
            text-decoration: underline;
        }
        
        .guides-content img {
            max-width: 100%;
            height: auto;
            border-radius: 6px;
            margin: 1.5rem 0;
        }
        
        .guides-content hr {
            border: none;
            border-top: 2px solid #e9ecef;
            margin: 2rem 0;
        }
        
        /* Index page specific */
        .guides-index {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            min-width: 750px; /* Accommodate wide ASCII diagrams; mobile users can pinch-zoom */
        }
        
        /* Apply same markdown styling to index page */
        .guides-index h1,
        .guides-index h2,
        .guides-index h3,
        .guides-index h4,
        .guides-index h5,
        .guides-index h6,
        .guides-index p,
        .guides-index ul,
        .guides-index ol,
        .guides-index li,
        .guides-index a,
        .guides-index code,
        .guides-index pre,
        .guides-index blockquote,
        .guides-index table,
        .guides-index hr {
            /* Inherit from guides-content styles */
        }
        
        /* GitHub-style list rendering - override CSS reset */
        .guides-index ul,
        .guides-index ol {
            margin: 0 0 16px 0 !important;
            padding-left: 2em !important;
            list-style-position: outside !important;
        }
        
        .guides-index ul {
            list-style-type: disc !important;
        }
        
        .guides-index ol {
            list-style-type: decimal !important;
        }
        
        .guides-index li {
            line-height: 1.6;
            margin-top: 0.25em !important;
            margin-bottom: 0 !important;
            padding-left: 0.5em;
        }
        
        .guides-index li:first-child {
            margin-top: 0 !important;
        }
        
        /* Nested lists */
        .guides-index ul ul,
        .guides-index ol ul {
            list-style-type: circle !important;
            margin-top: 0 !important;
            margin-bottom: 0 !important;
        }
        
        .guides-index ul ul ul {
            list-style-type: square !important;
        }
        
        .guides-index h1 {
            font-size: 2rem;
            color: #24292f;
            margin-top: 0;
            margin-bottom: 16px;
            padding-bottom: 0.3em;
            border-bottom: 1px solid #d0d7de;
            font-weight: 600;
        }
        
        .guides-index h2 {
            font-size: 1.5rem;
            color: #24292f;
            margin-top: 24px;
            margin-bottom: 16px;
            padding-bottom: 0.3em;
            border-bottom: 1px solid #d0d7de;
            font-weight: 600;
        }
        
        .guides-index h3 {
            font-size: 1.25rem;
            color: #24292f;
            margin-top: 24px;
            margin-bottom: 16px;
            font-weight: 600;
        }
        
        .guides-index p {
            line-height: 1.6;
            margin-bottom: 16px;
            color: #24292f;
        }
        
        .guides-index a {
            color: #0969da;
            text-decoration: none;
        }
        
        .guides-index a:hover {
            text-decoration: underline;
        }
        
        .guides-index strong {
            font-weight: 600;
            color: #24292f;
        }
        
        .guides-index hr {
            height: 0.25em;
            padding: 0;
            margin: 24px 0;
            background-color: #d0d7de;
            border: 0;
        }
        
        .guides-index code {
            padding: 0.2em 0.4em;
            margin: 0;
            font-size: 85%;
            background-color: rgba(175,184,193,0.2);
            border-radius: 6px;
            font-family: ui-monospace, SFMono-Regular, SF Mono, Menlo, Consolas, Liberation Mono, monospace;
        }
        
        footer {
            margin-top: 4rem;
            padding-top: 2rem;
            border-top: 1px solid #ddd;
            text-align: center;
            color: #555;
            font-size: 0.9rem;
        }
        
        footer a {
            color: #0066cc;
            text-decoration: none;
        }
        
        footer a:hover {
            text-decoration: underline;
        }
        
        /* ============================================
           Dark Mode Overrides for Guides
           Automatically applied based on browser preference
           ============================================ */
        @media (prefers-color-scheme: dark) {
            body {
                background: #121212;
                color: #e0e0e0;
            }
        }
        
        body.dark-mode {
            background: #121212;
            color: #e0e0e0;
        }
        
        body.dark-mode .hero {
            background: linear-gradient(135deg, #0a0a0a 0%, #003d7a 100%);
        }
        
        body.dark-mode .guides-sidebar {
            background: #1e1e1e;
        }
        
        body.dark-mode .guides-sidebar h3 {
            color: #4a9eff;
        }
        
        body.dark-mode .guides-sidebar a {
            color: #e0e0e0;
        }
        
        body.dark-mode .guides-sidebar a:hover {
            background: #252525;
            color: #4a9eff;
        }
        
        body.dark-mode .guides-sidebar a.active {
            background: #4a9eff;
            color: white;
        }
        
        body.dark-mode .guides-sidebar .back-link {
            border-bottom-color: #333;
        }
        
        body.dark-mode .guides-sidebar .back-link a {
            color: #4a9eff;
        }
        
        body.dark-mode .guides-content,
        body.dark-mode .guides-index {
            background: #1e1e1e;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        body.dark-mode .guides-content h1,
        body.dark-mode .guides-index h1 {
            color: #e0e0e0;
            border-bottom-color: #4a9eff;
        }
        
        body.dark-mode .guides-content h2,
        body.dark-mode .guides-index h2 {
            color: #4a9eff;
            border-bottom-color: #333;
        }
        
        body.dark-mode .guides-content h3,
        body.dark-mode .guides-content h4,
        body.dark-mode .guides-index h3 {
            color: #e0e0e0;
        }
        
        body.dark-mode .guides-content p,
        body.dark-mode .guides-index p {
            color: #a0a0a0;
        }
        
        body.dark-mode .guides-content a,
        body.dark-mode .guides-index a {
            color: #4a9eff;
        }
        
        body.dark-mode .guides-content code,
        body.dark-mode .guides-index code {
            background: transparent;
            color: #ffffff;
        }
        
        body.dark-mode .guides-content pre {
            background: transparent;
            border: 1px solid #333;
        }
        
        body.dark-mode .guides-content pre code {
            color: #ffffff;
        }
        
        body.dark-mode .guides-content blockquote {
            border-left-color: #4a9eff;
            color: #a0a0a0;
        }
        
        body.dark-mode .guides-content table th {
            background: #252525;
            color: #e0e0e0;
        }
        
        body.dark-mode .guides-content table td {
            border-color: #333;
        }
        
        body.dark-mode .guides-content table tr:nth-child(even) {
            background: #1a1a1a;
        }
        
        body.dark-mode .guides-content hr,
        body.dark-mode .guides-index hr {
            border-top-color: #333;
        }
        
        body.dark-mode .guides-content li,
        body.dark-mode .guides-index li {
            color: #a0a0a0;
        }
        
        body.dark-mode .guides-index strong {
            color: #e0e0e0;
        }
        
        body.dark-mode footer {
            border-top-color: #333;
            color: #a0a0a0;
        }
        
        body.dark-mode footer a {
            color: #4a9eff;
        }
    </style>
</head>
<body>
    <script>
    // Sync dark-mode class from html to body
    if (document.documentElement.classList.contains('dark-mode')) {
        document.body.classList.add('dark-mode');
    }
    </script>
    <main>
    <div class="container">
        <div class="hero">
            <h1><img src="<?= $baseUrl ?>/public/favicons/android-chrome-192x192.png" alt="AviationWX" style="vertical-align: middle; margin-right: 0.5rem; width: 76px; height: 76px; background: transparent;"> AviationWX Guides</h1>
            <p style="font-size: 1.2rem; opacity: 0.95;">
                Documentation and guides for using and contributing to AviationWX.org
            </p>
        </div>

        <?php if ($isIndex): ?>
            <!-- Index page - no sidebar -->
            <div class="guides-index">
                <?= $htmlContent ?>
            </div>
        <?php else: ?>
            <!-- Individual guide - with sidebar -->
            <div class="guides-layout">
                <div class="guides-sidebar">
                    <div class="back-link">
                        <a href="https://guides.aviationwx.org">← Back to Guides</a>
                    </div>
                    <h3>All Guides</h3>
                    <ul>
                        <?php foreach ($allGuides as $guide): 
                            $guideUrl = 'https://guides.aviationwx.org/' . $guide['slug'];
                            $isActive = ($guide['slug'] === $guideName);
                        ?>
                            <li>
                                <a href="<?= htmlspecialchars($guideUrl) ?>" <?= $isActive ? 'class="active"' : '' ?>>
                                    <?= htmlspecialchars($guide['slug']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="guides-content">
                    <?= $htmlContent ?>
                </div>
            </div>
        <?php endif; ?>

        <footer class="footer">
            <p>
                &copy; <?= date('Y') ?> <a href="https://aviationwx.org">AviationWX.org</a> • 
                <a href="https://airports.aviationwx.org">Airports</a> • 
                <a href="https://guides.aviationwx.org">Guides</a> • 
                <a href="https://aviationwx.org#about-the-project">Built for pilots, by pilots</a> • 
                <a href="https://github.com/alexwitherspoon/aviationwx.org" target="_blank" rel="noopener">Open Source<?php $gitSha = getGitSha(); echo $gitSha ? ' - ' . htmlspecialchars($gitSha) : ''; ?></a> • 
                <a href="https://terms.aviationwx.org">Terms of Service</a> • 
                <a href="https://api.aviationwx.org">API</a> • 
                <a href="https://status.aviationwx.org">Status</a>
            </p>
        </footer>
    </div>
    </main>
</body>
</html>

