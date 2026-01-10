# Federation Architecture - Design Document

## Overview

Enable single-airport installations of AviationWX to become **federated data sources** for the main AviationWX.org platform. This creates a decentralized network where airport operators maintain full control over their infrastructure while optionally participating in the larger network.

## Vision

**Decentralized Aviation Weather Network:**
- Airport operators self-host their own AviationWX installation
- Full control over cameras, weather stations, and data
- Expose data via standard AviationWX public API
- Main platform can optionally fetch from federated sources
- Creates resilient, distributed architecture

## Architecture

### Data Flow

```
┌─────────────────────────────────────────────────────────────┐
│ Local Airport Installation (weather.k0s9.org)              │
│                                                             │
│  ┌─────────────┐    ┌──────────────┐   ┌───────────────┐  │
│  │  Cameras    │───▶│  Processing  │──▶│  Public API   │  │
│  │  Weather    │    │  Pipeline    │   │  /api/v1/...  │  │
│  │  Stations   │    └──────────────┘   └───────┬───────┘  │
│  └─────────────┘                               │          │
└─────────────────────────────────────────────────┼──────────┘
                                                  │
                        HTTPS + API Key           │
                                                  │
┌─────────────────────────────────────────────────┼──────────┐
│ AviationWX.org Main Platform                    ▼          │
│                                                             │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ Weather Source: aviationwx_api                      │  │
│  │ - Fetches from federated instance                   │  │
│  │ - Treats like any other weather source              │  │
│  │ - Falls back on failure                             │  │
│  └──────────────────────────────────────────────────────┘  │
│                                                             │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ Unified Dashboard                                    │  │
│  │ - Shows all airports (local + federated)            │  │
│  │ - Consistent UI                                      │  │
│  └──────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

## Implementation

### 1. New Weather Source Adapter

**File:** `lib/weather/adapter/AviationWXAPIAdapter.php`

```php
<?php
/**
 * AviationWX API Weather Adapter
 * 
 * Fetches weather data from another AviationWX instance's public API.
 * Enables federated architecture for self-hosted installations.
 * 
 * Source Configuration:
 * {
 *   "type": "aviationwx_api",
 *   "base_url": "https://weather.myairport.com",
 *   "api_key": "ak_live_federated_xyz123",
 *   "timeout_seconds": 10
 * }
 */

require_once __DIR__ . '/../WeatherAdapter.php';
require_once __DIR__ . '/../../circuit-breaker.php';
require_once __DIR__ . '/../../logger.php';

class AviationWXAPIAdapter implements WeatherAdapter {
    
    public function getName(): string {
        return 'AviationWX API (Federated)';
    }
    
