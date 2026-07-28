<?php

declare(strict_types=1);

/**
 * Standalone verification harness.
 *
 * Runs the same assertions as tests/Unit/DomainLogicTest.php without PHPUnit
 * or Laravel, so the ported domain logic can be verified before `composer
 * install` has ever been run. Once the framework is installed, prefer
 * `php artisan test` — this exists so the port is not unverifiable in the
 * meantime.
 */
$base = dirname(__DIR__, 2);
foreach ([
    'app/Domain/Evidence/EvidenceService.php',
    'app/Domain/Reasoning/ReasoningService.php',
    'app/Domain/Recommendation/RecommendationService.php',
    'app/Domain/Kasba/KasbaService.php',
    'app/Domain/Learning/LearningService.php',
    'app/Domain/Policy/PolicyService.php',
] as $f) {
    require_once $base.'/'.$f;
}

use App\Domain\Evidence\EvidenceService;
use App\Domain\Kasba\KasbaService;
use App\Domain\Learning\LearningService;
use App\Domain\Policy\PolicyService;
use App\Domain\Reasoning\ReasoningService;
use App\Domain\Recommendation\RecommendationService;

$pass = 0; $fail = 0;
function check(string $name, $expected, $actual): void {
    global $pass, $fail;
    if ($expected === $actual) { $pass++; echo "  ok   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}\n       expected: ".json_encode($expected)."\n       actual:   ".json_encode($actual)."\n"; }
}

$NOW = new DateTimeImmutable('2026-07-27T00:00:00Z');
$at  = fn (int $d) => ($d >= 0 ? $NOW->modify("-{$d} days") : $NOW->modify('+'.abs($d).' days'))->format('c');

echo "Evidence — freshness half-life\n";
$e = new EvidenceService(90);
check('freshness at 0 days',   1.0,  round($e->computeFreshness($at(0), $NOW), 6));
check('freshness at 90 days',  0.5,  round($e->computeFreshness($at(90), $NOW), 6));
check('freshness at 180 days', 0.25, round($e->computeFreshness($at(180), $NOW), 6));
check('future evidence clamps to 1.0', 1.0, $e->computeFreshness($at(-30), $NOW));

echo "Reasoning — computed confidence (values from the Node original)\n";
$r  = new ReasoningService($e);
$ev = fn (float $c, int $d) => ['confidence' => $c, 'observedDate' => $at($d)];
check('no evidence -> base',       0.3,  $r->computeConfidence([]));
check('one fresh 0.9',             0.43, $r->computeConfidence([$ev(0.9, 0)]));
check('one 0.9 at half-life',      0.37, $r->computeConfidence([$ev(0.9, 90)]));
check('three perfect',             0.75, $r->computeConfidence([$ev(1.0,0), $ev(1.0,0), $ev(1.0,0)]));
check('never certain (ceiling)',   0.95, $r->computeConfidence(array_fill(0, 50, $ev(1.0, 0))));

echo "Recommendation — category forcing and urgency\n";
$s = new RecommendationService();
check('low confidence forced to watch', 'watch', $s->resolveCategory('opportunity', 0.2));
check('high confidence keeps category', 'opportunity', $s->resolveCategory('opportunity', 0.8));
check('compliance risk is immediate',   'immediate', $s->deriveUrgency('compliance', 0.65));
check('same confidence opportunity is not', 'normal', $s->deriveUrgency('opportunity', 0.65));

echo "KASBA — null is not zero\n";
$k = new KasbaService();
$sc = $k->computeScores(['knowledge_level' => 4, 'skill_level' => 2]);
check('assessed dimension kept',   4,    $sc['knowledge']);
check('unassessed stays null',     null, $sc['ability']);
check('overall averages assessed only', 3.0, $sc['overall']);
check('nothing assessed -> null overall', null, $k->computeScores(null)['overall']);
$gaps = $k->computeGaps(['knowledge_level' => 2], ['knowledge' => ['targetLevel' => 4], 'ability' => null]);
check('dimension without target skipped', 1, count($gaps));
check('gap computed correctly', 2.0, $gaps[0]['gap']);

