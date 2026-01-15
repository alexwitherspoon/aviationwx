<?php
/**
 * SEO Utilities for AviationWX.org
 * Provides functions for generating structured data, Open Graph tags, and meta tags
 */

/**
 * Get the current page URL (canonical)
 * 
 * Returns the canonical URL for the current page. For airport pages, always uses
 * the subdomain URL format (e.g., https://kspb.aviationwx.org) regardless of how
 * the page was accessed.
 * 
 * Always uses HTTPS protocol for canonical URLs since all HTTP requests redirect
 * to HTTPS via nginx. This ensures canonical URLs are consistent regardless of
 * how the page was initially accessed.
 * 
 * @param string|null $airportId Optional airport ID to generate subdomain URL
 * @return string Canonical URL (always HTTPS, host + path, query params removed)
 */
function getCanonicalUrl($airportId = null) {
    // Always use HTTPS for canonical URLs - HTTP redirects to HTTPS via nginx (301)
    $protocol = 'https';
    
    // If airport ID is provided, use subdomain URL
    if ($airportId) {
        return $protocol . '://' . $airportId . '.aviationwx.org';
    }
    
    // Otherwise, use current host (strip www. prefix for consistency)
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'aviationwx.org';
    // Remove www. prefix - www.aviationwx.org redirects to aviationwx.org
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    // Remove port if present (for local dev)
    $host = preg_replace('/:\d+$/', '', $host);
    
    $path = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    // Remove query parameters for canonical URL
    $path = strtok($path, '?');
    return $protocol . '://' . $host . $path;
}

/**
 * Get base URL (protocol + domain)
 * 
 * Returns the base URL for the current request (protocol + host).
 * Used for generating absolute URLs in structured data and meta tags.
 * 
 * Always uses HTTPS protocol since all HTTP requests redirect to HTTPS via nginx.
 * 
 * @return string Base URL (e.g., 'https://aviationwx.org')
 */
function getBaseUrl() {
    // Always use HTTPS - HTTP redirects to HTTPS via nginx (301)
    $protocol = 'https';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'aviationwx.org';
    // Remove www. prefix for consistency
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    // Remove port if present (for local dev)
    $host = preg_replace('/:\d+$/', '', $host);
    return $protocol . '://' . $host;
}

/**
 * Generate Organization structured data (JSON-LD) for homepage
 * 
 * Creates Schema.org Organization structured data for the homepage.
 * Includes name, URL, logo, description, social links, and contact information.
 * 
 * @return array Schema.org Organization JSON-LD structure
 */
function generateOrganizationSchema() {
    $baseUrl = getBaseUrl();
    return [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => 'AviationWX.org',
        'url' => $baseUrl,
        'logo' => getLogoUrl(),
        'description' => 'Free real-time aviation weather with live airport webcams and runway conditions for pilots',
        'sameAs' => [
            'https://github.com/alexwitherspoon/aviationwx.org'
        ],
        'contactPoint' => [
            '@type' => 'ContactPoint',
            'email' => 'contact@aviationwx.org',
            'contactType' => 'Customer Service'
        ]
    ];
}

/**
 * Generate WebSite structured data (JSON-LD) with SearchAction for homepage
 * 
 * Creates Schema.org WebSite structured data that enables Google's
 * Sitelinks Search Box feature. Users can search for airports directly
 * from Google search results using the subdomain URL pattern.
 * 
 * @return array Schema.org WebSite JSON-LD structure
 */
function generateWebSiteSchema() {
    return [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => 'AviationWX.org',
        'alternateName' => ['AviationWX', 'Aviation Weather'],
        'url' => 'https://aviationwx.org',
        'description' => 'Free real-time aviation weather with live airport webcams and runway conditions for pilots',
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => [
                '@type' => 'EntryPoint',
                'urlTemplate' => 'https://{search_term_string}.aviationwx.org'
            ],
            'query-input' => 'required name=search_term_string'
        ]
    ];
}

/**
 * Generate Airport structured data (JSON-LD) for airport pages
 * 
 * Creates Schema.org Airport structured data for airport pages.
 * Uses the specific Airport type (better than generic LocalBusiness) with
 * aviation-specific properties like icaoCode, iataCode, and sameAs links
 * to authoritative aviation databases.
 * 
 * @param array $airport Airport configuration array
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @return array Schema.org Airport JSON-LD structure
 */
