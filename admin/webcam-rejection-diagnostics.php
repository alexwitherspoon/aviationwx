<?php
/**
 * Webcam Rejection Diagnostics
 * 
 * Displays rejection metrics and recent rejection logs for push webcams.
 * Shows APCu metrics and lists rejected images in rejections directories.
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/webcam-upload-metrics.php';
require_once __DIR__ . '/../lib/webcam-rejection-logger.php';

header('Content-Type: text/html; charset=utf-8');

$config = loadConfig();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webcam Rejection Diagnostics - AviationWX</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #0066cc;
            padding-bottom: 10px;
        }
        h2 {
            color: #444;
            margin-top: 30px;
            border-bottom: 2px solid #ccc;
            padding-bottom: 5px;
        }
        h3 {
            color: #555;
            margin-top: 20px;
        }
        .camera-section {
            background: white;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 15px 0;
        }
        .metric-card {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
            border-left: 4px solid #0066cc;
        }
        .metric-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .metric-value {
            font-size: 28px;
            font-weight: bold;
            color: #333;
            margin: 5px 0;
        }
        .metric-value.warning {
            color: #ff9800;
        }
        .metric-value.error {
            color: #f44336;
        }
        .rejection-reasons {
            margin: 15px 0;
        }
        .reason-item {
            background: #fff3cd;
            padding: 10px;
            margin: 5px 0;
            border-radius: 4px;
            border-left: 4px solid #ff9800;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .reason-count {
            font-weight: bold;
            font-size: 18px;
            color: #ff9800;
        }
        .rejected-images {
            margin: 15px 0;
        }
        .image-item {
            background: #f8d7da;
            padding: 10px;
            margin: 5px 0;
            border-radius: 4px;
            border-left: 4px solid #dc3545;
            font-family: monospace;
            font-size: 14px;
        }
        .image-item .timestamp {
            color: #721c24;
            font-weight: bold;
        }
        .image-item .files {
            margin-top: 5px;
            color: #555;
        }
        .no-data {
            color: #666;
            font-style: italic;
            padding: 20px;
            text-align: center;
            background: #f9f9f9;
            border-radius: 4px;
        }
        .success {
            color: #4caf50;
        }
        .timestamp-note {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        .log-preview {
            background: #333;
            color: #fff;
            padding: 15px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
            margin-top: 10px;
            max-height: 300px;
            overflow-y: auto;
            white-space: pre-wrap;
        }
        .view-log-btn {
            background: #0066cc;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            margin-top: 5px;
        }
        .view-log-btn:hover {
            background: #0052a3;
        }
    </style>
</head>
<body>
    <h1>🚫 Webcam Rejection Diagnostics</h1>
    
    <p><strong>Last Updated:</strong> <?php echo date('Y-m-d H:i:s T'); ?></p>
    <p class="timestamp-note">Showing rejection metrics from last 60 minutes (APCu cache + filesystem)</p>
    
    <?php
    $cacheBase = getCacheDirectory();
    $hasData = false;
    
    if (isset($config['airports']) && is_array($config['airports'])) {
        foreach ($config['airports'] as $airport) {
            $airportId = strtolower($airport['id'] ?? '');
            if (empty($airportId)) continue;
            
            if (!isset($airport['webcams']) || !is_array($airport['webcams'])) continue;
            
            foreach ($airport['webcams'] as $camIndex => $webcam) {
                // Only show push cameras
                if (!isset($webcam['push_config'])) {
                    continue;
                }
                
                $hasData = true;
                $metrics = getWebcamUploadMetrics($airportId, $camIndex);
                $totalUploads = $metrics['accepted'] + $metrics['rejected'];
                $rejectionRate = $totalUploads > 0 ? ($metrics['rejected'] / $totalUploads) * 100 : 0;
                
                echo '<div class="camera-section">';
                echo '<h2>' . htmlspecialchars($airport['name'] ?? $airportId) . ' - Camera ' . $camIndex . '</h2>';
                echo '<p><strong>Camera Name:</strong> ' . htmlspecialchars($webcam['name'] ?? 'Unnamed') . '</p>';
                
                // Metrics
                echo '<div class="metrics-grid">';
                
                echo '<div class="metric-card">';
                echo '<div class="metric-label">Accepted (1h)</div>';
                echo '<div class="metric-value success">' . $metrics['accepted'] . '</div>';
                echo '</div>';
                
                $rejectedClass = $metrics['rejected'] > 0 ? 'error' : '';
                echo '<div class="metric-card">';
                echo '<div class="metric-label">Rejected (1h)</div>';
                echo '<div class="metric-value ' . $rejectedClass . '">' . $metrics['rejected'] . '</div>';
                echo '</div>';
                
                $rateClass = $rejectionRate > 10 ? 'error' : ($rejectionRate > 5 ? 'warning' : '');
                echo '<div class="metric-card">';
                echo '<div class="metric-label">Rejection Rate</div>';
                echo '<div class="metric-value ' . $rateClass . '">' . number_format($rejectionRate, 1) . '%</div>';
                echo '</div>';
                
                echo '</div>';
                
                // Rejection Reasons
                if (!empty($metrics['rejection_reasons'])) {
                    echo '<h3>Rejection Reasons (Last Hour)</h3>';
                    echo '<div class="rejection-reasons">';
                    arsort($metrics['rejection_reasons']);
                    foreach ($metrics['rejection_reasons'] as $reason => $count) {
                        echo '<div class="reason-item">';
                        echo '<span>' . htmlspecialchars($reason) . '</span>';
                        echo '<span class="reason-count">' . $count . '</span>';
                        echo '</div>';
                    }
                    echo '</div>';
                }
                
                // Check filesystem for rejected images
                if ($cacheBase) {
                    $rejectionsDir = $cacheBase . '/webcams/' . $airportId . '/' . $camIndex . '/rejections';
                    if (is_dir($rejectionsDir)) {
                        $files = glob($rejectionsDir . '/*');
                        if (!empty($files)) {
                            // Sort by modification time (newest first)
                            usort($files, function($a, $b) {
                                return filemtime($b) - filemtime($a);
                            });
                            
                            echo '<h3>Recent Rejected Images (Last 20)</h3>';
                            echo '<div class="rejected-images">';
                            
                            $imagesByBase = [];
                            foreach ($files as $file) {
                                $basename = pathinfo($file, PATHINFO_FILENAME);
                                $ext = pathinfo($file, PATHINFO_EXTENSION);
                                $imagesByBase[$basename][$ext] = $file;
                            }
                            
                            $count = 0;
                            foreach ($imagesByBase as $basename => $fileSet) {
                                if ($count >= 20) break;
                                
                                // Parse timestamp from filename
                                $timestamp = 'Unknown';
                                if (preg_match('/^(\d+)_rejected/', $basename, $matches)) {
                                    $timestamp = date('Y-m-d H:i:s', intval($matches[1]));
                                }
                                
                                echo '<div class="image-item">';
                                echo '<div class="timestamp">🕐 ' . htmlspecialchars($timestamp) . '</div>';
                                echo '<div class="files">';
                                
                                if (isset($fileSet['jpg']) || isset($fileSet['jpeg']) || isset($fileSet['png']) || isset($fileSet['webp'])) {
                                    $imageFile = $fileSet['jpg'] ?? $fileSet['jpeg'] ?? $fileSet['png'] ?? $fileSet['webp'] ?? null;
                                    if ($imageFile) {
                                        echo '📷 ' . basename($imageFile) . ' (' . number_format(filesize($imageFile)) . ' bytes)<br>';
                                    }
                                }
                                
                                if (isset($fileSet['log'])) {
                                    echo '📄 ' . basename($fileSet['log']);
                                    echo '<button class="view-log-btn" onclick="toggleLog(\'' . htmlspecialchars($basename) . '\')">View Log</button>';
                                    echo '<div id="log-' . htmlspecialchars($basename) . '" style="display:none;" class="log-preview">';
                                    echo htmlspecialchars(file_get_contents($fileSet['log']));
                                    echo '</div>';
                                }
                                
                                echo '</div>';
                                echo '</div>';
                                
                                $count++;
                            }
                            
                            echo '</div>';
                            
                            $totalRejected = count($imagesByBase);
                            if ($totalRejected > 20) {
                                echo '<p class="timestamp-note">Showing 20 of ' . $totalRejected . ' total rejected images in this directory.</p>';
                            }
                        } else {
                            echo '<p class="no-data">✅ No rejected images found in last rotation period.</p>';
                        }
                    }
                }
                
                echo '</div>';
            }
        }
    }
    
    if (!$hasData) {
        echo '<div class="no-data">';
        echo '<h2>No Push Webcams Configured</h2>';
        echo '<p>This system does not have any push webcams configured. Rejection tracking is only available for push webcams.</p>';
        echo '</div>';
    }
    ?>
    
    <script>
    function toggleLog(basename) {
        const logDiv = document.getElementById('log-' + basename);
        if (logDiv.style.display === 'none') {
            logDiv.style.display = 'block';
        } else {
            logDiv.style.display = 'none';
        }
    }
    </script>
</body>
</html>
