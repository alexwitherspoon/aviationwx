<?php
/**
 * NASR airport identifier resolution from platform config.
 */

/**
 * Candidate NASR ARPT_ID values for an airport config row (priority order).
 *
 * @param array $airport Airport configuration
 * @return list<string> Uppercase identifiers
 */
function nasrCandidateArptIds(array $airport): array
{
    $candidates = [];

    foreach (['faa', 'icao', 'iata'] as $key) {
        if (!empty($airport[$key]) && is_string($airport[$key])) {
            $candidates[] = strtoupper(trim($airport[$key]));
        }
    }

    if (!empty($airport['id']) && is_string($airport['id'])) {
        $candidates[] = strtoupper(trim($airport['id']));
    }

    if (!empty($airport['formerly']) && is_array($airport['formerly'])) {
        foreach ($airport['formerly'] as $former) {
            if (is_string($former) && $former !== '') {
                $candidates[] = strtoupper(trim($former));
            }
        }
    }

    $unique = [];
    foreach ($candidates as $id) {
        if ($id !== '' && !in_array($id, $unique, true)) {
            $unique[] = $id;
        }
    }

    // US ICAO (K + 3-char LID) often maps to NASR ARPT_ID without the K prefix.
    foreach ($unique as $id) {
        if (strlen($id) === 4 && $id[0] === 'K') {
            $lid = substr($id, 1);
            if ($lid !== '' && !in_array($lid, $unique, true)) {
                $unique[] = $lid;
            }
        }
    }

    return $unique;
}