function generateAirportSchema($airport, $airportId) {
    $airportUrl = 'https://' . $airportId . '.aviationwx.org';
    
    // Get primary identifier for display (handles null ICAO)
    $primaryIdentifier = getPrimaryIdentifier($airportId, $airport);
    
    // Build alternate names from all available identifiers
    $alternateNames = [$airport['name']];
    if (!empty($airport['icao'])) {
        $alternateNames[] = $airport['icao'];
    }
    if (!empty($airport['iata'])) {
        $alternateNames[] = $airport['iata'];
    }
    if (!empty($airport['faa']) && $airport['faa'] !== ($airport['icao'] ?? '')) {
        $alternateNames[] = $airport['faa'];
    }
    $alternateNames = array_unique($alternateNames);
    
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'Airport',
        'name' => $airport['name'],
        'alternateName' => $alternateNames,
        'identifier' => $primaryIdentifier,
        'description' => 'Live webcams and real-time weather conditions for ' . $airport['name'] . ' (' . $primaryIdentifier . ')',
        'url' => $airportUrl
    ];
    
    // Add ICAO code if available
    if (!empty($airport['icao'])) {
        $schema['icaoCode'] = $airport['icao'];
    }
    
    // Add IATA code if available
    if (!empty($airport['iata'])) {
        $schema['iataCode'] = $airport['iata'];
    }
    
    // Build sameAs links to authoritative aviation databases
    $sameAs = [];
    // AirNav and SkyVector work with any identifier (ICAO, FAA LID, etc.)
    $sameAs[] = 'https://www.airnav.com/airport/' . $primaryIdentifier;
    $sameAs[] = 'https://skyvector.com/airport/' . $primaryIdentifier;
    // AOPA works best with ICAO codes
    if (!empty($airport['icao'])) {
        $sameAs[] = 'https://www.aopa.org/destinations/airports/' . $airport['icao'];
    }
    $schema['sameAs'] = $sameAs;
    
    // Add address
    $schema['address'] = [
        '@type' => 'PostalAddress',
        'addressLocality' => $airport['address']
    ];
    
    // Add geo coordinates if available
    if (isset($airport['lat']) && isset($airport['lon'])) {
        $schema['geo'] = [
            '@type' => 'GeoCoordinates',
            'latitude' => $airport['lat'],
            'longitude' => $airport['lon']
        ];
    }
    
    // Add webcam images if available (emphasize live webcams in structured data)
    if (isset($airport['webcams']) && is_array($airport['webcams']) && count($airport['webcams']) > 0) {
        $images = [];
        foreach ($airport['webcams'] as $index => $cam) {
            // Generate webcam image URL
            $webcamUrl = $airportUrl . '/webcam.php?id=' . urlencode($airportId) . '&cam=' . $index . '&fmt=jpg';
            $images[] = $webcamUrl;
        }
        if (!empty($images)) {
            $schema['image'] = count($images) === 1 ? $images[0] : $images;
        }
        // Update description to emphasize live webcams
        $webcamCount = count($airport['webcams']);
        $schema['description'] = 'Live webcams (' . $webcamCount . ' camera' . ($webcamCount > 1 ? 's' : '') . ') and real-time runway conditions for ' . $airport['name'] . ' (' . $primaryIdentifier . ')';
    }
    
    // Add service description emphasizing live webcams and runway conditions
    $services = ['Live Airport Webcams', 'Real-time Runway Conditions', 'Aviation Weather Data'];
    $schema['hasOfferCatalog'] = [
        '@type' => 'OfferCatalog',
        'name' => 'Aviation Services',
        'itemListElement' => array_map(function($service) {
            return [
                '@type' => 'Offer',
                'itemOffered' => [
                    '@type' => 'Service',
                    'name' => $service
                ]
            ];
        }, $services)
    ];
    
    return $schema;
}

/**
 * Generate BreadcrumbList structured data (JSON-LD)
 * 
 * Creates Schema.org BreadcrumbList for navigation breadcrumbs.
 * Helps search engines understand site hierarchy and can display
 * breadcrumb trails in search results instead of raw URLs.
 * 
 * @param array $items Array of breadcrumb items, each with 'name' and optional 'url'
 *                     The last item should NOT have a 'url' (it's the current page)
 * @return array Schema.org BreadcrumbList JSON-LD structure
 */
