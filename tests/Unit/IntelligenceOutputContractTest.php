<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Intelligence\IntelligenceOutputContract;
use PHPUnit\Framework\TestCase;

/**
 * The validator itself, independent of any composer that produces a
 * contract — these pin the schema, not any one projection of it.
 */
final class IntelligenceOutputContractTest extends TestCase
{
    /** @return array<string, mixed> a minimal, valid envelope to mutate per test */
    private function validContract(): array
    {
        return [
            'title' => 'Engineering — Department Intelligence',
            'executiveSummary' => 'Everything measurable is healthy.',
            'context' => ['type' => 'OrganizationUnit', 'id' => '1', 'name' => 'Engineering'],
            'findings' => [
                ['type' => 'observed_fact', 'statement' => 'This unit has 12 people.', 'evidence' => [], 'confidence' => null],
            ],
            'recommendations' => [
                ['title' => 'Assign capabilities', 'rationale' => 'No capability data is recorded yet.'],
            ],
            'limitations' => [],
            'dataCoverage' => ['measuredDimensions' => 5, 'totalDimensions' => 7],
        ];
    }

    public function test_a_well_formed_contract_has_no_errors(): void
    {
        self::assertSame([], IntelligenceOutputContract::validate($this->validContract()));
    }

    public function test_an_unknown_finding_type_is_rejected(): void
    {
        $contract = $this->validContract();
        $contract['findings'][0]['type'] = 'vibes';

        $errors = IntelligenceOutputContract::validate($contract);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('findings[0]', $errors[0]);
        self::assertStringContainsString('type must be one of', $errors[0]);
    }

    public function test_every_declared_finding_type_is_individually_valid(): void
    {
        foreach (IntelligenceOutputContract::FINDING_TYPES as $type) {
            self::assertTrue(IntelligenceOutputContract::isValidFindingType($type));
        }

        self::assertFalse(IntelligenceOutputContract::isValidFindingType('not_a_real_type'));
    }

    public function test_a_finding_with_no_statement_is_rejected(): void
    {
        $contract = $this->validContract();
        $contract['findings'][0]['statement'] = '';

        $errors = IntelligenceOutputContract::validate($contract);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('statement is required', implode(' ', $errors));
    }

    public function test_a_finding_confidence_outside_zero_to_one_is_rejected(): void
    {
        $contract = $this->validContract();
        $contract['findings'][0]['confidence'] = 1.5;

        $errors = IntelligenceOutputContract::validate($contract);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('confidence must be null or a number between 0 and 1', implode(' ', $errors));
    }

    public function test_a_null_finding_confidence_is_valid_and_means_unmeasured_not_zero(): void
    {
        $contract = $this->validContract();
        $contract['findings'][0]['confidence'] = null;

        self::assertSame([], IntelligenceOutputContract::validate($contract));
    }

    public function test_a_finding_missing_the_evidence_array_is_rejected(): void
    {
        $contract = $this->validContract();
        unset($contract['findings'][0]['evidence']);

        $errors = IntelligenceOutputContract::validate($contract);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('evidence is required', implode(' ', $errors));
    }

    public function test_a_recommendation_with_no_rationale_is_rejected(): void
    {
        $contract = $this->validContract();
        unset($contract['recommendations'][0]['rationale']);

        $errors = IntelligenceOutputContract::validate($contract);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('recommendations[0]', $errors[0]);
        self::assertStringContainsString('rationale is required', $errors[0]);
    }

    public function test_a_missing_title_is_rejected(): void
    {
        $contract = $this->validContract();
        unset($contract['title']);

        $errors = IntelligenceOutputContract::validate($contract);

        self::assertContains('title is required and must be a non-empty string', $errors);
    }

    public function test_an_empty_recommendations_array_is_valid(): void
    {
        $contract = $this->validContract();
        $contract['recommendations'] = [];

        self::assertSame([], IntelligenceOutputContract::validate($contract));
    }

    public function test_an_empty_findings_array_is_valid_and_not_treated_as_an_error(): void
    {
        $contract = $this->validContract();
        $contract['findings'] = [];

        self::assertSame([], IntelligenceOutputContract::validate($contract));
    }

    public function test_missing_data_coverage_is_rejected(): void
    {
        $contract = $this->validContract();
        unset($contract['dataCoverage']);

        $errors = IntelligenceOutputContract::validate($contract);

        self::assertContains('dataCoverage is required and must be an array', $errors);
    }

    public function test_every_error_is_collected_in_one_pass_not_only_the_first(): void
    {
        $contract = $this->validContract();
        unset($contract['title'], $contract['executiveSummary'], $contract['dataCoverage']);

        $errors = IntelligenceOutputContract::validate($contract);

        self::assertCount(3, $errors);
    }
}
