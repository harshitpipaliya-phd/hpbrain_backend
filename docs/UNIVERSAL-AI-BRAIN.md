# UNIVERSAL AI BRAIN

## Overview

Prompt 3.3 implements the Universal AI Brain for HP Enterprise Brain, providing a comprehensive AI infrastructure that is provider-agnostic, tenant-safe, and governed by cost controls and safety filters.

## Architecture

### Domain Layer (`app/Domain/Ai/`)
- **Value Objects**: `AiRequest`, `AiResponse`, `ModelCapability`, `PromptDefinition`, `AssembledContext`, `RetrievalResult`, `GroundedResponse`, `CitationVerificationResult`, `SafetyFilterResult`, `QuotaResult`, `QuotaCheckResult`, `PromptInjectionResult`
- **Registries**: `ModelCapabilityRegistry`, `PromptRegistry`
- **Gateway**: `AiGateway` - single sanctioned entry point for all AI calls
- **Providers**: `AiProvider` interface + `AnthropicProvider`, `NullAiProvider`

### Service Layer (`app/Services/`)
- `ContextAssemblyService` - assembles context from multiple sources
- `RagService` - Retrieval Augmented Generation orchestration
- `RetrievalService` - multi-source retrieval (entities, documents, graph, memory)
- `RerankService` - document reranking by relevance
- `GroundingService` - grounding and citation verification
- `SafetyService` - PII redaction, prompt injection detection, content filtering
- `QuotaService` - rate limits and quota checking
- `AiQuotaEnforcer` - pre-call quota enforcement
- `TokenCostAccountingService` - token and cost tracking
- `AiCacheService` - tenant-aware AI caching
- `AiAuditService` - audit logging
- `AiFeedbackService` - feedback collection
- `EvaluationService` - evaluation datasets and runs
- `AiWorkspaceService` - AI workspace orchestration
- `AiProviderRegistry` - provider management

### Repository Layer (`app/Repositories/`)
- `AiExecutionRepository` - AI execution tracking
- `AiFeedbackRepository` - feedback storage
- `AiEvaluationRepository` - evaluation datasets
- `AiCacheRepository` - prompt template caching

### API Layer (`app/Http/Controllers/Api/`)
- `AiProviderController` - Provider CRUD + test + activate
- `AiPromptTemplateController` - Template CRUD + versions + render
- `AiEvaluationController` - Evaluation CRUD + run + results
- `AiFeedbackController` - Feedback CRUD
- `AiQuotaController` - Quota CRUD + reset
- `AiWorkspaceController` - Session management + messaging

### Frontend (`web/src/`)
- AI Workspace components for chat interface
- Admin components for provider/template/quota management
- API clients for all endpoints

## Key Principles

1. **NO AI call in a controller or React component** - all through `AiGateway`
2. **ALL AI executions recorded** in `hpbrain_ai_executions`
3. **Safety filtering BEFORE content reaches caller**
4. **PII minimization mandatory**
5. **Human approval for autonomous actions**
6. **Insufficient data returns UNKNOWN**
7. **Provider failures fall back to next in chain**
8. **Quotas checked BEFORE making the call**
