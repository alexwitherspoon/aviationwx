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

// SEO variables
$pageTitle = 'Airport Not Found - AviationWX.org';
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
                <h2>Why This Matters</h2>
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
- All technical setup and maintenance handled by the project
- Works with existing equipment or can provide new equipment

This is a safety-oriented service that helps pilots make better flight decisions with timely, accurate local weather information. It also helps encourage airport use and brings positive attention to the general aviation community.

The project can work with existing webcam and weather equipment, or provide new equipment if needed. All that's required is permission to partner with the project.

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
                </div>

                <div class="cta-box" style="border-left-color: #28a745;">
                    <h3 style="color: #28a745;">🏢 For Airport Owners, Managers & Organizations</h3>
                    <p><strong>Add Your Airport - It's Free & Easy!</strong></p>
                    <p>This is a <strong>safety-oriented service</strong> for pilots. We handle all the technical work - you just provide permission and access.</p>
                    
                    <p style="margin-top: 1rem;"><strong>What we need:</strong></p>
                    <ul>
                        <li>Permission to partner with the AviationWX.org project</li>
                        <li>Access to existing webcam and weather equipment data if available</li>
                        <li>If no local webcam or weather station is available, access to power and/or internet for equipment</li>
                    </ul>
                    
                    <p style="margin-top: 1rem;"><strong>What you get:</strong></p>
                    <ul>
                        <li>Free weather dashboard at <code><?= htmlspecialchars($displayAirportId) ?>.aviationwx.org</code></li>
                        <li>We handle all technical setup and ongoing maintenance</li>
                        <li>Equipment ownership stays with the airport (if we provide it)</li>
                        <li>SEO benefits - we link to your organization's website to help drive traffic</li>
                        <li>No ongoing costs or maintenance burden on your end</li>
                    </ul>
                    <?php
                    $ownerEmailSubject = rawurlencode("Request to add {$displayAirportId} to AviationWX.org");
                    $ownerEmailBody = encodeEmailBody("Hello AviationWX.org team,

I'm interested in adding {$displayAirportId} to the AviationWX.org network. Please find the information below:

=== AIRPORT INFORMATION ===
Airport ICAO Code: {$displayAirportId}
Airport Name: [Please provide]
Organization/Contact Name: [Please provide]
Your Email: [Please provide]
Your Phone: [Optional]

=== EXISTING EQUIPMENT (if available) ===
Webcams:
- Number of webcams: [Please provide]
- Webcam make/model: [Please provide]
- Webcam URL/API endpoint: [Please provide]
- Access credentials: [Please provide if needed]

Weather Station:
- Weather station make/model: [Please provide]
- Weather station API endpoint: [Please provide]
- API key/credentials: [Please provide]
- Data format: [Please provide if known]

=== INFRASTRUCTURE (if new equipment needed) ===
Power:
- Power available at location: [Yes/No]
- Power source type: [e.g., Grid, Solar, Generator]
- Location details: [Please describe where equipment could be installed]

Internet:
- Internet available at location: [Yes/No]
- Internet type: [e.g., Wired, WiFi, Cellular]
- Bandwidth/speed: [If known]
- Location details: [Please describe where equipment could be installed]

=== ADDITIONAL INFORMATION ===
Preferred installation location: [Please describe]
Any special considerations: [Please note any restrictions, access requirements, etc.]

Thank you for providing this free service to the aviation community!

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
                </div>
            </div>
            <?php endif; ?>

            <?php if ($isRealAirport): ?>
            <div class="section">
                <h2>What Happens Next?</h2>
                <ol class="steps-list">
                    <li><strong>Contact us</strong> - Reach out via email (see below) or share your airport's contact information</li>
                    <li><strong>We discuss requirements</strong> - Existing equipment vs. new setup, what's needed, timeline</li>
                    <li><strong>We handle all setup</strong> - Technical work is on us, you just provide access and permission</li>
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
            <?php else: ?>
            <div class="section">
                <h2>Find the Airport You're Looking For</h2>
                <p>Browse all airports in our network to find the one you need:</p>
                <p style="text-align: center; margin-top: 1.5rem;">
                    <a href="https://aviationwx.org#participating-airports" class="btn btn-primary">View All Airports</a>
                </p>
                <p style="text-align: center; margin-top: 1.5rem; color: #666; font-size: 0.95rem;">
                    Looking for an airport that's not in our network? 
                    <a href="https://aviationwx.org" style="color: #0066cc;">Learn how to help add it</a> - it's free and we handle all the technical work.
                </p>
            </div>
            <?php endif; ?>

            <div class="home-link">
                <a href="https://aviationwx.org" class="btn btn-primary">Return to Homepage</a>
            </div>
        </div>
    </div>
</body>
</html>