function generateBreadcrumbSchema($items) {
    $elements = [];
    foreach ($items as $i => $item) {
        $element = [
            '@type' => 'ListItem',
            'position' => $i + 1,
            'name' => $item['name']
        ];
        // Only add 'item' URL if provided (last breadcrumb shouldn't have URL)
        if (isset($item['url']) && !empty($item['url'])) {
            $element['item'] = $item['url'];
        }
        $elements[] = $element;
    }
    return [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => $elements
    ];
}

/**
 * Generate breadcrumbs for airport pages
 * 
 * Creates a simple two-level breadcrumb: Home > Airport Name
 * 
 * @param array $airport Airport configuration array
 * @param string $primaryIdentifier Primary airport identifier (ICAO/IATA/FAA)
 * @return array Schema.org BreadcrumbList JSON-LD structure
 */
function generateAirportBreadcrumbs($airport, $primaryIdentifier) {
    return generateBreadcrumbSchema([
        ['name' => 'AviationWX', 'url' => 'https://aviationwx.org'],
        ['name' => $primaryIdentifier . ' - ' . $airport['name']]
    ]);
}

/**
 * Generate breadcrumbs for guide pages
 * 
 * Creates breadcrumbs for guides: Home > Guides > [Guide Name] (if not index)
 * 
 * @param string|null $guideTitle Title of the current guide (null for index page)
 * @return array Schema.org BreadcrumbList JSON-LD structure
 */
function generateGuideBreadcrumbs($guideTitle = null) {
    $items = [
        ['name' => 'Home', 'url' => 'https://aviationwx.org'],
    ];
    
    if ($guideTitle !== null) {
        // Individual guide page
        $items[] = ['name' => 'Guides', 'url' => 'https://guides.aviationwx.org'];
        $items[] = ['name' => $guideTitle];
    } else {
        // Guides index page
        $items[] = ['name' => 'Guides'];
    }
    
    return generateBreadcrumbSchema($items);
}

/**
 * Extract a meta description from markdown content
 * 
 * Automatically extracts the first paragraph after the H1 heading
 * to use as a meta description. Falls back to a default if extraction fails.
 * Truncates to ~155 characters for optimal SEO.
 * 
 * @param string $markdownContent Raw markdown content
 * @param string $fallback Fallback description if extraction fails
 * @return string Meta description (max ~160 chars)
 */
function extractMetaDescriptionFromMarkdown($markdownContent, $fallback = 'AviationWX guide for airport weather installations.') {
    // Normalize line endings
    $content = str_replace(["\r\n", "\r"], "\n", $markdownContent);
    
    // Split into lines and find first real paragraph after H1
    $lines = explode("\n", $content);
    $foundH1 = false;
    $paragraphLines = [];
    $inParagraph = false;
    
    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        
        // Skip until we find the H1
        if (!$foundH1) {
            if (preg_match('/^#\s+/', $trimmedLine)) {
                $foundH1 = true;
            }
            continue;
        }
        
        // Skip empty lines before first paragraph
        if (empty($trimmedLine)) {
            if ($inParagraph) {
                // End of paragraph
                break;
            }
            continue;
        }
        
        // Skip H2+ headings, lists, code blocks, etc.
        if (preg_match('/^(#{2,}|\*|-|\d+\.|\||```|>)/', $trimmedLine)) {
            if ($inParagraph) {
                break; // Stop if we hit another element after starting a paragraph
            }
            continue;
        }
        
        // This is paragraph content
        $inParagraph = true;
        $paragraphLines[] = $trimmedLine;
    }
    
    if (!empty($paragraphLines)) {
        $firstParagraph = implode(' ', $paragraphLines);
        
        // Remove any markdown formatting (links, bold, etc.)
        $firstParagraph = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $firstParagraph); // Links
        $firstParagraph = preg_replace('/\*\*([^*]+)\*\*/', '$1', $firstParagraph); // Bold
        $firstParagraph = preg_replace('/\*([^*]+)\*/', '$1', $firstParagraph); // Italic
        $firstParagraph = preg_replace('/`([^`]+)`/', '$1', $firstParagraph); // Code
        $firstParagraph = strip_tags($firstParagraph);
        $firstParagraph = trim($firstParagraph);
        
        // Truncate to ~155 chars at word boundary
        if (strlen($firstParagraph) > 155) {
            $firstParagraph = substr($firstParagraph, 0, 152);
            // Cut at last space to avoid mid-word truncation
            $lastSpace = strrpos($firstParagraph, ' ');
            if ($lastSpace !== false && $lastSpace > 100) {
                $firstParagraph = substr($firstParagraph, 0, $lastSpace);
            }
            $firstParagraph .= '...';
        }
        
        if (!empty($firstParagraph)) {
            return $firstParagraph;
        }
    }
    
    return $fallback;
}

