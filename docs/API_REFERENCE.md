---
title: API Reference
layout: default
nav_order: 7
permalink: /api-reference/
---

# API Reference

This document covers all web service functions exposed by the AI Chat plugin, the SSE streaming endpoint, and the internal PHP API.

---

## Web Service Functions

All web service functions are AJAX-enabled and require an authenticated Moodle session. They are defined in `db/services.php` and implemented in `classes/external/`.

### `local_aichat_send_message`

Send a message to the AI assistant and receive a response.

| Property | Value |
|---|---|
| **Type** | Write |
| **Capability** | `local/aichat:use` |
| **AJAX** | Yes |

**Parameters:**

| Name | Type | Required | Description |
|---|---|---|---|
| `courseid` | int | Yes | The course ID |
| `message` | string | Yes | The user's message text |
| `sectionid` | int | No | Current section ID for context |
| `cmid` | int | No | Current course module ID for context |

**Response:**

```json
{
    "response": "The assistant's reply in markdown/HTML",
    "threadid": 42,
    "prompt_tokens": 1250,
    "completion_tokens": 380,
    "total_tokens": 1630
}
```

**Error Codes:**
- `emptyinput` — Message is empty after sanitization
- `burstwait` — Burst rate limit exceeded
- `dailylimitreached` — Daily message limit exceeded
- `assistantunavailable` — Circuit breaker open or Azure error

---

### `local_aichat_get_history`

Retrieve the message history for the current thread.

| Property | Value |
|---|---|
| **Type** | Read |
| **Capability** | `local/aichat:use` |
| **AJAX** | Yes |

**Parameters:**

| Name | Type | Required | Description |
|---|---|---|---|
| `courseid` | int | Yes | The course ID |

**Response:**

```json
{
    "messages": [
        {
            "id": 101,
            "role": "user",
            "message": "What is this course about?",
            "timecreated": 1711100000,
            "feedback": 0
        },
        {
            "id": 102,
            "role": "assistant",
            "message": "This course covers...",
            "timecreated": 1711100005,
            "feedback": 1
        }
    ]
}
```

The `feedback` field is:
- `1` — thumbs up
- `-1` — thumbs down
- `0` — no feedback given

---

### `local_aichat_new_thread`

Create a new conversation thread, replacing the previous one.

| Property | Value |
|---|---|
| **Type** | Write |
| **Capability** | `local/aichat:use` |
| **AJAX** | Yes |

**Parameters:**

| Name | Type | Required | Description |
|---|---|---|---|
| `courseid` | int | Yes | The course ID |

**Response:**

```json
{
    "threadid": 43,
    "success": true
}
```

**Behavior:** Deletes the previous thread and all its messages, feedback, and token usage records.

---

### `local_aichat_submit_feedback`

Submit thumbs up/down feedback on an assistant message.

| Property | Value |
|---|---|
| **Type** | Write |
| **Capability** | `local/aichat:use` |
| **AJAX** | Yes |

**Parameters:**

| Name | Type | Required | Description |
|---|---|---|---|
| `messageid` | int | Yes | The message ID |
| `feedback` | int | Yes | `1` (thumbs up) or `-1` (thumbs down) |

**Response:**

```json
{
    "success": true
}
```

**Behavior:** Upserts — if feedback already exists for this user+message, it is updated.

---

### `local_aichat_get_course_settings`

Get per-course settings (export/upload toggles).

| Property | Value |
|---|---|
| **Type** | Read |
| **Capability** | `local/aichat:use` |
| **AJAX** | Yes |

**Parameters:**

| Name | Type | Required | Description |
|---|---|---|---|
| `courseid` | int | Yes | The course ID |

**Response:**

```json
{
    "enable_export": false,
    "enable_upload": false
}
```

Returns defaults (`false`, `false`) if no per-course settings have been saved.

---

### `local_aichat_save_course_settings`

Save per-course settings.

| Property | Value |
|---|---|
| **Type** | Write |
| **Capability** | `local/aichat:manage` |
| **AJAX** | Yes |

**Parameters:**

| Name | Type | Required | Description |
|---|---|---|---|
| `courseid` | int | Yes | The course ID |
| `enable_export` | bool | Yes | Enable chat export for students |
| `enable_upload` | bool | Yes | Enable file upload for students |

**Response:**

```json
{
    "success": true
}
```

---

### `local_aichat_rebuild_index`

Trigger a full RAG re-index of a course's content.

| Property | Value |
|---|---|
| **Type** | Write |
| **Capability** | `local/aichat:manage` |
| **AJAX** | Yes |

**Parameters:**

| Name | Type | Required | Description |
|---|---|---|---|
| `courseid` | int | Yes | The course ID |

**Response:**

```json
{
    "success": true,
    "indexed": 45,
    "skipped": 12,
    "deleted": 3
}
```

