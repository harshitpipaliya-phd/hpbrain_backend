<?php
$output = [];
$students = DB::table('hpbrain_operational_records')->where('tenant_id', '1000094')->where('dataset', 'v1a-academic-results')->get();
$counts = [];
foreach($students as $s) {
  $p = json_decode($s->payload);
  $k = $p->standard . '-' . $p->division . '-' . $s->subject_ref;
  $counts[$p->standard . '-' . $p->division][$k] = 1;
}
foreach($counts as $sec => $arr) { echo $sec . ': ' . count($arr) . "\n"; }
