<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * The model seam (ADR-004).
 *
 * ONE METHOD, ON PURPOSE. Every capability this system needs from a language
 * model is "given these instructions and this grounding, return this JSON".
 * Streaming, tool use, embeddings and async are absent because nothing in v1
 * needs them, and an interface that anticipates needs has to be implemented by
 * every driver that follows.
 *
 * THE RULE THIS INTERFACE EXISTS TO ENFORCE: no vendor type may appear outside
 * app/Domain/Ai/Providers/. Business logic imports AiRequest and AiResponse and
 * nothing else. If a controller, verb or service ever imports an SDK
 * namespace, ADR-004 has been broken and the model is no longer swappable —
 * which matters because the provider WILL change, and the day it does must be
 * a one-file change rather than a search across the codebase.
 *
 * Implementations THROW on transport or protocol failure. They must not return
 * an AiResponse carrying an apology: AiGateway distinguishes "the model said
 * something" from "the call failed", and it can only do that if failure is an
 * exception.
 */
interface AiProvider
{
    public function complete(AiRequest $request): AiResponse;
}