/**
 * Generate Open Graph and Twitter Card meta tags
 * 
 * Creates HTML meta tags for social media sharing (Open Graph and Twitter Cards).
 * Includes title, description, URL, image, and type. Uses default image if none provided.
 * 
 * @param string $title Page title
 * @param string $description Page description
 * @param string $url Canonical URL
 * @param string|null $image Image URL (optional, uses default if null)
 * @param string $type Open Graph type (default: 'website')
 * @return string HTML meta tags (newline-separated)
 */
function generateSocialMetaTags($title, $description, $url, $image = null, $type = 'website') {
    $baseUrl = getBaseUrl();
    // Prefer WebP for about-photo, fallback to JPG
    $aboutPhotoWebp = __DIR__ . '/../public/images/about-photo.webp';
    $aboutPhotoJpg = __DIR__ . '/../public/images/about-photo.jpg';
    $defaultImage = file_exists($aboutPhotoWebp) 
        ? $baseUrl . '/public/images/about-photo.webp'
        : $baseUrl . '/public/images/about-photo.jpg';
    $image = $image ?: $defaultImage;
    
    $tags = [];
    
    // Open Graph tags
    $tags[] = '<meta property="og:title" content="' . htmlspecialchars($title) . '">';
    $tags[] = '<meta property="og:description" content="' . htmlspecialchars($description) . '">';
    $tags[] = '<meta property="og:url" content="' . htmlspecialchars($url) . '">';
    $tags[] = '<meta property="og:type" content="' . htmlspecialchars($type) . '">';
    $tags[] = '<meta property="og:image" content="' . htmlspecialchars($image) . '">';
    $tags[] = '<meta property="og:site_name" content="AviationWX.org">';
    $tags[] = '<meta property="og:locale" content="en_US">';
    
    // Twitter Card tags
    $tags[] = '<meta name="twitter:card" content="summary_large_image">';
    $tags[] = '<meta name="twitter:title" content="' . htmlspecialchars($title) . '">';
    $tags[] = '<meta name="twitter:description" content="' . htmlspecialchars($description) . '">';
    $tags[] = '<meta name="twitter:image" content="' . htmlspecialchars($image) . '">';
    
    return implode("\n    ", $tags);
}

/**
 * Generate enhanced meta tags
 * 
 * Creates standard HTML meta tags including description, keywords, author,
 * robots, and content-language.
 * 
 * @param string $description Page description
 * @param string $keywords Comma-separated keywords (default: empty)
 * @param string $author Author name (default: 'Alex Witherspoon')
 * @return string HTML meta tags (newline-separated)
 */
function generateEnhancedMetaTags($description, $keywords = '', $author = 'Alex Witherspoon') {
    $tags = [];
    
    // Description (if not already set)
    if (!empty($description)) {
        $tags[] = '<meta name="description" content="' . htmlspecialchars($description) . '">';
    }
    
    // Keywords
    if (!empty($keywords)) {
        $tags[] = '<meta name="keywords" content="' . htmlspecialchars($keywords) . '">';
    }
    
    // Author
    if (!empty($author)) {
        $tags[] = '<meta name="author" content="' . htmlspecialchars($author) . '">';
    }
    
    // Robots (allow indexing)
    $tags[] = '<meta name="robots" content="index, follow">';
    
    // Language
    $tags[] = '<meta http-equiv="content-language" content="en">';
    
    return implode("\n    ", $tags);
}

