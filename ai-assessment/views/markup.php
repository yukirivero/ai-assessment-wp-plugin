<?php if (!defined('ABSPATH')) exit; ?>
<div
  class="ai-assessment-root"
  id="<?php echo esc_attr($instance_id); ?>"
  data-allow-multiple="<?php echo $allow_multiple_bool ? '1' : '0'; ?>"
>
  <?php if (!empty($show_header_bool)) : ?>
    <header class="ai-head">
      <h1>AI-Amplified Talent™ Candidate Assessment</h1>
      <p class="lead">Scenario-based multiple choice (A–D). Each answer has weighted points. Final score shows readiness band.</p>
    </header>
  <?php endif; ?>

  <div class="card" data-el="wizardCard">
    <div class="wizard">
      <div class="step" data-el="stepText">Question 1 of 35</div>
      <div style="flex:1; margin:0 12px">
        <div class="progress"><div data-el="progressFill"></div></div>
      </div>
      <div class="step" data-el="percentText">0%</div>
    </div>
  </div>

  <form data-el="form" class="section" onsubmit="return false;">
    <section class="card" data-el="questionCard">
      <h2 data-el="categoryTitle" hidden>Category</h2>
      <fieldset class="q">
        <legend data-el="questionText">Question text</legend>
        <div class="options" data-el="optionsBox"></div>
      </fieldset>
    </section>

    <div class="toolbar">
      <button type="button" class="btn-outline" data-el="prevBtn">◀ Prev</button>
      <button type="button" data-el="nextBtn">Next ▶</button>
      <button type="button" class="btn-danger" data-el="resetBtn">Reset</button>
    </div>
  </form>

  <section class="card results" data-el="results" hidden aria-live="polite">
    <h2>Results</h2>

    <div class="kpi">
      <div><strong>Candidate</strong></div>
      <div class="muted" data-el="candidateSummary">—</div>
    </div>

    <div class="kpi">
      <div><strong>Overall Score</strong></div>
      <span class="badge" data-el="overallScore">—</span>
    </div>
    <div class="bar"><div data-el="overallBar"></div></div>
    <p class="muted" data-el="bandText">—</p>

    <h3 data-el="breakdownHeader">Category Breakdown</h3>
    <div data-el="categoryBreakdown"></div>

    <div class="toolbar">
      <button type="button" class="btn-outline" data-el="exportCsvBtn">Export CSV</button>
      <button type="button" class="btn-outline" onclick="window.print()">Print / Save PDF</button>
    </div>

    <h3 data-el="rawHeader">Raw Answers</h3>
    <pre data-el="rawAnswers"></pre>
  </section>
</div>
