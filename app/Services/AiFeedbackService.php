<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class AiFeedbackService
{
    public function record(string $tenantId, string $executionId, string $userId, string $rating, ?string $feedback = null, ?string $feedbackType = null): void
    {
        DB::table('hpbrain_ai_feedback')->insert([
            // Generated here, not by MySQL's UUID(): that function does not
            // exist on SQLite, so the suite could never reach this write.
            'id'            => Uuid::uuid4()->toString(),
            'tenant_id'     => $tenantId,
            'execution_id'  => $executionId,
            'user_id'       => $userId,
            'rating'        => $rating,
            'feedback_text' => $feedback,
            'feedback_type' => $feedbackType,
            'metadata'      => json_encode([]),
            'created_date'  => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getFeedback(string $tenantId, string $executionId): array
    {
        return DB::table('hpbrain_ai_feedback')
            ->where('tenant_id', $tenantId)
            ->where('execution_id', $executionId)
            ->orderByDesc('created_date')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }
}
