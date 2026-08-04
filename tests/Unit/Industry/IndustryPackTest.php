<?php

declare(strict_types=1);

namespace Tests\Unit\Industry;

use App\Domain\Industry\IndustryClassifier;
use App\Domain\Industry\IndustryPack;
use PHPUnit\Framework\TestCase;

/**
 * The packs and the classifier that chooses between them.
 *
 * These two decide what an organization is measured on for the rest of its life
 * in the product. A pack with a malformed capability produces a register that
 * cannot be assessed; a classifier that guesses produces one that describes work
 * the organization does not do. The second failure is worse, because nothing
 * downstream can detect it — coverage, deficit and criticality all compute
 * perfectly well against the wrong subject.
 */
final class IndustryPackTest extends TestCase
{
    private const REQUIRED_FIELDS = ['code', 'name', 'category', 'type', 'difficulty', 'criticality', 'description', 'kasba'];

    private const DIMENSIONS = ['knowledge', 'ability', 'skill', 'behaviour', 'attitude'];

    /** @test */
    public function every_capability_is_completely_specified(): void
    {
        foreach (IndustryPack::industries() as $industry) {
            $capabilities = IndustryPack::capabilities($industry);

            $this->assertNotEmpty($capabilities, "Industry '{$industry}' ships no capabilities, so its tenants get an empty register.");

            foreach ($capabilities as $cap) {
                $where = "{$industry}/".($cap['code'] ?? '?');

                foreach (self::REQUIRED_FIELDS as $field) {
                    $this->assertArrayHasKey($field, $cap, "{$where} is missing '{$field}'.");
                }

                foreach (self::DIMENSIONS as $dimension) {
                    $this->assertArrayHasKey($dimension, $cap['kasba'], "{$where} does not say what '{$dimension}' means.");
                    $this->assertNotSame('', trim((string) $cap['kasba'][$dimension]), "{$where}.{$dimension} is blank.");
                }
            }
        }
    }

    /**
     * A duplicate code inside one pack would silently drop a capability, since
     * provisioning skips any code the tenant already has.
     *
     * @test
     */
    public function capability_codes_are_unique_within_a_pack(): void
    {
        foreach (IndustryPack::industries() as $industry) {
            $codes = array_column(IndustryPack::capabilities($industry), 'code');

            $this->assertSame(
                array_values(array_unique($codes)),
                $codes,
                "Industry '{$industry}' repeats a capability code.",
            );
        }
    }

    /**
     * Criticality drives what the attention queue surfaces first, so an
     * unrecognised value would quietly sort a critical capability last.
     *
     * @test
     */
    public function criticality_and_difficulty_use_known_values(): void
    {
        foreach (IndustryPack::industries() as $industry) {
            foreach (IndustryPack::capabilities($industry) as $cap) {
                $this->assertContains($cap['criticality'], ['low', 'medium', 'high', 'critical'], "{$industry}/{$cap['code']}");
                $this->assertContains($cap['difficulty'], ['basic', 'intermediate', 'advanced'], "{$industry}/{$cap['code']}");
            }
        }
    }

    /** @test */
    public function terminology_always_covers_every_entity_the_ui_labels(): void
    {
        foreach (IndustryPack::industries() as $industry) {
            $terms = IndustryPack::terminology($industry);

            foreach (['Person', 'OrganizationUnit', 'Organization', 'Position', 'Capability'] as $entity) {
                $this->assertArrayHasKey($entity, $terms, "Industry '{$industry}' has no label for '{$entity}'.");
                $this->assertNotSame('', trim($terms[$entity]));
            }
        }
    }

    /**
     * The exact strings this ERP holds today.
     *
     * @test
     */
    public function the_live_industry_values_all_classify(): void
    {
        $expected = [
            'technology'         => 'technology',
            'Finance'            => 'bfsi',
            'Healthcare'         => 'healthcare',
            'Telecommunications' => 'telecom',
        ];

        foreach ($expected as $raw => $code) {
            $this->assertSame($code, IndustryClassifier::classify($raw), "'{$raw}' classified wrongly.");
            $this->assertTrue(IndustryPack::has($code), "'{$code}' has no pack.");
        }
    }

    /**
     * Longer terms win, or a university becomes a school and a bank's
     * "Financial Services" arm becomes something else.
     *
     * @test
     */
    public function the_most_specific_match_wins(): void
    {
        $this->assertSame('higher_education', IndustryClassifier::classify('Higher Education'));
        $this->assertSame('k12_education', IndustryClassifier::classify('Education'));
        $this->assertSame('bfsi', IndustryClassifier::classify('Financial Services'));
        $this->assertSame('technology', IndustryClassifier::classify('Information Technology'));
    }

    /**
     * Substring matching would classify a hotel group and a university as
     * technology companies, because 'it' is inside both 'hospitality' and
     * 'institute'.
     *
     * @test
     */
    public function a_term_inside_another_word_does_not_match(): void
    {
        $this->assertNotSame('technology', IndustryClassifier::classify('Hospitality'));
        $this->assertNotSame('technology', IndustryClassifier::classify('Institute of Fine Arts'));
    }

    /**
     * The refusal is the feature. A default pack would give an organization a
     * register describing work it does not do, and every figure derived from it
     * would be confidently wrong with nothing to indicate the premise failed.
     *
     * @test
     */
    public function an_unrecognised_industry_returns_null_rather_than_a_default(): void
    {
        foreach (['Widgets', 'Sports Club', '', null, '   ', '12345'] as $raw) {
            $this->assertNull(
                IndustryClassifier::classify($raw),
                'Unrecognised industry must not be assigned a pack.',
            );
        }
    }

    /** @test */
    public function unclassified_reports_the_distinct_values_that_failed(): void
    {
        $this->assertSame(
            ['Widgets', 'Sports Club'],
            IndustryClassifier::unclassified(['Healthcare', 'Widgets', 'Finance', 'Sports Club', 'Widgets']),
        );
    }
}
