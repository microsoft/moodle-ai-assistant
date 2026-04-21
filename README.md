# Moodle AI Chat Plugin (local_aichat)

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Moodle](https://img.shields.io/badge/Moodle-4.2%2B-orange.svg)](https://moodle.org/)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![Azure OpenAI](https://img.shields.io/badge/Azure-OpenAI-0078D4.svg?logo=microsoftazure&logoColor=white)](https://azure.microsoft.com/en-us/products/ai-services/openai-service)
[![GitHub release](https://img.shields.io/github/v/release/microsoft/moodle-ai-assistant?include_prereleases&sort=semver)](https://github.com/microsoft/moodle-ai-assistant/releases)
[![Docs](https://img.shields.io/badge/docs-GitHub%20Pages-blue.svg)](https://microsoft.github.io/moodle-ai-assistant/)
[![Docker](https://img.shields.io/badge/Docker-ready-2496ED.svg?logo=docker&logoColor=white)](./Dockerfile)
[![Made with GitHub Copilot](https://img.shields.io/badge/Made%20with-GitHub%20Copilot-8957E5.svg?logo=githubcopilot&logoColor=white)](https://github.com/features/copilot)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](https://github.com/microsoft/moodle-ai-assistant/pulls)

> **An accelerator for deploying an AI Agent on Moodle.** This project provides a ready-to-use,
> production-oriented starting point for integrating an AI-powered course assistant into any
> Moodle instance, so teams can focus on customizing the agent behaviour instead of building
> the Moodle integration from scratch.

The plugin integrates a floating chatbot widget into course pages, powered by Azure OpenAI
and a full RAG (Retrieval-Augmented Generation) pipeline that indexes course content for
context-aware responses.

| | |
|---|---|
| **Version** | 0.1.0 (Alpha) |
| **Moodle** | 4.2+ required, tested on 4.5 |
| **PHP** | 8.1+ (Docker image uses 8.3) |
| **Database** | PostgreSQL (recommended), MySQL/MariaDB |
| **License** | MIT |
| **Component** | `local_aichat` |

---

## Features

### Core Chat
- Floating chatbot widget on all course and activity pages
- Thread-based conversations (one active thread per user per course)
- Real-time streaming responses via Server-Sent Events (SSE)
- Markdown rendering with syntax highlighting for code blocks
- Voice input via Web Speech API
- File upload support (images, documents)
- Export conversations to TXT or PDF
- Thumbs up / thumbs down feedback on responses
- Follow-up suggestion chips
- Privacy notice overlay for GDPR compliance

### RAG Pipeline
- Automatic extraction of content from 12+ activity types (Page, Book, Glossary, Forum, Quiz, Wiki, Assignment, Lesson, Label, Choice, URL, Resource) with generic fallback for other modules
- Embeddings generated via Azure OpenAI Embedding API
- In-database vector store with cosine similarity search
- Daily automatic re-indexing with content-hash change detection
- Current-page context always injected for relevance
- Configurable token budget and similarity thresholds

### Teacher Features
- Per-course analytics dashboard (unique users, messages/day chart, token usage, feedback stats)
- RAG index management with manual re-index trigger
- Per-course settings: enable/disable export, enable/disable uploads
- Anonymized conversation log viewer with date filtering and pagination

### Administrator Features
- Site-wide admin dashboard with token usage trends and deployment breakdowns
- Top-20 course ranking by token consumption
- CSV export of usage reports
- Global configuration: Azure credentials, rate limits, theming, security
- Custom bot avatar and color theming
- Circuit breaker and rate limiting configuration

### Security
- Server-side input sanitization with prompt injection detection
- Whitelist-based output HTML sanitization (server + client)
- Per-user burst and daily rate limiting
- Circuit breaker for Azure OpenAI service resilience
- GDPR-compliant data export and deletion
- Event logging for audit trails

---

## Quick Start

### Docker (Recommended for Development)

```bash
# Clone the repository
git clone <repository-url>
cd moodle-assistant

# Start services
docker compose up -d

# Access Moodle
open http://localhost:8080
```

Default credentials: `admin` / `Admin1234!`

### Manual Installation

1. Copy the `local/aichat` folder into your Moodle installation at `<moodle>/local/aichat`
2. Log in to Moodle as an administrator
3. Navigate to **Site administration → Notifications** to trigger the database installation
4. Configure the plugin at **Site administration → Plugins → Local plugins → AI Chat**

### Method 3: ZIP Upload (Production)

Build distributable ZIP packages and upload them directly via the Moodle UI:

```bash
# Windows (PowerShell)
.\build.ps1                      # Package both plugins
.\build.ps1 -Plugin aichat       # Package only local_aichat
.\build.ps1 -Plugin theme        # Package only theme_myuni

# Linux / macOS
./build.sh                       # Package both plugins
./build.sh aichat                # Package only local_aichat
./build.sh theme                 # Package only theme_myuni
```

The ZIP files are created in the `dist/` directory:

| File | Install as |
|---|---|
| `local_aichat-<version>.zip` | **Site admin → Plugins → Install plugins** (type: Local plugin) |
| `theme_myuni-<version>.zip` | **Site admin → Plugins → Install plugins** (type: Theme) |

### Configuration

1. Navigate to **Site administration → Plugins → Local plugins → AI Chat**
2. Fill in the Azure OpenAI connection settings:
   - **Endpoint** — your Azure OpenAI resource URL (e.g. `https://your-resource.openai.azure.com`)
   - **API Key** — your Azure OpenAI API key
   - **Chat Deployment** — the chat model deployment name (e.g. `gpt-4o`)
   - **Embedding Deployment** — the embedding model deployment name (e.g. `text-embedding-3-small`)
   - **API Version** — API version string (default: `2024-08-01-preview`)
3. Save changes and enable the plugin

---

## Building & Packaging

The project includes build scripts that create Moodle-ready ZIP files. The ZIPs contain the correct single top-level folder structure that Moodle's plugin installer expects.

```bash
# Windows
.\build.ps1

# Linux / macOS
chmod +x build.sh
./build.sh
```

Output goes to the `dist/` folder (git-ignored). Each ZIP is named with the plugin version from `version.php` (e.g., `local_aichat-0.1.0.zip`).

### Automated releases

Pushing a git tag matching `v*` (e.g. `v0.1.0`) triggers the [release workflow](.github/workflows/release.yml), which:

1. Runs `build.sh` in a clean Ubuntu runner.
2. Produces `local_aichat-<version>.zip` and `theme_myuni-<version>.zip`.
3. Creates (or updates) a **GitHub Release** on the tag and attaches both ZIPs as downloadable assets.

```bash
git tag v0.1.0
git push origin v0.1.0
```

The workflow can also be run manually from the Actions tab (`workflow_dispatch`) by providing a tag name.

---

## Documentation

The full documentation is published as a **GitHub Pages site** and is also browsable directly in the [docs/](docs/) folder.

👉 **[Open the online documentation](https://microsoft.github.io/moodle-ai-assistant/)** (after the Pages workflow has run for the first time).

| Document | Description |
|---|---|
| [User Guide](docs/USER_GUIDE.md) | End-user walkthrough of the chatbot (students and teachers) |
| [Admin Guide](docs/ADMIN_GUIDE.md) | Requirements, installation (Docker/manual/ZIP), all settings, monitoring dashboards, scheduled tasks, troubleshooting |
| [Developer Guide](docs/DEVELOPER_GUIDE.md) | Dev setup, code structure, how to add web services / RAG types / events / tasks, frontend architecture, testing |
| [Architecture](docs/ARCHITECTURE.md) | System design, component diagram, message flow sequence, RAG pipeline, database ERD, deployment topology |
| [API Reference](docs/API_REFERENCE.md) | All 7 web service functions, SSE streaming endpoint, capabilities, export endpoint, internal PHP API |
| [Security](docs/SECURITY.md) | Defense-in-depth architecture, input/output sanitization, rate limiting, circuit breaker, GDPR, threat model, checklists |

The site is built with [Jekyll](https://jekyllrb.com/) + [just-the-docs](https://just-the-docs.com/) and deployed automatically on every change under `docs/` by the [Pages workflow](.github/workflows/pages.yml). To preview locally:

```bash
cd docs
bundle install
bundle exec jekyll serve
# open http://localhost:4000
```

> **One-time repo setup**: enable Pages in **Settings → Pages → Build and deployment → Source: GitHub Actions**.

---

## Project Structure

```
moodle-assistant/
├── build.ps1                   # Build script (Windows/PowerShell)
├── build.sh                    # Build script (Linux/macOS)
├── docker-compose.yml          # Docker dev environment
├── Dockerfile                  # Moodle Docker image
├── docker-entrypoint.sh        # Container entrypoint
├── .gitignore                  # Git ignore rules
├── dist/                       # Built ZIP packages (git-ignored)
├── docs/                       # Full documentation
├── theme/myuni/                # Custom Moodle theme (generic, Boost-based)
├── scripts/                    # Utility scripts (course import/creation)
└── local/aichat/               # AI Chat plugin (see below)
```

### Plugin Structure (`local/aichat/`)

```
local/aichat/
├── version.php                 # Plugin version and requirements
├── lib.php                     # Moodle hooks (before_footer, navigation)
├── settings.php                # Admin settings page
├── dashboard.php               # Teacher analytics dashboard
├── admin_dashboard.php         # Site-wide admin dashboard
├── course_settings.php         # Per-course settings page
├── export.php                  # Chat export endpoint (TXT/PDF)
├── logs.php                    # Anonymized conversation log viewer
├── ajax.php                    # SSE streaming endpoint
├── styles.css                  # Plugin styles (CSS custom properties)
├── amd/
│   └── src/
│       ├── chatbot.js          # Main chatbot AMD module
│       └── sanitizer.js        # Client-side HTML sanitizer
├── classes/
│   ├── azure_openai_client.php # Azure OpenAI API client
│   ├── course_context_builder.php # Legacy context fallback
│   ├── history_summarizer.php  # Conversation summary manager
│   ├── event/                  # Moodle event classes
│   ├── external/               # Web service function definitions
│   ├── privacy/                # GDPR provider
│   ├── rag/                    # RAG pipeline classes
│   │   ├── content_extractor.php
│   │   ├── embedding_client.php
│   │   ├── vector_store.php
│   │   └── context_assembler.php
│   ├── security/               # Security components
│   │   ├── input_sanitizer.php
│   │   ├── output_sanitizer.php
│   │   ├── rate_limiter.php
│   │   └── circuit_breaker.php
│   └── task/                   # Scheduled tasks
│       ├── reindex_courses.php
│       └── cleanup_stale_threads.php
├── db/
│   ├── access.php              # Capability definitions
│   ├── install.xml             # Database schema (XMLDB)
│   ├── services.php            # Web service declarations
│   ├── tasks.php               # Scheduled task schedules
│   ├── upgrade.php             # Database upgrade steps
│   └── caches.php              # Cache definitions
├── lang/
│   ├── en/local_aichat.php     # English strings
│   └── it/local_aichat.php     # Italian strings
├── templates/
│   ├── chatbot.mustache        # Main chatbot template
│   └── message.mustache        # Message bubble template
└── pix/                        # Plugin icons
```

---

## License

This project is licensed under the [MIT License](LICENSE).

Copyright © 2026 Moodle AI Chat Contributors
