# AI SAFETY

## Overview

AI Safety ensures all AI interactions are secure, compliant, and free from harmful content.

## SafetyService

### filter(string $content): SafetyFilterResult

Checks content for:
- Script injection (`<script>` tags)
- Prompt injection patterns
- Prohibited content

Returns:
- `safe`: boolean
- `flags`: array of detected issues
- `actions`: array of recommended actions (block/redact/flag)

### checkPermission(string $tenantId, string $userId, string $action): bool

Verifies user has permission for the requested AI action.

### redactPII(string $content): string

Redacts:
- Email addresses
- Phone numbers

Replaces with `[REDACTED]`.

### checkPromptInjection(string $prompt): PromptInjectionResult

Detects patterns like:
- "Ignore previous instructions"
- "Disregard all prior"
- "You are now"
- "New instructions"
- "Override your"

Returns:
- `detected`: boolean
- `patterns`: matched patterns
- `severity`: low/medium/high

## Safety Rules Database

Stored in `hpbrain_ai_safety_rules`:
- `rule_type`: pii/injection/prohibited_content/permission
- `pattern`: Regex pattern to match
- `action`: block/redact/flag
- `severity`: low/medium/high

## Mandatory Rules

1. Safety filtering happens BEFORE any content reaches the caller
2. PII minimization is mandatory
3. Human approval required for autonomous actions
4. Insufficient data returns UNKNOWN, never fabricated content
