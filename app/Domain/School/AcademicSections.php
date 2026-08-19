<?php

declare(strict_types=1);

namespace App\Domain\School;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The four school sections, derived from the standards a tenant's imported data
 * actually contains.
 *
 * WHAT THIS IS, AND WHAT IT IS DELIBERATELY NOT. These are ACADEMIC SECTIONS —
 * a grouping of the imported student projection by grade band. They are NOT
 * rows in hrms_departments and nothing here writes one. A department in this
 * system is a unit of the connected HR system, master data other screens and
 * other organizations depend on; inventing four of them to make a screen look
 * populated is exactly the fabrication the Departments screen already refuses
 * to do. The Departments screen renders these instead of its empty state when a
 * tenant has students but no HR units, and says so on the page.
 *
 * WHY BANDS AND NOT ONE PER STANDARD. Lions' data carries twelve standards
 * across two differently-shaped files, plus divisions, batches, quotas,
 * subjects and exam types. Rendering each as its own "department" produced
 * dozens of cards that answered no question anybody asks. Four bands do:
 * Primary, Middle, Secondary, Higher Secondary.
 *
 * WHAT IS NOT GUESSED AT. Lions' fee register records NR / JR / SR for 236
 * students, which reads as Nursery / Junior KG / Senior KG — and is not. Of
 * those 236, 131 ALSO carry a CBSE standard in the results export, and it is
 * CBSE-12 for 108 of them: in this school "SR" means the senior section, not
 * senior kindergarten. Those 131 are placed by their examined standard. The
 * remaining 105 carry no other evidence of a grade, and rather than assert one
 * reading of an ambiguous code they are reported as UNPLACED, counted in the
 * totals and named on the screen. A section labelled "Pre-Primary" holding 105
 * children who may well be in standard 12 would be a fabrication wearing a
 * plausible label, which is worse than an honest "105 without a readable
 * standard".
 *
 * ────────────────────────────────────────────────────────────────────────────
 * HOW A STUDENT'S GRADE IS READ.
 *
 * Two columns carry it and neither is complete. `academic_standard` comes from
 * the results export as "CBSE-9" and covers 5,304 students; `standard` comes
 * from the fee register as the Roman numeral "IX" and covers 4,052; 1,911
 * students have both and every student has at least one. So the grade is
 * COALESCEd across the pair, normalising each spelling to an integer.
 *
 * The mapping is expressed as a CASE over the literal values the data holds,
 * not as string arithmetic. It is longer, and it is right: SUBSTRING_INDEX on
 * "CBSE-10" is fine and on "SR" is silent nonsense, and a Roman numeral has no
 * arithmetic at all. Anything the source spells in a way not listed here lands
 * in `unplaced` and is REPORTED rather than quietly assigned to a band.
 *
 * ONE GROUPED QUERY. Not one query per section, and not a download of 7,445
 * rows to bucket them in PHP. Everything below is COUNT/SUM over the projection
 * with a tenant predicate, so the screen costs the same on a school with four
 * hundred thousand records as on one with four hundred.
 */
final class AcademicSections
{
    /**
     * The bands, in the order a school reads them.
     *
     * `min`/`max` are inclusive grade numbers. Pre-primary is modelled as
     * grades 0 and below so one comparison places every student.
     *
     * @var array<int, array{key: string, name: string, min: int, max: int, standards: string}>
     */
    private const SECTIONS = [
        ['key' => 'primary',          'name' => 'Primary Section',   'min' => 1,  'max' => 5,  'standards' => 'Standards 1–5'],
        ['key' => 'middle',           'name' => 'Middle School',     'min' => 6,  'max' => 8,  'standards' => 'Standards 6–8'],
        ['key' => 'secondary',        'name' => 'Secondary Section', 'min' => 9,  'max' => 10, 'standards' => 'Standards 9–10'],
        ['key' => 'higher-secondary', 'name' => 'Higher Secondary',  'min' => 11, 'max' => 12, 'standards' => 'Standards 11–12'],
    ];

    /**
     * Every spelling of a grade the imported data uses, mapped to its number.
     *
     * Roman numerals from the fee register and CBSE-n from the results export.
     * Plain integers are accepted too, because a source that writes "9" is not
     * wrong and the next school's export might.
     *
     * NR / JR / SR ARE DELIBERATELY ABSENT. They could mean nursery and the two
     * kindergarten years, or the junior and senior sections; in Lions' data 108
     * of the students marked "SR" were examined in CBSE-12, so here it plainly
     * means senior. A student carrying one of these AND a standard is placed by
     * the standard; one carrying only these is reported as unplaced. Adding a
     * guess to this table would move real children into a band on the strength
     * of two letters.
     *
     * @var array<string, int>
     */
    private const GRADE_WORDS = [
        'I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6,
        'VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10, 'XI' => 11, 'XII' => 12,
    ];

