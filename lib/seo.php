<?php
/**
 * SEO Utilities for AviationWX.org
 * Provides functions for generating structured data, Open Graph tags, and meta tags
 */

/**
 * Get the current page URL (canonical)
 * For airport pages, always use the subdomain URL (e.g., https://kspb.aviationwx.org)
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
 */
function getBaseUrl() {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'aviationwx.org';
    return $protocol . '://' . $host;
}

/**
 * Generate Organization structured data (JSON-LD) for homepage
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
 */
function generateCanonicalTag($url = null) {
    $url = $url ?: getCanonicalUrl();
    return '<link rel="canonical" href="' . htmlspecialchars($url) . '">';
}

/**
 * Generate structured data JSON-LD script tag
 */
function generateStructuredDataScript($data) {
    return '<script type="application/ld+json">' . "\n" . 
           json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . 
           '</script>';
}

/**
 * Generate favicon and icon tags for HTML head
 * Returns all necessary favicon, Apple touch icon, and manifest links
 */
function generateFaviconTags() {
    $baseUrl = getBaseUrl();
    $faviconPath = $baseUrl . '/public/favicons/aviationwx_favicons';
    
    $tags = [];
    
    // Standard favicon (for older browsers)
    $tags[] = '<link rel="icon" type="image/x-icon" href="' . $faviconPath . '/favicon.ico">';
    
    // Modern favicons with sizes
    $faviconSizes = [16, 32, 48, 64, 128, 256];
    foreach ($faviconSizes as $size) {
        $tags[] = '<link rel="icon" type="image/png" sizes="' . $size . 'x' . $size . '" href="' . $faviconPath . '/favicon-' . $size . 'x' . $size . '.png">';
    }
    
    // Apple Touch Icons (for iOS home screen)
    $appleSizes = [120, 152, 167, 180];
    foreach ($appleSizes as $size) {
        $tags[] = '<link rel="apple-touch-icon" sizes="' . $size . 'x' . $size . '" href="' . $faviconPath . '/apple-touch-icon-' . $size . 'x' . $size . '.png">';
    }
    
    // Web App Manifest (PWA support)
    $tags[] = '<link rel="manifest" href="' . $faviconPath . '/manifest.json">';
    
    // Theme color for mobile browsers
    $tags[] = '<meta name="theme-color" content="#3b82f6">';
    
    return implode("\n    ", $tags);
}

/**
 * Get logo URL for structured data
 * Checks for logo file in favicon folder, falls back to largest favicon or about-photo.jpg
 */
function getLogoUrl() {
    $baseUrl = getBaseUrl();
    $faviconDir = __DIR__ . '/../public/favicons/aviationwx_favicons';
    
    // Check for common logo file names
    $logoFiles = ['logo.png', 'logo.jpg', 'logo.svg', 'logo.webp'];
    foreach ($logoFiles as $logoFile) {
        if (file_exists($faviconDir . '/' . $logoFile)) {
            return $baseUrl . '/public/favicons/aviationwx_favicons/' . $logoFile;
        }
    }
    
    // Fallback to largest favicon (512x512) if no logo found
    if (file_exists($faviconDir . '/favicon-512x512.png')) {
        return $baseUrl . '/public/favicons/aviationwx_favicons/favicon-512x512.png';
    }
    
    // Final fallback to about-photo (prefer WebP, fallback to JPG)
    $aboutPhotoWebp = __DIR__ . '/../public/images/about-photo.webp';
    $aboutPhotoJpg = __DIR__ . '/../public/images/about-photo.jpg';
    return file_exists($aboutPhotoWebp) 
        ? $baseUrl . '/public/images/about-photo.webp'
        : $baseUrl . '/public/images/about-photo.jpg';
}

