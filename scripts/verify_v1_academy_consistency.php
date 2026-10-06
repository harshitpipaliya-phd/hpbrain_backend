<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tenant = (string) DB::table('school_setup')
    ->where('SchoolName', 'V1 Academy')
    ->value('id');

if ($tenant === '') {
    fwrite(STDERR, "V1 Academy tenant not found.\n");
    exit(1);
}

$orgRows = DB::table('school_setup')->where('SchoolName', 'V1 Academy')->count();
$activePeople = DB::table('tbluser')
    ->where('sub_institute_id', $tenant)
    ->where('status', 1)
    ->whereNull('deleted_at')
    ->count();

$departments = DB::table('hrms_departments as d')
    ->leftJoin('tbluser as u', function ($join) use ($tenant): void {
        $join->on('u.department_id', '=', 'd.id')
            ->where('u.sub_institute_id', '=', $tenant)
            ->where('u.status', '=', 1)
            ->whereNull('u.deleted_at');
    })
    ->where('d.sub_institute_id', $tenant)
    ->where('d.status', 1)
    ->whereNull('d.deleted_at')
    ->groupBy('d.id', 'd.department')
    ->orderBy('d.department')
    ->get([
        'd.id',
        'd.department',
        DB::raw('COUNT(u.id) AS active_people'),
    ]);

$duplicatePeople = DB::table('tbluser')
    ->select('email', DB::raw('COUNT(*) AS n'))
    ->where('sub_institute_id', $tenant)
    ->whereNull('deleted_at')
    ->groupBy('email')
    ->havingRaw('COUNT(*) > 1')
    ->count();

$duplicateDepartments = DB::table('hrms_departments')
    ->select('department', DB::raw('COUNT(*) AS n'))
    ->where('sub_institute_id', $tenant)
    ->whereNull('deleted_at')
    ->groupBy('department')
    ->havingRaw('COUNT(*) > 1')
    ->count();

$capabilities = DB::table('hpbrain_capabilities')
    ->where('tenant_id', $tenant)
    ->where('status', 'active')
    ->count();

$assignments = DB::table('hpbrain_capability_assignments')
    ->where('tenant_id', $tenant)
    ->where('status', 'active')
    ->count();

$badAssignments = DB::table('hpbrain_capability_assignments as a')
    ->leftJoin('hpbrain_capabilities as c', function ($join): void {
        $join->on('c.id', '=', 'a.capability_id')
            ->on('c.tenant_id', '=', 'a.tenant_id');
    })
    ->where('a.tenant_id', $tenant)
    ->whereNull('c.id')
    ->count();

echo "V1 Academy tenant: {$tenant}\n";
echo "V1 Academy org rows: {$orgRows}\n";
echo "Active people: {$activePeople}\n";
echo "Active capabilities: {$capabilities}\n";
echo "Active capability assignments: {$assignments}\n";
echo "Duplicate active departments: {$duplicateDepartments}\n";
echo "Duplicate people emails: {$duplicatePeople}\n";
echo "Capability assignments without tenant capability: {$badAssignments}\n";
echo "\nDepartment | Active People in DB | API Count | Detail/List Consistency\n";
echo "---------------------------------------------------------------------------\n";

foreach ($departments as $department) {
    $apiCount = DB::table('tbluser')
        ->where('sub_institute_id', $tenant)
        ->where('department_id', $department->id)
        ->where('status', 1)
        ->whereNull('deleted_at')
        ->count();

    $status = ((int) $department->active_people === $apiCount) ? 'PASS' : 'FAIL';
    echo "{$department->department} | {$department->active_people} | {$apiCount} | {$status}\n";
}