    /**
     * Whether this tenant has student data these sections can describe.
     *
     * False is not an error — it is a manufacturer, a telecom operator, or a
     * school whose projection has not been built. The caller renders its own
     * empty state rather than a row of zeroes.
     */
    public function availableFor(string $tenant): bool
    {
        return Schema::hasTable('hpbrain_students')
            && DB::table('hpbrain_students')->where('tenant_id', $tenant)->exists();
    }

    /**
     * The sections this tenant's data contains, each with its real counts.
     *
     * @return array{
     *   sections: array<int, array<string, mixed>>,
     *   totals: array{students: int, placed: int, unplaced: int, sections: int}
     * }
     */
    public function forTenant(string $tenant): array
    {
        if (! $this->availableFor($tenant)) {
            return ['sections' => [], 'totals' => ['students' => 0, 'placed' => 0, 'unplaced' => 0, 'sections' => 0]];
        }

        $rows = DB::table('hpbrain_students')
            ->where('tenant_id', $tenant)
            ->selectRaw($this->gradeExpression().' AS grade')
            ->selectRaw('COUNT(*) AS students')
            ->selectRaw('SUM(CASE WHEN in_academic = 1 AND in_fees = 1 THEN 1 ELSE 0 END) AS in_both')
            ->selectRaw('SUM(CASE WHEN in_fees = 1 THEN 1 ELSE 0 END) AS with_fees')
            ->selectRaw('SUM(academic_records) AS academic_records')
            ->selectRaw('SUM(fee_records) AS fee_records')
            ->selectRaw('SUM(total_paid) AS total_paid')
            /*
              THE SUM AND THE COUNT, not an AVG, and the count is of the
              students that HAVE a mark.

              A band's average has to be re-derived from its grades, and an AVG
              per grade cannot be re-averaged without knowing how many students
              each one was over. Worse, weighting those AVGs by the grade's
              STUDENT count silently readmits the students who have no mark: a
              grade of two children where one scored 40 and the other was never
              examined has an AVG of 40 over one child, and weighting it by two
              states 40 twice. Carrying the numerator and the denominator makes
              the band average exactly the average of its examined children.

              A student with no marks is still counted in `students`. They are
              in the section; they simply do not have a result.
            */
            ->selectRaw('SUM(avg_percentage) AS percentage_sum')
            ->selectRaw('COUNT(avg_percentage) AS graded_students')
            ->groupBy('grade')
            ->get();

        $byGrade = [];
        $unplaced = 0;
        $total = 0;

        foreach ($rows as $row) {
            $total += (int) $row->students;

            if ($row->grade === null) {
                $unplaced += (int) $row->students;

                continue;
            }

            $byGrade[(int) $row->grade] = $row;
        }

        $sections = [];
        $placed = 0;

        foreach (self::SECTIONS as $section) {
            $grades = array_filter(
                $byGrade,
                fn (int $grade) => $grade >= $section['min'] && $grade <= $section['max'],
                ARRAY_FILTER_USE_KEY,
            );

            if ($grades === []) {
                // A band with nobody in it is not a section this school has.
                // Emitting it would put an empty card on the screen and claim
                // the school runs a stage it does not.
                continue;
            }

            $students = array_sum(array_map(fn ($r) => (int) $r->students, $grades));
            $placed += $students;

            // Sum of the examined children's percentages over how many were
            // examined — the average of the CHILDREN, not of the grades.
            $percentageSum = 0.0;
            $gradedStudents = 0;

            foreach ($grades as $row) {
                $percentageSum += (float) ($row->percentage_sum ?? 0);
                $gradedStudents += (int) ($row->graded_students ?? 0);
            }

            $sections[] = [
                'id' => $section['key'],
                'name' => $section['name'],
                'standards' => $section['standards'],
                'gradeRange' => ['min' => $section['min'], 'max' => $section['max']],
                'students' => $students,
                'studentsInBothFiles' => array_sum(array_map(fn ($r) => (int) $r->in_both, $grades)),
                'studentsWithFees' => array_sum(array_map(fn ($r) => (int) $r->with_fees, $grades)),
                'academicRecords' => array_sum(array_map(fn ($r) => (int) $r->academic_records, $grades)),
                'feeRecords' => array_sum(array_map(fn ($r) => (int) $r->fee_records, $grades)),
                'feesCollected' => round(array_sum(array_map(fn ($r) => (float) $r->total_paid, $grades)), 2),
                // Null, not zero, when no student in the band has a mark.
                'averagePercentage' => $gradedStudents === 0 ? null : round($percentageSum / $gradedStudents, 1),
                'gradedStudents' => $gradedStudents,
                'status' => 'active',
            ];
        }

        return [
            'sections' => $sections,
            'totals' => [
                'students' => $total,
                'placed' => $placed,
                // Published, never hidden. If the sections do not sum to the
                // People screen's figure, this says by how many and why.
                'unplaced' => $unplaced + ($total - $placed - $unplaced),
                'sections' => count($sections),
            ],
        ];
    }

