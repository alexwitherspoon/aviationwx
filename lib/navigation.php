<?php
/**
 * Main Site Navigation Component
 * 
 * Displays navigation bar for content pages (not airport dashboards)
 * Includes: Logo, compact airport search, nav links
 * Responsive: full nav on desktop, hamburger on mobile
 */

// Load config if not already loaded
if (!function_exists('loadConfig')) {
    require_once __DIR__ . '/config.php';
}

// Get all airports for search
$navConfig = loadConfig();
$navAirports = [];
if ($navConfig && isset($navConfig['airports'])) {
    $enabledAirports = getEnabledAirports($navConfig);
    foreach ($enabledAirports as $airportId => $airport) {
        $navAirports[] = [
            'id' => $airportId,
            'name' => $airport['name'] ?? '',
            'identifier' => getPrimaryIdentifier($airportId, $airport),
            'icao' => $airport['icao'] ?? '',
            'iata' => $airport['iata'] ?? '',
            'faa' => $airport['faa'] ?? ''
        ];
    }
}

$baseDomain = getBaseDomain();
?>

<!-- Main Site Navigation -->
<nav class="site-nav">
    <div class="site-nav-container">
        <a href="https://aviationwx.org" class="site-nav-logo">
            <img src="/public/favicons/android-chrome-192x192.png" alt="AviationWX" width="36" height="36">
            <span class="site-nav-logo-text">AviationWX.org</span>
        </a>
        
        <div class="site-nav-search-container">
            <input type="text" 
                   id="site-nav-search" 
                   class="site-nav-search-input" 
                   placeholder="Search by name, ICAO, IATA, or FAA code..." 
                   autocomplete="off"
                   title="Search airports by code or name"
                   aria-label="Search airports">
            <div id="site-nav-search-dropdown" class="site-nav-search-dropdown">
                <!-- Populated by JavaScript -->
            </div>
        </div>
        
        <div class="site-nav-links">
            <a href="https://airports.aviationwx.org" class="site-nav-link">Airports</a>
            <a href="https://guides.aviationwx.org" class="site-nav-link">Guides</a>
            <a href="https://embed.aviationwx.org" class="site-nav-link">Embed</a>
            <a href="https://api.aviationwx.org" class="site-nav-link">API</a>
            <a href="https://github.com/alexwitherspoon/aviationwx.org" target="_blank" rel="noopener" class="site-nav-link">GitHub</a>
        </div>
        
        <button id="site-nav-hamburger" class="site-nav-hamburger" aria-label="Open navigation menu">
            <span class="hamburger-icon">☰</span>
        </button>
    </div>
</nav>

<!-- Mobile hamburger dropdown -->
<div id="site-nav-mobile-menu" class="site-nav-mobile-menu">
    <a href="https://airports.aviationwx.org" class="site-nav-mobile-item">
        <span class="mobile-nav-icon">✈️</span>
        <span>Airports</span>
    </a>
    <a href="https://guides.aviationwx.org" class="site-nav-mobile-item">
        <span class="mobile-nav-icon">📚</span>
        <span>Guides</span>
    </a>
    <a href="https://embed.aviationwx.org" class="site-nav-mobile-item">
        <span class="mobile-nav-icon">🔗</span>
        <span>Embed</span>
    </a>
    <a href="https://api.aviationwx.org" class="site-nav-mobile-item">
        <span class="mobile-nav-icon">📡</span>
        <span>API</span>
    </a>
    <a href="https://github.com/alexwitherspoon/aviationwx.org" target="_blank" rel="noopener" class="site-nav-mobile-item">
        <span class="mobile-nav-icon">💻</span>
        <span>GitHub</span>
    </a>
</div>

<style>
/* ============================================
   Main Site Navigation Styles
   ============================================ */

.site-nav {
    background: #f8f9fa;
    border-bottom: 1px solid #e0e0e0;
    padding: 0.75rem 1.5rem;
    position: relative;
    z-index: 900;
}

.site-nav-container {
    max-width: 1250px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    gap: 1.5rem;
}

