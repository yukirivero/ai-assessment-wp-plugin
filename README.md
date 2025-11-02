# ai-assessment-wp-plugin

# AI-Amplified Talent™ Assessment (WordPress)

Scenario-based, MBTI-style assessment for AI readiness.  
Randomized questions (and shuffled choices), weighted category scoring, clean results view, CSV export, and a secure REST endpoint that stores attempts in custom MySQL tables.

> **Stack**: WordPress (plugin + page template), vanilla JS, REST API (`wp-json`), MySQL custom tables via `dbDelta`.

---

## Table of Contents

- [Features](#features)  
- [Repo Structure](#repo-structure)  
- [Quick Start](#quick-start)  
- [Installation (Plugin)](#installation-plugin)  
- [Installation (Theme Template)](#installation-theme-template)  
- [How It Works](#how-it-works)  
- [Database Schema](#database-schema)  
- [REST API](#rest-api)  
- [Front-End Flow](#front-end-flow)  
- [CSV Export](#csv-export)  
- [Printing / PDF](#printing--pdf)  
- [Configuration Flags](#configuration-flags)  
- [Security Notes](#security-notes)  
- [Troubleshooting](#troubleshooting)  
- [Customization](#customization)  
- [Changelog](#changelog)  
- [License](#license)

---

## Features

- 🎲 **MBTI-style randomization**  
  Questions are presented in a global shuffled order; answer choices are also shuffled per question.
- 🧮 **Weighted category scoring**  
  Each category has a weight; overall score is a weighted sum, plus a human-readable readiness band.
- 🧾 **Data persistence**  
  Attempts (overall + per-category + per-question) saved to 3 custom tables.
- 🔐 **Secure REST endpoint**  
  Only authenticated users can submit attempts; Nonce-protected requests.
- 🧰 **Admin-friendly raw data**  
  Stores category breakdown and raw answers for deep analysis.
- 📤 **CSV export**  
  Client-side CSV export of the current attempt.
- 🖨️ **Clean print/PDF**  
  Print stylesheet outputs results only (no lingering last question).
- 🧩 **Theme-friendly**  
  Delivered as a page template; editors can still add intro/SEO content in WordPress.

---

## Repo Structure

/wp-content/
/plugins/
ai-assessment-results/
ai-assessment-results.php # Plugin (DB + REST)
/themes/your-theme/
page-ai-assessment.php # Page template (front-end quiz)


> You can name the template file anything (e.g., `page-ai-assessment.php`) as long as it has a proper `Template Name` header.

---

## Quick Start

1. **Install the plugin**
   - Upload `ai-assessment-results.php` as a plugin folder `ai-assessment-results/`.
   - Activate it in **WP Admin → Plugins**.

2. **Add the page template**
   - Copy `page-ai-assessment.php` into your active theme.
   - In **Pages → Add New**, select template **“AI Assessment”** and publish.

3. **Log in** and visit the page to take the assessment.  
   Results are saved automatically after completion.

---

## Installation (Plugin)

Create a plugin folder:

/wp-content/plugins/ai-assessment-results/


Place this file inside it:

- `ai-assessment-results.php` (your plugin from earlier)

Activate the plugin in **WP Admin → Plugins**.

> On activation, it creates/updates 3 custom tables using `dbDelta`. It also registers a REST route and a shortcode helper that localizes REST URL + Nonce + current user.

---

## Installation (Theme Template)

Place the annotated template into your active theme:

- `page-ai-assessment.php` (the fully commented template you’re using)

Create a **Page** in WP Admin and select Template: **AI Assessment**.

> The template renders WordPress page content (so editors can add intro text) and then the assessment UI.

---

## How It Works

- **Randomized flow**: Build a flat list of 35 question references (`ITEMS`), then shuffle it for MBTI-style presentation (no category hints).  
- **Shuffled options**: Each question’s options are shuffled via Fisher–Yates every load and on Reset.
- **Required answers**: No Skip; Next enforces a choice.
- **Results**: On final question, the UI hides, progress locks to 100%, and a **Results** card appears (Overall score, Band, Per-category bars).
- **Persistence**: The client builds a JSON payload and POSTs to `wp-json/ai-assessment/v1/submit` with a Nonce header. The plugin inserts:
  - One row into `*_ai_assessment_attempts`
  - 7 rows into `*_ai_assessment_categories`
  - 35 rows into `*_ai_assessment_answers`

---

## Database Schema

> Table names are prefixed with your `$wpdb->prefix` (e.g., `wp_`).

### `wp_ai_assessment_attempts`

| Column        | Type               | Notes                                    |
|--------------|--------------------|------------------------------------------|
| id           | BIGINT UNSIGNED PK | Attempt ID                               |
| user_id      | BIGINT UNSIGNED    | WP `ID` of submitting user               |
| overall_score| DECIMAL(5,2)       | 0..100                                   |
| band         | VARCHAR(80)        | Human band string                        |
| version      | VARCHAR(20)        | Optional question bank version           |
| duration_ms  | INT UNSIGNED       | Time to complete                         |
| created_at   | DATETIME           | Server time                              |
| ip           | VARBINARY(16)      | IPv4/IPv6 (binary)                       |
| user_agent   | VARCHAR(255)       | Browser UA                               |

### `wp_ai_assessment_categories`

| Column        | Type               | Notes                                |
|--------------|--------------------|--------------------------------------|
| id           | BIGINT UNSIGNED PK |                                      |
| attempt_id   | BIGINT UNSIGNED    | FK to attempts                       |
| category_key | VARCHAR(40)        | e.g. `process`                       |
| category_name| VARCHAR(120)       | Display name                         |
| raw          | TINYINT UNSIGNED   | 0..25                                |
| pct          | DECIMAL(5,2)       | 0..100 %                             |
| weighted     | DECIMAL(6,2)       | category points added to overall     |
| weight       | TINYINT UNSIGNED   | category weight (%)                  |

### `wp_ai_assessment_answers`

| Column         | Type               | Notes                                                        |
|----------------|--------------------|--------------------------------------------------------------|
| id             | BIGINT UNSIGNED PK |                                                              |
| attempt_id     | BIGINT UNSIGNED    | FK to attempts                                               |
| item_index     | TINYINT UNSIGNED   | 1..35 in the **shown order**                                 |
| category_key   | VARCHAR(40)        | Category for the shown question                              |
| question_index | TINYINT UNSIGNED   | 1..5 index **within** that category                          |
| letter         | CHAR(1)            | “A”..“D”                                                     |
| points         | TINYINT UNSIGNED   | Option points (1..5)                                         |
| option_text    | TEXT               | Snapshot of the selected choice text (for historical audits) |

---

## REST API

### Endpoint

POST /wp-json/ai-assessment/v1/submit


**Headers**
- `Content-Type: application/json`
- `X-WP-Nonce: <nonce>` (provided via `wp_localize_script`)

**Auth**: Must be logged in.

**Payload**
```json
{
  "overall": 82.5,
  "band": "Solid AI Potential – trainable with development",
  "version": "v1",
  "duration_ms": 123456,
  "breakdown": [
    { "key": "process", "name": "Process Thinking", "raw": 21, "pct": 84.0, "weighted": 16.8, "weight": 20 }
  ],
  "answers": [
    { "item_index": 1, "category_key": "communication", "question_index": 3, "letter": "B", "points": 5, "option_text": "…" }
  ]
}

### Responses

* 201 Created → { "attempt_id": 123 }
* 400 Bad Request → {"error":"invalid_payload"}
* 401 Unauthorized → {"error":"auth_required"}
* 500 Server Error → {"error":"insert_attempt_failed"}, etc.


## Front-End Flow

* Progress: countAnswered() / TOTAL_QUESTIONS
* Next:
    * Requires a selected radio; else alert.
    * If last question:
        * Set percentText to 100%,
        * Hide #assessmentForm and the wizard card,
        * Render results,
        * Submit to REST.
* Prev: saves current and steps back.
* Reset: clears answers, reshuffles everything, resets progress and UI.


### CSV Export

* Client-side CSV with:
    * timestamp, candidate (from WP user if available),
    * overall score and band,
    * per-category raw + weighted,
    * each question’s letter + points.
* Meant for ad-hoc exports; source of truth is the DB.


### Printing / PDF
* @media print hides the wizard and form.
* Shows Results only.
* Bars retain color via print-color-adjust.


### Configuration Flags
In the template JS:
``` const SHOW_CATEGORY_RESULTS = true; // show per-category breakdown on results UI
const SHOW_RAW_ANSWERS = false;     // hide raw answers block on results UI 
```
These do not affect the data saved to the database or CSV.


### Security Notes

* Endpoint is logged-in only and Nonce-protected.
* IP stored in binary using inet_pton — supports IPv4/IPv6.
* sanitize_text_field, sanitize_key, and wp_kses_post sanitize inputs server-side.
* SQL uses $wpdb->insert with format arrays to avoid injections.

### Troubleshooting

**“REST 400: invalid_payload”**
Ensure payload includes overall, band, breakdown (7), answers (35) and all numeric fields are numbers (not strings).

**“auth_required”**
User must be logged in. Verify Nonce is being localized and sent with X-WP-Nonce.

**DB tables missing**
Deactivate/activate the plugin to re-run dbDelta. Check ai_assessment_db_version option. Review error details returned by the REST handler (missing_table).

**Last question appears on results/PDF**
Template already hides the quiz UI before scoring and via @media print. If you customized, ensure:

* On last step: set percentText = '100%', hide #assessmentForm and the wizard card.
* Print CSS hides wizard/form.


### Customization

* Question bank: Edit CATEGORIES in the template.
    * Keep exactly 5 questions per category if you rely on raw/25.
    * Update weight to adjust contribution to overall.
* Readiness bands: Edit readinessBand(score) thresholds and labels.
* Versioning: Bump version: 'v1' in payload to track question bank revisions.
* Admin reports: Build WP Admin pages or external BI by reading the 3 tables.

### Changelog
1.0.0
Initial release: REST + custom tables, randomized flow, results view, CSV export, print layout.