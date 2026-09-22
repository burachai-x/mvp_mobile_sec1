<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\IntegrityReport;
use App\Models\RetentionPolicy;
use App\Support\DocumentStore;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Enforces the retention windows an admin set in the portal (architecture.md §10).
 *
 * "Delete" means crypto-shredding, never DELETE: the wrapped DEK and the
 * encrypted national ID are destroyed and the row stays behind. Dropping rows
 * would leave every audit_logs entry pointing at nothing, and the audit trail
 * has to outlive the data it describes.
 *
 * Runs on the scheduler container, which has no DOCUMENT_KEK on purpose.
 * None is needed: throwing a key away never requires reading what it unlocked.
 */
final class ApplyRetention extends Command
{
    protected $signature = 'retention:apply';

    protected $description = 'Crypto-shred driver data and documents whose retention window has passed';

    /**
     * Retention windows that exist as a setting but are deliberately not
     * enforced here, mapped to the reason. audit_logs is append-only: a
     * database trigger rejects DELETE and the model throws before reaching it.
     * Architecture §5 puts audit retention behind a separate job with an
     * approval step, which does not exist yet. Reporting the gap beats leaving
     * two settings that look like they do something.
     *
     * @var array<string, string>
     */
    private const NOT_ENFORCED = [
        'activity_log_days' => 'audit_logs is append-only; removal needs the approved job from architecture.md §5',
        'pii_access_log_days' => 'PII access records must outlive the data they describe (CLAUDE.md §6)',
    ];

    public function handle(): int
    {
        $policies = RetentionPolicy::query()->pluck('value_days', 'key');

        $documents = $this->shredDocuments($this->days($policies, 'document_after_termination_days'));
        $drivers = $this->shredDrivers($this->days($policies, 'driver_data_after_termination_days'));
        $reports = $this->pruneIntegrityReports($this->days($policies, 'integrity_report_days'));

        $this->line("documents shredded: {$documents}");
        $this->line("drivers anonymised: {$drivers}");
        $this->line("integrity reports pruned: {$reports}");

        foreach (self::NOT_ENFORCED as $key => $reason) {
            $this->line("not enforced: {$key} — {$reason}");
        }

        return self::SUCCESS;
    }

    /**
     * A missing policy would read as zero days and shred every record on the
     * first run, so refuse rather than fall back to a default.
     *
     * @param  Collection<string, int>  $policies
     */
    private function days(Collection $policies, string $key): int
    {
        $days = $policies->get($key);

        if (! is_int($days)) {
            throw new RuntimeException("Retention policy '{$key}' is missing from retention_policies.");
        }

        return $days;
    }

    /**
     * Destroying the DEK inside the transaction means a rollback leaves the
     * object deleted but still wrapped, which reads as nothing either way.
     * Failing closed is the right direction for a control that destroys data.
     */
    private function shredDocuments(int $days): int
    {
        $store = DocumentStore::make();

        $documents = DriverDocument::query()
            ->whereNull('destroyed_at')
            ->whereRelation('driver', 'status', 'terminated')
            ->whereRelation('driver', 'terminated_at', '<=', now()->subDays($days))
            ->get();

        foreach ($documents as $document) {
            DB::transaction(function () use ($store, $document): void {
                $store->destroy($document);

                AuditLog::create([
                    'actor_type' => 'system',
                    'action' => 'document.deleted',
                    'subject_type' => 'driver',
                    'subject_id' => $document->driver_id,
                    'meta' => [
                        'document_id' => $document->getKey(),
                        'type' => $document->type,
                        'reason' => 'retention',
                    ],
                    'created_at' => now(),
                ]);
            });
        }

        return $documents->count();
    }

    /**
     * national_id_hmac survives on purpose (§10.3): it is a keyed hash, not the
     * number, and without it a terminated driver could re-apply unnoticed.
     */
    private function shredDrivers(int $days): int
    {
        $drivers = Driver::query()
            ->where('status', 'terminated')
            ->whereNull('anonymized_at')
            ->where('terminated_at', '<=', now()->subDays($days))
            ->get();

        foreach ($drivers as $driver) {
            DB::transaction(function () use ($driver, $days): void {
                // Written through the query builder rather than the model:
                // national_id_encrypted is NOT NULL, and the EncryptedNationalId
                // cast turns an empty string into null on the way in. An empty
                // column is what "destroyed" looks like here, and the cast
                // already reads it back as null.
                DB::table('drivers')->where('id', $driver->getKey())->update([
                    'national_id_encrypted' => '',
                    'full_name' => '',
                    'phone' => '',
                    // Not in the §10.3 list, but CLAUDE.md §6 counts a licence
                    // number as personal data, so leaving it would undo the point.
                    'license_number' => null,
                    'anonymized_at' => now(),
                    'updated_at' => now(),
                ]);

                AuditLog::create([
                    'actor_type' => 'system',
                    'action' => 'driver.anonymized',
                    'subject_type' => 'driver',
                    'subject_id' => $driver->getKey(),
                    'meta' => [
                        'policy' => 'driver_data_after_termination_days',
                        'days' => $days,
                    ],
                    'created_at' => now(),
                ]);
            });
        }

        return $drivers->count();
    }

    /**
     * Device health telemetry, not an audit record: nothing in audit_logs
     * points at these rows, so they can be removed for real.
     */
    private function pruneIntegrityReports(int $days): int
    {
        return IntegrityReport::query()
            ->where('created_at', '<=', now()->subDays($days))
            ->delete();
    }
}
