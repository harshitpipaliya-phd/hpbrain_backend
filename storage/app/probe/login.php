<?php
use Illuminate\Support\Facades\DB;
$log = storage_path('app/probe/login.txt');
file_put_contents($log, "start\n");
$note = fn (string $l) => file_put_contents($log, $l."\n", FILE_APPEND);
$time = function (string $label, callable $fn) use ($note) {
    $s = microtime(true);
    try { $r = $fn(); $n = is_array($r) ? 'array('.count($r).')' : (is_object($r) ? 'obj' : var_export($r, true)); }
    catch (Throwable $e) { $n = 'ERR '.substr($e->getMessage(), 0, 70); }
    $note(sprintf('%-26s %7.2fs  %s', $label, microtime(true) - $s, substr((string) $n, 0, 60)));
    return $r ?? null;
};

$resolver = app(App\Domain\Universal\EntityResolver::class);
$sources = $time('everyTenantWith(Person)', fn () => $resolver->everyTenantWith('Person'));
$note('tenants: '.count($sources));

$src = reset($sources);
$time('email lookup', fn () => DB::table($src->table)
    ->where($src->field('email'), 'fibervalley@gmail.com')
    ->where($src->field('status'), 1)
    ->whereIn($src->tenantKey, array_keys($sources))
    ->first());

$row = DB::table($src->table)->where($src->field('email'), 'fibervalley@gmail.com')->first();
if ($row) {
    $tenant = (string) $row->{$src->tenantKey};
    $note('tenant of user: '.$tenant);
    $time('resolveRole-ish', fn () => DB::table('tbluserprofilemaster')->where('sub_institute_id', $tenant)->get());
    $time('loadOrganization', fn () => app(App\Repositories\OrganizationRepository::class)->list($tenant));
}
$note('done');
