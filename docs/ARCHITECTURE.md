---
title: Architecture
layout: default
nav_order: 6
permalink: /architecture/
---

# Architecture

## Overview

The AI Chat plugin follows a layered architecture that integrates with Moodle's plugin API. It consists of a **frontend chatbot widget**, a **backend service layer** with RAG capabilities, and **Azure OpenAI** as the LLM provider.

```
┌─────────────────────────────────────────────────────────────────────┐
│                          Moodle Platform                            │
│                                                                     │
│  ┌──────────────┐    ┌──────────────────────────────────────────┐   │
│  │   Browser     │    │          local_aichat Plugin             │   │
│  │              │    │                                          │   │
│  │  ┌─────────┐ │    │  ┌────────┐  ┌──────────┐  ┌─────────┐ │   │
│  │  │Chatbot  │◄├────┤──┤  Web   │  │  RAG     │  │Security │ │   │
│  │  │AMD      │ │    │  │Services│  │ Pipeline │  │ Layer   │ │   │
│  │  │Module   │─┼────┼─►│        │  │          │  │         │ │   │
│  │  └─────────┘ │    │  └───┬────┘  └────┬─────┘  └────┬────┘ │   │
│  │  ┌─────────┐ │    │      │            │             │       │   │
│  │  │Sanitizer│ │    │  ┌───▼────────────▼─────────────▼───┐   │   │
│  │  │AMD      │ │    │  │        Azure OpenAI Client       │   │   │
│  │  └─────────┘ │    │  └──────────────┬───────────────────┘   │   │
│  └──────────────┘    └─────────────────┼───────────────────────┘   │
│                                        │                           │
│  ┌──────────────────────────────────┐  │                           │
│  │      Moodle Database (XMLDB)     │  │                           │
│  │  ┌────────┐ ┌────────┐ ┌──────┐ │  │                           │
│  │  │Threads │ │Messages│ │Embed.│ │  │                           │
│  │  │        │ │        │ │      │ │  │                           │
│  │  └────────┘ └────────┘ └──────┘ │  │                           │
│  └──────────────────────────────────┘  │                           │
└────────────────────────────────────────┼───────────────────────────┘
                                         │
                               ┌─────────▼──────────┐
                               │   Azure OpenAI     │
                               │  ┌───────────────┐ │
                               │  │  Chat API     │ │
                               │  │  (GPT-4o)     │ │
                               │  └───────────────┘ │
                               │  ┌───────────────┐ │
                               │  │ Embeddings API│ │
                               │  │(text-emb-3)   │ │
                               │  └───────────────┘ │
                               └────────────────────┘
```

---

## Component Diagram

```mermaid
graph TB
    subgraph "Frontend (Browser)"
        FAB[Floating Action Button]
        CP[Chat Panel]
        JS[chatbot.js AMD Module]
        SAN[sanitizer.js AMD Module]
        MT1[chatbot.mustache]
        MT2[message.mustache]
    end

    subgraph "Backend (Moodle PHP)"
        subgraph "Entry Points"
            AJAX[ajax.php<br/>SSE Streaming]
            WS[Web Services<br/>AJAX API]
            HOOK[lib.php<br/>before_footer hook]
        end

        subgraph "External API"
            SM[send_message]
            GH[get_history]
            NT[new_thread]
            SF[submit_feedback]
            GCS[get_course_settings]
            SCS[save_course_settings]
            RI[rebuild_index]
        end

        subgraph "Core Services"
            AOC[AzureOpenAIClient]
            HS[HistorySummarizer]
            CCB[CourseContextBuilder]
        end

        subgraph "RAG Pipeline"
            CE[ContentExtractor]
            EC[EmbeddingClient]
            VS[VectorStore]
            CA[ContextAssembler]
        end

        subgraph "Security"
            IS[InputSanitizer]
            OS_[OutputSanitizer]
            RL[RateLimiter]
            CB[CircuitBreaker]
        end

        subgraph "Scheduled Tasks"
            RIC[reindex_courses]
            CST[cleanup_stale_threads]
        end
    end

    subgraph "External"
        AZURE_CHAT[Azure OpenAI<br/>Chat Completions]
        AZURE_EMB[Azure OpenAI<br/>Embeddings]
    end

    subgraph "Database"
        T_THREADS[(threads)]
        T_MESSAGES[(messages)]
        T_FEEDBACK[(feedback)]
        T_EMBED[(embeddings)]
        T_TOKENS[(token_usage)]
        T_SETTINGS[(course_settings)]
    end

    FAB --> CP
    CP --> JS
    JS --> SAN
    JS --> MT1
    JS --> MT2
    JS -->|SSE| AJAX
    JS -->|AJAX| WS

    HOOK --> JS

    WS --> SM & GH & NT & SF & GCS & SCS & RI

    AJAX --> IS --> RL --> CB --> CA --> AOC
    SM --> IS --> RL --> CB --> CA --> AOC

    AOC --> AZURE_CHAT
    AOC --> HS

    CA --> VS
    CA --> CE
    CA --> CCB
    VS --> EC --> AZURE_EMB

    RI --> VS

    SM --> T_THREADS & T_MESSAGES & T_TOKENS
    GH --> T_MESSAGES & T_FEEDBACK
    NT --> T_THREADS
    SF --> T_FEEDBACK
    VS --> T_EMBED
    GCS --> T_SETTINGS
    SCS --> T_SETTINGS

    RIC --> VS
    CST --> T_THREADS
```

