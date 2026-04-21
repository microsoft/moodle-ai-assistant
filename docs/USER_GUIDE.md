---
title: User Guide
layout: default
nav_order: 3
permalink: /user-guide/
---

# User Guide
{: .no_toc }

A practical walkthrough of the Moodle AI Chat assistant for students and teachers.
{: .fs-6 .fw-300 }

1. TOC
{: toc }

---

## 1. What is the course assistant?

Once installed and enabled, a **floating chat button** appears in the bottom-right corner of every course page and activity page. Click it to open a conversation with the AI assistant. The assistant is grounded in the content of the **current course** (pages, books, glossaries, forums, quizzes, wiki, assignments, lessons, labels, choices, URLs and resources) so its answers are tailored to your material.

![Chatbot widget](../local/aichat/pix/agent.svg){: width="120" }

## 2. Opening the chatbot

1. Log in to your Moodle instance.
2. Navigate to any page of a course where the plugin has been enabled.
3. Click the **chat bubble** in the bottom-right corner.
4. The first time you open the chatbot, a **privacy notice** is displayed. Read and accept it to continue.

## 3. Sending a message

- Type your question in the input box and press <kbd>Enter</kbd> (or click the send button).
- Responses stream in real-time — you'll see the text appear word by word.
- Code blocks are syntax-highlighted and can be copied with a single click.
- Use <kbd>Shift</kbd> + <kbd>Enter</kbd> to insert a newline without sending.

{: .note }
> The assistant automatically uses the **current page context** as part of its answer. Asking "explain this" while viewing a specific activity gives more relevant answers than asking from the course home.

## 4. Voice input

If your browser supports the Web Speech API (Chrome / Edge / Safari), you'll see a **microphone button** next to the input. Click it, grant microphone permission once, and speak your question. The transcript appears in the input box — edit if needed and send.

## 5. File uploads

If your teacher has enabled uploads for the course, a **paperclip button** is shown. You can attach:

- Images (`.png`, `.jpg`, `.webp`) — the assistant can describe or reason about them.
- Documents (`.pdf`, `.txt`, `.md`) — text content is extracted and included in the context.

File size and count limits are enforced by the server.

## 6. Follow-up suggestions

After each answer the assistant may display **follow-up chips**: click one to send a suggested follow-up question. This is useful to explore a topic without typing.

## 7. Giving feedback

Each assistant reply has a **👍 / 👎** button. Your feedback helps teachers and admins improve the system. Optionally you can add a short free-text comment when you give negative feedback.

## 8. Exporting a conversation

If export is enabled for the course, use the **Export** button in the chatbot header to download the current thread:

- **TXT** — plain text, ideal for quick reference.
- **PDF** — formatted document with course and date metadata.

## 9. Threads

Each user has **one active thread per course**. Use the **New thread** button to archive the current conversation and start a fresh one. The assistant will no longer remember older messages, which is useful when switching topic.

## 10. For teachers — per-course settings

Teachers (users with the `local/aichat:manage_course_settings` capability) see an **AI Chat settings** link in the course administration block. From there you can:

- Enable/disable export.
- Enable/disable file uploads.
- **Rebuild the RAG index** manually after major content changes.
- Open the **course dashboard** with analytics (unique users, messages per day, token usage, feedback stats).
- View an **anonymized log** of conversations.

## 11. Troubleshooting

| Problem | Try |
|---|---|
| Chat button not visible | Confirm the plugin is enabled site-wide and the course has `local/aichat:usechatbot` for your role. |
| "Service temporarily unavailable" | The circuit breaker has opened after repeated Azure errors — retry in a couple of minutes. |
| Rate-limit message | You've exceeded the per-user burst or daily quota. Wait and retry, or ask your admin to adjust limits. |
| Answers ignore my course content | Ask your teacher to trigger a **Rebuild index** after uploading new material. |
| Voice button missing | Use a Chromium-based browser or Safari; voice input relies on the browser's Web Speech API. |

For deeper issues, see the [Admin Guide]({{ '/admin-guide/' | relative_url }}#troubleshooting) or contact your Moodle administrator.