/**
 * Generate canonical URL tag
 * 
 * Creates HTML canonical link tag to prevent duplicate content issues.
 * Uses provided URL or generates canonical URL automatically.
 * 
 * @param string|null $url Canonical URL (optional, auto-generated if null)
 * @return string HTML canonical link tag
 */
function generateCanonicalTag($url = null) {
    $url = $url ?: getCanonicalUrl();
    return '<link rel="canonical" href="' . htmlspecialchars($url) . '">';
}

/**
 * Generate structured data JSON-LD script tag
 * 
 * Wraps structured data in a JSON-LD script tag for embedding in HTML.
 * Formats JSON with pretty printing for readability.
 * 
 * @param array $data Structured data array (Schema.org format)
 * @return string HTML script tag with JSON-LD content
 */
function generateStructuredDataScript($data) {
    return '<script type="application/ld+json">' . "\n" . 
           json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . 
           '</script>';
}

/**
 * Generate favicon and icon tags for HTML head
 * 
 * Returns all necessary favicon, Apple touch icon, and manifest links.
 * Includes standard favicons, Android Chrome icons, Apple touch icon, and
 * web app manifest for PWA support.
 * 
 * @return string HTML link and meta tags (newline-separated)
 */
function generateFaviconTags() {
    $baseUrl = getBaseUrl();
    $faviconPath = $baseUrl . '/public/favicons';
    
    $tags = [];
    
    // Standard favicon (for older browsers)
    $tags[] = '<link rel="icon" type="image/x-icon" href="' . $faviconPath . '/favicon.ico">';
    
    // Modern favicons with sizes (available: 16x16, 32x32)
    $faviconSizes = [16, 32];
    foreach ($faviconSizes as $size) {
        $tags[] = '<link rel="icon" type="image/png" sizes="' . $size . 'x' . $size . '" href="' . $faviconPath . '/favicon-' . $size . 'x' . $size . '.png">';
    }
    
    // Android Chrome icons
    $tags[] = '<link rel="icon" type="image/png" sizes="192x192" href="' . $faviconPath . '/android-chrome-192x192.png">';
    $tags[] = '<link rel="icon" type="image/png" sizes="512x512" href="' . $faviconPath . '/android-chrome-512x512.png">';
    
    // Apple Touch Icon (single file for all iOS devices)
    $tags[] = '<link rel="apple-touch-icon" href="' . $faviconPath . '/apple-touch-icon.png">';
    
    // Web App Manifest (PWA support) - now site.webmanifest
    $tags[] = '<link rel="manifest" href="' . $faviconPath . '/site.webmanifest">';
    
    // Theme color for mobile browsers
    $tags[] = '<meta name="theme-color" content="#3b82f6">';
    
    return implode("\n    ", $tags);
}

/**
 * Get logo URL for structured data
 * 
 * Determines the logo URL for use in structured data. Checks for common logo
 * file names in the favicon directory, falls back to largest favicon, then
 * to about-photo image. Prefers WebP format when available.
 * 
 * @return string Absolute URL to logo/image file
 */
function getLogoUrl() {
    $baseUrl = getBaseUrl();
    $faviconDir = __DIR__ . '/../public/favicons';
    
    // Check for common logo file names
    $logoFiles = ['logo.png', 'logo.jpg', 'logo.svg', 'logo.webp'];
    foreach ($logoFiles as $logoFile) {
        if (file_exists($faviconDir . '/' . $logoFile)) {
            return $baseUrl . '/public/favicons/' . $logoFile;
        }
    }
    
    // Fallback to largest favicon (android-chrome-512x512) if no logo found
    if (file_exists($faviconDir . '/android-chrome-512x512.png')) {
        return $baseUrl . '/public/favicons/android-chrome-512x512.png';
    }
    
    // Final fallback to about-photo (prefer WebP, fallback to JPG)
    $aboutPhotoWebp = __DIR__ . '/../public/images/about-photo.webp';
    $aboutPhotoJpg = __DIR__ . '/../public/images/about-photo.jpg';
    return file_exists($aboutPhotoWebp) 
        ? $baseUrl . '/public/images/about-photo.webp'
        : $baseUrl . '/public/images/about-photo.jpg';
}

