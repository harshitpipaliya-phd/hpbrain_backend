# RAG ARCHITECTURE

## Overview

Retrieval Augmented Generation (RAG) provides the AI with grounded, tenant-specific context before generating responses.

## Flow

```
User Query
  → Intent Classification
  → Auth/Permission Check
  → Tenant Context
  → Query Normalization
  → Multi-Source Retrieval
  → Permission Filtering
  → Reranking
  → Context Packaging
  → Prompt Rendering
  → Model Request
  → Structured Validation
  → Citation Verification
  → Safety Filtering
  → Cost/Audit Record
  → Feedback Collection
```

## Components

### RetrievalService

Searches multiple sources:
- `searchEntities()` - Brain entities (signals, evidence, decisions, capabilities)
- `searchDocuments()` - Document store
- `searchGraph()` - Knowledge graph
- `searchMemory()` - Conversation memory

### RerankService

Reranks retrieved documents by relevance using embedding similarity or LLM-based reranking.

### ContextAssemblyService

Assembles context from retrieved documents into:
- `systemPrompt` - Instructions for the model
- `userPrompt` - Actual query with context
- `citations` - List of source citations
- `groundingEvidence` - Evidence backing the response

### GroundingService

- `ground()` - Attaches evidence to response
- `verifyCitations()` - Ensures citations are from supplied evidence
- `attachEvidence()` - Appends source IDs to response

## Tenant Isolation

Every retrieval operation is scoped by `tenant_id`. The `permissionFilter()` ensures only documents the user has access to are included.

## Citation Verification

Every citation must be from the supplied evidence set. Fabricated citations are dropped, not down-weighted.
