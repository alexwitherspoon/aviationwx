<?php
// Load SEO utilities
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/seo.php';

// Get base URL for links
$baseUrl = getBaseUrl();

// SEO variables
$pageTitle = 'Page Not Found - AviationWX.org';
$pageDescription = 'The page you\'re looking for doesn\'t exist. Return to AviationWX.org to find airport weather dashboards.';
// For 404 pages, canonical should point to homepage, not the invalid URL
$canonicalUrl = 'https://aviationwx.org/';
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
    
    // 404 pages should not be indexed
    echo '<meta name="robots" content="noindex, nofollow">';
    echo "\n    ";
    
    // Enhanced meta tags (but skip robots meta since we set it above)
    echo '<meta name="description" content="' . htmlspecialchars($pageDescription) . '">';
    echo "\n    ";
    echo '<meta name="keywords" content="page not found, 404, aviation weather">';
    echo "\n    ";
    
    // Canonical URL
    echo generateCanonicalTag($canonicalUrl);
    echo "\n    ";
    
    // Open Graph and Twitter Card tags
    echo generateSocialMetaTags($pageTitle, $pageDescription, $canonicalUrl);
    ?>
    
    <link rel="stylesheet" href="public/css/styles.css">
    <link rel="stylesheet" href="/public/css/navigation.css">
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
        /* Airport Search Styles */
        .airport-search-section {
            margin-bottom: 2rem;
        }
        .airport-search-section h2 {
            color: #0066cc;
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.5rem;
            text-align: center;
        }
        .error-airport-search-container {
            max-width: 400px;
            margin: 1rem auto 0;
            position: relative;
        }
        .error-airport-search-input {
            width: 100%;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            background: white;
            color: #333;
            transition: all 0.2s;
            box-sizing: border-box;
        }
        .error-airport-search-input:focus {
            outline: none;
            border-color: #0066cc;
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.15);
        }
        .error-airport-search-input::placeholder {
            color: #888;
        }
        .error-airport-dropdown {
            position: absolute;
            top: calc(100% + 0.25rem);
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            max-height: 300px;
            overflow-y: auto;
            display: none;
            z-index: 100;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .error-airport-dropdown.show {
            display: block;
        }
        .error-airport-item {
            display: flex;
            flex-direction: column;
            padding: 0.75rem 1rem;
            text-decoration: none;
            color: inherit;
            cursor: pointer;
            transition: background 0.15s;
            border-bottom: 1px solid #eee;
        }
        .error-airport-item:last-child {
            border-bottom: none;
        }
        .error-airport-item:hover,
        .error-airport-item.selected {
            background: #f0f7ff;
        }
        .error-airport-item .airport-identifier {
            font-size: 1.1rem;
            font-weight: 700;
            color: #0066cc;
        }
        .error-airport-item .airport-name {
            font-size: 0.9rem;
            color: #555;
            margin-top: 0.15rem;
        }
        .error-airport-item.no-results {
            color: #666;
            font-style: italic;
            text-align: center;
            cursor: default;
        }
        .error-airport-item.no-results:hover {
            background: transparent;
        }
        
        /* ============================================
           Dark Mode Overrides for 404 Page
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
        
        body.dark-mode .note code {
            background: #2a2a2a;
            color: #ff7eb6;
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
        
        body.dark-mode .error-airport-search-input {
            background: #1e1e1e;
            border-color: #333;
            color: #e0e0e0;
        }
        
        body.dark-mode .error-airport-search-input::placeholder {
            color: #707070;
        }
        
        body.dark-mode .error-airport-search-input:focus {
            border-color: #4a9eff;
            box-shadow: 0 0 0 3px rgba(74, 158, 255, 0.15);
        }
        
        body.dark-mode .error-airport-dropdown {
            background: #1e1e1e;
            border-color: #333;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
        }
        
        body.dark-mode .error-airport-item {
            border-bottom-color: #333;
        }
        
        body.dark-mode .error-airport-item:hover,
        body.dark-mode .error-airport-item.selected {
            background: #252525;
        }
        
        body.dark-mode .error-airport-item .airport-identifier {
            color: #4a9eff;
        }
        
        body.dark-mode .error-airport-item .airport-name {
            color: #a0a0a0;
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
    
    <?php require_once __DIR__ . '/../lib/navigation.php'; ?>
    
    <div class="container">
        <div class="error-container">
            <div class="error-hero">
                <div class="error-code">404</div>
                <h1>Page Not Found</h1>
                <p>The page you're looking for doesn't exist or has been moved.</p>
            </div>

            <div class="section">
                <div class="airport-search-section">
                    <h2>Search Participating Airports</h2>
                    <p style="text-align: center; color: #666; margin-bottom: 1rem;">Find an airport in our network:</p>
                    <div class="error-airport-search-container">
                        <input type="text" 
                               id="error-airport-search" 
                               class="error-airport-search-input" 
                               placeholder="Search by name or identifier..." 
                               autocomplete="off"
                               aria-label="Search airports">
                        <div id="error-airport-dropdown" class="error-airport-dropdown">
                            <!-- Content populated by JavaScript -->
                        </div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h2>Helpful Links</h2>
                <ul class="helpful-links">
                    <li>
                        <a href="https://aviationwx.org">Homepage</a>
                    </li>
                    <li>
                        <a href="https://aviationwx.org#participating-airports">View All Airports</a>
                    </li>
                    <li>
                        <a href="https://aviationwx.org#about-the-project">About the Project</a>
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
                    <strong>Looking for a specific airport?</strong><br>
                    Airport dashboards are available at <code>ICAO.aviationwx.org</code> (e.g., <code>kspb.aviationwx.org</code>). 
                    If the airport you're looking for isn't online yet, visit its subdomain to learn how to help add it to the network.
                </div>
            </div>

            <div style="text-align: center; margin-top: 2rem;">
                <a href="https://aviationwx.org" class="btn btn-primary">Return to Homepage</a>
            </div>
        </div>
    </div>

    <?php
    // Prepare all airports for search (exclude unlisted airports)
    $config = loadConfig();
    $searchAirports = [];
    $listedAirports = $config ? getListedAirports($config) : [];
    foreach ($listedAirports as $searchAirportId => $searchAirport) {
        $searchPrimaryIdentifier = getPrimaryIdentifier($searchAirportId, $searchAirport);
        $searchAirports[] = [
            'id' => $searchAirportId,
            'name' => $searchAirport['name'] ?? '',
            'identifier' => $searchPrimaryIdentifier,
            'icao' => $searchAirport['icao'] ?? '',
            'iata' => $searchAirport['iata'] ?? '',
            'faa' => $searchAirport['faa'] ?? ''
        ];
    }
    ?>
    <script>
    (function() {
        'use strict';
        
        var ERROR_AIRPORTS = <?= json_encode($searchAirports) ?>;
        var BASE_DOMAIN = <?= json_encode(getBaseDomain()) ?>;
        
        function initErrorSearch() {
            var searchInput = document.getElementById('error-airport-search');
            var dropdown = document.getElementById('error-airport-dropdown');
            var selectedIndex = -1;
            var searchTimeout = null;
            
            if (!searchInput || !dropdown) return;
            
            function navigateToAirport(airportId) {
                var protocol = window.location.protocol;
                var newUrl = protocol + '//' + airportId.toLowerCase() + '.' + BASE_DOMAIN;
                window.location.href = newUrl;
            }
            
            function searchAirports(query) {
                if (!query || query.length < 2) return [];
                
                var queryLower = query.toLowerCase().trim();
                var results = [];
                
                for (var i = 0; i < ERROR_AIRPORTS.length; i++) {
                    var airport = ERROR_AIRPORTS[i];
                    var nameMatch = airport.name.toLowerCase().indexOf(queryLower) !== -1;
                    var icaoMatch = airport.icao && airport.icao.toLowerCase().indexOf(queryLower) !== -1;
                    var iataMatch = airport.iata && airport.iata.toLowerCase().indexOf(queryLower) !== -1;
                    var faaMatch = airport.faa && airport.faa.toLowerCase().indexOf(queryLower) !== -1;
                    var identifierMatch = airport.identifier.toLowerCase().indexOf(queryLower) !== -1;
                    
                    if (nameMatch || icaoMatch || iataMatch || faaMatch || identifierMatch) {
                        results.push(airport);
                    }
                }
                
                results.sort(function(a, b) {
                    var aExact = a.identifier.toLowerCase() === queryLower || 
                                (a.icao && a.icao.toLowerCase() === queryLower) ||
                                (a.iata && a.iata.toLowerCase() === queryLower);
                    var bExact = b.identifier.toLowerCase() === queryLower || 
                                (b.icao && b.icao.toLowerCase() === queryLower) ||
                                (b.iata && b.iata.toLowerCase() === queryLower);
                    
                    if (aExact && !bExact) return -1;
                    if (!aExact && bExact) return 1;
                    return a.name.localeCompare(b.name);
                });
                
                return results.slice(0, 10);
            }
            
            function populateDropdown(results) {
                dropdown.innerHTML = '';
                
                if (results.length === 0) {
                    var noResults = document.createElement('div');
                    noResults.className = 'error-airport-item no-results';
                    noResults.textContent = 'No airports found';
                    dropdown.appendChild(noResults);
                } else {
                    for (var i = 0; i < results.length; i++) {
                        (function(index) {
                            var airport = results[index];
                            var item = document.createElement('a');
                            item.href = '#';
                            item.className = 'error-airport-item';
                            item.dataset.airportId = airport.id;
                            item.dataset.index = index;
                            
                            var identifier = document.createElement('span');
                            identifier.className = 'airport-identifier';
                            identifier.textContent = airport.identifier;
                            
                            var name = document.createElement('span');
                            name.className = 'airport-name';
                            name.textContent = airport.name;
                            
                            item.appendChild(identifier);
                            item.appendChild(name);
                            
                            item.addEventListener('click', function(e) {
                                e.preventDefault();
                                navigateToAirport(airport.id);
                            });
                            
                            item.addEventListener('mouseenter', function() {
                                selectedIndex = index;
                                updateSelection();
                            });
                            
                            dropdown.appendChild(item);
                        })(i);
                    }
                }
                
                dropdown.classList.add('show');
                selectedIndex = -1;
            }
            
            function updateSelection() {
                var items = dropdown.querySelectorAll('.error-airport-item');
                for (var i = 0; i < items.length; i++) {
                    if (i === selectedIndex) {
                        items[i].classList.add('selected');
                    } else {
                        items[i].classList.remove('selected');
                    }
                }
            }
            
            function performSearch(query) {
                if (!query || query.length < 2) {
                    dropdown.classList.remove('show');
                    return;
                }
                var results = searchAirports(query);
                populateDropdown(results);
            }
            
            searchInput.addEventListener('input', function(e) {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    performSearch(e.target.value);
                }, 200);
            });
            
            searchInput.addEventListener('focus', function() {
                if (searchInput.value.length >= 2) {
                    performSearch(searchInput.value);
                }
            });
            
            searchInput.addEventListener('keydown', function(e) {
                var items = dropdown.querySelectorAll('.error-airport-item:not(.no-results)');
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (items.length > 0) {
                        selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                        updateSelection();
                        items[selectedIndex].scrollIntoView({ block: 'nearest' });
                    }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (items.length > 0) {
                        selectedIndex = Math.max(selectedIndex - 1, 0);
                        updateSelection();
                        items[selectedIndex].scrollIntoView({ block: 'nearest' });
                    }
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (selectedIndex >= 0 && selectedIndex < items.length) {
                        var airportId = items[selectedIndex].dataset.airportId;
                        if (airportId) navigateToAirport(airportId);
                    } else if (items.length === 1) {
                        var airportId = items[0].dataset.airportId;
                        if (airportId) navigateToAirport(airportId);
                    }
                } else if (e.key === 'Escape') {
                    dropdown.classList.remove('show');
                    searchInput.blur();
                }
            });
            
            document.addEventListener('click', function(e) {
                if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.classList.remove('show');
                }
            });
        }
        
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initErrorSearch);
        } else {
            initErrorSearch();
        }
    })();
    </script>
    
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
