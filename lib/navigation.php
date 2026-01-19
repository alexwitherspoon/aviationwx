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

// In single-airport mode, don't show main navigation
// Single-airport installs use only the dashboard UI
if (isSingleAirportMode()) {
    return;
}

// Get all airports for search
$navConfig = loadConfig();
$navAirports = [];
if ($navConfig && isset($navConfig['airports'])) {
    // Only show listed airports in navigation search (excludes unlisted)
    $listedAirports = getListedAirports($navConfig);
    foreach ($listedAirports as $airportId => $airport) {
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
        
        <div class="site-nav-search-wrapper">
            <input type="text" 
                   id="site-nav-airport-search" 
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
            <div class="site-nav-dropdown">
                <button class="site-nav-link site-nav-dropdown-trigger" aria-expanded="false">
                    Developers <span class="dropdown-arrow">▼</span>
                </button>
                <div class="site-nav-dropdown-menu">
                    <a href="https://embed.aviationwx.org" class="site-nav-dropdown-item">
                        <span class="dropdown-item-icon">🔗</span>
                        <span>Embed Generator</span>
                    </a>
                    <a href="https://api.aviationwx.org" class="site-nav-dropdown-item">
                        <span class="dropdown-item-icon">📡</span>
                        <span>API Documentation</span>
                    </a>
                    <div class="site-nav-dropdown-divider">Open Source</div>
                    <a href="https://github.com/alexwitherspoon/aviationwx.org" target="_blank" rel="noopener" class="site-nav-dropdown-item">
                        <span class="dropdown-item-icon">🛬</span>
                        <span>Platform Source Code</span>
                    </a>
                    <a href="https://github.com/alexwitherspoon/aviationwx-bridge" target="_blank" rel="noopener" class="site-nav-dropdown-item">
                        <span class="dropdown-item-icon">📷</span>
                        <span>Camera Bridge Tool</span>
                    </a>
                </div>
            </div>
            <a href="https://aviationwx.org#contact" class="site-nav-link">Contact</a>
            <a href="https://status.aviationwx.org" class="site-nav-link">Status</a>
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
    
    <div class="site-nav-mobile-divider">Developers</div>
    
    <a href="https://embed.aviationwx.org" class="site-nav-mobile-item site-nav-mobile-indent">
        <span class="dropdown-item-icon">🔗</span>
        <span>Embed Generator</span>
    </a>
    <a href="https://api.aviationwx.org" class="site-nav-mobile-item site-nav-mobile-indent">
        <span class="dropdown-item-icon">📡</span>
        <span>API Documentation</span>
    </a>
    
    <div class="site-nav-mobile-divider">Open Source</div>
    
    <a href="https://github.com/alexwitherspoon/aviationwx.org" target="_blank" rel="noopener" class="site-nav-mobile-item site-nav-mobile-indent">
        <span class="dropdown-item-icon">🛬</span>
        <span>Platform Source Code</span>
    </a>
    <a href="https://github.com/alexwitherspoon/aviationwx-bridge" target="_blank" rel="noopener" class="site-nav-mobile-item site-nav-mobile-indent">
        <span class="dropdown-item-icon">📷</span>
        <span>Camera Bridge Tool</span>
    </a>
    
    <a href="https://aviationwx.org#contact" class="site-nav-mobile-item">
        <span class="mobile-nav-icon">💬</span>
        <span>Contact</span>
    </a>
    <a href="https://status.aviationwx.org" class="site-nav-mobile-item">
        <span class="mobile-nav-icon">📊</span>
        <span>Status</span>
    </a>
</div>

<script>
(function() {
    'use strict';
    
    // Airport data for search
    const AIRPORTS = <?= json_encode($navAirports) ?>;
    const BASE_DOMAIN = <?= json_encode($baseDomain) ?>;
    
    // Global dropdown close functions exposed on window object
    window.aviationwxNav = window.aviationwxNav || {};
    
    // Initialize navigation
    function initSiteNavigation() {
        const searchInput = document.getElementById('site-nav-airport-search');
        const searchDropdown = document.getElementById('site-nav-search-dropdown');
        const hamburger = document.getElementById('site-nav-hamburger');
        const mobileMenu = document.getElementById('site-nav-mobile-menu');
        const developersDropdown = document.querySelector('.site-nav-dropdown');
        const developersButton = document.querySelector('.site-nav-dropdown-trigger');
        const developersMenu = document.querySelector('.site-nav-dropdown-menu');
        
        if (!searchInput || !searchDropdown) return;
        
        let selectedIndex = -1;
        let searchTimeout = null;
        let overlay = null;
        
        // Expose close functions globally for coordination
        window.aviationwxNav.closeSearchDropdown = function() {
            searchDropdown.classList.remove('show');
        };
        
        window.aviationwxNav.closeDevelopersDropdown = function() {
            if (developersMenu) {
                developersMenu.classList.remove('show');
                if (developersButton) {
                    developersButton.setAttribute('aria-expanded', 'false');
                }
            }
        };
        
        window.aviationwxNav.closeMobileMenu = function() {
            if (mobileMenu && overlay) {
                mobileMenu.classList.remove('show');
                overlay.classList.remove('show');
                document.body.style.overflow = '';
            }
        };
        
        window.aviationwxNav.closeAllNavDropdowns = function() {
            window.aviationwxNav.closeSearchDropdown();
            window.aviationwxNav.closeDevelopersDropdown();
            window.aviationwxNav.closeMobileMenu();
        };
        
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
                        
                        // Dispatch custom event for map integration
                        const event = new CustomEvent('airportSearchSelect', {
                            detail: { airportId: airport.id, airport: airport }
                        });
                        document.dispatchEvent(event);
                        
                        // Navigate after brief delay (allows map to respond)
                        setTimeout(() => {
                            navigateToAirport(airport.id);
                        }, 800);
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
                
                // Close other nav dropdowns when search opens
                window.aviationwxNav.closeDevelopersDropdown();
                window.aviationwxNav.closeMobileMenu();
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
            
            // Close developers dropdown when clicking outside
            if (developersDropdown && developersMenu && !developersDropdown.contains(e.target)) {
                developersMenu.classList.remove('show');
                if (developersButton) {
                    developersButton.setAttribute('aria-expanded', 'false');
                }
            }
        });
        
        // Developers dropdown
        if (developersButton && developersMenu) {
            developersButton.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const isOpen = developersMenu.classList.contains('show');
                
                if (isOpen) {
                    developersMenu.classList.remove('show');
                    developersButton.setAttribute('aria-expanded', 'false');
                } else {
                    developersMenu.classList.add('show');
                    developersButton.setAttribute('aria-expanded', 'true');
                    // Close search dropdown if open
                    searchDropdown.classList.remove('show');
                    window.aviationwxNav.closeMobileMenu();
                }
            });
        }
        
        // Hamburger menu
        if (hamburger && mobileMenu) {
            // Create overlay
            overlay = document.createElement('div');
            overlay.className = 'site-nav-overlay';
            document.body.appendChild(overlay);
            
            hamburger.addEventListener('click', (e) => {
                e.stopPropagation();
                const isOpen = mobileMenu.classList.contains('show');
                
                if (isOpen) {
                    mobileMenu.classList.remove('show');
                    overlay.classList.remove('show');
                    document.body.style.overflow = '';
                } else {
                    mobileMenu.classList.add('show');
                    overlay.classList.add('show');
                    document.body.style.overflow = 'hidden';
                    
                    // Close other dropdowns when hamburger opens
                    searchDropdown.classList.remove('show');
                    window.aviationwxNav.closeDevelopersDropdown();
                }
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
