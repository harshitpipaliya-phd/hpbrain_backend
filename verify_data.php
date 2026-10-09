<?php

$output = [];

$setup = DB::table('school_setup')->where('SchoolName', 'V1 Academy')->first();

if (!$setup) {
    echo json_encode(["error" => "V1 Academy not found"]);
    exit;
}

$tenantId = (string) $setup->id;
$output['tenant_id'] = $tenantId;

// 1. Organization exists exactly once
$orgCount = DB::table('school_setup')->where('SchoolName', 'V1 Academy')->count();
$output['v1_academy_org_count'] = $orgCount;

// Students and transport
// hpbrain_students projection
$students = DB::table('hpbrain_students')->where('tenant_id', $tenantId)->get();
$output['total_students'] = $students->count();

$sectionCounts = [];
$transportCounts = ['hostel' => 0, 'school_bus' => 0, 'own_transport' => 0, 'unknown' => 0];

// Let's get the transport data from hpbrain_operational_records
$transportRecords = DB::table('hpbrain_operational_records')
    ->where('tenant_id', $tenantId)
    ->where('category', 'Transport Allocation')
    ->get();

$hostelRecords = DB::table('hpbrain_operational_records')
    ->where('tenant_id', $tenantId)
    ->where('category', 'Hostel Allocation')
    ->get();

$transportMap = [];
foreach ($transportRecords as $rec) {
    $transportMap[$rec->subject_ref] = $rec->sub_category;
}

$hostelMap = [];
foreach ($hostelRecords as $rec) {
    $hostelMap[$rec->subject_ref] = true;
}

$overlap = 0;
foreach ($students as $stu) {
    $classSection = $stu->standard . '-' . $stu->division;
    if (!isset($sectionCounts[$classSection])) $sectionCounts[$classSection] = 0;
    $sectionCounts[$classSection]++;
    
    $mode = $transportMap[$stu->student_ref] ?? 'own_transport';
    if (!isset($transportCounts[$mode])) $transportCounts[$mode] = 0;
    $transportCounts[$mode]++;
    
    // check overlap in db
    $hasTrans = isset($transportMap[$stu->student_ref]) && $transportMap[$stu->student_ref] === 'school_bus';
    $hasHostel = isset($hostelMap[$stu->student_ref]);
    if ($hasTrans && $hasHostel) {
        $overlap++;
    }
}

$output['class_section_counts'] = $sectionCounts;
$output['transport_counts'] = $transportCounts;
$output['transport_hostel_overlap'] = $overlap;

// Records counts
$output['academic_records'] = DB::table('hpbrain_operational_records')->where('tenant_id', $tenantId)->where('dataset', 'v1a-academic-results')->count();
$output['fee_records'] = DB::table('hpbrain_operational_records')->where('tenant_id', $tenantId)->where('dataset', 'school_fee')->count();
$output['attendance_records'] = DB::table('hpbrain_operational_records')->where('tenant_id', $tenantId)->where('dataset', 'attendance')->count();
$output['staff_presence_records'] = DB::table('hpbrain_operational_records')->where('tenant_id', $tenantId)->where('dataset', 'EmployeeCheckin')->count();
$output['transport_records'] = $transportRecords->count();
$output['hostel_records'] = $hostelRecords->count();

// Intelligence counts
$output['signals'] = DB::table('hpbrain_signals')->where('tenant_id', $tenantId)->count();
$output['cases'] = DB::table('hpbrain_cases')->where('tenant_id', $tenantId)->count();
$output['recommendations'] = DB::table('hpbrain_recommendations')->where('tenant_id', $tenantId)->count();
$output['decisions'] = DB::table('hpbrain_decisions')->where('tenant_id', $tenantId)->count();

$sampleRecommendation = DB::table('hpbrain_recommendations')->where('tenant_id', $tenantId)->first();
$output['sample_recommendation'] = $sampleRecommendation;

$sampleCase = DB::table('hpbrain_cases')->where('tenant_id', $tenantId)->first();
$output['sample_case'] = $sampleCase;

$sampleSignal = DB::table('hpbrain_signals')->where('tenant_id', $tenantId)->first();
$output['sample_signal'] = $sampleSignal;

echo json_encode($output, JSON_PRETTY_PRINT);
