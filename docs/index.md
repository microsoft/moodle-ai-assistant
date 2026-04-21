---
title: Home
layout: default
nav_order: 1
description: "Moodle AI Chat — an accelerator for deploying an AI Agent on Moodle."
permalink: /
---

# Moodle AI Chat
{: .fs-9 }

An accelerator for deploying an **AI Agent on Moodle**, powered by Azure OpenAI and a full RAG (Retrieval-Augmented Generation) pipeline over course content.
{: .fs-6 .fw-300 }

[Get started]({{ '/admin-guide/' | relative_url }}){: .btn .btn-primary .fs-5 .mb-4 .mb-md-0 .mr-2 }
[View on GitHub](https://github.com/microsoft/moodle-ai-assistant){: .btn .fs-5 .mb-4 .mb-md-0 }
[Download latest release](https://github.com/microsoft/moodle-ai-assistant/releases/latest){: .btn .fs-5 .mb-4 .mb-md-0 }

---

## What is it?

Moodle AI Chat is a production-oriented **starting point** for integrating an AI-powered course assistant into any Moodle instance. Instead of wiring up the Moodle integration from scratch, teams can focus on customizing the agent's behavior, knowledge, and UX.

It ships as two Moodle plugins:

- **`local_aichat`** — core plugin with the chatbot widget, Azure OpenAI client, RAG pipeline, dashboards, privacy provider and web services.
- **`theme_myuni`** — lightweight Boost-based theme you can re-brand to match your institution.

## Highlights

- 💬 **Floating chatbot** on every course page with streaming responses (SSE), Markdown rendering, voice input and file upload.
- 🧠 **RAG pipeline** over 12+ activity types (Page, Book, Glossary, Forum, Quiz, Wiki, Assignment, Lesson, Label, Choice, URL, Resource) with embedding-based retrieval and daily re-index.
- 📊 **Teacher & admin dashboards** with per-course analytics, token usage, feedback and a conversation log viewer.
- 🔐 **Security-first**: input sanitization with prompt-injection detection, output HTML allowlist, rate limiting, circuit breaker, GDPR provider.
- 🐳 **Docker-ready** local environment and build scripts that produce Moodle-installable ZIPs.

## Where to next?

| Guide | For whom |
|---|---|
| [Features & Configuration]({{ '/features/' | relative_url }}) | Complete list of all features and every configuration setting |
| [Admin Guide]({{ '/admin-guide/' | relative_url }}) | Install, configure and monitor the plugin |
| [User Guide]({{ '/user-guide/' | relative_url }}) | End-user walkthrough of the chatbot |
| [Developer Guide]({{ '/developer-guide/' | relative_url }}) | Extend the plugin, add RAG types / services / tasks |
| [Architecture]({{ '/architecture/' | relative_url }}) | Component & data-flow diagrams |
| [API Reference]({{ '/api-reference/' | relative_url }}) | Web service endpoints and payloads |
| [Security]({{ '/security/' | relative_url }}) | Threat model and defense-in-depth overview |

---

## License

Released under the [MIT License](https://github.com/microsoft/moodle-ai-assistant/blob/main/LICENSE).
