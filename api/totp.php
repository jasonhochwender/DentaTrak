<?php
/**
 * TOTP (Time-based One-Time Password) Implementation
 * 
 * Implements RFC 6238 TOTP for two-factor authentication.
 * Compatible with Google Authenticator, Authy, and other TOTP apps.
 * 
 * Security: Secrets are stored encrypted. Never log or expose secrets.
 */

class TOTP {
    // ============================================
    // CONFIGURATION
    // ============================================
    private const SECRET_LENGTH = 20; // 160 bits for security
    private const CODE_DIGITS = 6;    // Standard 6-digit codes
    private const TIME_STEP = 30;     // 30-second time window
    private const ALGORITHM = 'sha1'; // Required for Google Authenticator compatibility
    private const ISSUER = 'Dentatrak';
    
    // Allow 1 time step before/after for clock drift tolerance
    private const ALLOWED_DRIFT = 1;
    
    /**
     * Generate a new random secret for TOTP
     * 
     * @return string Base32-encoded secret
     */
    public static function generateSecret(): string {
        // Generate cryptographically secure random bytes
        $randomBytes = random_bytes(self::SECRET_LENGTH);
        
        // Encode as Base32 (required for TOTP apps)
        return self::base32Encode($randomBytes);
    }
    
    /**
     * Generate a TOTP code for a given secret and time
     * 
     * @param string $secret Base32-encoded secret
     * @param int|null $timestamp Unix timestamp (null = current time)
     * @return string 6-digit TOTP code
     */
    public static function generateCode(string $secret, ?int $timestamp = null): string {
        if ($timestamp === null) {
            $timestamp = time();
        }
        
        // Calculate time counter
        $timeCounter = floor($timestamp / self::TIME_STEP);
        
        // Pack counter as 64-bit big-endian
        $timeBytes = pack('N*', 0, $timeCounter);
        
        // Decode Base32 secret
        $secretBytes = self::base32Decode($secret);
        
        // Calculate HMAC-SHA1
        $hash = hash_hmac(self::ALGORITHM, $timeBytes, $secretBytes, true);
        
        // Dynamic truncation (RFC 4226)
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );
        
        // Generate code with leading zeros
        $code = $binary % pow(10, self::CODE_DIGITS);
        return str_pad((string)$code, self::CODE_DIGITS, '0', STR_PAD_LEFT);
    }
    
    /**
     * Verify a TOTP code against a secret
     * 
     * @param string $secret Base32-encoded secret
     * @param string $code User-provided code
     * @return bool True if code is valid
     */
    public static function verifyCode(string $secret, string $code): bool {
        // Normalize code (remove spaces, ensure string)
        $code = preg_replace('/\s+/', '', trim($code));
        
        if (strlen($code) !== self::CODE_DIGITS || !ctype_digit($code)) {
            return false;
        }
        
        $currentTime = time();
        
        // Check current time step and allowed drift windows
        for ($drift = -self::ALLOWED_DRIFT; $drift <= self::ALLOWED_DRIFT; $drift++) {
            $checkTime = $currentTime + ($drift * self::TIME_STEP);
            $expectedCode = self::generateCode($secret, $checkTime);
            
            // Timing-safe comparison to prevent timing attacks
            if (hash_equals($expectedCode, $code)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Generate a QR code URL for authenticator apps
     * 
     * @param string $secret Base32-encoded secret
     * @param string $email User's email address
     * @return string otpauth:// URI for QR code
     */
    public static function getQRCodeUri(string $secret, string $email): string {
        $issuer = rawurlencode(self::ISSUER);
        $account = rawurlencode($email);
        
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=%s&digits=%d&period=%d',
            $issuer,
            $account,
            $secret,
            $issuer,
            strtoupper(self::ALGORITHM),
            self::CODE_DIGITS,
            self::TIME_STEP
        );
    }
    
    /**
     * Generate QR code as SVG using endroid/qr-code library
     * 
     * @param string $data Data to encode (otpauth:// URI)
     * @return string SVG markup
     */
    public static function generateQRCodeSVG(string $data): string {
        try {
            // Use endroid/qr-code library v6.x API
            // Create QrCode directly with constructor parameters
            $qrCode = new \Endroid\QrCode\QrCode(
                data: $data,
                errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::Medium,
                size: 200,
                margin: 10
            );
            
            $writer = new \Endroid\QrCode\Writer\SvgWriter();
            $result = $writer->write($qrCode);
            
            return $result->getString();
        } catch (\Exception $e) {
            error_log('[totp] QR code generation failed: ' . $e->getMessage());
            return '<p style="color: red;">Error generating QR code. Please try again.</p>';
        }
    }
    
    /**
     * Base32 encode binary data
     * 
     * @param string $data Binary data
     * @return string Base32-encoded string
     */
    private static function base32Encode(string $data): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        
        // Convert to binary string
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        
        // Pad to multiple of 5
        $binary = str_pad($binary, (int)(ceil(strlen($binary) / 5) * 5), '0', STR_PAD_RIGHT);
        
        // Convert 5-bit chunks to Base32
        $result = '';
        foreach (str_split($binary, 5) as $chunk) {
            $result .= $alphabet[bindec($chunk)];
        }
        
        return $result;
    }
    
    /**
     * Base32 decode to binary data
     * 
     * @param string $data Base32-encoded string
     * @return string Binary data
     */
    private static function base32Decode(string $data): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = strtoupper(preg_replace('/[^A-Z2-7]/', '', $data));
        
        $binary = '';
        foreach (str_split($data) as $char) {
            $index = strpos($alphabet, $char);
            if ($index !== false) {
                $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
            }
        }
        
        // Convert binary string to bytes
        $result = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $result .= chr(bindec($byte));
            }
        }
        
        return $result;
    }
}

