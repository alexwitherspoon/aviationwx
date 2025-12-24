<?php
// Load SEO utilities and config
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/seo.php';

// Encode email body for mailto: URLs with readable formatting
// Uses rawurlencode (%20 for spaces) and %0A for newlines to ensure proper display in email clients
function encodeEmailBody($text) {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = explode("\n", $text);
    $encodedLines = array_map('rawurlencode', $lines);
    return implode('%0A', $encodedLines);
}

$requestedAirportId = isset($requestedAirportId) ? strtoupper(trim($requestedAirportId)) : '';
$isValidIcaoFormat = isValidIcaoFormat($requestedAirportId);

$config = loadConfig();
$isRealAirport = false;
$similarAirports = [];
$displayAirportId = $requestedAirportId; // The airport code to display in messages

// Check if identifier is a valid ICAO code
if ($isValidIcaoFormat && !empty($requestedAirportId)) {
    $isRealAirport = isValidRealAirport($requestedAirportId, $config);
}

// If not found as ICAO, check if it's a valid IATA or FAA code and find corresponding ICAO
if (!$isRealAirport && !empty($requestedAirportId)) {
    // Use generalized function to get ICAO from any identifier type (IATA, FAA, or ICAO)
    $icaoFromIdentifier = getIcaoFromIdentifier($requestedAirportId);
    if ($icaoFromIdentifier !== null) {
        // Found ICAO for this identifier, check if it's a real airport
        $isRealAirport = isValidRealAirport($icaoFromIdentifier, $config);
            if ($isRealAirport) {
                // Use the ICAO code for display since that's the standard identifier
            $displayAirportId = $icaoFromIdentifier;
        }
    }
}

// Only show "Did you mean?" suggestions for typos or invalid codes
// Valid real airports (even if not in our network) get the opportunity section instead
if (!$isRealAirport && !empty($requestedAirportId) && $config !== null) {
    $similarAirports = findSimilarAirports($requestedAirportId, $config, 5);
}

$baseUrl = getBaseUrl();

// SEO variables - dynamic title with airport code when available
if ($isRealAirport && !empty($displayAirportId)) {
    $pageTitle = htmlspecialchars($displayAirportId) . ' Not Found - AviationWX.org';
} elseif ($isValidIcaoFormat && !empty($requestedAirportId)) {
    $pageTitle = htmlspecialchars($requestedAirportId) . ' Not Found - AviationWX.org';
} else {
    $pageTitle = 'Airport Not Found - AviationWX.org';
}

$pageDescription = $isValidIcaoFormat 
    ? "{$requestedAirportId} isn't part of the AviationWX network yet. Help bring weather dashboards to this airport!"
    : 'This airport isn\'t part of the AviationWX network yet. Help bring weather dashboards to more airports!';
