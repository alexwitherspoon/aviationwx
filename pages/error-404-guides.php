<?php
// Load SEO utilities
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/seo.php';

// Get base URL for links
$baseUrl = getBaseUrl();

// Get the requested guide name from the path
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$parsedUri = parse_url($requestUri);
$requestPath = isset($parsedUri['path']) ? trim($parsedUri['path'], '/') : '';
$requestedGuide = !empty($requestPath) ? htmlspecialchars($requestPath) : '';

// SEO variables
$pageTitle = 'Guide Not Found - AviationWX Guides';
$pageDescription = 'The guide you\'re looking for doesn\'t exist. Return to the guides index to browse all available guides.';
$canonicalUrl = getCanonicalUrl();

// Set cache headers for 404 pages - shorter cache since content might change
// Cache for 5 minutes, allow stale-while-revalidate for 15 minutes
header('Cache-Control: public, max-age=300, s-maxage=300, stale-while-revalidate=900');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 300) . ' GMT');
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
    echo generateEnhancedMetaTags($pageDescription, 'guide not found, 404, guides, documentation');
    echo "\n    ";
    
    // Canonical URL
    echo generateCanonicalTag($canonicalUrl);
    echo "\n    ";
    
    // Open Graph and Twitter Card tags
    echo generateSocialMetaTags($pageTitle, $pageDescription, $canonicalUrl);
    ?>
    
    <link rel="stylesheet" href="public/css/styles.css">
    <style>
        .error-container {
            max-width: 700px;
            margin: 0 auto;
            padding: 4rem 2rem;
            text-align: center;
        }
        .error-hero {
            padding: 3rem 2rem;
            background: linear-gradient(135deg, #1a1a1a 0%, #0066cc 100%);
            color: white;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        .error-code {
            font-size: 6rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            opacity: 0.9;
            line-height: 1;
        }
        .error-hero h1 {
            font-size: 2rem;
            margin: 1rem 0;
        }
        .error-hero p {
            font-size: 1.1rem;
            opacity: 0.95;
            margin: 0.5rem 0;
        }
        .section {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            text-align: left;
        }
        .section h2 {
            color: #0066cc;
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.5rem;
            text-align: center;
        }
        .helpful-links {
            list-style: none;
            padding: 0;
            margin: 1.5rem 0;
        }
        .helpful-links li {
            padding: 0.75rem 0;
            border-bottom: 1px solid #e9ecef;
        }
        .helpful-links li:last-child {
            border-bottom: none;
        }
        .helpful-links a {
            color: #0066cc;
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            transition: color 0.2s;
        }
        .helpful-links a:hover {
            color: #0052a3;
            text-decoration: underline;
        }
        .helpful-links a::before {
            content: "→";
            margin-right: 0.75rem;
            font-weight: bold;
        }
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
            transition: all 0.2s;
            margin: 0.5rem;
        }
        .btn-primary {
            background: #0066cc;
            color: white;
        }
        .btn-primary:hover {
            background: #0052a3;
        }
        .btn-secondary {
            background: white;
            color: #0066cc;
            border: 2px solid #0066cc;
        }
        .btn-secondary:hover {
            background: #0066cc;
            color: white;
        }
        .note {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 6px;
            border-left: 4px solid #0066cc;
            margin: 1.5rem 0;
            font-size: 0.95rem;
            color: #666;
        }
        .note strong {
            color: #333;
        }
        .requested-guide {
            font-family: monospace;
            background: #f4f4f4;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            color: #d63384;
        }
        
        /* ============================================
           Dark Mode Overrides for 404 Guides Page
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
        
        body.dark-mode .error-hero {
            background: linear-gradient(135deg, #0a0a0a 0%, #003d7a 100%);
        }
        
        body.dark-mode .requested-guide {
            background: #2a2a2a;
            color: #ff7eb6;
        }
        
        body.dark-mode .section {
            background: #1e1e1e;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        body.dark-mode .section h2 {
            color: #4a9eff;
        }
        
        body.dark-mode .helpful-links li {
            border-bottom-color: #333;
        }
        
        body.dark-mode .helpful-links a {
            color: #4a9eff;
        }
        
        body.dark-mode .helpful-links a:hover {
            color: #6eb5ff;
        }
        
        body.dark-mode .note {
            background: #1a1a1a;
            border-left-color: #4a9eff;
            color: #a0a0a0;
        }
        
        body.dark-mode .note strong {
            color: #e0e0e0;
        }
        
        body.dark-mode .note a {
            color: #4a9eff;
        }
        
        body.dark-mode .btn-primary {
            background: #4a9eff;
        }
        
        body.dark-mode .btn-primary:hover {
            background: #3a8eef;
        }
        
        body.dark-mode .btn-secondary {
            background: #1e1e1e;
            color: #4a9eff;
            border-color: #4a9eff;
        }
        
        body.dark-mode .btn-secondary:hover {
            background: #4a9eff;
            color: white;
        }
        
        body.dark-mode .footer {
            border-top-color: #333;
            color: #a0a0a0;
        }
        
        body.dark-mode .footer a {
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
    <div class="container">
        <div class="error-container">
            <div class="error-hero">
                <div class="error-code">404</div>
                <h1>Guide Not Found</h1>
                <p>The guide you're looking for doesn't exist or has been moved.</p>
                <?php if (!empty($requestedGuide)): ?>
                    <p style="font-size: 0.95rem; opacity: 0.85;">
                        Requested: <span class="requested-guide"><?= $requestedGuide ?></span>
                    </p>
                <?php endif; ?>
            </div>

            <div class="section">
                <h2>What Were You Looking For?</h2>
                <ul class="helpful-links">
                    <li>
                        <a href="https://guides.aviationwx.org">Guides Index</a>
                    </li>
                    <li>
                        <a href="https://aviationwx.org">AviationWX Homepage</a>
                    </li>
                    <li>
                        <a href="https://aviationwx.org#participating-airports">View All Airports</a>
                    </li>
                    <li>
                        <a href="https://status.aviationwx.org">System Status</a>
                    </li>
                    <li>
                        <a href="https://github.com/alexwitherspoon/aviationwx.org">GitHub Repository</a>
                    </li>
                </ul>
            </div>

            <div class="section">
                <div class="note">
                    <strong>Looking for a specific guide?</strong><br>
                    All guides are listed on the <a href="https://guides.aviationwx.org" style="color: #0066cc;">guides index page</a>. 
                    If you're looking for a guide that should exist, please check the spelling or contact us.
                </div>
            </div>

            <div style="text-align: center; margin-top: 2rem;">
                <a href="https://guides.aviationwx.org" class="btn btn-primary">Return to Guides</a>
                <a href="https://aviationwx.org" class="btn btn-secondary">Go to Homepage</a>
            </div>
        </div>
    </div>
    
    <footer class="footer" style="text-align: center; padding: 2rem 1rem; margin-top: 2rem; border-top: 1px solid #ddd; color: #666; font-size: 0.9rem;">
        <p>
            &copy; <?= date('Y') ?> <a href="https://aviationwx.org" style="color: #0066cc;">AviationWX.org</a> • 
            <a href="https://airports.aviationwx.org" style="color: #0066cc;">Airports</a> • 
            <a href="https://guides.aviationwx.org" style="color: #0066cc;">Guides</a> • 
            <a href="https://aviationwx.org#about-the-project" style="color: #0066cc;">Built for pilots, by pilots</a> • 
            <a href="https://github.com/alexwitherspoon/aviationwx.org" target="_blank" rel="noopener" style="color: #0066cc;">Open Source<?php $gitSha = getGitSha(); echo $gitSha ? ' - ' . htmlspecialchars($gitSha) : ''; ?></a> • 
            <a href="https://terms.aviationwx.org" style="color: #0066cc;">Terms of Service</a> • 
            <a href="https://api.aviationwx.org" style="color: #0066cc;">API</a> • 
            <a href="https://status.aviationwx.org" style="color: #0066cc;">Status</a>
        </p>
    </footer>
</body>
</html>