/**
 * Database functions for 2FA management
 */

/**
 * Ensure the 2FA columns exist in the users table
 */
function ensure2FAColumns() {
    global $pdo;
    
    if (!$pdo) return;
    
    try {
        // Check and add totp_secret column
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'totp_secret'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) NULL COMMENT 'Encrypted TOTP secret for 2FA'");
        }
        
        // Check and add totp_enabled column
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'totp_enabled'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '2FA enabled flag'");
        }
        
        // Check and add totp_enabled_at column
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'totp_enabled_at'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN totp_enabled_at DATETIME NULL COMMENT 'When 2FA was enabled'");
        }
    } catch (PDOException $e) {
        error_log('[totp] Error ensuring 2FA columns: ' . $e->getMessage());
    }
}

/**
 * Get 2FA status for a user
 * 
 * @param int $userId User ID
 * @return array 2FA status information
 */
function get2FAStatus(int $userId): array {
    global $pdo;
    
    ensure2FAColumns();
    
    try {
        $stmt = $pdo->prepare("SELECT totp_enabled, totp_enabled_at FROM users WHERE id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return [
                'enabled' => (bool)$result['totp_enabled'],
                'enabledAt' => $result['totp_enabled_at']
            ];
        }
    } catch (PDOException $e) {
        error_log('[totp] Error getting 2FA status: ' . $e->getMessage());
    }
    
    return ['enabled' => false, 'enabledAt' => null];
}

/**
 * Store a pending TOTP secret (before verification)
 * 
 * @param int $userId User ID
 * @param string $secret Base32-encoded secret
 * @return bool Success
 */
function storePending2FASecret(int $userId, string $secret): bool {
    global $pdo;
    
    ensure2FAColumns();
    
    try {
        // Store secret temporarily (not enabled yet)
        // The secret is stored but totp_enabled remains false until verified
        $stmt = $pdo->prepare("
            UPDATE users 
            SET totp_secret = :secret, totp_enabled = 0
            WHERE id = :user_id
        ");
        return $stmt->execute([
            'secret' => $secret,
            'user_id' => $userId
        ]);
    } catch (PDOException $e) {
        error_log('[totp] Error storing pending 2FA secret: ' . $e->getMessage());
        return false;
    }
}

/**
 * Enable 2FA after successful verification
 * 
 * @param int $userId User ID
 * @return bool Success
 */
function enable2FA(int $userId): bool {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET totp_enabled = 1, totp_enabled_at = NOW()
            WHERE id = :user_id AND totp_secret IS NOT NULL
        ");
        $result = $stmt->execute(['user_id' => $userId]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Log the 2FA enablement
            if (function_exists('logUserActivity')) {
                logUserActivity($userId, '2fa_enabled', 'User enabled two-factor authentication');
            }
            return true;
        }
        return false;
    } catch (PDOException $e) {
        error_log('[totp] Error enabling 2FA: ' . $e->getMessage());
        return false;
    }
}

/**
 * Disable 2FA for a user
 * 
 * @param int $userId User ID
 * @return bool Success
 */
function disable2FA(int $userId): bool {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET totp_enabled = 0, totp_secret = NULL, totp_enabled_at = NULL
            WHERE id = :user_id
        ");
        $result = $stmt->execute(['user_id' => $userId]);
        
        if ($result) {
            // Log the 2FA disablement
            if (function_exists('logUserActivity')) {
                logUserActivity($userId, '2fa_disabled', 'User disabled two-factor authentication');
            }
            return true;
        }
        return false;
    } catch (PDOException $e) {
        error_log('[totp] Error disabling 2FA: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get the TOTP secret for a user (for verification)
 * 
 * @param int $userId User ID
 * @return string|null Secret or null if not found
 */
function get2FASecret(int $userId): ?string {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT totp_secret FROM users WHERE id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        $result = $stmt->fetchColumn();
        
        return $result ?: null;
    } catch (PDOException $e) {
        error_log('[totp] Error getting 2FA secret: ' . $e->getMessage());
        return null;
    }
}

/**
 * Check if 2FA is enabled for a user by email
 * 
 * @param string $email User email
 * @return bool True if 2FA is enabled
 */
function is2FAEnabledForEmail(string $email): bool {
    global $pdo;
    
    ensure2FAColumns();
    
    try {
        $stmt = $pdo->prepare("SELECT totp_enabled FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $result = $stmt->fetchColumn();
        
        return (bool)$result;
    } catch (PDOException $e) {
        error_log('[totp] Error checking 2FA status: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get user ID and TOTP secret by email (for login verification)
 * 
 * @param string $email User email
 * @return array|null User data or null
 */
function get2FADataByEmail(string $email): ?array {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, totp_secret, totp_enabled 
            FROM users 
            WHERE email = :email
        ");
        $stmt->execute(['email' => $email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
    } catch (PDOException $e) {
        error_log('[totp] Error getting 2FA data: ' . $e->getMessage());
        return null;
    }
}