/* Logo */
.site-nav-logo {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
    color: #333;
    font-weight: 600;
    font-size: 1.1rem;
    flex-shrink: 0;
    transition: opacity 0.2s;
}

.site-nav-logo:hover {
    opacity: 0.8;
}

.site-nav-logo img {
    width: 36px;
    height: 36px;
}

.site-nav-logo-text {
    white-space: nowrap;
}

/* Compact Airport Search */
.site-nav-search-container {
    position: relative;
    flex-shrink: 0;
}

.site-nav-search-input {
    width: 300px;
    padding: 0.4rem 0.6rem;
    font-size: 0.9rem;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: white;
    color: #333;
    transition: all 0.2s;
    box-sizing: border-box;
}

.site-nav-search-input:focus {
    outline: none;
    border-color: #0066cc;
    width: 350px;
}

.site-nav-search-input::placeholder {
    color: #999;
}

/* Search dropdown */
.site-nav-search-dropdown {
    position: absolute;
    top: calc(100% + 0.25rem);
    left: 0;
    background: white;
    border: 1px solid #ddd;
    border-radius: 6px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    min-width: 280px;
    max-height: 400px;
    overflow-y: auto;
    display: none;
    z-index: 1000;
}

.site-nav-search-dropdown.show {
    display: block;
}

.site-nav-search-item {
    display: flex;
    flex-direction: column;
    padding: 0.75rem 1rem;
    text-decoration: none;
    color: inherit;
    cursor: pointer;
    transition: background 0.15s;
    border-bottom: 1px solid #f0f0f0;
}

.site-nav-search-item:last-child {
    border-bottom: none;
}

.site-nav-search-item:hover,
.site-nav-search-item.selected {
    background: #f0f7ff;
}

.site-nav-search-item .airport-identifier {
    font-size: 1rem;
    font-weight: 700;
    color: #0066cc;
}

.site-nav-search-item .airport-name {
    font-size: 0.85rem;
    color: #555;
    margin-top: 0.15rem;
}

.site-nav-search-item.no-results {
    color: #666;
    font-style: italic;
    text-align: center;
    cursor: default;
}

.site-nav-search-item.no-results:hover {
    background: transparent;
}

/* Desktop Nav Links */
.site-nav-links {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    margin-left: auto;
}

.site-nav-link {
    color: #555;
    text-decoration: none;
    font-size: 0.95rem;
    font-weight: 500;
    transition: color 0.2s;
    white-space: nowrap;
}

.site-nav-link:hover {
    color: #0066cc;
}

/* Hamburger (hidden on desktop) */
.site-nav-hamburger {
    display: none;
    background: none;
    border: none;
    font-size: 1.5rem;
    color: #555;
    cursor: pointer;
    padding: 0.25rem 0.5rem;
    margin-left: auto;
}

/* Mobile Menu */
.site-nav-mobile-menu {
    display: none;
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    width: 280px;
    background: white;
    box-shadow: -2px 0 12px rgba(0, 0, 0, 0.15);
    z-index: 1100;
    padding: 1rem 0;
    transform: translateX(100%);
    transition: transform 0.3s ease;
}

.site-nav-mobile-menu.show {
    display: block;
    transform: translateX(0);
}

.site-nav-mobile-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem 1.5rem;
    text-decoration: none;
    color: #333;
    font-size: 1rem;
    font-weight: 500;
    transition: background 0.15s;
    border-bottom: 1px solid #f0f0f0;
    min-height: 52px; /* Touch-friendly */
}

.site-nav-mobile-item:hover {
    background: #f8f9fa;
}

.mobile-nav-icon {
    font-size: 1.3rem;
    width: 1.5rem;
    text-align: center;
    flex-shrink: 0;
}

/* Mobile overlay */
.site-nav-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1050;
}

.site-nav-overlay.show {
    display: block;
}

/* ============================================
   Responsive Styles
   ============================================ */

@media (max-width: 900px) {
    .site-nav-links {
        display: none;
    }
    
    .site-nav-hamburger {
        display: block;
    }
}

