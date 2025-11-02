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