echo "Learning — reusability gate\n";
$l = new LearningService();
check('success + confidence is reusable', true,  $l->isReusable('success', 0.8));
check('failure never reusable',           false, $l->isReusable('failure', 0.9));
check('low confidence not reusable',      false, $l->isReusable('success', 0.2));

echo "Policy — type-strict comparison and composites\n";
$p = new PolicyService();
$rule = ['field' => 'level', 'operator' => 'eq', 'value' => 5];
check('int 5 equals int 5',        true,  $p->evaluateRule($rule, ['level' => 5]));
check('string "5" does not equal', false, $p->evaluateRule($rule, ['level' => '5']));
$cond = [['field' => 'confidence','operator' => 'gte','value' => 0.7], ['field' => 'category','operator' => 'eq','value' => 'risk']];
$ctx  = ['confidence' => 0.9, 'category' => 'opportunity'];
check('match any', true,  $p->evaluateRule(['conditions' => $cond, 'match' => 'any'], $ctx));
check('match all', false, $p->evaluateRule(['conditions' => $cond, 'match' => 'all'], $ctx));

echo "UNDETERMINED — first-class result\n";
require_once $base.'/app/Domain/Undetermined/VerbResult.php';
require_once $base.'/app/Domain/Undetermined/SufficiencyCheck.php';
require_once $base.'/app/Domain/Capability/CapabilityState.php';
use App\Domain\Undetermined\VerbResult;
use App\Domain\Undetermined\SufficiencyCheck;
use App\Domain\Capability\CapabilityState;

$u = VerbResult::undetermined(['missing_root_cause']);
check('undetermined reports its state', 'UNDETERMINED', $u->jsonSerialize()['state']);
check('undetermined names its gaps', ['missing_root_cause'], $u->jsonSerialize()['gaps']);
check('undetermined carries no value', true, $u->isUndetermined());
check('empty gaps still names something', ['unspecified_evidence_gap'], VerbResult::undetermined([])->jsonSerialize()['gaps']);
check('decided is not undetermined', false, VerbResult::decided('x')->isUndetermined());

$sc = new SufficiencyCheck();
check('no grounding -> undetermined', true, $sc->evaluate(['what_changed' => 'y'], [])->isUndetermined());
$full = array_fill_keys(SufficiencyCheck::QUESTIONS, 'answered');
check('all seven answered + grounding -> decided', false, $sc->evaluate($full, [['id' => 'e1']])->isUndetermined());
$partial = $full; $partial['what_would_falsify_it'] = null;
$r = $sc->evaluate($partial, [['id' => 'e1']]);
check('one unanswered -> undetermined', true, $r->isUndetermined());
check('names the unanswered question', ['what_would_falsify_it'], $r->jsonSerialize()['gaps']);

echo "Capability state — advance only on evidence\n";
check('advance with evidence works', 'Assessed', CapabilityState::advance('Inferred', 'Assessed', 'ev-1'));
$threw = false;
try { CapabilityState::advance('Inferred', 'Assessed', null); } catch (\InvalidArgumentException $e) { $threw = true; }
check('advance without evidence throws', true, $threw);
$threw = false;
try { CapabilityState::advance('Mastered', 'Asserted', 'ev-1'); } catch (\InvalidArgumentException $e) { $threw = true; }
check('silent regression throws', true, $threw);
check('explicit downgrade allowed', 'Asserted', CapabilityState::advance('Mastered', 'Asserted', 'ev-1', true, 'reassessed after audit'));
check('behaviour uses Observed', 'Observed', CapabilityState::forDimension('Demonstrated', 'behaviour'));
check('skill stays Demonstrated', 'Demonstrated', CapabilityState::forDimension('Demonstrated', 'skill'));
check('legacy level without provenance is only Asserted', 'Asserted', CapabilityState::fromLegacyLevel(5.0, false));
check('legacy level with provenance caps at Assessed', 'Assessed', CapabilityState::fromLegacyLevel(5.0, true));
check('legacy null is Unknown', 'Unknown', CapabilityState::fromLegacyLevel(null));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