@media (max-width: 640px) {
    .site-nav {
        padding: 0.5rem 1rem;
    }
    
    .site-nav-container {
        gap: 0.75rem;
    }
    
    .site-nav-logo-text {
        display: none;
    }
    
    .site-nav-search-input {
        width: 60px;
        font-size: 0.85rem;
    }
    
    .site-nav-search-input:focus {
        width: 140px;
    }
    
    .site-nav-search-dropdown {
        min-width: 240px;
        right: 0;
        left: auto;
    }
}

/* ============================================
   Dark Mode
   ============================================ */

@media (prefers-color-scheme: dark) {
    body {
        background: #121212;
        color: #e0e0e0;
    }
}

body.dark-mode .site-nav {
    background: #1a1a1a;
    border-bottom-color: #333;
}

body.dark-mode .site-nav-logo {
    color: #e0e0e0;
}

body.dark-mode .site-nav-search-input {
    background: #2a2a2a;
    border-color: #444;
    color: #e0e0e0;
}

body.dark-mode .site-nav-search-input::placeholder {
    color: #777;
}

body.dark-mode .site-nav-search-input:focus {
    border-color: #4a9eff;
}

body.dark-mode .site-nav-search-dropdown {
    background: #2a2a2a;
    border-color: #444;
}

body.dark-mode .site-nav-search-item {
    border-bottom-color: #333;
}

body.dark-mode .site-nav-search-item:hover,
body.dark-mode .site-nav-search-item.selected {
    background: #333;
}

body.dark-mode .site-nav-search-item .airport-identifier {
    color: #4a9eff;
}

body.dark-mode .site-nav-search-item .airport-name {
    color: #aaa;
}

body.dark-mode .site-nav-link {
    color: #aaa;
}

body.dark-mode .site-nav-link:hover {
    color: #4a9eff;
}

body.dark-mode .site-nav-hamburger {
    color: #aaa;
}

body.dark-mode .site-nav-mobile-menu {
    background: #1a1a1a;
}

body.dark-mode .site-nav-mobile-item {
    color: #e0e0e0;
    border-bottom-color: #333;
}

body.dark-mode .site-nav-mobile-item:hover {
    background: #2a2a2a;
}
</style>

