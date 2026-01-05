<?php
/**
 * Webcam Refresh Staggering Functions
 * 
 * Functions for calculating stagger offsets to distribute client requests
 * and avoid server load spikes when cron jobs trigger image generation.
 */

/**
 * Calculate random stagger offset for webcam refresh
 * 
 * Returns a random offset between 20-30% of the base refresh interval.
 * This distributes client requests over time, avoiding clustering at the
 * beginning of each minute when cron jobs run.
 * 
 * Random offset per client (better distribution, avoids clustering).
 * No sessionStorage needed - each page load gets new random offset.
 * 
 * @param int $baseInterval Refresh interval in seconds (minimum 60, typically 60-900)
 * @return int Stagger offset in seconds (20-30% of interval, rounded down)
 */
function calculateWebcamStaggerOffset($baseInterval) {
    // Random offset 20-30% of interval (accounts for format generation + buffer)
    // For 60s: 12-18 seconds
    // For 30s: 6-9 seconds  
    // For 120s: 24-36 seconds
    $minPercent = 0.20;
    $maxPercent = 0.30;
    $percent = $minPercent + (mt_rand() / mt_getrandmax()) * ($maxPercent - $minPercent);
    
    return (int)floor($baseInterval * $percent);
}