$canonicalUrl = getCanonicalUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    
    <?php
    // Favicon and icon tags
    echo generateFaviconTags();
    echo "\n    ";
    
    // Enhanced meta tags
    echo generateEnhancedMetaTags($pageDescription, 'airport not found, request airport, add airport, aviation weather');
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
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }
        .error-hero {
            text-align: center;
            padding: 3rem 2rem;
            background: linear-gradient(135deg, #1a1a1a 0%, #0066cc 100%);
            color: white;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        .error-code {
            font-size: 4rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            opacity: 0.9;
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
        .airport-code-highlight {
            font-size: 1.5rem;
            font-weight: bold;
            background: rgba(255,255,255,0.2);
            padding: 0.5rem 1rem;
            border-radius: 4px;
            display: inline-block;
            margin: 0.5rem 0;
        }
        .section {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        .section h2 {
            color: #0066cc;
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }
        .section h3 {
            color: #333;
            margin-top: 1.5rem;
            margin-bottom: 0.75rem;
            font-size: 1.2rem;
        }
        .suggestions-list {
            list-style: none;
            padding: 0;
            margin: 1rem 0;
        }
        .suggestion-item {
            background: #f8f9fa;
            padding: 1rem;
            margin-bottom: 0.75rem;
            border-radius: 6px;
            border-left: 4px solid #0066cc;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .suggestion-item:hover {
            transform: translateX(5px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .suggestion-item a {
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .suggestion-icao {
            font-size: 1.25rem;
            font-weight: bold;
            color: #0066cc;
            margin-bottom: 0.25rem;
        }
        .suggestion-name {
            color: #333;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        .suggestion-address {
            color: #666;
            font-size: 0.9rem;
        }
        .cta-box {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 6px;
            border-left: 5px solid #0066cc;
            margin: 1.5rem 0;
        }
        .cta-box h3 {
            margin-top: 0;
            color: #0066cc;
        }
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
            transition: all 0.2s;
            margin: 0.5rem 0.5rem 0.5rem 0;
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
        .contact-info {
            background: #e9ecef;
            padding: 1.5rem;
            border-radius: 6px;
            margin: 1.5rem 0;
            text-align: center;
        }
        .contact-info a {
            color: #0066cc;
            text-decoration: none;
            font-weight: 500;
            font-size: 1.1rem;
        }
        .contact-info a:hover {
            text-decoration: underline;
        }
        .steps-list {
            list-style: none;
            padding: 0;
            margin: 1rem 0;
        }
        .steps-list li {
            padding: 1rem;
            margin-bottom: 0.75rem;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 4px solid #28a745;
            position: relative;
            padding-left: 3rem;
        }
        .steps-list li::before {
            content: counter(step-counter);
            counter-increment: step-counter;
            position: absolute;
            left: 0.75rem;
            top: 1rem;
            background: #28a745;
            color: white;
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
        }
        .steps-list {
            counter-reset: step-counter;
        }
        .examples-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1.5rem 0;
        }
        .example-card {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 6px;
            text-align: center;
            border: 2px solid transparent;
            transition: all 0.2s;
        }
        .example-card:hover {
            border-color: #0066cc;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .example-card a {
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .example-icao {
            font-size: 1.5rem;
            font-weight: bold;
            color: #0066cc;
            margin-bottom: 0.5rem;
        }
        .example-name {
            color: #333;
            font-size: 0.9rem;
        }
        ul {
            line-height: 1.8;
            margin: 1rem 0;
        }
        .no-suggestions {
            text-align: center;
            padding: 2rem;
            color: #666;
            font-style: italic;
        }
        .home-link {
            text-align: center;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #ddd;
        }
        /* Airport Search Styles */
        .airport-search-section {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
    </style>
</head>
<body>
    <div class="container">
        <div class="error-container">
            <div class="error-hero">
                <div class="error-code">404</div>
                <h1>
                    <?php if ($isRealAirport): ?>
                        <?= htmlspecialchars($displayAirportId) ?> Isn't Online Yet
                    <?php else: ?>
                        Airport Not Found
                    <?php endif; ?>
                </h1>
                <p>
                    <?php if ($isRealAirport): ?>
                        <span class="airport-code-highlight"><?= htmlspecialchars($displayAirportId) ?></span> is a real airport, but it's not yet part of the AviationWX network.
                    <?php else: ?>
                        <?php if ($isValidIcaoFormat): ?>
                            <span class="airport-code-highlight"><?= htmlspecialchars($requestedAirportId) ?></span> doesn't appear to be a valid airport code.
                        <?php else: ?>
                            The airport code you're looking for doesn't appear to be valid.
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
                <?php if ($isRealAirport): ?>
                <p style="margin-top: 1rem; font-size: 1rem; opacity: 0.9;">
                    But it could be! Here's how you can help bring weather dashboards to <?= htmlspecialchars($displayAirportId) ?>.
                </p>
                <?php endif; ?>
            </div>

            <!-- Airport Search -->
            <div class="airport-search-section">
                <h2>Search Participating Airports</h2>
                <p style="text-align: center; color: #666; margin-bottom: 1rem;">Find an airport that's already in our network:</p>
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

            <?php if (!$isRealAirport && !empty($similarAirports)): ?>
            <div class="section">
                <h2>Did You Mean One of These Airports?</h2>
                <p>We found some similar airports that might be what you're looking for:</p>
                <ul class="suggestions-list">
                    <?php foreach ($similarAirports as $suggestion): ?>
                    <li class="suggestion-item">
                        <a href="https://<?= htmlspecialchars($suggestion['id']) ?>.aviationwx.org">
                            <div class="suggestion-icao"><?= htmlspecialchars($suggestion['icao']) ?></div>
                            <div class="suggestion-name"><?= htmlspecialchars($suggestion['name']) ?></div>
                            <?php if (!empty($suggestion['address'])): ?>
                            <div class="suggestion-address"><?= htmlspecialchars($suggestion['address']) ?></div>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <p style="margin-top: 1.5rem; color: #666; font-size: 0.95rem;">
                    If none of these match, you might be looking for an airport that's not yet in our network. 
                    Browse the full list of <a href="https://aviationwx.org#participating-airports" style="color: #0066cc;">participating airports</a> or 
                    <a href="https://aviationwx.org#for-airport-owners" style="color: #0066cc;">learn how to add a new airport</a>.
                </p>
            </div>
            <?php endif; ?>

            <?php if ($isRealAirport): ?>
            <div class="section">
                <h2>Why it Matters to add an Airport</h2>
                <p>
                    <strong><?= htmlspecialchars($displayAirportId) ?></strong> is a real airport, but pilots searching for weather information here aren't finding it. 
                    We've verified this is a legitimate airport that needs weather dashboard coverage.
                </p>
                
                <p><strong>Safety First</strong></p>
                <p>
                    Weather-related factors contribute to a significant portion of general aviation accidents. 
                    Real-time webcams and weather data help pilots see actual visibility conditions and make safer go/no-go decisions.
                </p>
                
                <p><strong>Economic Impact</strong></p>
                <p>
                    Tools like this encourage airport use, bringing more pilots to <strong><?= htmlspecialchars($displayAirportId) ?></strong> and supporting local economic activity.
                </p>
                
                <p><strong>Community & Culture</strong></p>
                <p>
                    Better weather information brings positive attention to the general aviation community, supports airport organizations, and strengthens aviation culture.
                </p>
                
                <p>
                    <strong>It's completely free</strong> - for the airport, for pilots, always. No fees, no subscriptions, no ads.
                </p>
            </div>
            <?php endif; ?>

            <?php if ($isRealAirport): ?>
            <div class="section">
                <h2>How to Help</h2>
                
                <div class="cta-box">
                    <h3>✈️ For Pilots</h3>
                    <p><strong>You're the best advocate!</strong> You know firsthand the value of timely, accurate weather information for safe flight decisions.</p>
                    <ul>
                        <li><strong>Share this project</strong> with the airport owner, manager, or airport advocacy organization</li>
                        <li><strong>Connect us</strong> with the right person at the airport</li>
                        <li><strong>Advocate</strong> - If you're part of a flying club or organization, bring it up at meetings</li>
                    </ul>
                    <?php
                    $emailSubject = rawurlencode("Request to add {$displayAirportId} to AviationWX.org");
                    $emailBody = encodeEmailBody("Hello,

I'm reaching out about AviationWX.org, a free weather dashboard service for airports. I noticed that {$displayAirportId} isn't yet part of the network, and I think it would be a valuable addition.

AviationWX.org provides:
- Real-time weather data and webcams
- Free for airports and pilots (no fees, no subscriptions, no ads)
- Dashboard hosting and software maintenance handled by the project
- Integration with existing equipment, or guidance for new installations

This is a safety-oriented service that helps pilots make better flight decisions with timely, accurate local weather information. It also helps encourage airport use and brings positive attention to the general aviation community.

AviationWX integrates with your existing cameras and sensors, or guides your community through new installations.

You can learn more at: https://aviationwx.org
Example airport dashboard: https://kspb.aviationwx.org

If you're interested, please contact: contact@aviationwx.org

Thank you for considering this opportunity to enhance safety and information for pilots at {$displayAirportId}.

Best regards");
                    ?>
                    <p style="margin-top: 1.5rem; text-align: center;">
                        <a href="mailto:?subject=<?= $emailSubject ?>&body=<?= $emailBody ?>" class="btn btn-primary">
                            📧 Send Email to Airport Owner
                        </a>
                    </p>
                    <p style="margin-top: 0.5rem; font-size: 0.9rem; color: #666; text-align: center;">
                        Opens your email client with a pre-written message you can customize
                    </p>
                    <p style="margin-top: 1rem; text-align: center;">
                        <a href="https://guides.aviationwx.org" style="color: #0066cc; text-decoration: none; font-size: 0.95rem;">
                            📚 Read setup guides and documentation →
                        </a>
                    </p>
                </div>

                <div class="cta-box" style="border-left-color: #28a745;">
                    <h3 style="color: #28a745;">🏢 For Airport Owners, Managers & Organizations</h3>
                    <p><strong>Add Your Airport - It's Free & Easy!</strong></p>
                    <p>This is a <strong>safety-oriented service</strong> for pilots. We host the dashboard and integrate with your existing cameras and sensors, or guide your community through new installations.</p>
                    
                    <p style="margin-top: 1rem;"><strong>What we need:</strong></p>
                    <ul>
                        <li>Permission to partner with the AviationWX.org project</li>
                        <li>Existing webcam and weather equipment we can integrate with, or local equipment installed by your community (we provide recommendations and guidance)</li>
                    </ul>
                    
                    <p style="margin-top: 1rem;"><strong>What you get:</strong></p>
                    <ul>
                        <li>Free weather dashboard at <code><?= htmlspecialchars($displayAirportId) ?>.aviationwx.org</code></li>
                        <li>We handle all dashboard hosting and software maintenance</li>
                        <li>Equipment recommendations and installation guidance</li>
                        <li>Equipment ownership stays with the airport</li>
                        <li>SEO benefits - we link to your organization's website to help drive traffic</li>
                    </ul>
                    <?php
                    $ownerEmailSubject = rawurlencode("Request to add {$displayAirportId} to AviationWX.org");
                    $ownerEmailBody = encodeEmailBody("Hello AviationWX.org team,

I'm interested in adding {$displayAirportId} to the AviationWX.org network.

Airport Code: {$displayAirportId}
Airport Name: [Please provide]
Your Name: [Please provide]
Your Role: [e.g., Airport manager, Pilot, Community volunteer]

Do you have existing equipment?
- [ ] Yes - webcam(s) and/or weather station already installed
- [ ] No - starting fresh, will need equipment recommendations

Brief description of your situation:
[Please describe - existing setup, or what you're hoping to achieve]

I've reviewed the installation guides at guides.aviationwx.org:
- [ ] Yes
- [ ] Not yet

Best regards,
[Your name]");
                    ?>
                    <p style="margin-top: 1.5rem; text-align: center;">
                        <a href="mailto:contact@aviationwx.org?subject=<?= $ownerEmailSubject ?>&body=<?= $ownerEmailBody ?>" class="btn btn-primary" style="background: #28a745; border-color: #28a745;">
                            📧 Get Started - Send Setup Information
                        </a>
                    </p>
                    <p style="margin-top: 0.5rem; font-size: 0.9rem; color: #666; text-align: center;">
                        Opens your email client with a template you can fill out with your airport's information
                    </p>
                    <p style="margin-top: 1rem; text-align: center;">
                        <a href="https://guides.aviationwx.org" style="color: #0066cc; text-decoration: none; font-size: 0.95rem;">
                            📚 Read setup guides and documentation →
                        </a>
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($isRealAirport): ?>
            <div class="section">
                <h2>What Happens Next?</h2>
                <ol class="steps-list">
                    <li><strong>Contact us</strong> - Reach out via email (see below) or share your airport's contact information</li>
                    <li><strong>We discuss requirements</strong> - Existing equipment vs. new setup, what's needed, timeline</li>
                    <li><strong>Equipment setup</strong> - We integrate with your existing equipment, or your community installs new sensors with our guidance. Once online, we connect everything to your dashboard.</li>
                    <li><strong>Your airport goes live!</strong> - Pilots can start using the dashboard at <?= htmlspecialchars($displayAirportId) ?>.aviationwx.org</li>
                </ol>
            </div>

            <div class="section">
                <h2>See How Other Airports Are Benefiting</h2>
                <p>These airports are already providing pilots with real-time weather and webcam information:</p>
                <?php
                $exampleAirports = [];
                if ($config !== null && isset($config['airports']) && is_array($config['airports'])) {
                    $allAirports = $config['airports'];
                    $airportIds = array_keys($allAirports);
                    shuffle($airportIds);
                    $exampleIds = array_slice($airportIds, 0, min(3, count($airportIds)));
                    
                    foreach ($exampleIds as $id) {
                        if (isset($allAirports[$id])) {
                            $exampleAirports[] = [
                                'id' => $id,
                                'icao' => isset($allAirports[$id]['icao']) ? $allAirports[$id]['icao'] : strtoupper($id),
                                'name' => $allAirports[$id]['name'] ?? 'Airport'
                            ];
                        }
                    }
                }
                ?>
                <?php if (!empty($exampleAirports)): ?>
                <div class="examples-grid">
                    <?php foreach ($exampleAirports as $example): ?>
                    <div class="example-card">
                        <a href="https://<?= htmlspecialchars($example['id']) ?>.aviationwx.org">
                            <div class="example-icao"><?= htmlspecialchars($example['icao']) ?></div>
                            <div class="example-name"><?= htmlspecialchars($example['name']) ?></div>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <p style="text-align: center; margin-top: 1rem;">
                    <a href="https://aviationwx.org" class="btn btn-secondary">View All Airports</a>
                </p>
            </div>

            <div class="section">
                <h2>Get in Touch</h2>
                <p style="text-align: center; margin-bottom: 1rem;">
                    <strong>No hard sell, no sale at all</strong> - just someone who wants to work on safety for small airports.
                </p>
                <div class="contact-info">
                    <strong>Contact Alex Witherspoon:</strong><br>
                    <a href="mailto:contact@aviationwx.org?subject=<?= rawurlencode("Request to add {$displayAirportId} to AviationWX") ?>">
                        contact@aviationwx.org
                    </a>
                </div>
                <p style="text-align: center; font-size: 0.9rem; color: #666; margin-top: 1rem;">
                    Email subject will be pre-filled: "Request to add <?= htmlspecialchars($displayAirportId) ?> to AviationWX"
                </p>
                <p style="text-align: center; font-size: 0.9rem; color: #666; margin-top: 0.5rem;">
                    Happy to answer questions, discuss requirements, or help coordinate setup.
                </p>
            </div>
            <?php endif; ?>

            <div class="home-link">
                <a href="https://aviationwx.org" class="btn btn-primary">Return to Homepage</a>
            </div>
        </div>
    </div>

    <?php
    // Prepare all airports for search
    $searchAirports = [];
    $enabledAirports = $config ? getEnabledAirports($config) : [];
    foreach ($enabledAirports as $searchAirportId => $searchAirport) {
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
</body>
</html>