<script>
(function() {
    'use strict';
    
    // Airport data for search
    const AIRPORTS = <?= json_encode($navAirports) ?>;
    const BASE_DOMAIN = <?= json_encode($baseDomain) ?>;
    
    // Initialize navigation
    function initSiteNavigation() {
        const searchInput = document.getElementById('site-nav-search');
        const searchDropdown = document.getElementById('site-nav-search-dropdown');
        const hamburger = document.getElementById('site-nav-hamburger');
        const mobileMenu = document.getElementById('site-nav-mobile-menu');
        
        if (!searchInput || !searchDropdown) return;
        
        let selectedIndex = -1;
        let searchTimeout = null;
        
        // Navigate to airport
        function navigateToAirport(airportId) {
            const protocol = window.location.protocol;
            const newUrl = `${protocol}//${airportId.toLowerCase()}.${BASE_DOMAIN}`;
            window.location.href = newUrl;
        }
        
        // Search airports
        function searchAirports(query) {
            if (!query || query.length < 2) {
                return [];
            }
            
            const queryLower = query.toLowerCase().trim();
            const results = [];
            
            for (const airport of AIRPORTS) {
                const nameMatch = airport.name.toLowerCase().indexOf(queryLower) !== -1;
                const icaoMatch = airport.icao && airport.icao.toLowerCase().indexOf(queryLower) !== -1;
                const iataMatch = airport.iata && airport.iata.toLowerCase().indexOf(queryLower) !== -1;
                const faaMatch = airport.faa && airport.faa.toLowerCase().indexOf(queryLower) !== -1;
                const identifierMatch = airport.identifier.toLowerCase().indexOf(queryLower) !== -1;
                
                if (nameMatch || icaoMatch || iataMatch || faaMatch || identifierMatch) {
                    results.push(airport);
                }
            }
            
            // Sort: exact matches first
            results.sort((a, b) => {
                const aExact = a.identifier.toLowerCase() === queryLower || 
                              (a.icao && a.icao.toLowerCase() === queryLower) ||
                              (a.iata && a.iata.toLowerCase() === queryLower);
                const bExact = b.identifier.toLowerCase() === queryLower || 
                              (b.icao && b.icao.toLowerCase() === queryLower) ||
                              (b.iata && b.iata.toLowerCase() === queryLower);
                
                if (aExact && !bExact) return -1;
                if (!aExact && bExact) return 1;
                
                return a.name.localeCompare(b.name);
            });
            
            return results.slice(0, 10);
        }
        
        // Populate dropdown
        function populateDropdown(results) {
            searchDropdown.innerHTML = '';
            
            if (results.length === 0) {
                const noResults = document.createElement('div');
                noResults.className = 'site-nav-search-item no-results';
                noResults.textContent = 'No airports found';
                searchDropdown.appendChild(noResults);
            } else {
                results.forEach((airport, index) => {
                    const item = document.createElement('a');
                    item.href = '#';
                    item.className = 'site-nav-search-item';
                    item.dataset.airportId = airport.id;
                    item.dataset.index = index;
                    
                    const identifier = document.createElement('span');
                    identifier.className = 'airport-identifier';
                    identifier.textContent = airport.identifier;
                    
                    const name = document.createElement('span');
                    name.className = 'airport-name';
                    name.textContent = airport.name;
                    
                    item.appendChild(identifier);
                    item.appendChild(name);
                    
                    item.addEventListener('click', (e) => {
                        e.preventDefault();
                        navigateToAirport(airport.id);
                    });
                    
                    item.addEventListener('mouseenter', () => {
                        selectedIndex = index;
                        updateSelection();
                    });
                    
                    searchDropdown.appendChild(item);
                });
            }
            
            searchDropdown.classList.add('show');
            selectedIndex = -1;
        }
        
        function updateSelection() {
            const items = searchDropdown.querySelectorAll('.site-nav-search-item:not(.no-results)');
            items.forEach((item, index) => {
                if (index === selectedIndex) {
                    item.classList.add('selected');
                } else {
                    item.classList.remove('selected');
                }
            });
        }
        
        function performSearch(query) {
            if (!query || query.length < 2) {
                searchDropdown.classList.remove('show');
                return;
            }
            
            const results = searchAirports(query);
            populateDropdown(results);
        }
        
        // Search input events
        searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                performSearch(e.target.value);
            }, 200);
        });
        
        searchInput.addEventListener('focus', () => {
            if (searchInput.value.length >= 2) {
                performSearch(searchInput.value);
            }
        });
        
        searchInput.addEventListener('keydown', (e) => {
            const items = searchDropdown.querySelectorAll('.site-nav-search-item:not(.no-results)');
            
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
                    const airportId = items[selectedIndex].dataset.airportId;
                    if (airportId) {
                        navigateToAirport(airportId);
                    }
                } else if (items.length === 1) {
                    const airportId = items[0].dataset.airportId;
                    if (airportId) {
                        navigateToAirport(airportId);
                    }
                }
            } else if (e.key === 'Escape') {
                searchDropdown.classList.remove('show');
                searchInput.blur();
            }
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !searchDropdown.contains(e.target)) {
                searchDropdown.classList.remove('show');
            }
        });
        
        // Hamburger menu
        if (hamburger && mobileMenu) {
            // Create overlay
            const overlay = document.createElement('div');
            overlay.className = 'site-nav-overlay';
            document.body.appendChild(overlay);
            
            hamburger.addEventListener('click', (e) => {
                e.stopPropagation();
                mobileMenu.classList.toggle('show');
                overlay.classList.toggle('show');
                document.body.style.overflow = mobileMenu.classList.contains('show') ? 'hidden' : '';
            });
            
            overlay.addEventListener('click', () => {
                mobileMenu.classList.remove('show');
                overlay.classList.remove('show');
                document.body.style.overflow = '';
            });
            
            // Close on ESC
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && mobileMenu.classList.contains('show')) {
                    mobileMenu.classList.remove('show');
                    overlay.classList.remove('show');
                    document.body.style.overflow = '';
                }
            });
        }
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSiteNavigation);
    } else {
        initSiteNavigation();
    }
})();
</script>
