<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

/**
 * Best-effort schema inference from an uploaded batch.
 *
 * This is intentionally heuristic and generic: it never hardcodes a
 * customer dataset.  It looks at column names, value distributions, and
 * simple patterns to propose a schema the reviewer can accept or override
 * before commit.
 */
final class SchemaDetector
{
    /** @var array<string, array<string, string>> canonical hints grouped by signal type */
    private const ENTITY_HINTS = [
        'Fees' => ['fees', 'fee', 'payment', 'amount', 'student', 'receipt', 'invoice', 'paid', 'balance'],
        'Attendance' => ['attendance', 'present', 'absent', 'leave', 'holiday', 'working_day', 'student_id', 'date'],
        'HR' => ['employee', 'salary', 'department', 'designation', 'joining', 'resign', 'hr', 'payroll'],
        'Inventory' => ['stock', 'inventory', 'quantity', 'warehouse', 'sku', 'item', 'product'],
        'Finance' => ['account', 'transaction', 'ledger', 'debit', 'credit', 'balance', 'bank'],
        'Healthcare' => ['patient', 'diagnosis', 'medicine', 'doctor', 'hospital', 'treatment', 'appointment'],
        'Telecom' => ['plan', 'recharge', 'call', 'data', 'usage', 'subscriber', 'sim', 'mobile'],
        'Projects' => ['project', 'task', 'milestone', 'deadline', 'status', 'assignee', 'priority'],
        'CRM' => ['lead', 'opportunity', 'customer', 'deal', 'pipeline', 'contact', 'sales'],
        'ERP' => ['order', 'purchase', 'vendor', 'supplier', 'invoice', 'procurement'],
    ];

    /** @var array<string, string> column-name hints for data-type inference */
    private const TYPE_HINTS = [
        'id' => 'identifier',
        'code' => 'identifier',
        'number' => 'identifier',
        'email' => 'email',
        'phone' => 'phone',
        'mobile' => 'phone',
        'amount' => 'currency',
        'price' => 'currency',
        'salary' => 'currency',
        'total' => 'currency',
        'balance' => 'currency',
        'date' => 'date',
        'time' => 'datetime',
        'created' => 'datetime',
        'updated' => 'datetime',
        'dob' => 'date',
        'joining' => 'date',
        'address' => 'text',
        'description' => 'text',
        'remark' => 'text',
        'note' => 'text',
        'comment' => 'text',
        'status' => 'enum',
        'stage' => 'enum',
        'type' => 'enum',
        'gender' => 'enum',
        'city' => 'text',
        'state' => 'text',
        'country' => 'text',
        'pincode' => 'text',
        'zip' => 'text',
        'url' => 'url',
        'link' => 'url',
        'image' => 'url',
        'logo' => 'url',
        'quantity' => 'number',
        'count' => 'number',
        'age' => 'number',
        'percentage' => 'number',
        'percent' => 'number',
        'score' => 'number',
        'lat' => 'coordinate',
        'latitude' => 'coordinate',
        'long' => 'coordinate',
        'lng' => 'coordinate',
        'longitude' => 'coordinate',
    ];

    /** @var array<int, array<string, mixed>> */
    private array $sampleRows;

    /** @var array<int, string> */
    private array $headers;

    /** @var array<string, string> */
    private array $inferredTypes = [];

    /** @var array<string, int> */
    private array $nullCounts = [];

    /** @var array<string, array<string, mixed>> */
    private array $columnStats = [];

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>                $headers
     */
    public function __construct(array $rows, array $headers)
    {
        $this->sampleRows = $rows;
        $this->headers = $headers;
    }

