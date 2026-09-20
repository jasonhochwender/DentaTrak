<?php
/**
 * CanonicalCase
 *
 * Provider-agnostic representation of an external PMS lab case as DentaTrak
 * understands it. Adapters normalize their raw API records into this object;
 * SyncEngine (and later CaseService) consumes it. Pure value object - it
 * performs no database access and holds no PDO reference.
 *
 * PHI SAFETY:
 *  - Patient names, DOB, provider name, and instructions ARE PHI-adjacent
 *    and live here because a real import needs them.
 *  - jsonSerialize() therefore returns ONLY the non-PHI projection
 *    (toSafeArray), so `json_encode($canonicalCase)` in a log line can never
 *    dump patient data.
 *  - toArray() returns the full record for the import path only - treat it
 *    as sensitive and never pass it to logging/sync-event detail.
 */

class CanonicalCase implements JsonSerializable {

    // External identifiers (all optional - a PMS may not supply every ID).
    public ?string $externalCaseId = null;        // e.g. Open Dental LabCaseNum
    public ?string $externalPatientId = null;     // e.g. PatNum
    public ?string $externalProviderId = null;    // e.g. ProvNum
    public ?string $externalLaboratoryId = null;  // e.g. LaboratoryNum
    public ?string $externalAppointmentId = null; // e.g. AptNum / PlannedAptNum

    // Patient fields (PHI - excluded from toSafeArray/jsonSerialize).
    public ?string $patientFirstName = null;
    public ?string $patientLastName = null;
    public ?string $patientDob = null;
    public ?string $patientGender = null;

    // Provider/lab display fields (provider name excluded from safe output).
    public ?string $providerName = null;
    public ?string $laboratoryName = null;

    // Case fields.
    public ?string $dueDate = null;               // ISO-8601 string; PMS-owned
    public ?string $appointmentDate = null;       // ISO-8601 string; PMS-owned
    public ?string $instructions = null;          // PHI-adjacent; PMS-owned
    public ?string $sourceCaseType = null;        // provider's own type/category label

    // Non-PHI provider metadata (counts, flags, source timestamps). Never
    // place raw API payloads or patient-identifying values here.
    public array $metadata = [];

    public function __construct(array $data = []) {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public static function fromArray(array $data): self {
        return new self($data);
    }

    /**
     * Full record including PHI. For the import/write path ONLY.
     * Never log this output and never put it in sync-event detail_json.
     */
    public function toArray(): array {
        return [
            'externalCaseId'        => $this->externalCaseId,
            'externalPatientId'     => $this->externalPatientId,
            'externalProviderId'    => $this->externalProviderId,
            'externalLaboratoryId'  => $this->externalLaboratoryId,
            'externalAppointmentId' => $this->externalAppointmentId,
            'patientFirstName'      => $this->patientFirstName,
            'patientLastName'       => $this->patientLastName,
            'patientDob'            => $this->patientDob,
            'patientGender'         => $this->patientGender,
            'providerName'          => $this->providerName,
            'laboratoryName'        => $this->laboratoryName,
            'dueDate'               => $this->dueDate,
            'appointmentDate'       => $this->appointmentDate,
            'instructions'          => $this->instructions,
            'sourceCaseType'        => $this->sourceCaseType,
            'metadata'              => $this->metadata,
        ];
    }

    /**
     * Non-PHI projection safe for logs and sync-event detail_json:
     * external identifiers, the provider's case-type label, and presence
     * flags only - no names, dates of birth, or instruction text.
     */
    public function toSafeArray(): array {
        return [
            'externalCaseId'        => $this->externalCaseId,
            'externalPatientId'     => $this->externalPatientId,
            'externalProviderId'    => $this->externalProviderId,
            'externalLaboratoryId'  => $this->externalLaboratoryId,
            'externalAppointmentId' => $this->externalAppointmentId,
            'sourceCaseType'        => $this->sourceCaseType,
            'hasPatient'            => $this->patientFirstName !== null || $this->patientLastName !== null,
            'hasDueDate'            => $this->dueDate !== null,
            'hasInstructions'       => $this->instructions !== null,
        ];
    }

    /**
     * Deliberately the SAFE projection: json_encode($canonicalCase) anywhere
     * (including error logs) can never serialize patient data.
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize() {
        return $this->toSafeArray();
    }

    /**
     * Minimal safe string form for interpolation into log messages.
     * Exposes only the external case ID.
     */
    public function __toString(): string {
        return 'CanonicalCase{externalCaseId=' . ($this->externalCaseId ?? 'none') . '}';
    }
}
