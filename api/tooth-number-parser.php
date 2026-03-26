<?php
/**
 * Tooth Number Parser Utility
 * 
 * Parses and validates tooth number input for Crown case type.
 * Supports multiple formats:
 * - Single tooth: 14
 * - Comma-separated: 14, 30
 * - Space-separated: 14 30
 * - Ranges: 14-18
 * - Combinations: 14-18, 30 31
 * 
 * All values must be integers between 1 and 32 (Universal Numbering System).
 */

define('MIN_TOOTH_NUMBER', 1);
define('MAX_TOOTH_NUMBER', 32);

/**
 * Parse and validate tooth number input supporting multiple formats
 * 
 * @param string $value Input string (e.g., "14", "14, 30", "14-18", "14-18, 30 31")
 * @return array ['valid' => bool, 'error' => string|null, 'numbers' => int[], 'normalized' => string]
 */
function parseToothNumbers($value) {
    if (empty($value) || trim($value) === '') {
        return [
            'valid' => false,
            'error' => 'At least one tooth number is required',
            'numbers' => [],
            'normalized' => ''
        ];
    }
    
    $trimmed = trim($value);
    $allNumbers = [];
    
    // Split by comma first
    $commaParts = explode(',', $trimmed);
    
    foreach ($commaParts as $commaPart) {
        // Then split each comma part by whitespace
        $spaceParts = preg_split('/\s+/', trim($commaPart));
        
        foreach ($spaceParts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            
            // Check if it's a range (e.g., "14-18")
            if (strpos($part, '-') !== false) {
                $rangeParts = explode('-', $part);
                
                // Validate range format (must have exactly 2 parts)
                if (count($rangeParts) !== 2) {
                    return [
                        'valid' => false,
                        'error' => 'Invalid range format: "' . $part . '"',
                        'numbers' => [],
                        'normalized' => ''
                    ];
                }
                
                $start = trim($rangeParts[0]);
                $end = trim($rangeParts[1]);
                
                // Validate both parts are numeric
                if (!ctype_digit($start) || !ctype_digit($end)) {
                    return [
                        'valid' => false,
                        'error' => 'Range values must be numbers (1-32): "' . $part . '"',
                        'numbers' => [],
                        'normalized' => ''
                    ];
                }
                
                $startNum = (int)$start;
                $endNum = (int)$end;
                
                // Validate range bounds
                if ($startNum < MIN_TOOTH_NUMBER || $startNum > MAX_TOOTH_NUMBER) {
                    return [
                        'valid' => false,
                        'error' => 'Tooth number ' . $startNum . ' must be between 1 and 32',
                        'numbers' => [],
                        'normalized' => ''
                    ];
                }
                if ($endNum < MIN_TOOTH_NUMBER || $endNum > MAX_TOOTH_NUMBER) {
                    return [
                        'valid' => false,
                        'error' => 'Tooth number ' . $endNum . ' must be between 1 and 32',
                        'numbers' => [],
                        'normalized' => ''
                    ];
                }
                
                // Validate range direction
                if ($startNum > $endNum) {
                    return [
                        'valid' => false,
                        'error' => 'Invalid range: start (' . $startNum . ') must be less than or equal to end (' . $endNum . ')',
                        'numbers' => [],
                        'normalized' => ''
                    ];
                }
                
                // Expand range
                for ($n = $startNum; $n <= $endNum; $n++) {
                    $allNumbers[] = $n;
                }
            } else {
                // Single number
                if (!ctype_digit($part)) {
                    return [
                        'valid' => false,
                        'error' => 'Tooth number must be a number (1-32): "' . $part . '"',
                        'numbers' => [],
                        'normalized' => ''
                    ];
                }
                
                $num = (int)$part;
                
                if ($num < MIN_TOOTH_NUMBER || $num > MAX_TOOTH_NUMBER) {
                    return [
                        'valid' => false,
                        'error' => 'Tooth number ' . $num . ' must be between 1 and 32',
                        'numbers' => [],
                        'normalized' => ''
                    ];
                }
                
                $allNumbers[] = $num;
            }
        }
    }
    
    if (empty($allNumbers)) {
        return [
            'valid' => false,
            'error' => 'At least one tooth number is required',
            'numbers' => [],
            'normalized' => ''
        ];
    }
    
    // Deduplicate and sort
    $uniqueNumbers = array_unique($allNumbers);
    sort($uniqueNumbers, SORT_NUMERIC);
    
    // Create normalized string (comma-separated, sorted)
    $normalized = implode(', ', $uniqueNumbers);
    
    return [
        'valid' => true,
        'error' => null,
        'numbers' => array_values($uniqueNumbers),
        'normalized' => $normalized
    ];
}