---

## Message Flow

The following sequence diagram shows what happens when a user sends a message:

```mermaid
sequenceDiagram
    actor User
    participant Browser as Chatbot (JS)
    participant AJAX as ajax.php (SSE)
    participant IS as InputSanitizer
    participant RL as RateLimiter
    participant CB as CircuitBreaker
    participant CA as ContextAssembler
    participant VS as VectorStore
    participant EC as EmbeddingClient
    participant HS as HistorySummarizer
    participant AOC as AzureOpenAIClient
    participant Azure as Azure OpenAI

    User->>Browser: Types message & clicks Send
    Browser->>AJAX: GET /ajax.php?courseid=...&message=...
    Note over AJAX: Content-Type: text/event-stream

    AJAX->>IS: sanitize_message(message)
    IS-->>AJAX: cleaned message

    AJAX->>RL: check_burst(userid)
    alt Burst limit exceeded
        RL-->>AJAX: blocked
        AJAX-->>Browser: SSE error event
        Browser-->>User: "Please wait" message
    end

    AJAX->>RL: check_daily(userid)
    alt Daily limit exceeded
        RL-->>AJAX: blocked
        AJAX-->>Browser: SSE error event
        Browser-->>User: "Daily limit reached"
    end

    AJAX->>CB: can_proceed()
    alt Circuit open
        CB-->>AJAX: blocked
        AJAX-->>Browser: SSE error event
        Browser-->>User: "Service temporarily unavailable"
    end

    AJAX->>CA: build_context(courseid, query, sectionid, cmid)
    CA->>VS: search(courseid, query, topk=5, threshold=0.7)
    VS->>EC: embed(query)
    EC->>Azure: POST /embeddings
    Azure-->>EC: embedding vector
    EC-->>VS: vector
    VS-->>CA: top-K chunks (cosine similarity)
    CA-->>AJAX: assembled context string

    AJAX->>HS: get_context_history(threadid)
    HS-->>AJAX: summary + recent messages

    AJAX->>AOC: stream(messages, callback)
    AOC->>Azure: POST /chat/completions (stream=true)

    loop SSE chunks
        Azure-->>AOC: chunk
        AOC-->>AJAX: chunk
        AJAX-->>Browser: SSE token event
        Browser-->>User: Render text incrementally
    end

    Azure-->>AOC: [DONE]
    AOC-->>AJAX: completion finished

    AJAX->>AJAX: Save message + token usage to DB
    AJAX->>HS: update_summary_if_needed(threadid)
    AJAX-->>Browser: SSE done event

    Browser->>Browser: Parse markdown, sanitize HTML
    Browser-->>User: Final rendered response
```

---

## RAG Pipeline Flow

```mermaid
flowchart TB
    subgraph "Indexing (Scheduled / On-Demand)"
        A[Course Content] --> B[ContentExtractor]
        B --> C{Module Type}
        C -->|Page| D1[Extract HTML content]
        C -->|Book| D2[Extract chapters]
        C -->|Glossary| D3[Extract entries]
        C -->|Forum| D4[Extract discussions]
        C -->|Quiz| D5[Extract questions]
        C -->|Assignment| D6[Extract description]
        C -->|Wiki| D7[Extract pages]
        C -->|Label| D8[Extract content]
        C -->|Lesson| D9[Extract pages]
        C -->|Choice| D10[Extract options]

        D1 & D2 & D3 & D4 & D5 & D6 & D7 & D8 & D9 & D10 --> E[Text Chunks<br/>~1500 tokens each]
        E --> F[SHA-256 Hash]
        F --> G{Hash Changed?}
        G -->|No| H[Skip]
        G -->|Yes| I[EmbeddingClient]
        I --> J[Azure OpenAI<br/>Embeddings API]
        J --> K[Vector]
        K --> L[(embeddings table)]
    end

    subgraph "Retrieval (Per Query)"
        Q[User Query] --> R[EmbeddingClient]
        R --> S[Azure OpenAI<br/>Embeddings API]
        S --> T[Query Vector]
        T --> U[VectorStore.search]
        L --> U
        U --> V[Cosine Similarity<br/>Ranking]
        V --> W[Top-K Chunks<br/>above threshold]
    end

    subgraph "Context Assembly"
        W --> X[ContextAssembler]
        CP2[Current Page<br/>Live Extraction] --> X
        CM[Course Metadata<br/>Name, Dates] --> X
        X --> Y[Token Budget<br/>Enforcement]
        Y --> Z[System Prompt<br/>Context Block]
    end
```

