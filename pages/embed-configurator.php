<?php
/**
 * Embed Configurator
 * 
 * Allows users to configure and generate embed code for airport weather widgets.
 * Supports iframe and web component embed formats.
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/seo.php';

// Load configuration
$config = loadConfig();
$airports = [];
if ($config && isset($config['airports'])) {
    // Use getListedAirports() to exclude unlisted airports from embed configurator
    $listedAirports = getListedAirports($config);
    foreach ($listedAirports as $id => $airport) {
        $airports[] = [
            'id' => $id,
            'name' => $airport['name'] ?? '',
            'identifier' => getPrimaryIdentifier($id, $airport),
            'icao' => $airport['icao'] ?? '',
            'iata' => $airport['iata'] ?? '',
            'faa' => $airport['faa'] ?? '',
            'has_webcams' => isset($airport['webcams']) && count($airport['webcams']) > 0,
            'webcam_count' => isset($airport['webcams']) ? count($airport['webcams']) : 0,
            'webcam_names' => isset($airport['webcams']) ? array_map(function($cam) {
                return $cam['name'] ?? 'Camera';
            }, $airport['webcams']) : []
        ];
    }
}

// SEO variables
$baseDomain = getBaseDomain();
$pageTitle = 'Embed Generator - AviationWX.org';
$pageDescription = 'Create embeddable weather widgets for your airport website. Generate iframe or web component code to display real-time weather and webcams.';
$canonicalUrl = 'https://embed.' . $baseDomain;
$baseUrl = getBaseUrl();

// Check if there are query parameters that indicate a specific configuration
// These URL variations should not be indexed - only the base configurator page should be
$hasQueryParams = !empty($_SERVER['QUERY_STRING']);
$shouldNoIndex = $hasQueryParams;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    
    <?php
    // Prevent indexing of configurator pages with query parameters
    // Only the base embed.aviationwx.org page should be indexed
    if ($shouldNoIndex) {
        echo '<meta name="robots" content="noindex, nofollow">' . "\n    ";
    }
    
    echo generateFaviconTags();
    echo "\n    ";
    echo generateEnhancedMetaTags($pageDescription, 'embed, widget, aviation weather, airport webcam, embed code');
    echo "\n    ";
    echo generateCanonicalTag($canonicalUrl);
    ?>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        /* ============================================
           LIGHT MODE (DEFAULT)
           ============================================ */
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
            color: #333;
            background: #f5f5f5;
            min-height: 100vh;
        }
        
        .header {
            background: linear-gradient(135deg, #f8f9fa 0%, #0066cc 100%);
            color: white;
            padding: 1.5rem 2rem;
            text-align: center;
            border-bottom: 1px solid #ddd;
        }
        
        .header h1 {
            font-size: 1.8rem;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .header h1 img {
            width: 32px;
            height: 32px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 0.95rem;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem;
        }
        
        .main-layout {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 2rem;
            align-items: start;
        }
        
        @media (max-width: 900px) {
            .main-layout {
                grid-template-columns: 1fr;
            }
        }
        
        /* Configuration Panel */
        .config-panel {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid #ddd;
        }
        
        .config-section {
            margin-bottom: 1.5rem;
        }
        
        .config-section:last-child {
            margin-bottom: 0;
        }
        
        .config-section h3 {
            color: #0066cc;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #ddd;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            color: #666;
            font-size: 0.85rem;
            margin-bottom: 0.35rem;
        }
        
        .form-group input[type="text"],
        .form-group select {
            width: 100%;
            padding: 0.6rem 0.75rem;
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 6px;
            color: #333;
            font-size: 0.95rem;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #0066cc;
            box-shadow: 0 0 0 2px rgba(0, 102, 204, 0.1);
        }
        
        /* Airport Search */
        .airport-search-wrapper {
            position: relative;
        }
        
        .airport-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #ffffff;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 6px 6px;
            max-height: 250px;
            overflow-y: auto;
            z-index: 100;
            display: none;
        }
        
        .airport-dropdown.show {
            display: block;
        }
        
        .airport-option {
            padding: 0.6rem 0.75rem;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.15s;
        }
        
        .airport-option:last-child {
            border-bottom: none;
        }
        
        .airport-option:hover,
        .airport-option.selected {
            background: #f8f9fa;
        }
        
        .airport-option .identifier {
            font-weight: 600;
            color: #0066cc;
        }
        
        .airport-option .name {
            font-size: 0.85rem;
            color: #666;
        }
        
        /* Radio/Checkbox styling */
        .radio-group,
        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .radio-item,
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            padding: 0.4rem 0;
        }
        
        .radio-item input,
        .checkbox-item input {
            accent-color: #0066cc;
        }
        
        .radio-item span,
        .checkbox-item span {
            color: #333;
            font-size: 0.9rem;
        }
        
        .radio-item.disabled,
        .checkbox-item.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Unit options */
        .unit-options {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .unit-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .unit-row:last-child {
            border-bottom: none;
        }
        
        .unit-label {
            color: #666;
            font-size: 0.85rem;
        }
        
        .unit-toggle-group {
            display: flex;
            gap: 0.25rem;
            background: #f8f9fa;
            border-radius: 6px;
            padding: 0.25rem;
        }
        
        .unit-option {
            cursor: pointer;
        }
        
        .unit-option input {
            display: none;
        }
        
        .unit-option span {
            display: block;
            padding: 0.35rem 0.6rem;
            border-radius: 4px;
            font-size: 0.8rem;
            color: #666;
            transition: all 0.2s;
        }
        
        .unit-option input:checked + span {
            background: #0066cc;
            color: white;
        }
        
        .unit-option:hover span {
            color: #333;
        }
        
        .unit-option input:checked + span:hover {
            color: white;
        }
        
        /* Size inputs */
        .size-inputs {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }
        
        .size-inputs input {
            width: 80px;
            padding: 0.5rem;
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 6px;
            color: #333;
            text-align: center;
        }
        
        .size-inputs span {
            color: #666;
        }
        
        /* Camera slot selectors */
        .cam-slots-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }
        
        .cam-slots-grid .form-group {
            margin-bottom: 0;
        }
        
        .cam-slot-group .form-group {
            margin-bottom: 0.75rem;
        }
        
        .cam-slot-group .form-group:last-child {
            margin-bottom: 0;
        }
        
        /* Preview Panel */
        .preview-panel {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #ddd;
            overflow: hidden;
        }
        
        .preview-header {
            background: #f8f9fa;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .preview-header h2 {
            font-size: 1.1rem;
            color: #333;
        }
        
        .preview-dimensions {
            font-size: 0.85rem;
            color: #666;
        }
        
        .preview-container {
            padding: 2rem;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 550px;
            background: #f5f5f5; /* Light background to show embed as it would appear on most sites */
        }
        
        .preview-frame {
            border: 2px dashed #ccc;
            background: white;
            position: relative;
            overflow: hidden;
            transition: all 0.3s;
        }
        
        .preview-frame iframe {
            border: none;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
        
        .preview-placeholder {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: #666;
            padding: 2rem;
        }
        
        .preview-placeholder .icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        /* Embed Code Panel */
        .embed-code-panel {
            margin-top: 2rem;
        }
        
        .embed-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .embed-tab {
            padding: 0.6rem 1.2rem;
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 6px;
            color: #666;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.9rem;
        }
        
        .embed-tab:hover {
            background: #e9ecef;
            color: #333;
        }
        
        .embed-tab.active {
            background: #0066cc;
            border-color: #0066cc;
            color: white;
        }
        
        .embed-code-container {
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #ddd;
            overflow: hidden;
        }
        
        .embed-code-header {
            background: #e9ecef;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .embed-code-header span {
            color: #666;
            font-size: 0.85rem;
        }
        
        .copy-btn {
            padding: 0.4rem 0.75rem;
            background: #0066cc;
            border: none;
            border-radius: 4px;
            color: white;
            font-size: 0.85rem;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .copy-btn:hover {
            background: #0052a3;
        }
        
        .copy-btn.copied {
            background: #28a745;
        }
        
        .embed-code {
            padding: 1rem;
            overflow-x: auto;
        }
        
        .embed-code pre {
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 0.85rem;
            color: #333;
            white-space: pre-wrap;
            word-break: break-all;
            margin: 0;
            line-height: 1.5;
        }
        
        .embed-code code {
            color: #0066cc;
        }
        
        /* Footer */
        .footer {
            text-align: center;
            padding: 2rem;
            color: #666;
            font-size: 0.85rem;
            border-top: 1px solid #e0e0e0;
            margin-top: 2rem;
        }
        
        .footer a {
            color: #0066cc;
            text-decoration: none;
        }
        
        .footer a:hover {
            text-decoration: underline;
        }
        
        /* Info callout */
        .info-callout {
            background: rgba(0, 102, 204, 0.1);
            border: 1px solid rgba(0, 102, 204, 0.3);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            font-size: 0.85rem;
            color: #0066cc;
        }
        
        .info-callout strong {
            color: #0052a3;
        }
        
        /* ============================================
           DARK MODE SUPPORT
           ============================================ */
        
        @media (prefers-color-scheme: dark) {
            body {
                color: #e0e0e0;
                background: #1a1a1a;
            }
            
            .header {
                background: linear-gradient(135deg, #1a1a1a 0%, #0066cc 100%);
                border-bottom-color: #333;
            }
            
            .config-panel {
                background: #242424;
                border-color: #333;
            }
            
            .config-section h3 {
                color: #0099ff;
                border-bottom-color: #333;
            }
            
            .form-group label {
                color: #aaa;
            }
            
            .form-group input[type="text"],
            .form-group select {
                background: #1a1a1a;
                border-color: #444;
                color: #e0e0e0;
            }
            
            .form-group input:focus,
            .form-group select:focus {
                border-color: #0099ff;
                box-shadow: 0 0 0 2px rgba(0, 153, 255, 0.2);
            }
            
            .airport-dropdown {
                background: #1a1a1a;
                border-color: #444;
            }
            
            .airport-option {
                border-bottom-color: #333;
            }
            
            .airport-option:hover,
            .airport-option.selected {
                background: #2a2a2a;
            }
            
            .airport-option .identifier {
                color: #0099ff;
            }
            
            .airport-option .name {
                color: #888;
            }
            
            .radio-item span,
            .checkbox-item span {
                color: #ccc;
            }
            
            .unit-row {
                border-bottom-color: #333;
            }
            
            .unit-label {
                color: #999;
            }
            
            .unit-toggle-group {
                background: #1a1a1a;
            }
            
            .unit-option span {
                color: #888;
            }
            
            .size-inputs input {
                background: #1a1a1a;
                border-color: #444;
                color: #e0e0e0;
            }
            
            .preview-panel {
                background: #242424;
                border-color: #333;
            }
            
            .preview-header {
                background: #2a2a2a;
                border-bottom-color: #333;
            }
            
            .preview-header h2 {
                color: #e0e0e0;
            }
            
            .preview-container {
                background: #1a1a1a;
            }
            
            .preview-dimensions {
                color: #888;
            }
            
            .preview-container {
                background: #1a1a1a;
            }
            
            .embed-tab {
                background: #2a2a2a;
                border-color: #444;
                color: #888;
            }
            
            .embed-tab:hover {
                background: #333;
                color: #ccc;
            }
            
            .embed-code-container {
                background: #1a1a1a;
                border-color: #333;
            }
            
            .embed-code-header {
                background: #242424;
                border-bottom-color: #333;
            }
            
            .embed-code-header span {
                color: #888;
            }
            
            .embed-code pre {
                color: #e0e0e0;
            }
            
            .embed-code code {
                color: #4ec9b0;
            }
            
            .placeholder-state {
                color: #888;
                background: #1e1e1e;
            }
            
            .embed-tabs {
                background: #1a1a1a;
                border-color: #333;
            }
            
            .embed-tab {
                color: #888;
                border-right-color: #333;
            }
            
            .embed-tab.active {
                background: #242424;
                color: #0099ff;
            }
            
            .embed-tab:hover:not(.active) {
                background: #1e1e1e;
            }
            
            .code-panel {
                background: #1a1a1a;
                border-color: #333;
            }
            
            .copy-btn {
                background: #0066cc;
            }
            
            .copy-btn:hover {
                background: #0052a3;
            }
            
            .info-callout {
                background: rgba(0, 153, 255, 0.1);
                border-color: #0099ff;
            }
            
            .info-callout strong {
                color: #0099ff;
            }
            
            footer {
                border-top-color: #333;
            }
            
            footer a {
                color: #0099ff;
            }
            
            footer a:hover {
                color: #33adff;
            }
        }
        
        /* Explicit dark mode class support (in addition to system preference) */
        body.dark-mode {
            color: #e0e0e0;
            background: #1a1a1a;
        }
        
        body.dark-mode .header {
            background: linear-gradient(135deg, #1a1a1a 0%, #0066cc 100%);
            border-bottom-color: #333;
        }
        
        body.dark-mode .config-panel {
            background: #242424;
            border-color: #333;
        }
        
        body.dark-mode .config-section h3 {
            color: #0099ff;
            border-bottom-color: #333;
        }
        
        body.dark-mode .form-group label {
            color: #aaa;
        }
        
        body.dark-mode .form-group input[type="text"],
        body.dark-mode .form-group select {
            background: #1a1a1a;
            border-color: #444;
            color: #e0e0e0;
        }
        
        body.dark-mode .form-group input:focus,
        body.dark-mode .form-group select:focus {
            border-color: #0099ff;
            box-shadow: 0 0 0 2px rgba(0, 153, 255, 0.2);
        }
        
        body.dark-mode .airport-dropdown {
            background: #1a1a1a;
            border-color: #444;
        }
        
        body.dark-mode .airport-option {
            border-bottom-color: #333;
        }
        
        body.dark-mode .airport-option:hover,
        body.dark-mode .airport-option.selected {
            background: #2a2a2a;
        }
        
        body.dark-mode .airport-option .identifier {
            color: #0099ff;
        }
        
        body.dark-mode .airport-option .name {
            color: #888;
        }
        
        body.dark-mode .radio-item span,
        body.dark-mode .checkbox-item span {
            color: #ccc;
        }
        
        body.dark-mode .unit-row {
            border-bottom-color: #333;
        }
        
        body.dark-mode .unit-label {
            color: #999;
        }
        
        body.dark-mode .unit-toggle-group {
            background: #1a1a1a;
        }
        
        body.dark-mode .unit-option span {
            color: #888;
        }
        
        body.dark-mode .size-inputs input {
            background: #1a1a1a;
            border-color: #444;
            color: #e0e0e0;
        }
        
        body.dark-mode .preview-panel {
            background: #242424;
            border-color: #333;
        }
        
        body.dark-mode .preview-header {
            background: #2a2a2a;
            border-bottom-color: #333;
        }
        
        body.dark-mode .preview-header h2 {
            color: #e0e0e0;
        }
        
        body.dark-mode .preview-dimensions {
            color: #888;
        }
        
        body.dark-mode .preview-container {
            background: #1a1a1a;
        }
        
        body.dark-mode .placeholder-state {
            color: #888;
            background: #1e1e1e;
        }
        
        body.dark-mode .embed-tab {
            background: #2a2a2a;
            border-color: #444;
            color: #888;
        }
        
        body.dark-mode .embed-tab:hover {
            background: #333;
            color: #ccc;
        }
        
        body.dark-mode .embed-tabs {
            background: #1a1a1a;
            border-color: #333;
        }
        
        body.dark-mode .embed-tab.active {
            background: #242424;
            color: #0099ff;
        }
        
        body.dark-mode .embed-tab:hover:not(.active) {
            background: #1e1e1e;
        }
        
        body.dark-mode .code-panel {
            background: #1a1a1a;
            border-color: #333;
        }
        
        body.dark-mode .embed-code-container {
            background: #1a1a1a;
            border-color: #333;
        }
        
        body.dark-mode .embed-code-header {
            background: #242424;
            border-bottom-color: #333;
        }
        
        body.dark-mode .embed-code-header span {
            color: #888;
        }
        
        body.dark-mode .embed-code pre {
            color: #e0e0e0;
        }
        
        body.dark-mode .embed-code code {
            color: #4ec9b0;
        }
        
        body.dark-mode .copy-btn {
            background: #0066cc;
        }
        
        body.dark-mode .copy-btn:hover {
            background: #0052a3;
        }
        
        body.dark-mode .info-callout {
            background: rgba(0, 153, 255, 0.1);
            border-color: #0099ff;
        }
        
        body.dark-mode .info-callout strong {
            color: #0099ff;
        }
        
        body.dark-mode footer {
            border-top-color: #333;
        }
        
        body.dark-mode footer a {
            color: #0099ff;
        }
        
        body.dark-mode footer a:hover {
            color: #33adff;
        }
    </style>
    <link rel="stylesheet" href="/public/css/navigation.css">
</head>
<body>
    <?php require_once __DIR__ . '/../lib/navigation.php'; ?>
    
    <div class="container">
        <div class="main-layout">
            <!-- Configuration Panel -->
            <div class="config-panel">
                <div class="config-section">
                    <h3>Airport</h3>
                    <div class="form-group">
                        <label for="airport-search">Search airports</label>
                        <div class="airport-search-wrapper">
                            <input type="text" id="airport-search" placeholder="Type airport name or code..." autocomplete="off">
                            <div id="airport-dropdown" class="airport-dropdown"></div>
                        </div>
                    </div>
                    <div id="selected-airport" style="display: none; margin-top: 0.5rem; padding: 0.75rem; background: #f8f9fa; border-radius: 6px; border: 1px solid #0066cc;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span id="selected-identifier" style="font-weight: 600; color: #0066cc;"></span>
                                <span id="selected-name" style="color: #666; font-size: 0.85rem; margin-left: 0.5rem;"></span>
                            </div>
                            <button id="clear-airport" style="background: none; border: none; color: #666; cursor: pointer; font-size: 1.2rem;">&times;</button>
                        </div>
                    </div>
                </div>
                
                <div class="config-section">
                    <h3>Widget Style</h3>
                    <div class="radio-group">
                        <label class="radio-item">
                            <input type="radio" name="style" value="card" checked>
                            <span>Weather Card (400×435)</span>
                        </label>
                        <label class="radio-item" id="style-webcam-only">
                            <input type="radio" name="style" value="webcam-only">
                            <span>Webcam Only Single (450×380)</span>
                        </label>
                        <label class="radio-item" id="style-dual-only">
                            <input type="radio" name="style" value="dual-only">
                            <span>Webcam Only Dual (600×250)</span>
                        </label>
                        <label class="radio-item" id="style-multi-only">
                            <input type="radio" name="style" value="multi-only">
                            <span>Webcam Only Quad (600×400)</span>
                        </label>
                        <label class="radio-item" id="style-full-single">
                            <input type="radio" name="style" value="full-single">
                            <span>Full Single (800×740)</span>
                        </label>
                        <label class="radio-item" id="style-full-dual">
                            <input type="radio" name="style" value="full-dual">
                            <span>Full Dual (800×550)</span>
                        </label>
                        <label class="radio-item" id="style-full-multi">
                            <input type="radio" name="style" value="full-multi">
                            <span>Full Quad (800×750)</span>
                        </label>
                    </div>
                </div>
                
                <div class="config-section">
                    <h3>Theme</h3>
                    <div class="radio-group">
                        <label class="radio-item">
                            <input type="radio" name="theme" value="light" checked>
                            <span>Light</span>
                        </label>
                        <label class="radio-item">
                            <input type="radio" name="theme" value="dark">
                            <span>Dark</span>
                        </label>
                        <label class="radio-item">
                            <input type="radio" name="theme" value="auto">
                            <span>Auto (System)</span>
                        </label>
                    </div>
                </div>
                
                <div class="config-section" id="webcam-options" style="display: none;">
                    <h3>Webcam Options</h3>
                    <!-- Single webcam selector (for webcam/full styles) -->
                    <div class="form-group" id="single-webcam-select-group">
                        <label for="webcam-select">Select camera</label>
                        <select id="webcam-select">
                            <option value="0">Camera 1</option>
                        </select>
                    </div>
                    <!-- Multi-cam slot selectors (for dual/multi styles) -->
                    <div id="multi-cam-slots" style="display: none;">
                        <div class="cam-slot-group" id="dual-cam-slots" style="display: none;">
                            <div class="form-group">
                                <label for="cam-slot-0">Left Camera</label>
                                <select id="cam-slot-0" class="cam-slot-select" data-slot="0">
                                    <option value="0">Camera 1</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="cam-slot-1">Right Camera</label>
                                <select id="cam-slot-1" class="cam-slot-select" data-slot="1">
                                    <option value="1">Camera 2</option>
                                </select>
                            </div>
                        </div>
                        <div class="cam-slot-group" id="multi-cam-slots-4" style="display: none;">
                            <div class="cam-slots-grid">
                                <div class="form-group">
                                    <label for="cam-slot-0-multi">Top Left</label>
                                    <select id="cam-slot-0-multi" class="cam-slot-select-multi" data-slot="0">
                                        <option value="0">Camera 1</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="cam-slot-1-multi">Top Right</label>
                                    <select id="cam-slot-1-multi" class="cam-slot-select-multi" data-slot="1">
                                        <option value="1">Camera 2</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="cam-slot-2-multi">Bottom Left</label>
                                    <select id="cam-slot-2-multi" class="cam-slot-select-multi" data-slot="2">
                                        <option value="2">Camera 3</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="cam-slot-3-multi">Bottom Right</label>
                                    <select id="cam-slot-3-multi" class="cam-slot-select-multi" data-slot="3">
                                        <option value="3">Camera 4</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="config-section">
                    <h3>Size</h3>
                    <div class="size-inputs">
                        <input type="number" id="width" value="300" min="200" max="1200">
                        <span>×</span>
                        <input type="number" id="height" value="300" min="80" max="800">
                        <span>px</span>
                    </div>
                    <div class="info-callout" style="margin-top: 0.75rem;">
                        <strong>Tip:</strong> Size updates automatically when you change widget style. Adjust manually for custom dimensions.
                    </div>
                </div>
                
                <div class="config-section">
                    <h3>Units</h3>
                    <div class="unit-options">
                        <div class="unit-row">
                            <span class="unit-label">Temperature</span>
                            <div class="unit-toggle-group">
                                <label class="unit-option">
                                    <input type="radio" name="temp_unit" value="F" checked>
                                    <span>°F</span>
                                </label>
                                <label class="unit-option">
                                    <input type="radio" name="temp_unit" value="C">
                                    <span>°C</span>
                                </label>
                            </div>
                        </div>
                        <div class="unit-row">
                            <span class="unit-label">Altitude/Distance</span>
                            <div class="unit-toggle-group">
                                <label class="unit-option">
                                    <input type="radio" name="dist_unit" value="ft" checked>
                                    <span>ft</span>
                                </label>
                                <label class="unit-option">
                                    <input type="radio" name="dist_unit" value="m">
                                    <span>m</span>
                                </label>
                            </div>
                        </div>
                        <div class="unit-row">
                            <span class="unit-label">Wind Speed</span>
                            <div class="unit-toggle-group">
                                <label class="unit-option">
                                    <input type="radio" name="wind_unit" value="kt" checked>
                                    <span>kt</span>
                                </label>
                                <label class="unit-option">
                                    <input type="radio" name="wind_unit" value="mph">
                                    <span>mph</span>
                                </label>
                                <label class="unit-option">
                                    <input type="radio" name="wind_unit" value="kmh">
                                    <span>km/h</span>
                                </label>
                            </div>
                        </div>
                        <div class="unit-row">
                            <span class="unit-label">Barometer</span>
                            <div class="unit-toggle-group">
                                <label class="unit-option">
                                    <input type="radio" name="baro_unit" value="inHg" checked>
                                    <span>inHg</span>
                                </label>
                                <label class="unit-option">
                                    <input type="radio" name="baro_unit" value="hPa">
                                    <span>hPa</span>
                                </label>
                                <label class="unit-option">
                                    <input type="radio" name="baro_unit" value="mmHg">
                                    <span>mmHg</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="config-section">
                    <h3>Link Behavior</h3>
                    <div class="radio-group">
                        <label class="radio-item">
                            <input type="radio" name="target" value="_blank" checked>
                            <span>Open in new tab</span>
                        </label>
                        <label class="radio-item">
                            <input type="radio" name="target" value="_self">
                            <span>Open in same tab</span>
                        </label>
                    </div>
                </div>
            </div>
            
            <!-- Preview Panel -->
            <div>
                <div class="preview-panel">
                    <div class="preview-header">
                        <h2>Live Preview</h2>
                        <span class="preview-dimensions" id="preview-dimensions">400 × 435 px</span>
                    </div>
                    <div class="preview-container">
                        <div class="preview-frame" id="preview-frame" style="width: 400px; height: 435px;">
                            <div class="preview-placeholder" id="preview-placeholder">
                                <div class="icon">✈️</div>
                                <p>Select an airport to see preview</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Embed Code Panel -->
                <div class="embed-code-panel">
                    <div class="embed-tabs">
                        <button class="embed-tab active" data-type="iframe">iframe Embed</button>
                        <button class="embed-tab" data-type="webcomponent">Web Component</button>
                    </div>
                    
                    <div class="embed-code-container">
                        <div class="embed-code-header">
                            <span id="embed-type-label">iframe Embed Code</span>
                            <button class="copy-btn" id="copy-btn">📋 Copy</button>
                        </div>
                        <div class="embed-code">
                            <pre id="embed-code"><code><!-- Select an airport to generate embed code --></code></pre>
                        </div>
                    </div>
                    
                    <div class="info-callout" id="embed-info">
                        <strong>iframe Embed:</strong> Works on Google Sites, WordPress, Squarespace, and any HTML page. Simply paste the code where you want the widget to appear.
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <footer class="footer">
        <p>
            &copy; <?= date('Y') ?> <a href="https://aviationwx.org">AviationWX.org</a> • 
            <a href="https://airports.aviationwx.org">Airports</a> • 
            <a href="https://guides.aviationwx.org">Guides</a> • 
            <a href="https://aviationwx.org#about-the-project">Built for pilots, by pilots</a> • 
            <a href="https://github.com/alexwitherspoon/aviationwx.org" target="_blank" rel="noopener">Open Source<?php $gitSha = getGitSha(); echo $gitSha ? ' - ' . htmlspecialchars($gitSha) : ''; ?></a> • 
            <a href="https://terms.aviationwx.org">Terms of Service</a> • 
            <a href="https://api.aviationwx.org">API</a> • 
            <a href="https://status.aviationwx.org">Status</a>
        </p>
    </footer>
    
    <script>
    (function() {
        'use strict';
        
        // Airport data
        var AIRPORTS = <?= json_encode($airports) ?>;
        var BASE_DOMAIN = <?= json_encode($baseDomain) ?>;
        var IS_LOCAL_DEV = <?= json_encode(!isProduction()) ?>;
        
        // State
        var state = {
            airport: null,
            style: 'card',
            theme: 'light',
            webcam: 0,
            cams: [0, 1, 2, 3], // Camera indices for multi-cam widgets
            width: 400,
            height: 435,
            target: '_blank',
            embedType: 'iframe',
            tempUnit: 'F',
            distUnit: 'ft',
            windUnit: 'kt',
            baroUnit: 'inHg'
        };
        
        // Size presets for each style
        var SIZE_PRESETS = {
            card: { width: 400, height: 435 },
            'webcam-only': { width: 450, height: 380 },
            'dual-only': { width: 600, height: 250 },
            'multi-only': { width: 600, height: 400 },
            full: { width: 800, height: 700 },
            'full-single': { width: 800, height: 740 },
            'full-dual': { width: 800, height: 550 },
            'full-multi': { width: 800, height: 750 }
        };
        
        // DOM elements
        var elements = {
            searchInput: document.getElementById('airport-search'),
            dropdown: document.getElementById('airport-dropdown'),
            selectedAirport: document.getElementById('selected-airport'),
            selectedIdentifier: document.getElementById('selected-identifier'),
            selectedName: document.getElementById('selected-name'),
            clearAirport: document.getElementById('clear-airport'),
            previewFrame: document.getElementById('preview-frame'),
            previewPlaceholder: document.getElementById('preview-placeholder'),
            previewDimensions: document.getElementById('preview-dimensions'),
            embedCode: document.getElementById('embed-code'),
            embedTypeLabel: document.getElementById('embed-type-label'),
            embedInfo: document.getElementById('embed-info'),
            copyBtn: document.getElementById('copy-btn'),
            widthInput: document.getElementById('width'),
            heightInput: document.getElementById('height'),
            webcamOptions: document.getElementById('webcam-options'),
            webcamSelect: document.getElementById('webcam-select'),
            styleWebcamOnly: document.getElementById('style-webcam-only'),
            styleDualOnly: document.getElementById('style-dual-only'),
            styleMultiOnly: document.getElementById('style-multi-only'),
            styleFullSingle: document.getElementById('style-full-single'),
            styleFullDual: document.getElementById('style-full-dual'),
            styleFullMulti: document.getElementById('style-full-multi')
        };
        
        // Generate embed URL (for actual embed widget with render=1)
        function getEmbedUrl() {
            if (!state.airport) return null;
            
            var baseUrl;
            if (IS_LOCAL_DEV) {
                // For local dev, use query param approach
                var protocol = window.location.protocol;
                var host = window.location.host;
                baseUrl = protocol + '//' + host + '/?embed';
            } else {
                // Production: use subdomain with query params
                baseUrl = 'https://embed.' + BASE_DOMAIN + '/';
            }
            
            var params = [];
            params.push('render=1'); // Required to trigger embed renderer vs configurator
            params.push('airport=' + state.airport.id);
            params.push('style=' + state.style);
            params.push('theme=' + state.theme);
            if (state.style === 'webcam-only' || state.style === 'full' || state.style === 'full-single') {
                params.push('webcam=' + state.webcam);
            }
            if (state.style === 'dual-only' || state.style === 'full-dual') {
                params.push('cams=' + state.cams.slice(0, 2).join(','));
            }
            if (state.style === 'multi-only' || state.style === 'full-multi') {
                params.push('cams=' + state.cams.slice(0, 4).join(','));
            }
            params.push('target=' + state.target);
            // Add unit preferences (only if not default)
            if (state.tempUnit !== 'F') params.push('temp=' + state.tempUnit);
            if (state.distUnit !== 'ft') params.push('dist=' + state.distUnit);
            if (state.windUnit !== 'kt') params.push('wind=' + state.windUnit);
            if (state.baroUnit !== 'inHg') params.push('baro=' + state.baroUnit);
            
            return baseUrl + (baseUrl.indexOf('?') >= 0 ? '&' : '?') + params.join('&');
        }
        
        // Update browser URL to reflect current configurator state (for sharing/bookmarking)
        function updateBrowserUrl() {
            var params = new URLSearchParams();
            // Preserve 'embed' param for local development
            if (IS_LOCAL_DEV) params.set('embed', '');
            if (state.airport) params.set('airport', state.airport.id);
            params.set('style', state.style);
            params.set('theme', state.theme);
            if (state.style === 'webcam-only' || state.style === 'full' || state.style === 'full-single') {
                // Explicitly convert to string to handle webcam=0 correctly
                params.set('webcam', String(state.webcam));
            }
            if (state.style === 'dual-only' || state.style === 'full-dual') {
                params.set('cams', state.cams.slice(0, 2).join(','));
            }
            if (state.style === 'multi-only' || state.style === 'full-multi') {
                params.set('cams', state.cams.slice(0, 4).join(','));
            }
            // Explicitly convert to string to handle width=0 or height=0 correctly
            params.set('width', String(state.width));
            params.set('height', String(state.height));
            params.set('target', state.target);
            if (state.tempUnit !== 'F') params.set('temp', state.tempUnit);
            if (state.distUnit !== 'ft') params.set('dist', state.distUnit);
            if (state.windUnit !== 'kt') params.set('wind', state.windUnit);
            if (state.baroUnit !== 'inHg') params.set('baro', state.baroUnit);
            
            var newUrl = window.location.pathname + '?' + params.toString();
            window.history.replaceState({}, '', newUrl);
        }
        
        // Generate dashboard URL
        function getDashboardUrl() {
            if (!state.airport) return null;
            return 'https://' + state.airport.id + '.' + BASE_DOMAIN;
        }
        
        // Generate embed code based on type
        function generateEmbedCode() {
            if (!state.airport) {
                return '<!-- Select an airport to generate embed code -->';
            }
            
            var embedUrl = getEmbedUrl();
            var dashboardUrl = getDashboardUrl();
            
            switch (state.embedType) {
                case 'iframe':
                    return '<iframe\n  src="' + embedUrl + '"\n  width="' + state.width + '"\n  height="' + state.height + '"\n  frameborder="0"\n  loading="lazy"\n  title="' + state.airport.identifier + ' Weather - AviationWX.org">\n</iframe>';
                
                case 'webcomponent':
                    var wcAttrs = ' airport="' + state.airport.id + '" style="' + state.style + '" theme="' + state.theme + '"';
                    if (state.style === 'webcam-only' || state.style === 'full' || state.style === 'full-single') {
                        wcAttrs += ' webcam="' + state.webcam + '"';
                    } else if (state.style === 'dual-only' || state.style === 'full-dual') {
                        wcAttrs += ' cams="' + state.cams.slice(0, 2).join(',') + '"';
                    } else if (state.style === 'multi-only' || state.style === 'full-multi') {
                        wcAttrs += ' cams="' + state.cams.slice(0, 4).join(',') + '"';
                    }
                    return '<!-- Include the AviationWX.org widget script (once per page) -->\n<script src="https://embed.' + BASE_DOMAIN + '/widget.js"></' + 'script>\n\n<!-- Place the widget where you want it to appear -->\n<aviation-wx' + wcAttrs + '>\n</aviation-wx>';
                
                default:
                    return '';
            }
        }
        
        // Update preview
        function updatePreview() {
            // Update dimensions display
            elements.previewDimensions.textContent = state.width + ' × ' + state.height + ' px';
            
            // Update preview frame size
            elements.previewFrame.style.width = state.width + 'px';
            elements.previewFrame.style.height = state.height + 'px';
            
            // If no airport selected, show placeholder
            if (!state.airport) {
                elements.previewPlaceholder.style.display = 'block';
                // Remove any existing iframe
                var existingIframe = elements.previewFrame.querySelector('iframe');
                if (existingIframe) {
                    existingIframe.remove();
                }
                return;
            }
            
            // Hide placeholder
            elements.previewPlaceholder.style.display = 'none';
            
            // Create or update iframe
            var iframe = elements.previewFrame.querySelector('iframe');
            if (!iframe) {
                iframe = document.createElement('iframe');
                iframe.style.border = 'none';
                iframe.style.width = '100%';
                iframe.style.height = '100%';
                elements.previewFrame.appendChild(iframe);
            }
            
            iframe.src = getEmbedUrl();
        }
        
        // Update embed code display
        function updateEmbedCode() {
            var code = generateEmbedCode();
            elements.embedCode.innerHTML = '<code>' + escapeHtml(code) + '</code>';
            
            // Update embed type label and info
            switch (state.embedType) {
                case 'iframe':
                    elements.embedTypeLabel.textContent = 'iframe Embed Code';
                    elements.embedInfo.innerHTML = '<strong>iframe Embed:</strong> Works on Google Sites, WordPress, Squarespace, and any HTML page. Simply paste the code where you want the widget to appear.';
                    break;
                case 'webcomponent':
                    elements.embedTypeLabel.textContent = 'Web Component Code';
                    elements.embedInfo.innerHTML = '<strong>Web Component:</strong> Modern approach that integrates seamlessly with your page. Requires JavaScript support.';
                    break;
            }
            
            // Update browser URL to reflect current state (for sharing/bookmarking)
            updateBrowserUrl();
        }
        
        // Escape HTML for display
        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Update webcam options based on airport
        function updateWebcamOptions() {
            var singleSelectGroup = document.getElementById('single-webcam-select-group');
            var multiCamSlots = document.getElementById('multi-cam-slots');
            var dualCamSlots = document.getElementById('dual-cam-slots');
            var multiCamSlots4 = document.getElementById('multi-cam-slots-4');
            
            if (!state.airport || !state.airport.has_webcams) {
                elements.webcamOptions.style.display = 'none';
                return;
            }
            
            // Show webcam options for webcam styles
            if (state.style === 'webcam-only' || state.style === 'dual-only' || state.style === 'multi-only' || state.style === 'full' || state.style === 'full-single' || state.style === 'full-dual' || state.style === 'full-multi') {
                elements.webcamOptions.style.display = 'block';
                
                var camCount = state.airport.webcam_count;
                var camNames = state.airport.webcam_names || [];
                
                // Build options HTML for all selectors
                function buildOptions(selectedIndex) {
                    var html = '';
                    for (var i = 0; i < camCount; i++) {
                        var name = camNames[i] || ('Camera ' + (i + 1));
                        var selected = (i === selectedIndex) ? ' selected' : '';
                        html += '<option value="' + i + '"' + selected + '>' + name + '</option>';
                    }
                    return html;
                }
                
                if (state.style === 'webcam-only' || state.style === 'full' || state.style === 'full-single') {
                    // Single webcam selector
                    singleSelectGroup.style.display = 'block';
                    multiCamSlots.style.display = 'none';
                    elements.webcamSelect.innerHTML = buildOptions(state.webcam);
                } else if (state.style === 'dual-only' || state.style === 'full-dual') {
                    // Dual camera selectors
                    singleSelectGroup.style.display = 'none';
                    multiCamSlots.style.display = 'block';
                    dualCamSlots.style.display = 'block';
                    multiCamSlots4.style.display = 'none';
                    
                    document.getElementById('cam-slot-0').innerHTML = buildOptions(state.cams[0]);
                    document.getElementById('cam-slot-1').innerHTML = buildOptions(state.cams[1] < camCount ? state.cams[1] : Math.min(1, camCount - 1));
                } else if (state.style === 'multi-only' || state.style === 'full-multi') {
                    // 4 camera grid selectors
                    singleSelectGroup.style.display = 'none';
                    multiCamSlots.style.display = 'block';
                    dualCamSlots.style.display = 'none';
                    multiCamSlots4.style.display = 'block';
                    
                    for (var i = 0; i < 4; i++) {
                        var select = document.getElementById('cam-slot-' + i + '-multi');
                        var defaultCam = state.cams[i] < camCount ? state.cams[i] : Math.min(i, camCount - 1);
                        select.innerHTML = buildOptions(defaultCam);
                    }
                }
            } else {
                elements.webcamOptions.style.display = 'none';
            }
        }
        
        // Update webcam style availability
        function updateWebcamStyles() {
            var hasWebcams = state.airport && state.airport.has_webcams;
            var webcamStyles = [elements.styleWebcamOnly, elements.styleDualOnly, elements.styleMultiOnly, elements.styleFullSingle, elements.styleFullDual, elements.styleFullMulti];
            var webcamStyleValues = ['webcam-only', 'dual-only', 'multi-only', 'full-single', 'full-dual', 'full-multi'];
            
            if (hasWebcams) {
                webcamStyles.forEach(function(el) { if (el) { el.classList.remove('disabled'); el.querySelector('input').disabled = false; } });
            } else {
                webcamStyles.forEach(function(el) { if (el) { el.classList.add('disabled'); el.querySelector('input').disabled = true; } });
                
                // If webcam style was selected, switch to card
                if (webcamStyleValues.indexOf(state.style) >= 0) {
                    state.style = 'card';
                    var cardRadio = document.querySelector('input[name="style"][value="card"]');
                    if (cardRadio) cardRadio.checked = true;
                    updateSizeFromStyle();
                }
            }
        }
        
        // Update size from style preset
        function updateSizeFromStyle() {
            var preset = SIZE_PRESETS[state.style];
            if (preset) {
                state.width = preset.width;
                state.height = preset.height;
                elements.widthInput.value = preset.width;
                elements.heightInput.value = preset.height;
            }
        }
        
        // Airport search
        function searchAirports(query) {
            if (!query || query.length < 2) return [];
            
            var q = query.toLowerCase();
            var results = AIRPORTS.filter(function(airport) {
                return (
                    airport.name.toLowerCase().indexOf(q) !== -1 ||
                    airport.identifier.toLowerCase().indexOf(q) !== -1 ||
                    (airport.icao && airport.icao.toLowerCase().indexOf(q) !== -1) ||
                    (airport.iata && airport.iata.toLowerCase().indexOf(q) !== -1) ||
                    (airport.faa && airport.faa.toLowerCase().indexOf(q) !== -1)
                );
            });
            
            // Sort: exact matches first
            results.sort(function(a, b) {
                var aExact = a.identifier.toLowerCase() === q;
                var bExact = b.identifier.toLowerCase() === q;
                if (aExact && !bExact) return -1;
                if (!aExact && bExact) return 1;
                return a.name.localeCompare(b.name);
            });
            
            return results.slice(0, 10);
        }
        
        // Show search dropdown
        function showDropdown(results) {
            if (results.length === 0) {
                elements.dropdown.innerHTML = '<div class="airport-option" style="color: #666; cursor: default;">No airports found</div>';
            } else {
                elements.dropdown.innerHTML = results.map(function(airport, index) {
                    return '<div class="airport-option" data-index="' + index + '">' +
                        '<span class="identifier">' + escapeHtml(airport.identifier) + '</span> ' +
                        '<span class="name">' + escapeHtml(airport.name) + '</span>' +
                        '</div>';
                }).join('');
            }
            elements.dropdown.classList.add('show');
            elements.dropdown._results = results;
        }
        
        // Select airport
        function selectAirport(airport) {
            state.airport = airport;
            
            // Update UI
            elements.searchInput.value = '';
            elements.dropdown.classList.remove('show');
            elements.selectedAirport.style.display = 'block';
            elements.selectedIdentifier.textContent = airport.identifier;
            elements.selectedName.textContent = airport.name;
            
            // Update webcam-related UI
            updateWebcamStyles();
            updateWebcamOptions();
            
            // Update preview, code, and URL
            updatePreview();
            updateEmbedCode();
            updateBrowserUrl();
        }
        
        // Clear airport selection
        function clearAirport() {
            state.airport = null;
            elements.selectedAirport.style.display = 'none';
            elements.searchInput.value = '';
            updateWebcamStyles();
            updateWebcamOptions();
            updatePreview();
            updateEmbedCode();
            updateBrowserUrl();
        }
        
        // Event listeners
        var searchTimeout = null;
        elements.searchInput.addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                var results = searchAirports(e.target.value);
                if (e.target.value.length >= 2) {
                    showDropdown(results);
                } else {
                    elements.dropdown.classList.remove('show');
                }
            }, 150);
        });
        
        elements.searchInput.addEventListener('focus', function() {
            if (this.value.length >= 2) {
                showDropdown(searchAirports(this.value));
            }
        });
        
        document.addEventListener('click', function(e) {
            if (!elements.searchInput.contains(e.target) && !elements.dropdown.contains(e.target)) {
                elements.dropdown.classList.remove('show');
            }
        });
        
        elements.dropdown.addEventListener('click', function(e) {
            var option = e.target.closest('.airport-option');
            if (option && option.dataset.index !== undefined) {
                var index = parseInt(option.dataset.index, 10);
                var results = elements.dropdown._results;
                if (results && results[index]) {
                    selectAirport(results[index]);
                }
            }
        });
        
        elements.clearAirport.addEventListener('click', clearAirport);
        
        // Style radio buttons
        document.querySelectorAll('input[name="style"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                state.style = this.value;
                updateSizeFromStyle();
                updateWebcamOptions();
                updatePreview();
                updateEmbedCode();
            });
        });
        
        // Theme radio buttons
        document.querySelectorAll('input[name="theme"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                state.theme = this.value;
                updatePreview();
                updateEmbedCode();
            });
        });
        
        // Target radio buttons
        document.querySelectorAll('input[name="target"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                state.target = this.value;
                updateEmbedCode();
            });
        });
        
        // Unit radio buttons
        document.querySelectorAll('input[name="temp_unit"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                state.tempUnit = this.value;
                updatePreview();
                updateEmbedCode();
            });
        });
        
        document.querySelectorAll('input[name="dist_unit"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                state.distUnit = this.value;
                updatePreview();
                updateEmbedCode();
            });
        });
        
        document.querySelectorAll('input[name="wind_unit"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                state.windUnit = this.value;
                updatePreview();
                updateEmbedCode();
            });
        });
        
        document.querySelectorAll('input[name="baro_unit"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                state.baroUnit = this.value;
                updatePreview();
                updateEmbedCode();
            });
        });
        
        // Size inputs
        elements.widthInput.addEventListener('input', function() {
            state.width = parseInt(this.value, 10) || 300;
            updatePreview();
            updateEmbedCode();
        });
        
        elements.heightInput.addEventListener('input', function() {
            state.height = parseInt(this.value, 10) || 200;
            updatePreview();
            updateEmbedCode();
        });
        
        // Webcam select (single)
        elements.webcamSelect.addEventListener('change', function() {
            state.webcam = parseInt(this.value, 10) || 0;
            updatePreview();
            updateEmbedCode();
        });
        
        // Dual camera slot selects
        document.querySelectorAll('.cam-slot-select').forEach(function(select) {
            select.addEventListener('change', function() {
                var slot = parseInt(this.dataset.slot, 10);
                state.cams[slot] = parseInt(this.value, 10) || 0;
                updatePreview();
                updateEmbedCode();
            });
        });
        
        // 4 camera grid slot selects
        document.querySelectorAll('.cam-slot-select-multi').forEach(function(select) {
            select.addEventListener('change', function() {
                var slot = parseInt(this.dataset.slot, 10);
                state.cams[slot] = parseInt(this.value, 10) || 0;
                updatePreview();
                updateEmbedCode();
            });
        });
        
        // Embed type tabs
        document.querySelectorAll('.embed-tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.embed-tab').forEach(function(t) {
                    t.classList.remove('active');
                });
                this.classList.add('active');
                state.embedType = this.dataset.type;
                updateEmbedCode();
            });
        });
        
        // Copy button
        elements.copyBtn.addEventListener('click', function() {
            var code = generateEmbedCode();
            navigator.clipboard.writeText(code).then(function() {
                elements.copyBtn.textContent = '✓ Copied!';
                elements.copyBtn.classList.add('copied');
                setTimeout(function() {
                    elements.copyBtn.textContent = '📋 Copy';
                    elements.copyBtn.classList.remove('copied');
                }, 2000);
            });
        });
        
        // Keyboard navigation for dropdown
        var selectedDropdownIndex = -1;
        elements.searchInput.addEventListener('keydown', function(e) {
            var options = elements.dropdown.querySelectorAll('.airport-option[data-index]');
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedDropdownIndex = Math.min(selectedDropdownIndex + 1, options.length - 1);
                options.forEach(function(opt, i) {
                    opt.classList.toggle('selected', i === selectedDropdownIndex);
                });
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedDropdownIndex = Math.max(selectedDropdownIndex - 1, 0);
                options.forEach(function(opt, i) {
                    opt.classList.toggle('selected', i === selectedDropdownIndex);
                });
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (selectedDropdownIndex >= 0 && elements.dropdown._results) {
                    selectAirport(elements.dropdown._results[selectedDropdownIndex]);
                }
            } else if (e.key === 'Escape') {
                elements.dropdown.classList.remove('show');
            }
        });
        
        // Initialize from URL parameters (for sharing/bookmarking)
        function initFromUrl() {
            var params = new URLSearchParams(window.location.search);
            
            // Style
            var style = params.get('style');
            if (style && ['card', 'webcam', 'dual', 'multi', 'full', 'full-single', 'full-dual', 'full-multi'].indexOf(style) !== -1) {
                state.style = style;
                var styleRadio = document.querySelector('input[name="style"][value="' + style + '"]');
                if (styleRadio) styleRadio.checked = true;
            }
            
            // Theme
            var theme = params.get('theme');
            if (theme && ['light', 'dark', 'auto'].indexOf(theme) !== -1) {
                state.theme = theme;
                var themeRadio = document.querySelector('input[name="theme"][value="' + theme + '"]');
                if (themeRadio) themeRadio.checked = true;
            }
            
            // Size
            var width = params.get('width');
            var height = params.get('height');
            if (width && !isNaN(parseInt(width))) {
                state.width = parseInt(width);
                if (elements.widthInput) {
                    elements.widthInput.value = state.width;
                }
            }
            if (height && !isNaN(parseInt(height))) {
                state.height = parseInt(height);
                if (elements.heightInput) {
                    elements.heightInput.value = state.height;
                }
            }
            
            // Target
            var target = params.get('target');
            if (target && ['_blank', '_self'].indexOf(target) !== -1) {
                state.target = target;
                var targetRadio = document.querySelector('input[name="target"][value="' + target + '"]');
                if (targetRadio) targetRadio.checked = true;
            }
            
            // Units
            var temp = params.get('temp');
            if (temp && ['F', 'C'].indexOf(temp) !== -1) {
                state.tempUnit = temp;
                var tempRadio = document.querySelector('input[name="temp-unit"][value="' + temp + '"]');
                if (tempRadio) tempRadio.checked = true;
            }
            var dist = params.get('dist');
            if (dist && ['ft', 'm'].indexOf(dist) !== -1) {
                state.distUnit = dist;
                var distRadio = document.querySelector('input[name="dist-unit"][value="' + dist + '"]');
                if (distRadio) distRadio.checked = true;
            }
            var wind = params.get('wind');
            if (wind && ['kt', 'mph', 'kmh'].indexOf(wind) !== -1) {
                state.windUnit = wind;
                var windRadio = document.querySelector('input[name="wind-unit"][value="' + wind + '"]');
                if (windRadio) windRadio.checked = true;
            }
            var baro = params.get('baro');
            if (baro && ['inHg', 'hPa', 'mmHg'].indexOf(baro) !== -1) {
                state.baroUnit = baro;
                var baroRadio = document.querySelector('input[name="baro-unit"][value="' + baro + '"]');
                if (baroRadio) baroRadio.checked = true;
            }
            
            // Webcam
            var webcam = params.get('webcam');
            if (webcam && !isNaN(parseInt(webcam))) {
                state.webcam = parseInt(webcam);
            }
            
            // Cams (for multi-cam widgets)
            var cams = params.get('cams');
            if (cams) {
                state.cams = cams.split(',').map(function(c) { return parseInt(c) || 0; });
            }
            
            // Airport (load from URL)
            var airportId = params.get('airport');
            if (airportId) {
                // Find the airport in our list
                var foundAirport = null;
                for (var i = 0; i < AIRPORTS.length; i++) {
                    if (AIRPORTS[i].id.toLowerCase() === airportId.toLowerCase() ||
                        AIRPORTS[i].identifier.toLowerCase() === airportId.toLowerCase()) {
                        foundAirport = AIRPORTS[i];
                        break;
                    }
                }
                if (foundAirport) {
                    selectAirport(foundAirport);
                }
            }
        }
        
        // Initialize
        initFromUrl();
        updateWebcamStyles();
        updatePreview();
        updateEmbedCode();
    })();
    </script>
</body>
</html>