    /**
     * One section's definition, or null if the key names none.
     *
     * @return array{key: string, name: string, min: int, max: int, standards: string}|null
     */
    public function section(string $key): ?array
    {
        foreach (self::SECTIONS as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        return null;
    }

    /**
     * A SQL predicate confining a query to one section's grades.
     *
     * Handed to the student repository so the drill-down list is filtered by
     * the DATABASE, on the same grade expression the counts were computed from.
     * The alternative — resolving the band to a list of standard spellings and
     * sending a WHERE IN — would drift the moment a source spelled one of them
     * differently, and the card and the list beneath it would disagree.
     *
     * @return array{sql: string, bindings: array<int, int>}|null
     */
    public function gradePredicate(string $key): ?array
    {
        $section = $this->section($key);

        if ($section === null) {
            return null;
        }

        return [
            'sql' => $this->gradeExpression().' BETWEEN ? AND ?',
            'bindings' => [$section['min'], $section['max']],
        ];
    }

    /**
     * The grade of a student row, as an integer, or NULL when unreadable.
     *
     * COALESCE across the two columns because neither covers everybody:
     * `academic_standard` is the results export's "CBSE-9" and `standard` is
     * the fee register's "IX". Where a student has both they agree; where they
     * do not, the academic export is preferred because it is the file that
     * states the standard a child was examined in.
     */
    private function gradeExpression(): string
    {
        return 'COALESCE('
            .$this->normalise('academic_standard').', '
            .$this->normalise('standard')
            .')';
    }

    /**
     * One column normalised to a grade number.
     *
     * "IX" and "SR" are matched against the words table; "CBSE-9", "CBSE 9" and
     * a bare "9" are matched by their trailing digits. Anything else yields NULL
     * and the student is counted as unplaced rather than guessed at.
     *
     * WHY LIKE AND NOT REGEXP_REPLACE. The obvious form —
     * REGEXP_REPLACE(col, '[^0-9]', '') — is MySQL 8 only, and this project's
     * suite runs on in-memory SQLite (phpunit.xml), where it is
     * "no such function". A grouping rule that cannot be executed by the tests
     * is a rule nothing verifies, so the expression is built from LIKE and
     * TRIM, which every dialect this application targets implements
     * identically. It costs a longer CASE and buys a testable one.
     *
     * ORDER MATTERS AND IS DESCENDING. 12, 11 and 10 are matched before 2 and 1
     * so "CBSE-12" cannot be read as a 2. The patterns anchor on the END of the
     * string, so '%-1' matches "CBSE-1" and not "CBSE-11" — the descending
     * order is belt and braces on top of that, not the only guard.
     */
    private function normalise(string $column): string
    {
        $value = "UPPER(TRIM({$column}))";

        $words = '';

        foreach (self::GRADE_WORDS as $word => $grade) {
            $words .= " WHEN '{$word}' THEN {$grade}";
        }

        $digits = '';

        for ($grade = 12; $grade >= 1; $grade--) {
            // "CBSE-9" / "CBSE 9" / "9". A board prefix of any name works,
            // because only the tail is examined.
            $digits .= " WHEN {$value} LIKE '%-{$grade}' OR {$value} LIKE '% {$grade}' OR {$value} = '{$grade}' THEN {$grade}";
        }

        // The word table wins, so "IX" is 9 rather than falling through to a
        // digit match it would never satisfy anyway.
        return 'COALESCE('
            ."CASE {$value}{$words} ELSE NULL END, "
            ."CASE{$digits} ELSE NULL END"
            .')';
    }
}