    public function fetchWeather(string $airportId, array $sourceConfig): ?array {
        $baseUrl = rtrim($sourceConfig['base_url'] ?? '', '/');
        $apiKey = $sourceConfig['api_key'] ?? null;
        $timeout = $sourceConfig['timeout_seconds'] ?? 10;
        
        if (empty($baseUrl)) {
            aviationwx_log('error', 'AviationWXAPIAdapter: Missing base_url', [
                'airport_id' => $airportId
            ]);
            return null;
        }
        
        // Check circuit breaker
        $breakerKey = "aviationwx_api_{$baseUrl}";
        if (isCircuitOpen($breakerKey)) {
            aviationwx_log('warning', 'AviationWXAPIAdapter: Circuit breaker open', [
                'airport_id' => $airportId,
                'base_url' => $baseUrl
            ]);
            return null;
        }
        
        try {
            // Construct API endpoint
            $url = "{$baseUrl}/api/v1/weather/{$airportId}";
            
            // Make HTTP request
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => $this->buildHeaders($apiKey),
                CURLOPT_USERAGENT => 'AviationWX-Federation/1.0'
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($response === false) {
                recordCircuitBreakerFailure($breakerKey);
                aviationwx_log('error', 'AviationWXAPIAdapter: cURL error', [
                    'airport_id' => $airportId,
                    'base_url' => $baseUrl,
                    'error' => $error
                ]);
                return null;
            }
            
            if ($httpCode !== 200) {
                recordCircuitBreakerFailure($breakerKey);
                aviationwx_log('error', 'AviationWXAPIAdapter: HTTP error', [
                    'airport_id' => $airportId,
                    'base_url' => $baseUrl,
                    'http_code' => $httpCode
                ]);
                return null;
            }
            
            // Parse JSON response
            $data = json_decode($response, true);
            if (!$data || !is_array($data)) {
                recordCircuitBreakerFailure($breakerKey);
                aviationwx_log('error', 'AviationWXAPIAdapter: Invalid JSON', [
                    'airport_id' => $airportId,
                    'base_url' => $baseUrl
                ]);
                return null;
            }
            
            // Success - reset circuit breaker
            recordCircuitBreakerSuccess($breakerKey);
            
            // Transform API response to internal format
            return $this->transformResponse($data, $airportId);
            
        } catch (Exception $e) {
            recordCircuitBreakerFailure($breakerKey);
            aviationwx_log('error', 'AviationWXAPIAdapter: Exception', [
                'airport_id' => $airportId,
                'base_url' => $baseUrl,
                'exception' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    private function buildHeaders(?string $apiKey): array {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json'
        ];
        
        if ($apiKey) {
            $headers[] = "X-API-Key: {$apiKey}";
        }
        
        return $headers;
    }
    
    private function transformResponse(array $apiResponse, string $airportId): ?array {
        // The API response should already be in our standard format
        // Just validate it has required fields
        
        if (!isset($apiResponse['airport_id']) || $apiResponse['airport_id'] !== $airportId) {
            aviationwx_log('warning', 'AviationWXAPIAdapter: Airport ID mismatch', [
                'expected' => $airportId,
                'received' => $apiResponse['airport_id'] ?? 'null'
            ]);
            return null;
        }
        
        // Validate timestamp exists and is recent
        if (!isset($apiResponse['timestamp'])) {
            aviationwx_log('warning', 'AviationWXAPIAdapter: Missing timestamp', [
                'airport_id' => $airportId
            ]);
            return null;
        }
        
        $timestamp = strtotime($apiResponse['timestamp']);
        $age = time() - $timestamp;
        
        // Reject data older than 1 hour
        if ($age > 3600) {
            aviationwx_log('warning', 'AviationWXAPIAdapter: Data too old', [
                'airport_id' => $airportId,
                'age_seconds' => $age
            ]);
            return null;
        }
        
        // Return the data as-is (already in our format)
        return $apiResponse;
    }
}
```

### 2. Webcam Fetcher Integration

**File:** `scripts/fetch-webcam.php` (add new case)

```php
// Handle federated webcam sources
if (isset($webcam['type']) && $webcam['type'] === 'aviationwx_api') {
    $baseUrl = rtrim($webcam['base_url'] ?? '', '/');
    $apiKey = $webcam['api_key'] ?? null;
    $cameraIndex = $webcam['camera_index'] ?? 0;
    
    if (empty($baseUrl)) {
        aviationwx_log('error', 'Federated webcam: Missing base_url', [
            'airport_id' => $airportId,
            'camera_index' => $camIndex
        ]);
        return false;
    }
    
    // Fetch from federated instance's webcam endpoint
    $url = "{$baseUrl}/api/v1/webcams/{$airportId}/{$cameraIndex}/latest";
    
    // Use circuit breaker
    $breakerKey = "aviationwx_api_webcam_{$baseUrl}_{$airportId}_{$cameraIndex}";
    if (isCircuitOpen($breakerKey)) {
        aviationwx_log('warning', 'Federated webcam: Circuit breaker open', [
            'airport_id' => $airportId,
            'camera_index' => $camIndex
        ]);
        return false;
    }
    
    // Fetch image
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $apiKey ? ["X-API-Key: {$apiKey}"] : [],
        CURLOPT_USERAGENT => 'AviationWX-Federation/1.0'
    ]);
    
    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($imageData === false || $httpCode !== 200) {
        recordCircuitBreakerFailure($breakerKey);
        return false;
    }
    
    recordCircuitBreakerSuccess($breakerKey);
    
    // Save to local storage
    return saveWebcamImage($airportId, $camIndex, $imageData);
}
```

### 3. Configuration Examples

#### On Main Platform (aviationwx.org)

```json
{
  "airports": {
    "k0s9": {
      "name": "Jefferson County International",
      "enabled": true,
      "federated": true,
      "federated_source": "https://weather.k0s9.org",
      
      "weather_source": {
        "type": "aviationwx_api",
        "base_url": "https://weather.k0s9.org",
        "api_key": "ak_live_federated_k0s9_12345",
        "timeout_seconds": 10
      },
      
      "weather_source_backup": {
        "type": "metar",
        "station": "K0S9"
      },
      
      "webcams": [
        {
          "name": "Runway 26",
          "type": "aviationwx_api",
          "base_url": "https://weather.k0s9.org",
          "api_key": "ak_live_federated_k0s9_12345",
          "camera_index": 0
        },
        {
          "name": "Runway 08",
          "type": "aviationwx_api",
          "base_url": "https://weather.k0s9.org",
          "api_key": "ak_live_federated_k0s9_12345",
          "camera_index": 1
        }
      ]
    }
  }
}
```

#### On Local Install (weather.k0s9.org)

```json
{
  "config": {
    "base_domain": "weather.k0s9.org",
    
    "public_api": {
      "enabled": true,
      "version": "1",
      "rate_limits": {
        "partner": {
          "requests_per_minute": 120,
          "requests_per_hour": 5000,
          "requests_per_day": 50000
        }
      },
      "partner_keys": {
        "ak_live_federated_k0s9_12345": {
          "name": "AviationWX.org Main Platform",
          "contact": "federated@aviationwx.org",
          "enabled": true,
          "tier": "partner",
          "created": "2026-01-09",
          "notes": "Federated data sharing with main platform"
        }
      }
    }
  },
  
  "airports": {
    "k0s9": {
      "name": "Jefferson County International",
      "enabled": true,
      
      "weather_source": {
        "type": "tempest",
        "station_id": "149918",
        "api_key": "local_tempest_api_key"
      },
      
      "webcams": [
        {
          "name": "Runway 26",
          "url": "rtsp://camera1.local:554/stream",
          "type": "rtsp"
        },
        {
          "name": "Runway 08",
          "url": "rtsp://camera2.local:554/stream",
          "type": "rtsp"
        }
      ]
    }
  }
}
```

## Benefits

### For Airport Operators
- ✅ **Full Control:** Own infrastructure, cameras, data
- ✅ **No Vendor Lock-In:** Can leave network anytime
- ✅ **Local Processing:** Data processed on-site (privacy, performance)
- ✅ **Optional Participation:** Choose to federate or stay standalone
- ✅ **Recognition:** Airport listed on main platform
- ✅ **Fallback:** If main platform is down, local site still works

### For AviationWX.org Platform
- ✅ **Scalability:** Expand coverage without infrastructure costs
- ✅ **Resilience:** Distributed architecture (no single point of failure)
- ✅ **Community Growth:** Empower airport operators
- ✅ **Graceful Degradation:** Falls back if federated source offline
- ✅ **Lower Bandwidth:** Only fetch API responses, not raw streams

### For Pilots/Users
- ✅ **More Coverage:** Access to more airports
- ✅ **Consistent Interface:** All via aviationwx.org
- ✅ **Direct Access:** Can also bookmark individual airport sites
- ✅ **Reliability:** Multiple data sources

## Security

### Authentication
- Partner API keys for federated sources
- Rate limiting per key (high limits for federation)
- Keys stored securely in config (not in repo)
- Keys can be revoked by either party

### Data Validation
- Validate airport ID matches
- Reject stale data (> 1 hour old)
- Sanity checks on weather values
- Circuit breaker for failing sources

### Privacy
- Local installs control what data to expose
- Can restrict API access to specific IPs
- HTTPS required for federation
- No raw camera streams sent (only processed images)

### Failure Handling
- Circuit breaker prevents cascading failures
- Automatic fallback to backup sources
- Never silently fail (safety-critical)
- Log all federation issues

## Future Enhancements

### Phase 1 (Current): Basic Federation
- Manual configuration of federated sources
- One-way data flow (local → main)
- Partner API keys

### Phase 2: Auto-Discovery
- DNS TXT records for federation metadata
- Example: `_aviationwx.k0s9.org TXT "api=https://weather.k0s9.org,version=1"`
- Automatic detection of federated instances
- Self-registration workflow

### Phase 3: Health Monitoring
- Periodic health checks of federated sources
- Auto-disable if consistently down
- Email notifications to airport operators
- Status dashboard for federated sources

### Phase 4: Bidirectional Federation
- Large installs can federate with each other
- Create mesh network of weather data
- Distributed load balancing
- Geographic redundancy

### Phase 5: Contributor Dashboard
- Public recognition page on main site
- Show federated contributors
- Credit airport operators/sponsors
- Statistics (uptime, requests served)

## Testing Strategy

### Local Testing
1. Set up two local instances (port 8080 and 8081)
2. Configure port 8081 as federated source for 8080
3. Test weather fetching
4. Test webcam fetching
5. Test circuit breaker behavior

### Integration Tests
- Mock federated API responses
- Test timeout handling
- Test HTTP error codes
- Test invalid JSON
- Test stale data rejection

### Production Rollout
1. Start with one pilot airport (volunteer)
2. Monitor for 1 week
3. Fix any issues discovered
4. Expand to 2-3 more airports
5. Document best practices
6. Open to community

## Documentation Needs

### For Airport Operators
- `docs/FEDERATION_SETUP.md` - How to enable federation
- API key generation process
- Security best practices
- Troubleshooting guide

### For Platform Maintainers
- `docs/FEDERATION_ARCHITECTURE.md` - This document
- How to add federated airports
- Monitoring and maintenance
- Circuit breaker tuning

### For Users
- Federated airports marked on map
- Link to local airport site
- Explain distributed nature

## Rollout Plan

### Phase 1: Foundation (Current Task)
- [x] Design federation architecture
- [ ] Implement `AviationWXAPIAdapter.php`
- [ ] Add webcam federation support
- [ ] Add tests
- [ ] Documentation

### Phase 2: Pilot Program
- [ ] Find volunteer airport operator
- [ ] Set up first federated airport
- [ ] Monitor for issues
- [ ] Refine based on feedback

### Phase 3: Community Launch
- [ ] Announce federation capability
- [ ] Create onboarding workflow
- [ ] Build contributor dashboard
- [ ] Marketing/outreach

## Success Metrics

- Number of federated airports
- Uptime of federated sources
- API response times
- Circuit breaker activations
- User feedback
- Community growth

## Conclusion

Federation transforms AviationWX from a centralized platform into a **decentralized network**. This empowers airport operators while expanding coverage for pilots. The architecture is robust, secure, and scales naturally with community growth.
