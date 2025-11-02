# AI Assessment (WordPress Plugin)

Shortcode-powered AI Readiness assessment that:
- Randomizes questions across categories (MBTI-style, no category hints while answering)
- Requires an answer for every question (no skipping)
- Computes an Overall Score (0–100) and Readiness Band
- Optionally shows Category Breakdown and Raw Answers
- Submits attempts to custom DB tables via a secure REST endpoint
- Prevents duplicate UIs on the same page (hard de-dupe + late-injection watcher)
- Supports client-side CSV export

---

## Table of Contents
- [Requirements](#requirements)
- [Installation](#installation)
- [File Structure](#file-structure)
- [Activation & Database Schema](#activation--database-schema)
- [Shortcode Usage](#shortcode-usage)
- [Front-End Behavior](#front-end-behavior)
- [REST API](#rest-api)
- [Data Model / Tables](#data-model--tables)
- [Security Notes](#security-notes)
- [Caching Notes](#caching-notes)
- [Styling](#styling)
- [Troubleshooting](#troubleshooting)
- [Development Tips](#development-tips)
- [Changelog](#changelog)
- [License](#license)

---

## Requirements
- WordPress 6.0+
- PHP 7.4+ (PHP 8.x recommended)
- A theme/page that renders `the_content` and supports shortcodes
- Users must be logged in (REST endpoint is auth-gated)

---

## Installation

1. Create the plugin folder:
wp-content/plugins/ai-assessment/

2. Copy all plugin files into that folder (see [File Structure](#file-structure)).

3. In WP Admin → **Plugins**, **Activate** “AI Assessment”.
- On activation, custom DB tables are created via `dbDelta`.

---

## File Structure

ai-assessment/
├─ ai-assessment.php # Plugin bootstrap (defines constants, loads class)
├─ includes/
│ └─ class-ai-assessment.php # Core: schema, REST routes, shortcode, assets
├─ assets/
│ ├─ css/
│ │ └─ ai-assessment.css # Minimal styles (override from your theme if needed)
│ └─ js/
│ └─ ai-assessment.js # Front-end app (randomize, require, score, REST submit)
└─ views/
└─ markup.php # HTML shell rendered by the [ai_assessment] shortcode


---

## Activation & Database Schema

- On first activation, the plugin runs `dbDelta` to create/update 3 tables:
  - `{prefix}ai_assessment_attempts`
  - `{prefix}ai_assessment_categories`
  - `{prefix}ai_assessment_answers`
- Schema version is stored in `ai_assessment_db_version`.
- On `plugins_loaded`, if the stored version differs from the class’ `DB_VERSION`, `activate()` is re-run to safely apply schema changes.

> If you manually change tables/columns, **bump** `DB_VERSION` in `class-ai-assessment.php` and re-activate the plugin or let `maybe_update_schema()` run.

---

## Shortcode Usage

Add this shortcode anywhere in your content:

[ai_assessment]


**Attributes**

- `show_header` (default `0`)  
  `1` = render an internal heading/description wrapper above the form.
- `allow_multiple` (default `0`)  
  `1` = allow multiple instances on the same page.  
  By default the plugin removes duplicate roots at runtime to avoid double UIs from page builders.

**Examples**
[ai_assessment show_header="1"]
[ai_assessment allow_multiple="1"]


**Login Gate**  
If the user is not logged in, the shortcode renders a login prompt linking to the WP login page.

---

## Front-End Behavior

- **Randomization**  
  The app flattens all questions across categories into a single array, then Fisher–Yates shuffles it. Users cannot infer categories while answering.

- **Required Answers**  
  Navigation forward requires a selection. No skipping.

- **Progress UI**  
  Shows `Question X of N` and a progress bar (percentage = answered / total).

- **Results Screen**  
  - Overall Score (0–100) + Readiness Band label.
  - **Category Breakdown** (toggle in JS via `SHOW_CATEGORY_RESULTS`).
  - **Raw Answers** (toggle in JS via `SHOW_RAW_ANSWERS`; still saved to DB even when hidden).
  - **CSV Export** button generates a client-side CSV.

- **De-Duplication**  
  - Immediate pass removes all but the first `.ai-assessment-root` unless a root has `data-allow-multiple="1"`.
  - A `MutationObserver` continues watching for late-injected duplicates and removes them.

---

## REST API

**Endpoint**
POST /wp-json/ai-assessment/v1/submit


**Authentication**
- Logged-in users only (`permission_callback` checks auth).
- Uses localized `X-WP-Nonce` to protect against CSRF.

**Headers**
Content-Type: application/json
X-WP-Nonce: <localized nonce>


**Request Body (example)**
```json
{
  "overall": 82.5,
  "band": "Solid AI Potential – trainable with development",
  "version": "v1",
  "duration_ms": 73456,
  "breakdown": [
    { "key":"willingness","name":"Willingness to Learn","raw":22,"pct":88,"weighted":13.2,"weight":15 },
    { "key":"process","name":"Process Thinking","raw":20,"pct":80,"weighted":16,"weight":20 }
  ],
  "answers": [
    { "item_index":1,"category_key":"process","question_index":3,"letter":"B","points":5,"option_text":"Propose an integration/connector and RACI with SLAs." },
    { "item_index":2,"category_key":"growth","question_index":1,"letter":"B","points":5,"option_text":"Thank them and iterate on a new version." }
  ]
}
```
**Successful Response**
{ "attempt_id": 123 }

**Error Responses**
```
{ "error": "auth_required" }
{ "error": "invalid_payload" }
{ "error": "missing_table", "table": "wp_ai_assessment_attempts" }
{ "error": "insert_attempt_failed", "detail": "MySQL error message" }
```