    /**
     * Build the schema payload returned in the preview.
     *
     * @return array<string, mixed>
     */
    public function detect(): array
    {
        $this->inferColumnStats();
        $primaryKeys = $this->detectPrimaryKeys();
        $possibleEntity = $this->guessEntity();
        $possibleDomain = $this->guessDomain();

        return [
            'dataset_type' => $possibleEntity,
            'domain' => $possibleDomain,
            'columns' => $this->buildColumnSchema(),
            'primary_key_candidates' => $primaryKeys,
            'relationships' => $this->guessRelationships($primaryKeys),
            'row_count' => count($this->sampleRows),
            'confidence' => $this->confidenceScore($primaryKeys, $possibleEntity),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function inferColumnStats(): void
    {
        foreach ($this->headers as $header) {
            $this->nullCounts[$header] = 0;
            $this->columnStats[$header] = [
                'unique' => [],
                'numeric' => 0,
                'dates' => 0,
                'emails' => 0,
                'phones' => 0,
                'maxLength' => 0,
                'sample' => [],
            ];
        }

        foreach ($this->sampleRows as $row) {
            foreach ($this->headers as $header) {
                $raw = $row[$header] ?? null;
                $value = is_scalar($raw) ? (string) $raw : ($raw === null ? '' : json_encode($raw));

                if ($value === '' || $value === null) {
                    $this->nullCounts[$header]++;
                    continue;
                }

                $stats = $this->columnStats[$header];
                $stats['unique'][$value] = true;
                $stats['maxLength'] = max($stats['maxLength'], strlen($value));

                if (count($stats['sample']) < 5) {
                    $stats['sample'][] = $value;
                }

                if (is_numeric($value)) {
                    $stats['numeric']++;
                }

                if ($this->looksLikeDate($value)) {
                    $stats['dates']++;
                }

                if ($this->looksLikeEmail($value)) {
                    $stats['emails']++;
                }

                if ($this->looksLikePhone($value)) {
                    $stats['phones']++;
                }
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildColumnSchema(): array
    {
        $columns = [];

        foreach ($this->headers as $header) {
            $stats = $this->columnStats[$header];
            $type = $this->inferColumnType($header, $stats);
            $this->inferredTypes[$header] = $type;

            $nullFraction = count($this->sampleRows) > 0
                ? round($this->nullCounts[$header] / count($this->sampleRows), 2)
                : 0.0;

            $columns[$header] = [
                'inferred_type' => $type,
                'null_fraction' => $nullFraction,
                'unique_count' => count($stats['unique']),
                'max_length' => $stats['maxLength'],
                'sample_values' => array_values($stats['sample']),
            ];
        }

        return $columns;
    }

    /**
     * @return array<int, string>
     */
    private function detectPrimaryKeys(): array
    {
        $candidates = [];

        foreach ($this->headers as $header) {
            $lower = strtolower($header);
            if (str_contains($lower, 'id') || str_contains($lower, 'code') || str_contains($lower, 'number')) {
                $stats = $this->columnStats[$header];
                $uniqueRatio = count($this->sampleRows) > 0
                    ? count($stats['unique']) / count($this->sampleRows)
                    : 0;

                if ($uniqueRatio >= 0.95) {
                    $candidates[] = $header;
                }
            }
        }

        return $candidates;
    }

    private function guessEntity(): string
    {
        $headerText = strtolower(implode(' ', $this->headers));
        $bestMatch = 'Unknown';
        $bestScore = 0;

        foreach (self::ENTITY_HINTS as $entity => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                if (str_contains($headerText, $keyword)) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $entity;
            }
        }

        return $bestMatch;
    }

    private function guessDomain(): string
    {
        $entity = $this->guessEntity();

        return match ($entity) {
            'Fees', 'Finance' => 'Finance',
            'Attendance', 'HR' => 'Human Resources',
            'Inventory' => 'Supply Chain',
            'Healthcare' => 'Healthcare',
            'Telecom' => 'Telecommunications',
            'Projects' => 'Project Management',
            'CRM' => 'Customer Relationship Management',
            'ERP' => 'Enterprise Resource Planning',
            default => 'Operations',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function guessRelationships(array $primaryKeys): array
    {
        $relationships = [];
        $lowerHeaders = array_map(static fn (string $h) => strtolower($h), $this->headers);

        foreach ($this->headers as $header) {
            $lower = strtolower($header);

            if (str_ends_with($lower, '_id') && !in_array($header, $primaryKeys, true)) {
                $targetEntity = $this->inferEntityFromColumn($header);
                if ($targetEntity !== null) {
                    $relationships[] = [
                        'type' => 'foreign_key',
                        'source_column' => $header,
                        'target_entity' => $targetEntity,
                        'confidence' => 'medium',
                    ];
                }
            }
        }

        return $relationships;
    }

    private function inferEntityFromColumn(string $column): ?string
    {
        $lower = strtolower($column);
        $entityMap = [
            'student' => 'Student',
            'employee' => 'Employee',
            'person' => 'Person',
            'user' => 'User',
            'customer' => 'Customer',
            'client' => 'Client',
            'vendor' => 'Vendor',
            'supplier' => 'Supplier',
            'product' => 'Product',
            'item' => 'Item',
            'order' => 'Order',
            'invoice' => 'Invoice',
            'project' => 'Project',
            'task' => 'Task',
            'department' => 'Department',
            'organization' => 'Organization',
            'signal' => 'Signal',
            'evidence' => 'Evidence',
        ];

        foreach ($entityMap as $keyword => $entity) {
            if (str_contains($lower, $keyword)) {
                return $entity;
            }
        }

        return null;
    }

    private function inferColumnType(string $header, array $stats): string
    {
        $lower = strtolower($header);

        foreach (self::TYPE_HINTS as $needle => $type) {
            if (str_contains($lower, $needle)) {
                if ($type === 'enum' || $type === 'text' || $type === 'url') {
                    return $type;
                }

                if ($stats['numeric'] >= count($this->sampleRows) * 0.8) {
                    return match ($type) {
                        'currency' => 'currency',
                        'number' => 'number',
                        'identifier' => 'identifier',
                        default => 'text',
                    };
                }

                if ($stats['dates'] >= count($this->sampleRows) * 0.8) {
                    return 'date';
                }

                if ($stats['emails'] >= count($this->sampleRows) * 0.5) {
                    return 'email';
                }

                if ($stats['phones'] >= count($this->sampleRows) * 0.5) {
                    return 'phone';
                }

                return $type;
            }
        }

        if ($stats['numeric'] >= count($this->sampleRows) * 0.8) {
            return 'number';
        }

        if ($stats['dates'] >= count($this->sampleRows) * 0.8) {
            return 'date';
        }

        if ($stats['emails'] >= count($this->sampleRows) * 0.5) {
            return 'email';
        }

        if ($stats['phones'] >= count($this->sampleRows) * 0.5) {
            return 'phone';
        }

        if ($stats['maxLength'] > 255) {
            return 'text';
        }

        return 'text';
    }

    private function looksLikeDate(string $value): bool
    {
        $parsed = strtotime($value);

        return $parsed !== false && $parsed > 0;
    }

    private function looksLikeEmail(string $value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
    }

    private function looksLikePhone(string $value): bool
    {
        $digits = preg_replace('/\D/', '', $value);

        return $digits !== null && strlen($digits) >= 10;
    }

    /**
     * @param  array<int, string>  $primaryKeys
     */
    private function confidenceScore(array $primaryKeys, string $entity): float
    {
        $score = 0.0;

        if ($entity !== 'Unknown') {
            $score += 0.4;
        }

        if (!empty($primaryKeys)) {
            $score += 0.3;
        }

        $typedCount = 0;

        foreach ($this->inferredTypes as $type) {
            if ($type !== 'text') {
                $typedCount++;
            }
        }

        if (count($this->headers) > 0) {
            $score += min(0.3, ($typedCount / count($this->headers)) * 0.3);
        }

        return round(min(1.0, $score), 2);
    }
}
