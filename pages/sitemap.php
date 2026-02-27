<?php
/**
 * HTML Sitemap Page
 * Human and crawler-readable sitemap for AviationWX.org
 * 
 * Uses shared URL generation from lib/sitemap.php
 */

require_once __DIR__ . '/../lib/seo.php';
require_once __DIR__ . '/../lib/sitemap.php';

// Get grouped sitemap URLs
$sitemapUrls = getSitemapUrls();
$categoryLabels = getSitemapCategoryLabels();

// Count total URLs
$totalUrls = 0;
foreach ($sitemapUrls as $urls) {
    $totalUrls += count($urls);
}

// SEO variables
$pageTitle = 'Site Map - AviationWX.org';
$pageDescription = 'Complete site map of AviationWX.org - browse all airport weather pages, guides, tools, and resources.';
$pageKeywords = 'sitemap, site map, aviationwx, airport weather, directory';
$canonicalUrl = 'https://aviationwx.org/sitemap';
$baseUrl = getBaseUrl();

// Breadcrumbs
$breadcrumbs = generateBreadcrumbSchema([
    ['name' => 'Home', 'url' => 'https://aviationwx.org'],
    ['name' => 'Site Map']
]);
?>
<!DOCTYPE html>
<html lang="en">
<script>
// Apply dark mode immediately based on browser preference to prevent flash
// Listen for preference changes so theme transitions without refresh
(function() {
    function applyDarkMode(isDark) {
        const el = document.documentElement;
        const body = document.body;
        if (isDark) {
            el.classList.add('dark-mode');
            if (body) { body.classList.add('dark-mode'); }
        } else {
            el.classList.remove('dark-mode');
            if (body) { body.classList.remove('dark-mode'); }
        }
    }
    const mq = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)');
    if (mq) {
        applyDarkMode(mq.matches);
        mq.addEventListener('change', function(e) { applyDarkMode(e.matches); });
    }
})();
</script>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    
    <?php
    echo generateFaviconTags();
    echo "\n    ";
    echo generateEnhancedMetaTags($pageDescription, $pageKeywords);
    echo "\n    ";
    echo generateCanonicalTag($canonicalUrl);
    echo "\n    ";
    echo generateSocialMetaTags($pageTitle, $pageDescription, $canonicalUrl);
    echo "\n    ";
    echo generateStructuredDataScript($breadcrumbs);
    ?>
    
    <link rel="stylesheet" href="/public/css/styles.css">
    <link rel="stylesheet" href="/public/css/navigation.css">
    <style>
        .sitemap-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }
        
        .sitemap-header {
            text-align: center;
            margin-bottom: 3rem;
        }
        
        .sitemap-header h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            color: var(--text-primary, #1a1a2e);
        }
        
        .sitemap-header p {
            color: var(--text-secondary, #666);
            font-size: 1.1rem;
        }
        
        .sitemap-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
        }
        
        .sitemap-section {
            background: var(--card-bg, #fff);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .sitemap-section h2 {
            font-size: 1.25rem;
            color: var(--text-primary, #1a1a2e);
            margin: 0 0 1rem 0;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--accent-color, #3b82f6);
        }
        
        .sitemap-section ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        
        .sitemap-section li {
            margin-bottom: 0.5rem;
        }
        
        .sitemap-section a {
            color: var(--link-color, #3b82f6);
            text-decoration: none;
            font-size: 0.95rem;
            display: block;
            padding: 0.25rem 0;
            transition: color 0.2s ease;
        }
        
        .sitemap-section a:hover {
            color: var(--link-hover, #2563eb);
            text-decoration: underline;
        }
        
        /* Airports section - multi-column for long lists */
        .sitemap-section.airports-section {
            grid-column: 1 / -1;
        }
        
        .sitemap-section.airports-section ul {
            column-count: 3;
            column-gap: 2rem;
        }
        
        @media (max-width: 768px) {
            .sitemap-section.airports-section ul {
                column-count: 2;
            }
        }
        
        @media (max-width: 480px) {
            .sitemap-section.airports-section ul {
                column-count: 1;
            }
            
            .sitemap-header h1 {
                font-size: 1.75rem;
            }
        }
        
        /* Dark mode */
        .dark-mode .sitemap-section {
            background: var(--card-bg-dark, #1e293b);
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        
        .dark-mode .sitemap-header h1,
        .dark-mode .sitemap-section h2 {
            color: var(--text-primary-dark, #f1f5f9);
        }
        
        .dark-mode .sitemap-header p {
            color: var(--text-secondary-dark, #94a3b8);
        }
        
        .dark-mode .sitemap-section a {
            color: var(--link-color-dark, #60a5fa);
        }
        
        .dark-mode .sitemap-section a:hover {
            color: var(--link-hover-dark, #93c5fd);
        }
        
        .sitemap-footer {
            text-align: center;
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid var(--border-color, #e5e7eb);
            color: var(--text-secondary, #666);
            font-size: 0.9rem;
        }
        
        .dark-mode .sitemap-footer {
            border-top-color: var(--border-color-dark, #374151);
            color: var(--text-secondary-dark, #94a3b8);
        }
        
        .sitemap-footer a {
            color: var(--link-color, #3b82f6);
        }
        
        .dark-mode .sitemap-footer a {
            color: var(--link-color-dark, #60a5fa);
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../lib/navigation.php'; ?>
    
    <main class="sitemap-container">
        <header class="sitemap-header">
            <h1>AviationWX.org Site Map</h1>
            <p><?= $totalUrls ?> pages across <?= count(array_filter($sitemapUrls, fn($urls) => !empty($urls))) ?> categories</p>
        </header>
        
        <div class="sitemap-grid">
            <?php foreach ($sitemapUrls as $category => $urls): ?>
                <?php if (!empty($urls)): ?>
                <section class="sitemap-section <?= $category === 'airports' ? 'airports-section' : '' ?>">
                    <h2><?= htmlspecialchars($categoryLabels[$category] ?? ucfirst($category)) ?></h2>
                    <ul>
                        <?php foreach ($urls as $url): ?>
                        <li>
                            <a href="<?= htmlspecialchars($url['loc']) ?>"><?= htmlspecialchars($url['title']) ?></a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        
        <footer class="sitemap-footer">
            <p>
                Also available as <a href="/sitemap.xml">XML Sitemap</a> for search engines.
            </p>
        </footer>
    </main>
    
</body>
</html>
