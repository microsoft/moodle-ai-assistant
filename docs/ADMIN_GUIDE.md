---
title: Admin Guide
layout: default
nav_order: 4
permalink: /admin-guide/
---

# Administrator Guide

Complete guide for installing, configuring, and maintaining the AI Chat plugin.

---

## Table of Contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Per-Course Settings](#per-course-settings)
5. [Monitoring & Analytics](#monitoring--analytics)
6. [Scheduled Tasks](#scheduled-tasks)
7. [Troubleshooting](#troubleshooting)
8. [Upgrading](#upgrading)
9. [Uninstalling](#uninstalling)

---

## Requirements

### System Requirements

| Component | Minimum | Recommended |
|---|---|---|
| Moodle | 4.2 | 4.5 |
| PHP | 8.1 | 8.3 |
| Database | PostgreSQL 13 / MySQL 8 | PostgreSQL 15 |
| Memory | 256MB | 512MB |

### Azure OpenAI Requirements

You need an Azure OpenAI resource with at least two model deployments:

| Deployment | Purpose | Recommended Model |
|---|---|---|
| Chat | Conversation responses | `gpt-4o` or `gpt-4o-mini` |
| Embeddings | Content indexing (RAG) | `text-embedding-3-small` |

**How to set up Azure OpenAI:**

1. Go to [Azure Portal](https://portal.azure.com)
2. Create an **Azure OpenAI** resource
3. Navigate to **Azure OpenAI Studio → Deployments**
4. Create a **Chat** deployment (e.g., name it `gpt-4o`)
5. Create an **Embeddings** deployment (e.g., name it `text-embedding-3-small`)
6. Copy the **Endpoint** and **API Key** from the resource's Keys and Endpoint page

---

## Installation

### Method 1: Docker (Development)

```bash
git clone <repository-url>
cd moodle-assistant
docker compose up -d
```

The plugin is mounted read-only at `/var/www/html/local/aichat`. Access Moodle at `http://localhost:8080` with credentials `admin / Admin1234!`.

### Method 2: Manual Installation

1. Download or clone the plugin source
2. Copy `local/aichat` to `<moodle-root>/local/aichat`
3. (Optional) Copy `theme/myuni` to `<moodle-root>/theme/myuni`
4. Visit `Site administration → Notifications` in Moodle to install the database tables
5. Proceed to configuration

### Method 3: ZIP Upload (Recommended for Production)

The project includes build scripts that produce Moodle-ready ZIP files:

```bash
# Windows (PowerShell)
.\build.ps1

# Linux / macOS
./build.sh
```

This creates two files in the `dist/` directory:

| File | Description |
|---|---|
| `local_aichat-<version>.zip` | AI Chat plugin |
| `theme_myuni-<version>.zip` | Custom theme |

To install:

1. Go to **Site administration → Plugins → Install plugins**
2. Upload `local_aichat-<version>.zip` and follow the prompts
3. (Optional) Upload `theme_myuni-<version>.zip` for the custom theme

You can also package only one component:

```bash
# Windows
.\build.ps1 -Plugin aichat
.\build.ps1 -Plugin theme

# Linux / macOS
./build.sh aichat
./build.sh theme
```

---

## Configuration

Navigate to **Site administration → Plugins → Local plugins → AI Chat**.

### General

| Setting | Description | Default |
|---|---|---|
| **Enable plugin** | Master switch for the chatbot | Disabled |

### Azure OpenAI Connection

| Setting | Description | Example |
|---|---|---|
| **Endpoint** | Azure OpenAI resource URL | `https://myresource.openai.azure.com` |
| **API Key** | Authentication key | *(password field)* |
| **Chat Deployment** | Chat model deployment name | `gpt-4o` |
| **Embedding Deployment** | Embedding model deployment name | `text-embedding-3-small` |
| **API Version** | Azure OpenAI API version | `2024-08-01-preview` |

### Model Configuration

| Setting | Description | Default |
|---|---|---|
| **Max Tokens** | Maximum tokens in AI response | 1024 |
| **Temperature** | Response randomness (0=deterministic, 1=creative) | 0.3 |
| **System Prompt** | Base prompt template. Supports `{coursename}` and `{lang}` placeholders | *(built-in)* |
| **History Window** | Number of recent raw messages to include (older ones are summarized) | 5 |
| **Enable Suggestions** | Show follow-up suggestion chips after responses | No |

### RAG Configuration

| Setting | Description | Default |
|---|---|---|
| **Token Budget** | Maximum tokens allocated for RAG context in the system prompt | 3000 |
| **Top K** | Number of most relevant chunks to retrieve | 5 |
| **Similarity Threshold** | Minimum cosine similarity score (0.0–1.0) to include a chunk | 0.7 |

### Usage Limits

| Setting | Description | Default |
|---|---|---|
| **Daily Limit** | Maximum messages per user per day (0 = unlimited) | 50 |
| **Burst Limit** | Maximum messages per user per minute | 5 |
| **Max Message Length** | Maximum characters per message | 2000 |

### Privacy & Compliance

| Setting | Description | Default |
|---|---|---|
| **Privacy Notice** | HTML text shown to users on first interaction | *(built-in)* |
| **Show Privacy Notice** | Display the privacy overlay on first use | Yes |

### Security

| Setting | Description | Default |
|---|---|---|
| **Enable Circuit Breaker** | Activate circuit breaker for Azure API | Yes |
| **Failure Threshold** | Consecutive failures before opening circuit | 3 |
| **Cooldown (minutes)** | Time before allowing retry after circuit opens | 5 |
| **Enable File Logging** | Write debug logs to file | No |
| **Log Level** | Minimum severity: DEBUG, INFO, WARN, ERROR | ERROR |

### Bot Appearance

| Setting | Description | Default |
|---|---|---|
| **Primary Color** | Main accent color (HEX) | `#4f46e5` |
| **Secondary Color** | Secondary accent color (HEX) | `#3730a3` |
| **Header Title** | Custom chatbot header text | *(plugin name)* |
| **Bot Avatar** | Custom avatar image (max 200KB, JPG/PNG/SVG) | *(default icon)* |

---

## Per-Course Settings

Teachers with the `local/aichat:manage` capability can configure per-course options at **Course → AI Chat Settings** (in the course navigation menu).

| Setting | Description | Default |
|---|---|---|
| **Enable Export** | Allow students to export their conversations | Disabled |
| **Enable Upload** | Allow students to upload files (images, documents) | Disabled |

### RAG Index Management

From the **Course Dashboard** (accessible to teachers), click **Rebuild Index** to trigger an immediate re-indexing of all course content. The dashboard shows:

- Number of indexed chunks
- Last index timestamp
- Total tokens stored

---

## Monitoring & Analytics

### Course Dashboard

**Path:** Course → AI Chat Dashboard
**Capability:** `local/aichat:viewdashboard`

Displays for the last 30 days:

- **Unique users** — distinct student count
- **Total messages** — user + assistant messages
- **Total tokens** — prompt + completion tokens
- **Feedback breakdown** — thumbs up vs. thumbs down count
- **Messages per day chart** — bar chart with daily counts
- **RAG index statistics** — chunk count, last indexed, total tokens

### Admin Dashboard

**Path:** Site administration → Plugins → Local plugins → AI Chat → Admin Dashboard
**Capability:** `local/aichat:viewadmindashboard`

Site-wide analytics:

- **All-time token usage** — total tokens consumed
- **Last 30 days tokens** — recent consumption
- **Total conversations & messages** — across all courses
- **Daily token trends** — prompt vs. completion breakdown
- **Tokens per deployment** — pie chart by model
- **Top 20 courses** — ranked by token consumption
- **CSV export** — download course-level statistics

### Conversation Logs

**Path:** Course → AI Chat Logs
**Capability:** `local/aichat:viewlogs`

- Date range filter (default: last 7 days)
- Pagination (20 conversations per page)
- User identities are **anonymized** (e.g., "User 1", "User 2")
- Shows all messages with timestamps

---

## Scheduled Tasks

### Reindex Courses

| Property | Value |
|---|---|
| **Class** | `\local_aichat\task\reindex_courses` |
| **Schedule** | Daily at 02:00 |
| **Behavior** | Re-indexes courses with embeddings older than 24h. Uses content hashing to skip unchanged content. |

### Cleanup Stale Threads

| Property | Value |
|---|---|
| **Class** | `\local_aichat\task\cleanup_stale_threads` |
| **Schedule** | Daily at 03:00 |
| **Behavior** | Deletes threads with no user messages older than 30 days. Cascades to messages, feedback, and token usage. |

### Managing Tasks

Navigate to **Site administration → Server → Scheduled tasks** to:

- View next run time
- Manually trigger a task ("Run now")
- Adjust the schedule (cron syntax)
- Disable/enable tasks

---

## Troubleshooting

### Chatbot Not Appearing

1. **Plugin not enabled** — Check `Site admin → Plugins → Local plugins → AI Chat → Enable plugin`
2. **Azure not configured** — Verify endpoint, API key, and deployment names are set
3. **User lacks capability** — Ensure the user's role has `local/aichat:use` in the course context
4. **Front page** — The chatbot does not appear on the Moodle front page, only inside courses
5. **JavaScript errors** — Check the browser console for AMD module loading errors

### Rate Limiting Issues

- **"Please wait before sending another message"** — Burst limit is active. Wait 1–2 minutes.
- **"You have reached your daily message limit"** — The user has exhausted their daily quota. Resets at midnight (server time).
- Adjust limits in `Site admin → Plugins → Local plugins → AI Chat`

### Azure OpenAI Errors

- **401 Unauthorized** — Check the API key
- **404 Not Found** — Verify the deployment name matches exactly
- **429 Too Many Requests** — Azure rate limit hit. Consider increasing the circuit breaker cooldown.
- **Circuit breaker open** — The plugin detected repeated Azure failures. Wait for the cooldown period, then the next request will probe Azure.

### RAG Index Issues

- **No relevant context** — Course content may not be indexed. Go to the course dashboard and click Rebuild Index.
- **Stale content** — Wait for the nightly reindex task, or trigger a manual rebuild.
- **Low similarity results** — Lower the similarity threshold (e.g., from 0.7 to 0.5) in settings.

### Logging

Enable file logging in settings:
1. Set **Enable File Logging** to Yes
2. Set **Log Level** to DEBUG for maximum detail
3. Logs are written to Moodle's standard log output
4. Check `Site administration → Reports → Logs` for event-level logs

---

## Upgrading

1. Replace the `local/aichat` folder with the new version
2. Visit `Site administration → Notifications`
3. Moodle will detect the version change and run `db/upgrade.php` automatically
4. Review the upgrade steps and confirm

**Docker:** Rebuild the container:
```bash
docker compose down
docker compose build --no-cache moodle
docker compose up -d
```

---

## Uninstalling

1. Navigate to `Site administration → Plugins → Plugins overview`
2. Find `AI Chat (local_aichat)` and click **Uninstall**
3. Confirm the removal

This will:
- Drop all `local_aichat_*` database tables
- Remove all plugin configuration
- Remove cached data
- **Not** remove the plugin files from disk (do this manually)

### Data Retention

Before uninstalling, consider:
- Exporting usage reports from the Admin Dashboard (CSV export)
- GDPR data export for affected users via `Site admin → Privacy → Data requests`