---

## Database Schema (ERD)

```mermaid
erDiagram
    local_aichat_threads {
        bigint id PK
        bigint userid FK
        bigint courseid FK
        varchar title
        text summary
        bigint timecreated
        bigint timemodified
    }

    local_aichat_messages {
        bigint id PK
        bigint threadid FK
        varchar role "user | assistant"
        text message
        bigint timecreated
    }

    local_aichat_feedback {
        bigint id PK
        bigint messageid FK
        bigint userid FK
        int feedback "1 or -1"
        bigint timecreated
    }

    local_aichat_token_usage {
        bigint id PK
        bigint messageid FK
        varchar deployment
        int prompt_tokens
        int completion_tokens
        int total_tokens
        bigint timecreated
    }

    local_aichat_embeddings {
        bigint id PK
        bigint courseid FK
        varchar chunk_type
        varchar chunk_id
        varchar chunk_title
        text content_text
        varchar content_hash
        text embedding "JSON float array"
        int token_count
        bigint timecreated
        bigint timemodified
    }

    local_aichat_course_settings {
        bigint id PK
        bigint courseid FK
        int enable_export
        int enable_upload
        bigint timecreated
        bigint timemodified
    }

    local_aichat_threads ||--o{ local_aichat_messages : "has many"
    local_aichat_messages ||--o| local_aichat_feedback : "has optional"
    local_aichat_messages ||--o| local_aichat_token_usage : "tracks usage"
```

---

## Security Architecture

```mermaid
flowchart LR
    subgraph "Client Side"
        INPUT[User Input]
        DOM_SAN[DOM Sanitizer<br/>sanitizer.js]
    end

    subgraph "Server Side - Inbound"
        IN_SAN[InputSanitizer<br/>• Strip control chars<br/>• Validate length<br/>• Detect injection]
        RATE[RateLimiter<br/>• Burst: per minute<br/>• Daily: per day]
        CIRCUIT[CircuitBreaker<br/>• Failure threshold<br/>• Cooldown period]
    end

    subgraph "Server Side - Outbound"
        OUT_SAN[OutputSanitizer<br/>• HTML whitelist<br/>• Strip event handlers<br/>• Sanitize URIs]
    end

    subgraph "Moodle Security"
        SESSKEY[sesskey validation]
        CAP[Capability checks]
        CONTEXT[Context validation]
    end

    INPUT --> IN_SAN --> RATE --> CIRCUIT --> AZURE_API
    AZURE_API[Azure OpenAI] --> OUT_SAN --> DOM_SAN --> DISPLAY[Rendered Output]
    INPUT --> SESSKEY --> CAP --> CONTEXT
```

---

## Deployment Architecture (Docker)

```mermaid
graph TB
    subgraph "Docker Compose"
        subgraph "moodle container"
            APACHE[Apache 2.4]
            PHP[PHP 8.3]
            MOODLE[Moodle 4.5]
            PLUGIN[local_aichat<br/>bind mount :ro]
        end

        subgraph "db container"
            PG[PostgreSQL 13]
        end

        subgraph "Volumes"
            V1[moodlefiles]
            V2[moodledata]
            V3[pgdata]
        end
    end

    CLIENT[Browser :8080] --> APACHE
    APACHE --> PHP --> MOODLE
    MOODLE --> PLUGIN
    MOODLE --> PG
    MOODLE --> V1
    MOODLE --> V2
    PG --> V3
    MOODLE -.->|HTTPS| AZURE[Azure OpenAI API]
```

---

## Event System

```mermaid
flowchart LR
    subgraph "Triggers"
        T1[User sends message]
        T2[New thread created]
        T3[Chat exported]
        T4[Feedback given]
    end

    subgraph "Events"
        E1[chat_message_sent]
        E2[chat_thread_created]
        E3[chat_exported]
        E4[chat_feedback_given]
    end

    subgraph "Consumers"
        LOG[Moodle Logs]
        REPORT[Reports API]
        PRIVACY[Privacy API]
    end

    T1 --> E1 --> LOG & REPORT
    T2 --> E2 --> LOG & REPORT
    T3 --> E3 --> LOG & REPORT
    T4 --> E4 --> LOG & REPORT
    LOG --> PRIVACY
```

---

## Cache Architecture

| Cache Store | TTL | Purpose |
|---|---|---|
| `burst_rate` | 120s | Tracks per-user message count within burst window |
| `circuit_breaker` | 600s | Stores circuit state (CLOSED/OPEN/HALF_OPEN) |

Both use the **application** cache store for cross-request persistence.

---

## Scheduled Tasks

| Task | Schedule | Purpose |
|---|---|---|
| `reindex_courses` | Daily at 02:00 | Re-embed course content older than 24h |
| `cleanup_stale_threads` | Daily at 03:00 | Delete threads with no user messages older than 30 days |