| Field | Description |
|---|---|
| `indexed` | Number of new or updated chunks embedded |
| `skipped` | Number of unchanged chunks (same content hash) |
| `deleted` | Number of orphaned embeddings removed |

---

## SSE Streaming Endpoint

### `ajax.php` (Server-Sent Events)

Used for real-time streaming of AI responses to the browser.

**URL:** `/local/aichat/ajax.php`

**Method:** GET

**Query Parameters:**

| Name | Type | Required | Description |
|---|---|---|---|
| `courseid` | int | Yes | The course ID |
| `message` | string | Yes | URL-encoded user message |
| `sesskey` | string | Yes | Moodle session key |
| `sectionid` | int | No | Current section ID |
| `cmid` | int | No | Current course module ID |

**Response Headers:**

```
Content-Type: text/event-stream
Cache-Control: no-cache
X-Accel-Buffering: no
```

**Event Types:**

#### `token`
Streamed token chunks from the AI response.

```
event: token
data: {"token": "The course "}

event: token
data: {"token": "covers the following "}

event: token
data: {"token": "topics..."}
```

#### `done`
Signals the end of the response, includes metadata.

```
event: done
data: {"prompt_tokens": 1250, "completion_tokens": 380, "total_tokens": 1630, "suggestions": ["Tell me more about topic X", "Quiz me on this"]}
```

#### `error`
Signals an error occurred.

```
event: error
data: {"error": "dailylimitreached", "message": "You have reached your daily message limit."}
```

---

## Capabilities

| Capability | Context | Granted To | Description |
|---|---|---|---|
| `local/aichat:use` | Course | student, teacher, editingteacher, manager | Use the chatbot widget |
| `local/aichat:manage` | Course | editingteacher, manager | Manage course settings, rebuild index |
| `local/aichat:viewdashboard` | Course | teacher, editingteacher, manager | View course analytics dashboard |
| `local/aichat:viewadmindashboard` | System | manager | View site-wide admin dashboard |
| `local/aichat:viewlogs` | Course | editingteacher, manager | View anonymized conversation logs |

---

## Export Endpoint

### `export.php`

Export the current user's conversation for a course.

**URL:** `/local/aichat/export.php`

**Method:** GET

**Parameters:**

| Name | Type | Required | Description |
|---|---|---|---|
| `courseid` | int | Yes | The course ID |
| `format` | string | Yes | `txt` or `pdf` |
| `sesskey` | string | Yes | Moodle session key |

**Capability:** `local/aichat:use`

**Response:** File download with filename pattern `chat-export-{shortname}-{YYYY-MM-DD}.{ext}`

---

## Internal PHP Classes

### `azure_openai_client`

Main client for Azure OpenAI API calls.

```php
// Non-streaming completion (static method)
$result = \local_aichat\azure_openai_client::complete($messages);

// Streaming completion via SSE (static method)
\local_aichat\azure_openai_client::stream($messages, function(string $token) {
    echo "event: token\ndata: " . json_encode(['token' => $token]) . "\n\n";
    flush();
});
```

### `vector_store`

RAG vector store for course content indexing and retrieval.

```php
$vs = new \local_aichat\rag\vector_store();

// Index a course (returns stats)
$stats = $vs->index_course($courseid);

// Search for relevant chunks
$chunks = $vs->search($courseid, $query, $topk = 5, $threshold = 0.7);

// Get index statistics
$info = $vs->get_index_stats($courseid);
```

### `context_assembler`

Assembles the final RAG context for the system prompt.

```php
$ca = new \local_aichat\rag\context_assembler();

$context = $ca->build_context($courseid, $query, $sectionid, $cmid);
```

### `history_summarizer`

Manages conversation history with rolling summarization.

```php
$hs = new \local_aichat\history_summarizer();

// Get summarized history (older summary + recent raw messages)
$history = $hs->get_context_history($threadid);

// Update summary if recent messages exceeded window
$hs->update_summary_if_needed($threadid);
```

### `rate_limiter`

Per-user rate limiting with burst and daily windows. All methods are static.

```php
// Check burst limit — throws moodle_exception('burstwait') if exceeded
\local_aichat\security\rate_limiter::check_burst_limit($userid);

// Check daily limit — throws moodle_exception('dailylimitreached') if exceeded
// Returns: ['allowed' => bool, 'remaining' => int, 'reset_in' => int]
$status = \local_aichat\security\rate_limiter::check_daily_limit($userid);
```

### `circuit_breaker`

Circuit breaker pattern for Azure OpenAI resilience. All methods are static.

```php
\local_aichat\security\circuit_breaker::check();           // throws if circuit is open
\local_aichat\security\circuit_breaker::record_success();  // transition to CLOSED
\local_aichat\security\circuit_breaker::record_failure();   // increment failure count, may open circuit
```
