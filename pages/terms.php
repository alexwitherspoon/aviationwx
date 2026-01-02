<?php
/**
 * Terms of Service Page
 * 
 * Accessible at terms.aviationwx.org
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/seo.php';

// SEO variables
$pageTitle = 'Terms of Service - AviationWX.org';
$pageDescription = 'Terms of Service for AviationWX.org - Free aviation weather dashboards and API for pilots and airports.';
$canonicalUrl = 'https://terms.aviationwx.org';

// Set cache headers
header('Cache-Control: public, max-age=3600, s-maxage=3600, stale-while-revalidate=86400');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
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
    echo generateEnhancedMetaTags($pageDescription, 'terms of service, legal, aviation weather, aviationwx');
    echo "\n    ";
    
    // Canonical URL
    echo generateCanonicalTag($canonicalUrl);
    echo "\n    ";
    
    // Open Graph and Twitter Card tags
    echo generateSocialMetaTags($pageTitle, $pageDescription, $canonicalUrl);
    ?>
    
    <link rel="stylesheet" href="public/css/styles.css">
    <style>
        .terms-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }
        
        .terms-header {
            text-align: center;
            padding: 2rem;
            background: linear-gradient(135deg, #1a1a1a 0%, #0066cc 100%);
            color: white;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        
        .terms-header h1 {
            font-size: 2rem;
            margin: 0 0 0.5rem 0;
        }
        
        .terms-header p {
            opacity: 0.9;
            margin: 0;
        }
        
        .terms-content {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .terms-content h2 {
            color: #0066cc;
            font-size: 1.4rem;
            margin-top: 2rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e9ecef;
        }
        
        .terms-content h2:first-child {
            margin-top: 0;
        }
        
        .terms-content h3 {
            color: #333;
            font-size: 1.1rem;
            margin-top: 1.5rem;
            margin-bottom: 0.75rem;
        }
        
        .terms-content p {
            line-height: 1.7;
            color: #444;
            margin-bottom: 1rem;
        }
        
        .terms-content ul, .terms-content ol {
            line-height: 1.8;
            color: #444;
            margin: 1rem 0;
            padding-left: 1.5rem;
        }
        
        .terms-content li {
            margin-bottom: 0.5rem;
        }
        
        .terms-content a {
            color: #0066cc;
            text-decoration: none;
        }
        
        .terms-content a:hover {
            text-decoration: underline;
        }
        
        .highlight-box {
            background: #f8f9fa;
            border-left: 4px solid #0066cc;
            padding: 1rem 1.5rem;
            margin: 1.5rem 0;
            border-radius: 0 6px 6px 0;
        }
        
        .highlight-box.warning {
            border-left-color: #dc3545;
            background: #fff5f5;
        }
        
        .highlight-box.info {
            border-left-color: #28a745;
            background: #f0fff4;
        }
        
        .highlight-box p {
            margin: 0;
        }
        
        .last-updated {
            text-align: center;
            color: #666;
            font-size: 0.9rem;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #e9ecef;
        }
        
        .footer {
            margin-top: 2rem;
        }
        
        /* ============================================
           Dark Mode Overrides for Terms
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
        
        body.dark-mode .terms-header {
            background: linear-gradient(135deg, #0a0a0a 0%, #003d7a 100%);
        }
        
        body.dark-mode .terms-content {
            background: #1e1e1e;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        body.dark-mode .terms-content h2 {
            color: #4a9eff;
            border-bottom-color: #333;
        }
        
        body.dark-mode .terms-content h3 {
            color: #e0e0e0;
        }
        
        body.dark-mode .terms-content p {
            color: #a0a0a0;
        }
        
        body.dark-mode .terms-content ul,
        body.dark-mode .terms-content ol {
            color: #a0a0a0;
        }
        
        body.dark-mode .terms-content a {
            color: #4a9eff;
        }
        
        body.dark-mode .highlight-box {
            background: #1a1a1a;
            border-left-color: #4a9eff;
        }
        
        body.dark-mode .highlight-box.warning {
            background: #1a0a0a;
            border-left-color: #ef4444;
        }
        
        body.dark-mode .highlight-box.info {
            background: #0a1a0a;
            border-left-color: #4ade80;
        }
        
        body.dark-mode .last-updated {
            color: #707070;
            border-top-color: #333;
        }
        
        body.dark-mode .footer {
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
    <main>
    <div class="container">
        <div class="terms-container">
            <div class="terms-header">
                <h1>Terms of Service</h1>
                <p>AviationWX.org</p>
            </div>
            
            <div class="terms-content">
                <h2>1. About This Service</h2>
                <p>
                    AviationWX.org ("Service", "we", "us", "our") provides free aviation weather dashboards 
                    and a public API for pilots, airport operators, and the aviation community. The Service 
                    aggregates weather data, webcam imagery, and airport information to help pilots make 
                    informed decisions.
                </p>
                
                <div class="highlight-box info">
                    <p><strong>This project is operated without commercial interest.</strong> There are no fees, 
                    subscriptions, or advertisements. All revenue (if any) is reinvested into hosting and 
                    operational costs. The project is entirely open source and operated by volunteers with 
                    no intention of generating profit.</p>
                </div>
                
                <h2>2. Acceptance of Terms</h2>
                <p>
                    By accessing or using AviationWX.org, including the website, airport dashboards, and API, 
                    you agree to be bound by these Terms of Service. If you do not agree to these terms, 
                    please do not use the Service.
                </p>
                
                <h2>3. Important Safety Disclaimer</h2>
                <div class="highlight-box warning">
                    <p><strong>⚠️ CRITICAL: Data provided by AviationWX.org is for advisory and informational 
                    purposes only.</strong></p>
                </div>
                <ul>
                    <li>Weather data, webcam images, and other information displayed on this Service should 
                    <strong>NOT</strong> be used as the sole basis for flight planning or safety-critical decisions.</li>
                    <li>Always consult official weather sources (such as <a href="https://aviationweather.gov" target="_blank" rel="noopener">Aviation Weather Center</a>, 
                    <a href="https://1800wxbrief.com" target="_blank" rel="noopener">1800WxBrief</a>, or local Flight Service) 
                    before conducting any flight operations.</li>
                    <li>Data may be delayed, inaccurate, incomplete, or unavailable at any time.</li>
                    <li>Webcam images may not reflect current conditions due to latency, camera issues, or network problems.</li>
                    <li>Weather conditions can change rapidly and may differ from displayed information.</li>
                </ul>
                <p>
                    <strong>The pilot-in-command is solely responsible for evaluating weather conditions and 
                    making go/no-go decisions.</strong>
                </p>
                
                <h2>4. Data Sources, Attribution, and Accuracy</h2>
                <p>
                    AviationWX.org aggregates data from various sources. We gratefully acknowledge these upstream 
                    data providers:
                </p>
                
                <h3>Public Domain Data Sources</h3>
                <ul>
                    <li><strong><a href="https://aviationweather.gov" target="_blank" rel="noopener">Aviation Weather Center (aviationweather.gov)</a></strong> - 
                    METAR, TAF, and other official aviation weather products. Public domain as a work of the U.S. Government.</li>
                    <li><strong><a href="https://www.weather.gov" target="_blank" rel="noopener">National Weather Service (NWS) / NOAA</a></strong> - 
                    Weather forecasts, observations, and alerts. Public domain as a work of the U.S. Government.</li>
                    <li><strong><a href="https://ourairports.com" target="_blank" rel="noopener">OurAirports</a></strong> - 
                    Airport identifier data (ICAO, IATA, FAA codes) for 40,000+ airports worldwide. 
                    <a href="https://davidmegginson.github.io/ourairports-data/" target="_blank" rel="noopener">Data</a> 
                    released to the Public Domain. We thank David Megginson and the OurAirports community for maintaining this resource.</li>
                </ul>
                
                <h3>Partner-Contributed Data</h3>
                <ul>
                    <li>Airport-provided weather stations (e.g., Davis Instruments, Tempest, Ambient Weather)</li>
                    <li>Airport-provided webcam systems</li>
                    <li>Third-party weather data providers that contribute to NOAA forecasting models</li>
                </ul>
                
                <p>
                    We make reasonable efforts to ensure data accuracy and availability, but we do not guarantee 
                    the accuracy, completeness, timeliness, or reliability of any data displayed on the Service.
                </p>
                
                <h3>Data Redistribution Rights</h3>
                <p>
                    The data available through AviationWX.org comes from sources with different redistribution rights:
                </p>
                <ul>
                    <li><strong>Public Domain Data:</strong> Weather data originating from the National Weather Service (NWS), 
                    NOAA, METAR reports, and other U.S. federal government sources is in the public domain and may be freely 
                    used and redistributed without restriction.</li>
                    <li><strong>Partner-Contributed Data:</strong> Webcam images and weather station data provided by 
                    airport partners remain the property of those partners. When airport partners provide API credentials 
                    for their weather stations (such as Tempest, Davis WeatherLink, Ambient Weather, or similar systems), 
                    they authorize AviationWX.org to access, display, cache, and redistribute their data through our 
                    website and API for the benefit of the aviation community.</li>
                </ul>
                <p>
                    Data accessed through our public API may be used and redistributed in accordance with our 
                    <a href="https://api.aviationwx.org">API Terms</a>, provided proper attribution is given to 
                    AviationWX.org and, where applicable, the original data sources.
                </p>
                
                <h3>Third-Party Weather Services</h3>
                <p>
                    AviationWX.org integrates with various third-party weather station platforms and APIs. While airport 
                    partners authorize us to access and display their station data, the underlying platforms 
                    (Tempest/WeatherFlow, Davis WeatherLink, Ambient Weather, SynopticData, etc.) have their own terms 
                    of service. Airport partners are responsible for ensuring their use of these platforms permits 
                    data sharing with services like AviationWX.org. We believe our non-commercial, safety-focused use 
                    is consistent with typical personal weather station terms, but we recommend partners review their 
                    platform's terms if they have concerns.
                </p>
                
                <h2>5. API Usage</h2>
                <p>
                    The AviationWX Public API is provided free of charge subject to the following terms:
                </p>
                <ul>
                    <li><strong>Rate Limits:</strong> API access is subject to rate limits. Exceeding these limits 
                    may result in temporary or permanent restriction of access.</li>
                    <li><strong>Attribution Required:</strong> Applications using the API must display attribution 
                    to AviationWX.org as specified in the <a href="https://api.aviationwx.org">API documentation</a>.</li>
                    <li><strong>No Abuse:</strong> Automated scraping, excessive requests, or any use that degrades 
                    the Service for other users is prohibited.</li>
                    <li><strong>Commercial Use:</strong> Contact us for commercial use cases or if you require 
                    higher rate limits.</li>
                </ul>
                
                <h2>6. User Conduct</h2>
                <p>When using the Service, you agree not to:</p>
                <ul>
                    <li>Attempt to disrupt, overload, or interfere with the Service</li>
                    <li>Access the Service through automated means (bots, scrapers) except via the official API</li>
                    <li>Attempt to bypass rate limits or security measures</li>
                    <li>Misrepresent data from the Service as official or certified weather information</li>
                    <li>Use the Service for any illegal purpose</li>
                </ul>
                
                <h2>7. Open Source</h2>
                <p>
                    AviationWX.org is open source software, released under the terms specified in our 
                    <a href="https://github.com/alexwitherspoon/aviationwx.org" target="_blank" rel="noopener">GitHub repository</a>. 
                    You are welcome to:
                </p>
                <ul>
                    <li>View, fork, and contribute to the source code</li>
                    <li>Deploy your own instance of the software</li>
                    <li>Report issues and suggest improvements</li>
                </ul>
                <p>
                    Contributions are subject to our <a href="https://github.com/alexwitherspoon/aviationwx.org/blob/main/CONTRIBUTING.md" target="_blank" rel="noopener">Contributing Guidelines</a> 
                    and <a href="https://github.com/alexwitherspoon/aviationwx.org/blob/main/CODE_OF_CONDUCT.md" target="_blank" rel="noopener">Code of Conduct</a>.
                </p>
                
                <h2>8. Privacy</h2>
                <p>
                    AviationWX.org is committed to user privacy:
                </p>
                <ul>
                    <li><strong>No User Accounts:</strong> We do not require user registration or collect personal information.</li>
                    <li><strong>No Tracking:</strong> We do not use third-party analytics, advertising trackers, or cookies for tracking purposes.</li>
                    <li><strong>Server Logs:</strong> Standard server logs (IP addresses, request timestamps) may be retained 
                    temporarily for security and operational purposes.</li>
                    <li><strong>API Keys:</strong> If you request an API key, we collect only the information necessary 
                    to provide and manage API access.</li>
                </ul>
                
                <h2>9. Limitation of Liability</h2>
                <p>
                    <strong>THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE" WITHOUT WARRANTIES OF ANY KIND, 
                    EITHER EXPRESS OR IMPLIED.</strong>
                </p>
                <p>
                    To the fullest extent permitted by law, AviationWX.org, its operators, contributors, and 
                    partners shall not be liable for any:
                </p>
                <ul>
                    <li>Direct, indirect, incidental, special, consequential, or punitive damages</li>
                    <li>Loss of profits, data, or goodwill</li>
                    <li>Personal injury or property damage</li>
                    <li>Any damages arising from reliance on information provided by the Service</li>
                </ul>
                <p>
                    <strong>Your use of data from this Service for flight planning or safety decisions is 
                    entirely at your own risk.</strong>
                </p>
                
                <h2>10. Service Availability</h2>
                <p>
                    We strive to maintain high availability, but we do not guarantee uninterrupted access. 
                    The Service may be:
                </p>
                <ul>
                    <li>Temporarily unavailable due to maintenance, updates, or technical issues</li>
                    <li>Modified, suspended, or discontinued at any time without notice</li>
                    <li>Affected by third-party data source outages beyond our control</li>
                </ul>
                
                <h2>11. Airport Partners</h2>
                <p>
                    Airports participating in AviationWX.org retain ownership of their equipment (cameras, 
                    weather stations) and the data generated by that equipment. By partnering with AviationWX.org, 
                    airports grant us a non-exclusive license to display, cache, and distribute their data 
                    through our website and API for the benefit of the aviation community.
                </p>
                <p>
                    We host and maintain the software dashboard at no cost. Partner airports may request 
                    modification of their data sharing preferences or removal from the Service at any time 
                    by contacting us.
                </p>
                
                <h2>12. Changes to Terms</h2>
                <p>
                    We may update these Terms of Service from time to time. Material changes will be noted 
                    with an updated "Last Updated" date. Continued use of the Service after changes constitutes 
                    acceptance of the new terms.
                </p>
                
                <h2>13. Contact</h2>
                <p>
                    For questions about these Terms of Service or the Service in general, please contact:
                </p>
                <ul>
                    <li>Email: <a href="mailto:contact@aviationwx.org">contact@aviationwx.org</a></li>
                    <li>GitHub: <a href="https://github.com/alexwitherspoon/aviationwx.org/issues" target="_blank" rel="noopener">Open an Issue</a></li>
                </ul>
                
                <div class="last-updated">
                    <p><strong>Last Updated:</strong> <?= date('F j, Y') ?></p>
                </div>
            </div>
            
            <footer class="footer">
                <p>
                    &copy; <?= date('Y') ?> <a href="https://aviationwx.org">AviationWX.org</a> • 
                    <a href="https://aviationwx.org/airports">Airports</a> • 
                    <a href="https://guides.aviationwx.org">Guides</a> • 
                    <a href="https://aviationwx.org#about-the-project">Built for pilots, by pilots</a> • 
                    <a href="https://github.com/alexwitherspoon/aviationwx.org" target="_blank" rel="noopener">Open Source<?php $gitSha = getGitSha(); echo $gitSha ? ' - ' . htmlspecialchars($gitSha) : ''; ?></a> • 
                    <a href="https://terms.aviationwx.org">Terms of Service</a> • 
                    <a href="https://api.aviationwx.org">API</a> • 
                    <a href="https://status.aviationwx.org">Status</a>
                </p>
            </footer>
        </div>
    </div>
    </main>
</body>
</html>

