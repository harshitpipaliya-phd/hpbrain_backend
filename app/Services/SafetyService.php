<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Ai\AiResponse;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AssembledContext;
use App\Domain\Ai\SafetyFilterResult;
use App\Domain\Ai\PromptInjectionResult;
use App\Domain\Ai\QuotaCheckResult;
use Illuminate\Support\Facades\DB;

final class SafetyService
{
    public function filter(string $content): SafetyFilterResult
    {
        $flags = [];
        $actions = [];

        if (preg_match('/<script.*?>.*?<\/script>/is', $content)) {
            $flags[] = 'script_injection';
            $actions[] = 'block';
        }

        if (preg_match('/ignore\s+(all\s+)?previous\s+instructions?/i', $content)) {
            $flags[] = 'prompt_injection';
            $actions[] = 'block';
        }

        return new SafetyFilterResult(
            safe: $flags === [],
            content: $content,
            flags: $flags,
            actions: $actions,
        );
    }

    public function checkPermission(string $tenantId, string $userId, string $action): bool
    {
        $permission = DB::table('hpbrain_auth_users')
            ->where('tenant_id', $tenantId)
            ->where('id', $userId)
            ->value('role');

        return in_array($permission, ['admin', 'manager'], true);
    }

    public function redactPII(string $content): string
    {
        return preg_replace(
            ['/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/', '/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/'],
            '[REDACTED]',
            $content
        ) ?? $content;
    }

    /**
     * Flag prompts that try to restate the model's instructions.
     *
     * MATCHED AS PATTERNS, NOT AS LITERAL SUBSTRINGS. The previous version used
     * str_contains() against fixed phrases, so "ignore previous instructions"
     * was caught but "ignore ALL previous instructions" — one inserted word —
     * was not. Any filler between the verb and its object defeated the whole
     * check, which is the first thing an attacker tries and the first thing a
     * model paraphrasing an attack does by accident.
     *
     * Each entry keeps a stable canonical name so callers, logs and dashboards
     * can group occurrences without depending on the regex that found them.
     *
     * This is a cheap lexical screen and nothing more: it raises the cost of
     * the obvious attempts. It is not a substitute for the structural defences
     * — untrusted content stays in a separate role, retrieved text is never
     * concatenated into the system prompt, and tool calls are permission- and
     * tenant-checked independently of anything a prompt says.
     */
    public function checkPromptInjection(string $prompt): PromptInjectionResult
    {
        // Canonical name => regex. \W+ spans the filler words ("all", "any",
        // "the", punctuation) that a literal match could not tolerate.
        $patterns = [
            'ignore previous instructions' => '/\b(ignore|disregard|forget)\b.{0,20}?\b(previous|prior|earlier|above|preceding)\b.{0,20}?\b(instruction|prompt|rule|direction)/i',
            'disregard all prior'          => '/\b(disregard|discard)\b.{0,20}?\b(all|any)\b.{0,20}?\bprior\b/i',
            'you are now'                  => '/\byou\s+are\s+now\b/i',
            'new instructions'             => '/\b(new|updated|revised)\s+(instruction|rule|prompt)s?\b/i',
            'override your'                => '/\boverride\b.{0,20}?\byour\b/i',
            'reveal system prompt'         => '/\b(reveal|show|print|repeat|output)\b.{0,30}?\b(system\s+prompt|initial\s+instruction|your\s+instruction)/i',
        ];

        $found = [];

        foreach ($patterns as $name => $regex) {
            if (preg_match($regex, $prompt) === 1) {
                $found[] = $name;
            }
        }

        return new PromptInjectionResult(
            detected: $found !== [],
            patterns: $found,
            severity: $found !== [] ? 'high' : 'low',
        );
    }
}
