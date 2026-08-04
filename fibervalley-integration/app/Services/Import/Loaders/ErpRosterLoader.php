<?php

declare(strict_types=1);

namespace App\Services\Import\Loaders;

use App\Services\Import\ImportProfile;
use Illuminate\Support\Facades\DB;

/**
 * Loads the staff roster into the ERP tables the Brain already reads Person and
 * Department from — hrms_departments and tbluser, scoped by sub_institute_id.
 *
 * WHY THIS ONE WRITES TO THE ERP AND THE OTHER DOES NOT
 * -----------------------------------------------------
 * The 'Left ot Join' sheet is the only master data in the four workbooks: it
 * names employees, their department, and who they report to. Every other sheet
 * is transactional. Loading it here rather than into hpbrain_operational_records
 * is what makes FiberValley behave "exactly like the other organizations":
 *
 *   - OrganizationRepository / PersonRepository / DepartmentRepository return
 *     real rows with no changes;
 *   - WorkspaceController::homeMetrics() ERP tiles show real headcount;
 *   - the five existing SignalGenerator rules (people without department,
 *     departments without manager, people without profile, people without
 *     email, inactive users in active departments) evaluate for FiberValley
 *     with no new code whatsoever.
 *
 * The alternative — keeping staff in a Brain table — would have required
 * reimplementing all five rules against a different table, which is precisely
 * the duplication the brief forbids.
 *
 * WHAT IT DELIBERATELY LEAVES NULL
 * --------------------------------
 * The roster has no email addresses and no employee numbers. Those columns are
 * left null rather than synthesised. That is not laziness: the existing
 * 'people without email' rule will then fire for FiberValley and report a real
 * gap in their records. Generating placeholder addresses would suppress a true
 * finding and put fictional data in the ERP.
 *
 * SAFETY
 * ------
 * Every statement is scoped to the caller's sub_institute_id. Nothing here can
 * touch another organization's rows, and nothing is ever deleted — a person who
 * has left is marked status=0, preserving their history and the complaint
 * records that reference them by name.
 */
final class ErpRosterLoader implements RecordLoader
{
    /** @var array<string, int> normalised department name => id */
    private array $departments = [];

    private bool $primed = false;

    /** @var array<int, string> */
    private array $createdUsers = [];

    /** @var array<int, string> */
    private array $createdDepartments = [];

    public function load(string $tenantId, ImportProfile $profile, string $naturalKey, array $fields, array $context): array
    {
        $this->prime($tenantId);

        $fullName = trim((string) ($fields['full_name'] ?? ''));

        if ($fullName === '') {
            return ['action' => 'skipped', 'entityId' => null];
        }

        $departmentId = $this->resolveDepartment($tenantId, $fields['department'] ?? null, $context);
        [$first, $last] = $this->splitName($fullName);

        $isActive = (bool) ($fields['__active'] ?? true);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        // Match on name within the tenant. The roster carries no stable id, so
        // the full name is the only identifier available — which is exactly why
        // this loader never deletes: a renamed person would otherwise vanish.
        $existing = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->where('first_name', $first)
            ->where('last_name', $last)
            ->first();

        $attributes = [
            'first_name'       => $first,
            'last_name'        => $last,
            'department_id'    => $departmentId,
            'user_profile_id'  => $this->employeeProfileId($tenantId),
            'status'           => $isActive ? 1 : 0,
            'updated_at'       => $now,
        ];

        if ($existing) {
            DB::table('tbluser')->where('id', $existing->id)->update($attributes);

            return ['action' => 'updated', 'entityId' => (string) $existing->id];
        }

        $id = DB::table('tbluser')->insertGetId($attributes + [
            'sub_institute_id' => $tenantId,
            'created_at'       => $now,
        ]);

        $this->createdUsers[] = (string) $id;

        return ['action' => 'created', 'entityId' => (string) $id];
    }

