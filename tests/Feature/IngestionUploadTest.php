<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SeedsEntityMappings;
use Tests\TestCase;

/**
 * The upload half of the ingestion engine.
 *
 * FOUR DEFECTS ARE PINNED HERE, all of which shipped and all of which were
 * invisible from the response body:
 *
 * 1. THE STORED FILE LOST ITS EXTENSION. ->store() names uploads from
 *    hashName(), whose extension comes from a MIME sniff; an ordinary CSV
 *    sniffs as text/plain and was written as `<hash>.txt`. CsvUploadSource read
 *    the extension back off that path, took its plain-text branch, and
 *    collapsed the ENTIRE FILE into one row — the filename as the title and the
 *    whole content as evidence text. A 162,000-row export ingested as a single
 *    document record and reported success.
 *
 * 2. THE SIZE CAP WAS 20 MB, which refused ordinary organization exports.
 *
 * 3. EVERY PHP UPLOAD FAILURE READ "The file failed to upload." Seven distinct
 *    causes — over the ini limit, no temp directory, an unwritable temp
 *    directory, a truncated request — produced one sentence that named none of
 *    them, so the same 422 was reported for a misconfigured server and for a
 *    file that was simply too big.
 *
 * 4. fgetcsv WAS HARDCODED TO A COMMA, so a semicolon-delimited export — what
 *    Excel produces on any machine with a comma decimal separator — parsed as a
 *    single column whose name was the entire header line. It did not error.
 */
final class IngestionUploadTest extends TestCase
{
    use SeedsEntityMappings;

