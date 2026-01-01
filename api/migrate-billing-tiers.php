<?php
/**
 * One-time migrations for billing tiers and case status tracking
 * 
 * This migration:
 * 1. Changes billing_tier column from ENUM to VARCHAR(50)
 * 2. Updates tier values: free->evaluate, standard->operate, plus->control
 * 3. Sets initial status_changed_at values for existing cases
 * 
 * This file can be deleted after successful deployment to production.
 */

function runBillingTierMigration($pdo) {
    // Check if migration has already run
    try {
        // First check if column is already VARCHAR (migration already ran)
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'billing_tier'");
        $columnInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($columnInfo) {
            $columnType = strtolower($columnInfo['Type'] ?? '');
            // If already VARCHAR, check if old tier values exist
            if (strpos($columnType, 'varchar') !== false) {
                // Check if any old tier names still exist
                $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE billing_tier IN ('free', 'standard', 'plus')");
                $oldTierCount = (int)$stmt->fetchColumn();
                
                if ($oldTierCount === 0) {
                    return true; // Already migrated
                }
                // Old tiers exist, need to update values (but not column type)
            }
        }
    } catch (PDOException $e) {
        // If query fails, continue with migration
        error_log('[MIGRATION] Check failed, will attempt migration: ' . $e->getMessage());
    }
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Step 1: Alter the column to VARCHAR to accept new values
        // This handles the case where billing_tier is an ENUM
        $pdo->exec("ALTER TABLE users MODIFY COLUMN billing_tier VARCHAR(50) NOT NULL DEFAULT 'evaluate'");
        
        // Step 2: Update the tier values
        $pdo->exec("UPDATE users SET billing_tier = 'evaluate' WHERE billing_tier = 'free'");
        $pdo->exec("UPDATE users SET billing_tier = 'operate' WHERE billing_tier = 'standard'");
        $pdo->exec("UPDATE users SET billing_tier = 'control' WHERE billing_tier = 'plus'");
        
        // Handle NULL or empty values
        $pdo->exec("UPDATE users SET billing_tier = 'evaluate' WHERE billing_tier IS NULL OR billing_tier = ''");
        
        // Commit transaction
        $pdo->commit();
        
        error_log('[MIGRATION] Billing tier migration completed successfully');
        
    } catch (PDOException $e) {
        // Rollback on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        error_log('[MIGRATION] Billing tier migration failed: ' . $e->getMessage());
        return false;
    }
    
    // Migration 2: Set initial status_changed_at for existing cases that don't have it
    try {
        // Check if any cases have NULL status_changed_at
        $stmt = $pdo->query("SELECT COUNT(*) FROM cases_cache WHERE status_changed_at IS NULL");
        $nullCount = (int)$stmt->fetchColumn();
        
        if ($nullCount > 0) {
            // Set status_changed_at to last_update_date for cases that don't have it
            $pdo->exec("UPDATE cases_cache SET status_changed_at = last_update_date WHERE status_changed_at IS NULL AND last_update_date IS NOT NULL");
            // For any remaining NULL cases, set to creation_date
            $pdo->exec("UPDATE cases_cache SET status_changed_at = creation_date WHERE status_changed_at IS NULL AND creation_date IS NOT NULL");
            // For any still NULL, set to NOW()
            $pdo->exec("UPDATE cases_cache SET status_changed_at = NOW() WHERE status_changed_at IS NULL");
            
            error_log('[MIGRATION] Set status_changed_at for ' . $nullCount . ' existing cases');
        }
    } catch (PDOException $e) {
        // Log but don't fail - this is a non-critical migration
        error_log('[MIGRATION] Status changed at migration warning: ' . $e->getMessage());
    }
    
    // Migration 3: Add limited_visibility column to practice_users if it doesn't exist
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM practice_users LIKE 'limited_visibility'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE practice_users ADD COLUMN limited_visibility BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'If true, user can only see cases assigned to them'");
            error_log('[MIGRATION] Added limited_visibility column to practice_users table');
        }
    } catch (PDOException $e) {
        error_log('[MIGRATION] Limited visibility migration warning: ' . $e->getMessage());
    }
    
    return true;
}
