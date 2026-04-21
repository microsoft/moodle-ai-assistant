---
title: Developer Guide
layout: default
nav_order: 5
permalink: /developer-guide/
---

# Developer Guide

This guide covers the plugin architecture, code conventions, development setup, and how to extend the plugin.

---

## Table of Contents

1. [Development Setup](#development-setup)
2. [Code Structure](#code-structure)
3. [Moodle Plugin Conventions](#moodle-plugin-conventions)
4. [Adding a New Web Service](#adding-a-new-web-service)
5. [Adding a New Activity Type to RAG](#adding-a-new-activity-type-to-rag)
6. [Adding a New Event](#adding-a-new-event)
7. [Adding a New Scheduled Task](#adding-a-new-scheduled-task)
8. [Frontend Development](#frontend-development)
9. [Database Changes](#database-changes)
10. [Testing](#testing)
11. [Coding Standards](#coding-standards)

---

## Development Setup

### Docker (Recommended)

```bash
# Clone the repository
git clone <repository-url>
cd moodle-assistant

# Start services
docker compose up -d

# Access Moodle at http://localhost:8080
# Admin: admin / Admin1234!
```

### Building Distributable ZIPs

To create Moodle-ready ZIP packages for installation:

```bash
# Windows (PowerShell)
.\build.ps1                      # Both plugins
.\build.ps1 -Plugin aichat       # Only local_aichat
.\build.ps1 -Plugin theme        # Only theme_myuni

# Linux / macOS
./build.sh                       # Both plugins
./build.sh aichat                # Only local_aichat
./build.sh theme                 # Only theme_myuni
```

Output goes to `dist/` (git-ignored). The ZIPs contain the correct top-level folder
structure (`aichat/` or `myuni/`) required by Moodle's plugin installer.
Version numbers are read from each plugin's `version.php`.

The plugin folder is bind-mounted read-only. To work on the plugin:

```bash
# The plugin source is at local/aichat (your local filesystem)
# Changes to PHP files take effect immediately (no rebuild needed)
# Changes to AMD JS/CSS require Moodle cache purge
```

### Purging Caches

After modifying templates, JavaScript, or CSS:

```
Site administration → Development → Purge all caches
```

Or via CLI inside the Docker container:

```bash
docker compose exec moodle php admin/cli/purge_caches.php
```

### Building AMD Modules

Moodle uses AMD (Asynchronous Module Definition) for JavaScript. The source files are in `amd/src/` and the minified builds go to `amd/build/`.

```bash
# Install Moodle's Node.js tools (requires Node.js 18+)
cd <moodle-root>
npm install

# Build all AMD modules
npx grunt amd

# Build just the plugin modules
npx grunt amd --root=local/aichat
```

---

## Code Structure

```
local/aichat/
│
├── version.php                     # Plugin metadata (version, requires, maturity)
├── lib.php                         # Moodle hook implementations
├── settings.php                    # Admin settings page (settings tree)
│
├── classes/                        # PSR-4 autoloaded classes
│   ├── azure_openai_client.php     # \local_aichat\azure_openai_client
│   ├── course_context_builder.php  # \local_aichat\course_context_builder
│   ├── history_summarizer.php      # \local_aichat\history_summarizer
│   │
│   ├── external/                   # Web service function implementations
│   │   ├── send_message.php        # \local_aichat\external\send_message
│   │   ├── get_history.php         # \local_aichat\external\get_history
│   │   ├── new_thread.php          # \local_aichat\external\new_thread
│   │   ├── submit_feedback.php     # \local_aichat\external\submit_feedback
│   │   ├── get_course_settings.php # \local_aichat\external\get_course_settings
│   │   ├── save_course_settings.php# \local_aichat\external\save_course_settings
│   │   └── rebuild_index.php       # \local_aichat\external\rebuild_index
│   │
│   ├── rag/                        # RAG pipeline
│   │   ├── content_extractor.php   # \local_aichat\rag\content_extractor
│   │   ├── embedding_client.php    # \local_aichat\rag\embedding_client
│   │   ├── vector_store.php        # \local_aichat\rag\vector_store
│   │   └── context_assembler.php   # \local_aichat\rag\context_assembler
│   │
│   ├── security/                   # Security layer
│   │   ├── input_sanitizer.php     # \local_aichat\security\input_sanitizer
│   │   ├── output_sanitizer.php    # \local_aichat\security\output_sanitizer
│   │   ├── rate_limiter.php        # \local_aichat\security\rate_limiter
│   │   └── circuit_breaker.php     # \local_aichat\security\circuit_breaker
│   │
│   ├── task/                       # Scheduled tasks
│   │   ├── reindex_courses.php     # \local_aichat\task\reindex_courses
│   │   └── cleanup_stale_threads.php # \local_aichat\task\cleanup_stale_threads
│   │
│   ├── event/                      # Moodle events
│   │   ├── chat_message_sent.php
│   │   ├── chat_thread_created.php
│   │   ├── chat_exported.php
│   │   └── chat_feedback_given.php
│   │
│   └── privacy/                    # GDPR compliance
│       └── provider.php
│
├── db/                             # Database definitions
│   ├── install.xml                 # XMLDB schema
│   ├── access.php                  # Capabilities
│   ├── services.php                # Web service declarations
│   ├── tasks.php                   # Task schedules
│   ├── upgrade.php                 # Schema migration steps
│   └── caches.php                  # Cache definitions
│
├── amd/                            # JavaScript AMD modules
│   ├── src/
│   │   ├── chatbot.js              # Main chatbot logic
│   │   └── sanitizer.js            # Client-side HTML sanitizer
│   └── build/
│       ├── chatbot.min.js
│       └── sanitizer.min.js
│
├── templates/                      # Mustache templates
│   ├── chatbot.mustache            # Main chatbot panel
│   └── message.mustache            # Message bubble
│
├── lang/                           # Language strings
│   ├── en/local_aichat.php         # English
│   └── it/local_aichat.php         # Italian
│
└── pix/                            # Plugin icons
```

---

## Moodle Plugin Conventions

### Namespacing

All classes use PSR-4 autoloading under the `\local_aichat` namespace:

```php
namespace local_aichat\rag;

class vector_store {
    // Class at: classes/rag/vector_store.php
}
```

### String Management

All user-facing strings use Moodle's `get_string()`:

```php
$label = get_string('coursesettings', 'local_aichat');
```

Strings are defined in `lang/en/local_aichat.php` and `lang/it/local_aichat.php`.

### Capability Checks

Always validate capabilities before performing actions:

```php
$context = \context_course::instance($courseid);
require_capability('local/aichat:use', $context);
```

### Database Access

Use Moodle's `$DB` global for all queries:

```php
global $DB;

$thread = $DB->get_record('local_aichat_threads', [
    'userid' => $USER->id,
    'courseid' => $courseid,
]);
```

### Events

Fire events for auditable actions:

```php
$event = \local_aichat\event\chat_message_sent::create([
    'context' => $context,
    'userid' => $USER->id,
    'courseid' => $courseid,
    'other' => ['threadid' => $threadid, 'messagelength' => strlen($message)],
]);
$event->trigger();
```

---

## Adding a New Web Service

### Step 1: Create the External Class

Create `classes/external/my_function.php`:

```php
<?php
namespace local_aichat\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

class my_function extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    public static function execute(int $courseid): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/aichat:use', $context);

        // Your logic here...

        return ['success' => true];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success flag'),
        ]);
    }
}
```

### Step 2: Register in `db/services.php`

```php
$functions['local_aichat_my_function'] = [
    'classname'   => 'local_aichat\external\my_function',
    'methodname'  => 'execute',
    'description' => 'My new function description',
    'type'        => 'read', // or 'write'
    'ajax'        => true,
    'capabilities' => 'local/aichat:use',
];
```

### Step 3: Bump Version

Increment the version number in `version.php` so Moodle detects the change.

### Step 4: Call from JavaScript

```javascript
import Ajax from 'core/ajax';

const result = await Ajax.call([{
    methodname: 'local_aichat_my_function',
    args: { courseid: 42 }
}])[0];
```

---

## Adding a New Activity Type to RAG

The content extractor in `classes/rag/content_extractor.php` supports pluggable module extraction.

### Step 1: Add Extraction Logic

In `content_extractor.php`, add a case to the module switch:

```php
case 'mymodule':
    $chunks[] = [
        'type' => 'mymodule',
        'id' => $cm->id,
        'title' => $cm->name,
        'content' => $this->extract_mymodule_content($cm),
    ];
    break;
```

### Step 2: Implement the Extractor

```php
private function extract_mymodule_content(\cm_info $cm): string {
    global $DB;
    $record = $DB->get_record('mymodule', ['id' => $cm->instance]);
    return strip_tags($record->intro ?? '') . "\n" . ($record->content ?? '');
}
```

### Step 3: Add the Chunk Type

Add `'mymodule'` to the list of supported `chunk_type` values in the codebase documentation and any validation logic.

---

## Adding a New Event

### Step 1: Create Event Class

Create `classes/event/my_event.php`:

```php
<?php
namespace local_aichat\event;

use core\event\base;

class my_event extends base {

    protected function init() {
        $this->data['crud'] = 'r';  // c, r, u, d
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'local_aichat_threads';
    }

    public static function get_name() {
        return get_string('eventmyevent', 'local_aichat');
    }

    public function get_description() {
        return "The user with id '{$this->userid}' did something.";
    }

    public function get_url() {
        return new \moodle_url('/local/aichat/dashboard.php', [
            'courseid' => $this->courseid,
        ]);
    }
}
```

### Step 2: Add Language String

In `lang/en/local_aichat.php`:

```php
$string['eventmyevent'] = 'My event happened';
```

---

## Adding a New Scheduled Task

### Step 1: Create Task Class

Create `classes/task/my_task.php`:

```php
<?php
namespace local_aichat\task;

use core\task\scheduled_task;

class my_task extends scheduled_task {

    public function get_name() {
        return get_string('taskmytask', 'local_aichat');
    }

    public function execute() {
        mtrace('Running my task...');
        // Task logic here
    }
}
```

### Step 2: Register Schedule in `db/tasks.php`

```php
$tasks = [
    // ... existing tasks ...
    [
        'classname' => 'local_aichat\task\my_task',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '4',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
];
```

### Step 3: Bump Version

Increment the version in `version.php`.

---

## Frontend Development

### AMD Module Architecture

The chatbot JavaScript follows a modular pattern:

```javascript
// amd/src/chatbot.js
define(['core/ajax', 'core/templates', 'local_aichat/sanitizer'], 
    function(Ajax, Templates, Sanitizer) {
    
    return {
        init: function(params) {
            // Initialize chatbot with server-provided parameters
        }
    };
});
```

### Key Frontend Features

| Feature | Implementation |
|---|---|
| **SSE Streaming** | `EventSource` API connecting to `ajax.php` |
| **Markdown Rendering** | Custom parser supporting headers, lists, code blocks, tables, links |
| **Voice Input** | Web Speech API (`SpeechRecognition`) with browser support detection |
| **File Upload** | `FormData` with drag-and-drop support |
| **Feedback** | Inline thumbs up/down buttons calling `submit_feedback` web service |
| **Export** | Redirect to `export.php` with format parameter |

### Mustache Templates

Templates use Moodle's Mustache engine:

**`chatbot.mustache`** — Main panel layout:
- Floating action button (FAB)
- Chat header with action buttons
- Scrollable message container
- Input area with character counter
- Welcome state with action cards

**`message.mustache`** — Individual message bubble:
- Role-specific styling (user/assistant)
- Timestamp formatting
- Attachment previews
- Feedback buttons (assistant only)
- Suggestion chips (assistant only)

### CSS Custom Properties

The plugin uses CSS custom properties for theming, set from admin configuration:

```css
:root {
    --aichat-primary: #4f46e5;    /* Configurable via admin */
    --aichat-secondary: #3730a3;  /* Configurable via admin */
}
```

Dark mode is supported via `@media (prefers-color-scheme: dark)`.

---

## Database Changes

### Adding a New Table

1. **Edit `db/install.xml`** — Add the XMLDB table definition
2. **Edit `db/upgrade.php`** — Add a migration step for the new version:

```php
if ($oldversion < 2024010106) {
    $dbman = $DB->get_manager();
    $table = new xmldb_table('local_aichat_newtable');
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
    $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
    // ... more fields ...
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('ix_courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);

    if (!$dbman->table_exists($table)) {
        $dbman->create_table($table);
    }

    upgrade_plugin_savepoint(true, 2024010106, 'local', 'aichat');
}
```

3. **Bump version** in `version.php` to match the upgrade step version number.

### Using the XMLDB Editor

Moodle includes a visual XMLDB editor:

```
Site administration → Development → XMLDB editor
```

This can generate the XML and upgrade code for you.

---

## Testing

### Running Moodle PHPUnit Tests

```bash
# Initialize PHPUnit (first time)
php admin/tool/phpunit/cli/init.php

# Run plugin tests
vendor/bin/phpunit --testsuite local_aichat_testsuite

# Run a specific test
vendor/bin/phpunit local/aichat/tests/vector_store_test.php
```

### Running Behat Tests

```bash
# Initialize Behat
php admin/tool/behat/cli/init.php

# Run plugin features
vendor/bin/behat --config /path/to/behat/behat.yml --tags @local_aichat
```

### Key Test Areas

| Area | What to Test |
|---|---|
| Web Services | Input validation, capability checks, return structures |
| RAG Pipeline | Content extraction per module type, embedding, search ranking |
| Security | Injection detection, sanitization, rate limiting, circuit breaker |
| Events | Event creation, data integrity |
| Privacy | Data export completeness, deletion cascading |

---

## Coding Standards

### PHP

- Follow [Moodle Coding Style](https://moodledev.io/general/development/policies/codingstyle)
- Use `defined('MOODLE_INTERNAL') || die();` at the top of included files
- Use `require_login()` and `require_sesskey()` for page scripts
- Always validate and clean parameters with `PARAM_*` constants
- Use Moodle's `$DB` API, never raw SQL with user input

### JavaScript

- Use AMD module format (`define()`)
- Follow Moodle's ESLint configuration
- Use `core/ajax` for web service calls
- Use `core/str` for language strings
- Use `core/templates` for rendering Mustache templates

### Language Strings

- All user-facing text must use `get_string()`
- Provide translations in `lang/en/` and `lang/it/`
- Use descriptive string identifiers (e.g., `chatexported`, not `str42`)
