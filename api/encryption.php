<?php
/**
 * Encryption/Decryption utilities for PII protection
 * Uses AES-256-CBC encryption with keys from environment variables
 */

class PIIEncryption {
    private static $encryptionKey = null;
    private static $ivLength = 16; // AES-256-CBC uses 16-byte IV
    
    /**
     * Initialize encryption key from environment
     */
    private static function initializeKey() {
        if (self::$encryptionKey === null) {
            // Try environment variable first
            $key = $_ENV['ENCRYPTION_KEY'] ?? $_SERVER['ENCRYPTION_KEY'] ?? null;
            
            // Fallback to config file (less secure but functional)
            if ($key === null && defined('ENCRYPTION_KEY')) {
                $key = ENCRYPTION_KEY;
            }
            
            // Generate a key if none exists (for initial setup only)
            if ($key === null) {
                // This should be replaced with a proper key in production
                $key = bin2hex(random_bytes(32));
                error_log('WARNING: Using auto-generated encryption key. Please set ENCRYPTION_KEY environment variable.');
            }
            
            // Ensure key is exactly 32 bytes (256 bits) for AES-256
            self::$encryptionKey = substr(hash('sha256', $key, true), 0, 32);
        }
    }
    
    /**
     * Encrypt sensitive data
     * @param string $data Plain text data to encrypt
     * @return string Base64 encoded encrypted data with IV
     */
    public static function encrypt($data) {
        if ($data === null || $data === '') {
            return $data;
        }
        
        self::initializeKey();
        
        // Generate random IV
        $iv = random_bytes(self::$ivLength);
        
        // Encrypt the data
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', self::$encryptionKey, OPENSSL_RAW_DATA, $iv);
        
        if ($encrypted === false) {
            throw new Exception('Encryption failed: ' . openssl_error_string());
        }
        
        // Combine IV and encrypted data, then base64 encode
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt sensitive data
     * @param string $encryptedData Base64 encoded encrypted data with IV
     * @return string Decrypted plain text data
     */
    public static function decrypt($encryptedData) {
        if ($encryptedData === null || $encryptedData === '') {
            return $encryptedData;
        }
        
        self::initializeKey();
        
        // Decode base64
        $data = base64_decode($encryptedData);
        if ($data === false) {
            throw new Exception('Invalid encrypted data format');
        }
        
        // Extract IV and encrypted data
        $iv = substr($data, 0, self::$ivLength);
        $encrypted = substr($data, self::$ivLength);
        
        if (strlen($iv) !== self::$ivLength) {
            throw new Exception('Invalid IV length in encrypted data');
        }
        
        // Decrypt the data
        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', self::$encryptionKey, OPENSSL_RAW_DATA, $iv);
        
        if ($decrypted === false) {
            throw new Exception('Decryption failed: ' . openssl_error_string());
        }
        
        return $decrypted;
    }
    
    /**
     * Encrypt case PII fields
     * @param array $caseData Case data array
     * @return array Case data with encrypted PII fields
     */
    public static function encryptCaseData($caseData) {
        $piiFields = ['patientFirstName', 'patientLastName', 'patientDOB', 'dentistName', 'notes'];
        
        foreach ($piiFields as $field) {
            if (isset($caseData[$field])) {
                $caseData[$field] = self::encrypt($caseData[$field]);
            }
        }
        
        return $caseData;
    }
    
    /**
     * Decrypt case PII fields
     * @param array $caseData Case data array with encrypted fields
     * @return array Case data with decrypted PII fields
     */
    public static function decryptCaseData($caseData) {
        $piiFields = ['patientFirstName', 'patientLastName', 'patientDOB', 'dentistName', 'notes'];
        
        foreach ($piiFields as $field) {
            if (isset($caseData[$field])) {
                try {
                    $caseData[$field] = self::decrypt($caseData[$field]);
                } catch (Exception $e) {
                    // Don't expose the error to the user
                }
            }
        }
        
        return $caseData;
    }
    
    /**
     * Filter cases by search term (decrypts in memory)
     * @param array $cases Array of case data
     * @param string $searchTerm Search term
     * @return array Filtered cases
     */
    public static function filterCasesBySearch($cases, $searchTerm) {
        if (empty($searchTerm)) {
            return $cases;
        }
        
        $searchTerm = strtolower(trim($searchTerm));
        $filtered = [];
        
        foreach ($cases as $case) {
            // Decrypt case temporarily for searching
            $decryptedCase = self::decryptCaseData($case);
            
            // Check if search term matches any PII fields
            $match = false;
            $searchFields = ['patientFirstName', 'patientLastName', 'dentistName', 'notes'];
            
            foreach ($searchFields as $field) {
                if (isset($decryptedCase[$field]) && 
                    strpos(strtolower($decryptedCase[$field]), $searchTerm) !== false) {
                    $match = true;
                    break;
                }
            }
            
            // Special handling for combined patient name searches
            if (!$match && isset($decryptedCase['patientFirstName']) && isset($decryptedCase['patientLastName'])) {
                $fullName = trim($decryptedCase['patientFirstName'] . ' ' . $decryptedCase['patientLastName']);
                $fullNameReversed = trim($decryptedCase['patientLastName'] . ' ' . $decryptedCase['patientFirstName']);
                
                // Check if search term matches full name (either order)
                if (strpos(strtolower($fullName), $searchTerm) !== false || 
                    strpos(strtolower($fullNameReversed), $searchTerm) !== false) {
                    $match = true;
                }
                // Check if search term contains parts of both first and last name
                else {
                    $searchWords = explode(' ', $searchTerm);
                    $firstNameMatch = false;
                    $lastNameMatch = false;
                    
                    foreach ($searchWords as $word) {
                        if (!empty(trim($word))) {
                            if (strpos(strtolower($decryptedCase['patientFirstName']), trim($word)) !== false) {
                                $firstNameMatch = true;
                            }
                            if (strpos(strtolower($decryptedCase['patientLastName']), trim($word)) !== false) {
                                $lastNameMatch = true;
                            }
                        }
                    }
                    
                    // Match if search contains parts of both names (e.g., "John D" matches "John Doe")
                    if ($firstNameMatch && $lastNameMatch) {
                        $match = true;
                    }
                }
            }
            
            // Also check non-PII fields
            if (!$match) {
                $nonPiiFields = ['caseType', 'status', 'id'];
                foreach ($nonPiiFields as $field) {
                    if (isset($case[$field]) && 
                        strpos(strtolower($case[$field]), $searchTerm) !== false) {
                        $match = true;
                        break;
                    }
                }
            }
            
            if ($match) {
                $filtered[] = $case; // Return encrypted version
            }
        }
        
        return $filtered;
    }
}
