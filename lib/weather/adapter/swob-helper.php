<?php
/**
 * SWOB-ML XML Helper
 *
 * Parses Environment Canada SWOB-ML XML into a standard weather array.
 * Used by swob_auto and swob_man adapters for Canadian airport weather.
 *
 * @package AviationWX\Weather\Adapter
 */

/**
 * Parse SWOB-ML XML into a standard weather array.
 *
 * Extracts: air_temp, dwpt_temp, rel_hum, altmetr_setng, wind, visibility,
 * cloud cover/ceiling. Treats MSNG as null. Converts units to aviation standard.
 *
 * @param string $xml Raw SWOB-ML XML
 * @return array|null Weather array or null on parse failure
 */
function parseSwobXmlToWeatherArray(string $xml): ?array
{
    if (trim($xml) === '') {
        return null;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (@$dom->loadXML($xml) === false) {
        libxml_clear_errors();
        return null;
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('om', 'http://www.opengis.net/om/1.0');

    $getValue = function (string $name) use ($xpath): ?string {
        $nodes = $xpath->query("//*[local-name()='element' and @name='" . $name . "']");
        $el = $nodes->item(0);
        if ($el === null) {
            return null;
        }
        $val = $el->getAttribute('value');
        return $val === '' ? null : $val;
    };

    $parseFloat = function (?string $v): ?float {
        if ($v === null || strtoupper($v) === 'MSNG') {
            return null;
        }
        return is_numeric($v) ? (float) $v : null;
    };

    $obsTime = null;
    $dateTm = $getValue('date_tm');
    if ($dateTm !== null && strtoupper($dateTm) !== 'MSNG') {
        $ts = strtotime($dateTm);
        $obsTime = $ts !== false ? $ts : null;
    }
    $obsTime = $obsTime ?? time();

    $temperature = $parseFloat($getValue('air_temp'));
    $dewpoint = $parseFloat($getValue('dwpt_temp'));
    $humidity = $parseFloat($getValue('rel_hum'));
    $pressure = $parseFloat($getValue('altmetr_setng'));

    $windDir = $parseFloat($getValue('avg_wnd_dir_10m_pst10mts'));
    $windSpdKmh = $parseFloat($getValue('avg_wnd_spd_10m_pst10mts'));
    $windSpeed = $windSpdKmh !== null ? $windSpdKmh / 1.852 : null;

    $gustKmh = $parseFloat($getValue('max_wnd_gst_spd_10m_pst10mts'));
    $gustSpeed = $gustKmh !== null ? $gustKmh / 1.852 : null;

    $visKm = $parseFloat($getValue('avg_vis_pst10mts'));
    $visibility = $visKm !== null ? $visKm * 0.621371 : null;

    $cldCode = $parseFloat($getValue('cld_amt_code_1'));
    $cldHgtM = $parseFloat($getValue('cld_bas_hgt_1'));

    $cloudCover = mapWmoCloudCodeToCover($cldCode);
    $ceiling = null;
    if ($cloudCover !== null && in_array($cloudCover, ['BKN', 'OVC'], true) && $cldHgtM !== null) {
        $ceiling = $cldHgtM * 3.28084;
    }

    return [
        'temperature' => $temperature,
        'dewpoint' => $dewpoint,
        'humidity' => $humidity,
        'pressure' => $pressure,
        'wind_speed' => $windSpeed,
        'wind_direction' => $windDir !== null ? (int) round($windDir) : null,
        'gust_speed' => $gustSpeed,
        'visibility' => $visibility,
        'ceiling' => $ceiling,
        'cloud_cover' => $cloudCover,
        'obs_time' => $obsTime,
    ];
}

/**
 * Map WMO cloud amount code (2700) to aviation cover.
 *
 * @param float|int|null $code WMO code 0-8
 * @return string|null SKC, FEW, SCT, BKN, OVC, or null
 */
function mapWmoCloudCodeToCover($code): ?string
{
    if ($code === null) {
        return null;
    }
    $c = (int) $code;
    return match (true) {
        $c === 0 => 'SKC',
        $c >= 1 && $c <= 2 => 'FEW',
        $c >= 3 && $c <= 4 => 'SCT',
        $c >= 5 && $c <= 7 => 'BKN',
        $c === 8 => 'OVC',
        default => null,
    };
}