    /**
     * DELIBERATELY EMPTY — ERP master data is not auto-rollbackable.
     *
     * The ids are tracked (see $createdUsers / $createdDepartments) but not
     * published to the import job's rollback_data, because
     * ImportEngine::rollbackImport() would then DELETE rows from tbluser and
     * hrms_departments. Those rows are ERP master data: other tables reference
     * them by id, the Brain's own signals cite them as evidence, and an
     * organization's staff list is not something an undo button should be able
     * to erase. Undoing a roster import is a deliberate, supervised act, not a
     * side effect of rolling back a spreadsheet load.
     *
     * The operational loader has no such constraint — its records are
     * Brain-owned facts with no external references, so those ARE rollbackable.
     */
    public function createdIds(): array
    {
        return [];
    }

    /**
     * What this run touched, for the command's summary and for an operator who
     * needs to undo a roster import by hand.
     *
     * @return array{users: array<int, string>, departments: array<int, string>}
     */
    public function createdErpIds(): array
    {
        return ['users' => $this->createdUsers, 'departments' => $this->createdDepartments];
    }

    public function flush(): void
    {
        // Written row by row; nothing buffered.
    }

    /**
     * Find or create the department, returning its ERP id.
     *
     * parent_id is left at 0 rather than guessed. The 'departments without
     * manager' rule keys off exactly that, so FiberValley will correctly report
     * unmanaged departments until someone assigns leadership in the ERP — a
     * true statement about their data, not an artefact of the import.
     */
    private function resolveDepartment(string $tenantId, ?string $name, array $context): int
    {
        $name = trim((string) $name);

        if ($name === '') {
            return 0;
        }

        $normalised = mb_strtolower($name);

        if (isset($this->departments[$normalised])) {
            return $this->departments[$normalised];
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $id = DB::table('hrms_departments')->insertGetId([
            'sub_institute_id' => $tenantId,
            'department'       => $name,
            'parent_id'        => 0,
            'status'           => 1,
            'created_by'       => $context['actor'] ?? 'import',
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $this->departments[$normalised] = (int) $id;
        $this->createdDepartments[] = (string) $id;

        return (int) $id;
    }

    /**
     * The ERP requires every institute to own an 'Employee' profile;
     * PersonRepository throws when it is missing. OrganizationRepository::create()
     * provisions it for Brain-created organizations, and the FiberValley seeder
     * does the same — this resolves it, and provisions it defensively if an
     * organization was created by some other path.
     */
    private function employeeProfileId(string $tenantId): int
    {
        static $cache = [];

        if (isset($cache[$tenantId])) {
            return $cache[$tenantId];
        }

        $row = DB::table('tbluserprofilemaster')
            ->where('sub_institute_id', $tenantId)
            ->where('name', 'Employee')
            ->where('status', 1)
            ->first();

        if ($row) {
            return $cache[$tenantId] = (int) $row->id;
        }

        return $cache[$tenantId] = (int) DB::table('tbluserprofilemaster')->insertGetId([
            'sub_institute_id' => $tenantId,
            'name'             => 'Employee',
            'status'           => 1,
        ]);
    }

    /**
     * Split a full name into first and last.
     *
     * Indian names in this roster run to four and five parts
     * ('Mohammad Faiyaz Mohammad Yakub Shaikh'). Taking the FIRST token as the
     * given name and everything after it as the surname keeps the whole string
     * recoverable, which matters because the complaint and work-order sheets
     * reference these people by their full name and any rule joining the two
     * has to be able to reconstruct it.
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];

        if (count($parts) === 1) {
            return [$parts[0], ''];
        }

        $first = array_shift($parts);

        return [mb_substr($first, 0, 128), mb_substr(implode(' ', $parts), 0, 128)];
    }

    private function prime(string $tenantId): void
    {
        if ($this->primed) {
            return;
        }

        $this->primed = true;

        DB::table('hrms_departments')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->select('id', 'department')
            ->get()
            ->each(function ($row) {
                $this->departments[mb_strtolower((string) $row->department)] = (int) $row->id;
            });
    }
}