    private const TENANT = '4';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->buildSchema();
        $this->installEntityMappings([self::TENANT]);
    }

    private function token(string $tenant = self::TENANT): string
    {
        return Jwt::issueAccess(['id' => '1', 'tenantId' => $tenant, 'role' => 'tenant_admin']);
    }

    /** @param array<int, array<int, string>> $rows */
    private function csv(string $name, array $rows, string $delimiter = ','): UploadedFile
    {
        $body = implode("\n", array_map(
            fn (array $r): string => implode($delimiter, array_map(
                fn (string $c): string => str_contains($c, $delimiter) || str_contains($c, "\n")
                    ? '"'.str_replace('"', '""', $c).'"'
                    : $c,
                $r,
            )),
            $rows,
        ));

        return UploadedFile::fake()->createWithContent($name, $body);
    }

    // =====================================================================
    // The extension defect — the one that silently destroyed datasets
    // =====================================================================

    public function test_a_csv_is_parsed_as_rows_not_as_one_document(): void
    {
        $response = $this->postJson('/api/v1/ingestion/upload', [
            'file' => $this->csv('fees.csv', [
                ['Student ID', 'Name', 'Amount Due', 'Payment Status'],
                ['STU001', 'Ada', '12000', 'Paid'],
                ['STU002', 'Bo', '15000', 'Pending'],
                ['STU003', 'Cy', '18000', 'Partial'],
            ]),
            'source_id' => 'fees',
        ], ['Authorization' => 'Bearer '.$this->token()]);

        $response->assertStatus(201);

        // Three data rows, not one document row. Before the fix this was 1.
        self::assertSame(3, $response->json('preview.row_count'));

        // And the real columns, not title/state/evidence_text/file_type.
        self::assertSame(
            ['Student ID', 'Name', 'Amount Due', 'Payment Status'],
            $response->json('preview.headers'),
        );
    }

    /**
     * The file on disk keeps the extension the user actually uploaded.
     *
     * This is the mechanism behind the test above, asserted directly: if the
     * stored name reverts to a MIME guess, the parse silently reverts with it.
     */
    public function test_the_stored_file_keeps_its_real_extension(): void
    {
        $this->postJson('/api/v1/ingestion/upload', [
            'file'      => $this->csv('fees.csv', [['a', 'b'], ['1', '2']]),
            'source_id' => 'fees',
        ], ['Authorization' => 'Bearer '.$this->token()])->assertStatus(201);

        $stored = Storage::disk('local')->allFiles('ingestion/'.self::TENANT);

        self::assertNotEmpty($stored, 'Nothing was stored.');
        foreach ($stored as $path) {
            self::assertStringEndsWith('.csv', $path, "Stored as {$path}; the extension was rewritten.");
        }
    }

    /** Uploads land under the authenticated tenant's own directory. */
    public function test_uploads_are_stored_under_the_authenticated_tenant(): void
    {
        $this->postJson('/api/v1/ingestion/upload', [
            'file'      => $this->csv('fees.csv', [['a'], ['1']]),
            'source_id' => 'fees',
        ], ['Authorization' => 'Bearer '.$this->token()])->assertStatus(201);

        foreach (Storage::disk('local')->allFiles('ingestion') as $path) {
            self::assertStringStartsWith('ingestion/'.self::TENANT.'/', $path);
        }
    }

    // =====================================================================
    // Delimiters
    // =====================================================================

    /**
     * A semicolon export is a table, not a one-column table.
     *
     * Excel writes semicolons wherever the decimal separator is a comma. The
     * previous reader produced a single column named
     * "Student ID;Name;Amount Due" and reported success.
     */
    public function test_a_semicolon_delimited_csv_is_parsed_as_columns(): void
    {
        $response = $this->postJson('/api/v1/ingestion/upload', [
            'file' => $this->csv('fees.csv', [
                ['Student ID', 'Name', 'Amount Due'],
                ['STU001', 'Ada', '12000'],
                ['STU002', 'Bo', '15000'],
            ], ';'),
            'source_id' => 'fees',
        ], ['Authorization' => 'Bearer '.$this->token()]);

        $response->assertStatus(201);
        self::assertSame(['Student ID', 'Name', 'Amount Due'], $response->json('preview.headers'));
        self::assertSame(2, $response->json('preview.row_count'));
    }

    /** A comma inside a quoted field must not outvote the real separator. */
    public function test_quoted_commas_do_not_confuse_delimiter_detection(): void
    {
        $response = $this->postJson('/api/v1/ingestion/upload', [
            'file' => $this->csv('fees.csv', [
                ['Name', 'Note'],
                ['Ada', 'Paid, in full'],
                ['Bo', 'Pending, chased twice'],
            ]),
            'source_id' => 'fees',
        ], ['Authorization' => 'Bearer '.$this->token()]);

        $response->assertStatus(201);
        self::assertSame(['Name', 'Note'], $response->json('preview.headers'));
        self::assertSame(2, $response->json('preview.row_count'));
    }

    /**
     * Rows are records, not lines.
     *
     * A quoted field containing newlines makes the physical line count exceed
     * the record count. The reported row_count must be records, or every
     * downstream reconciliation is wrong.
     */
    public function test_multiline_quoted_fields_count_as_one_row_each(): void
    {
        $response = $this->postJson('/api/v1/ingestion/upload', [
            'file' => $this->csv('notes.csv', [
                ['Name', 'Remarks'],
                ['Ada', "line one\nline two\nline three"],
                ['Bo', "another\nspanning note"],
            ]),
            'source_id' => 'notes',
        ], ['Authorization' => 'Bearer '.$this->token()]);

        $response->assertStatus(201);
        self::assertSame(2, $response->json('preview.row_count'));
    }

    // =====================================================================
    // Every usable column survives
    // =====================================================================

    /**
     * Columns the mapper does not recognise are REPORTED, never dropped.
     *
     * unmapped_fields is what tells the reviewer a column was not understood.
     * Silently discarding it would lose data with no trace.
     */
    public function test_unrecognised_columns_are_preserved_and_reported(): void
    {
        $headers = ['Student ID', 'Name', 'Payment Status', 'Bespoke Metric', 'Another Odd Column'];

        $response = $this->postJson('/api/v1/ingestion/upload', [
            'file' => $this->csv('fees.csv', [
                $headers,
                ['STU001', 'Ada', 'Paid', '42', 'xyz'],
            ]),
            'source_id' => 'fees',
        ], ['Authorization' => 'Bearer '.$this->token()]);

        $response->assertStatus(201);

        // Every original column is still named in the preview.
        self::assertSame($headers, $response->json('preview.headers'));

        // And the odd ones survive into the sample rows rather than vanishing.
        $sample = $response->json('preview.sample_rows.0');
        self::assertArrayHasKey('Bespoke Metric', $sample);
        self::assertArrayHasKey('Another Odd Column', $sample);
        self::assertSame('42', $sample['Bespoke Metric']);
    }

    // =====================================================================
    // Validation and precise errors
    // =====================================================================

    public function test_an_unsupported_extension_is_refused(): void
    {
        $this->postJson('/api/v1/ingestion/upload', [
            'file'      => UploadedFile::fake()->create('payload.exe', 16),
            'source_id' => 'x',
        ], ['Authorization' => 'Bearer '.$this->token()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_a_file_over_the_cap_names_the_cap(): void
    {
        $response = $this->postJson('/api/v1/ingestion/upload', [
            // 64 MB cap; 70 MB is over it.
            'file'      => UploadedFile::fake()->create('huge.csv', 70 * 1024),
            'source_id' => 'x',
        ], ['Authorization' => 'Bearer '.$this->token()]);

        $response->assertStatus(422)->assertJsonValidationErrors('file');
        self::assertStringContainsString('65536', (string) $response->json('errors.file.0'));
    }

    /**
     * A PHP-level upload failure names ITS OWN cause.
     *
     * THE DEFECT THIS PINS: all seven UPLOAD_ERR_* codes rendered as
     * "The file failed to upload." — the message the production report showed —
     * which is true of a 2 MB ini limit, a missing temp directory and a dropped
     * connection alike, and actionable for none of them.
     *
     * @dataProvider uploadErrorCodes
     */
    public function test_a_php_upload_error_is_reported_precisely(int $code, string $expectedError, string $mustMention): void
    {
        /*
          AN EMPTY tmp_name, WHICH IS WHAT PHP ACTUALLY HANDS LARAVEL.

          The first version of this test passed a real tempnam() path, so
          getPath() was non-empty, hasFile() returned true, and the controller's
          guard fired. That made the test green against a guard that could never
          fire in production: on UPLOAD_ERR_INI_SIZE, NO_TMP_DIR and PARTIAL,
          PHP sets tmp_name to '' — hasFile() is then false and the request fell
          through to the generic `uploaded` message.

          Passing '' here is the whole point of the test. It is the difference
          between exercising the real failure and exercising a fiction.
        */
        $broken = new UploadedFile(
            '',
            'lions_fees_data.csv',
            'text/csv',
            $code,
            true, // test mode: skip is_uploaded_file()
        );

        self::assertFalse($broken->isValid(), 'Fixture is not actually a failed upload.');

        $response = $this->postJson('/api/v1/ingestion/upload', [
            'file'      => $broken,
            'source_id' => 'fees',
        ], ['Authorization' => 'Bearer '.$this->token()]);

        $response->assertStatus(422);
        self::assertSame($expectedError, $response->json('error'));

        $message = (string) $response->json('message');
        self::assertStringContainsStringIgnoringCase($mustMention, $message);

        // Never the generic sentence again.
        self::assertNotSame('The file failed to upload.', $message);

        // Mirrored where the SPA's extractor reads it.
        self::assertSame($message, $response->json('errors.file.0'));
    }

    /** @return array<string, array{0: int, 1: string, 2: string}> */
    public static function uploadErrorCodes(): array
    {
        return [
            'over the php ini limit'  => [UPLOAD_ERR_INI_SIZE, 'file_exceeds_php_limit', 'upload_max_filesize'],
            'over the form limit'     => [UPLOAD_ERR_FORM_SIZE, 'file_exceeds_form_limit', 'form'],
            'truncated request'       => [UPLOAD_ERR_PARTIAL, 'upload_incomplete', 'interrupted'],
            'no temp directory'       => [UPLOAD_ERR_NO_TMP_DIR, 'missing_temp_directory', 'temporary'],
            'temp dir not writable'   => [UPLOAD_ERR_CANT_WRITE, 'temp_directory_not_writable', 'write'],
            'blocked by an extension' => [UPLOAD_ERR_EXTENSION, 'upload_blocked_by_extension', 'extension'],
        ];
    }

    /**
     * The diagnostic works on the object a REAL request produces.
     *
     * THE REGRESSION THIS PINS, which shipped and which the data-provider test
     * above did NOT catch. PHP's $_FILES is converted by Symfony's FileBag into
     * Symfony\Component\HttpFoundation\File\UploadedFile. Laravel only upgrades
     * those to its own Illuminate\Http\UploadedFile subclass inside allFiles(),
     * a path that $request->files->get() never takes — so on a genuine upload
     * the object is the Symfony PARENT.
     *
     * The controller checked `instanceof Illuminate\Http\UploadedFile`, which is
     * false for the parent, so the guard returned null and every real failure
     * fell through to "The file failed to upload.". Every test passed
     * throughout, because postJson() accepts an already-constructed Illuminate
     * instance and keeps it.
     *
     * Passing the Symfony class explicitly is the only way this test differs
     * from the one above, and it is the entire point of it.
     */
    public function test_the_diagnostic_handles_a_symfony_uploaded_file(): void
    {
        $symfony = new \Symfony\Component\HttpFoundation\File\UploadedFile(
            '',
            'lions_fees_data.csv',
            'text/csv',
            UPLOAD_ERR_INI_SIZE,
            true,
        );

        self::assertNotInstanceOf(
            UploadedFile::class,
            $symfony,
            'Fixture must be the Symfony parent, or this test proves nothing.',
        );

        $response = $this->call(
            'POST',
            '/api/v1/ingestion/upload',
            ['source_id' => 'fees'],
            [],
            ['file' => $symfony],
            $this->transformHeadersToServerVars([
                'Authorization' => 'Bearer ' . $this->token(),
                'Accept'        => 'application/json',
            ]),
        );

        $response->assertStatus(422);
        self::assertSame('file_exceeds_php_limit', $response->json('error'));
        self::assertNotSame('The file failed to upload.', $response->json('message'));
    }

    // =====================================================================
    // Authentication and tenant isolation
    // =====================================================================

    public function test_an_unauthenticated_upload_is_refused(): void
    {
        $this->postJson('/api/v1/ingestion/upload', [
            'file'      => $this->csv('fees.csv', [['a'], ['1']]),
            'source_id' => 'fees',
        ])->assertStatus(401);
    }

    /**
     * The tenant comes from the token, never from the body.
     *
     * A body-supplied tenant that could redirect where the file is stored would
     * let any authenticated user write into another organization's directory.
     */
    public function test_a_body_supplied_tenant_cannot_redirect_the_upload(): void
    {
        $this->postJson('/api/v1/ingestion/upload', [
            'file'      => $this->csv('fees.csv', [['a'], ['1']]),
            'source_id' => 'fees',
            'tenant_id' => '999',
            'tenantId'  => '999',
        ], ['Authorization' => 'Bearer '.$this->token()])->assertStatus(201);

        foreach (Storage::disk('local')->allFiles('ingestion') as $path) {
            self::assertStringStartsWith('ingestion/'.self::TENANT.'/', $path);
            self::assertStringNotContainsString('/999/', $path);
        }
    }

    // =====================================================================
    // Fixture
    // =====================================================================

    private function buildSchema(): void
    {
        // Columns copied from what ImportJobRepository actually writes, not
        // guessed — org_id, entity_type, error_report and started_by are all
        // in the insert and were absent from an earlier version of this
        // fixture, which surfaced as a 503 from the controller's QueryException
        // handler rather than as a missing-column error.
        Schema::create('hpbrain_import_jobs', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36)->nullable();
            $t->string('source_id')->nullable();
            $t->string('source_ref')->nullable();
            $t->string('import_type')->nullable();
            $t->string('entity_type')->nullable();
            $t->string('sync_type')->nullable();
            $t->string('checkpoint')->nullable();
            $t->timestamp('fetched_at')->nullable();
            $t->string('file_name')->nullable();
            $t->string('status')->default('pending');
            $t->integer('total_rows')->default(0);
            $t->integer('processed_rows')->default(0);
            $t->integer('success_count')->default(0);
            $t->integer('error_count')->default(0);
            $t->integer('duplicate_count')->default(0);
            $t->text('field_map')->nullable();
            $t->text('error_report')->nullable();
            $t->text('rollback_data')->nullable();
            $t->string('started_by')->nullable();
            $t->string('created_by')->nullable();
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
            $t->timestamp('completed_date')->nullable();
        });

        Schema::create('hpbrain_import_logs', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('import_job_id', 36);
            $t->integer('row_number')->nullable();
            $t->string('action');
            $t->string('entity_type')->nullable();
            $t->string('entity_id')->nullable();
            $t->text('data')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_data_sources', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('source_key', 190);
            $t->string('display_name');
            $t->string('source_type', 50)->default('csv_upload');
            $t->text('config')->nullable();
            $t->text('field_map')->nullable();
            $t->string('checkpoint')->nullable();
            $t->boolean('is_active')->default(true);
            $t->dateTime('last_synced_at')->nullable();
            $t->string('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
            $t->unique(['tenant_id', 'source_key'], 'data_sources_tenant_key_unique');
        });
    }
}
