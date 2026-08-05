<?php
/**
 * Practice Trial Initialization Helper
 *
 * Centralized, one-way initialization of the DentaTrak 90-day practice trial.
 * This value is written exactly once when a practice is INSERTed and must
 * NEVER be reset, extended, or overwritten by login, invitations, BAA views,
 * Settings, Billing, or any other lifecycle event.
 *
 * The trial is stored on the practice row, not the user row.  When
 * BILLING_ENABLED is false, the trial record is ignored by access logic and
 * all users have full access.  When billing is later enabled, the original
 * trial_ends_at value remains the authoritative trial expiration.
 */

/**
 * Return the UTC trial end timestamp for a new practice.
 *
 * @param int|null $offsetSeconds Optional offset added to the 90-day window.
 *                                Defaults to 0 so every practice gets the
 *                                same 90-day window from creation.
 * @return string MySQL-compatible UTC datetime (Y-m-d H:i:s)
 */
function getNewPracticeTrialEndsAt(?int $offsetSeconds = 0): string {
    $trialEnd = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $trialEnd = $trialEnd->modify('+90 days')->modify("+{$offsetSeconds} seconds");
    return $trialEnd->format('Y-m-d H:i:s');
}

/**
 * Default trial column values to use in a new practices INSERT statement.
 *
 * @return array { subscription_status: 'trialing', trial_ends_at: string }
 */
function getNewPracticeTrialDefaults(): array {
    return [
        'subscription_status' => 'trialing',
        'trial_ends_at'       => getNewPracticeTrialEndsAt(),
    ];
}
