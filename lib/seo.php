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
 * @param string|null $airportId Optional airport ID to generate subdomain URL
 * @return string Canonical URL (protocol + host + path, query params removed)
 */
function getCanonicalUrl($airportId = null) {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    
    // If airport ID is provided, use subdomain URL
    if ($airportId) {
        return $protocol . '://' . $airportId . '.aviationwx.org';
    }
    
    // Otherwise, use current host
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'aviationwx.org';
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
 * @return string Base URL (e.g., 'https://aviationwx.org')
 */
function getBaseUrl() {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'aviationwx.org';
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
            'email' => 'alex@alexwitherspoon.com',
            'contactType' => 'Customer Service'
        ]
    ];
}

/**
 * Generate LocalBusiness structured data (JSON-LD) for airport pages
 * 
 * Creates Schema.org LocalBusiness structured data for airport pages.
 * Includes airport name, description, address, geo coordinates, webcam images,
 * and service offerings.
 * 
 * @param array $airport Airport configuration array
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @return array Schema.org LocalBusiness JSON-LD structure
 */
function generateAirportSchema($airport, $airportId) {
    $baseUrl = getBaseUrl();
    $airportUrl = 'https://' . $airportId . '.aviationwx.org';
    
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'LocalBusiness',
        'name' => $airport['name'] . ' (' . $airport['icao'] . ')',
        'description' => 'Live webcams and real-time weather conditions for ' . $airport['name'] . ' (' . $airport['icao'] . ')',
        'url' => $airportUrl,
        'address' => [
            '@type' => 'PostalAddress',
            'addressLocality' => $airport['address']
        ]
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
        $schema['description'] = 'Live webcams (' . $webcamCount . ' camera' . ($webcamCount > 1 ? 's' : '') . ') and real-time runway conditions for ' . $airport['name'] . ' (' . $airport['icao'] . ')';
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

