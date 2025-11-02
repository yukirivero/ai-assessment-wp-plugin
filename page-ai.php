<?php
/**
 * Template Name: AI Assessment
 * The template for displaying all single posts
 */

get_header();
?>

	<div id="primary" class="content-area">
		<main id="main" class="site-main">

			<?php

			// Start the Loop.
			while ( have_posts() ) :
				the_post();

				get_template_part( 'template-parts/content/content', 'page' );

				// If comments are open or we have at least one comment, load up the comment template.
				if ( comments_open() || get_comments_number() ) {
					comments_template();
				}

			endwhile; // End the loop.
			?>

<style>
    h1{font-size:1.9rem;margin:0 0 6px}
    h2{font-size:1.25rem;margin:16px 0 8px}
    h3{font-size:1.05rem;margin:14px 0 6px}
    p.lead{color:#555;margin-top:0}
    .card{background:#fafafa;border:1px solid #ddd;border-radius:8px;padding:16px;margin:12px 0}
    .toolbar{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}
    button{border:0;border-radius:6px;padding:10px 14px;background:#0d6efd;color:#fff;font-weight:600;cursor:pointer}
    button.btn-outline{background:#fff;color:#0d6efd;border:1px solid #0d6efd}
    button.btn-muted{background:#e9ecef;color:#222}
    button.btn-danger{background:#dc3545}
    .q{padding:12px;border-radius:8px;background:#fff;border:1px solid #e6e6e6;margin:8px 0}
    fieldset{border:0;margin:0;padding:0}
    legend{font-weight:600;margin-bottom:8px}
    .options{display:flex;flex-direction:column;gap:6px}
    label{cursor:pointer}
    input[type=radio]{margin-right:6px}
    .results{display:none}
    .kpi{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:10px 0}
    .bar{flex:1;height:10px;background:#eee;border:1px solid #ccc;border-radius:6px;overflow:hidden}
    .bar>div{height:100%;width:0;background:#0d6efd;transition:width .6s ease}
    .badge{font-weight:700;padding:4px 10px;border-radius:12px;background:#0d6efd;color:#fff}
    .muted{color:#555}
    pre{background:#f5f5f5;padding:10px;border-radius:6px;overflow:auto}
    .wizard{display:flex;align-items:center;justify-content:space-between;margin:8px 0}
    .progress{height:8px;background:#eee;border:1px solid #ddd;border-radius:999px;overflow:hidden}
    .progress>div{height:100%;width:0;background:#0d6efd}
    .step{font-size:.95rem;color:#333}
    /* Candidate info */
    .grid{display:flex;gap:12px;flex-wrap:wrap}
    .field{flex:1;min-width:220px;display:flex;flex-direction:column;gap:6px}
    .field input,.field select{padding:10px;border:1px solid #ccc;border-radius:6px}
    .req{color:#dc3545}

    /* Results viz polish */
    .results .viz-grid { display: grid; grid-template-columns: 180px 1fr; gap: 16px; align-items: start; }
    .results .donut { display:flex; align-items:center; justify-content:center; }
    .results .cat-row { margin: 10px 0; }
    .results .cat-head { display:flex; justify-content:space-between; font-weight:600; margin-bottom:6px; }
    .results .cat-bar { height: 10px; background: #eee; border-radius: 999px; overflow: hidden; }
    .results .cat-fill { height: 100%; background: linear-gradient(90deg,#4f46e5,#22c55e); border-radius: 999px; }
    .results .muted-small { font-size: 12px; color: #777; }

</style>


<div class="wrap">
        <header>
          <h1>AI-Amplified Talent™ Candidate Assessment</h1>
          <p class="lead">Scenario-based multiple choice (A-D). Each answer has weighted points. Final score shows readiness band.</p>
        </header>
    
        <!-- Candidate Info -->
    
        <div class="card">
          <div class="wizard">
            <div class="step" id="stepText">Category 1 of 7 • Question 1 of 5</div>
            <div style="flex:1; margin:0 12px">
              <div class="progress"><div id="progressFill"></div></div>
            </div>
            <div class="step" id="percentText">0%</div>
          </div>
        </div>
    
        <form id="assessmentForm" class="section">
          <section class="card" id="questionCard">
            <h2 id="categoryTitle"></h2>
            <fieldset class="q">
              <legend id="questionText"></legend>
              <div class="options" id="optionsBox"></div>
            </fieldset>
          </section>
    
          <div class="toolbar">
            <button type="button" class="btn-outline" id="prevBtn">◀ Prev</button>
            <button type="button" id="nextBtn">Next ▶</button>
            <button type="button" class="btn-muted" id="skipBtn" title="Skip and answer later">Skip</button>
            <button type="button" class="btn-danger" id="resetBtn">Reset</button>
          </div>
        </form>
    
        <section id="resultCard" class="card results" aria-live="polite">
          <h2>Results</h2>
    
          <div class="kpi">
            <div><strong>Candidate</strong></div>
            <div id="candidateSummary" class="muted">—</div>
          </div>
    
          <div class="kpi"><div><strong>Overall Score</strong></div><span id="overallScore" class="badge">—</span></div>
          <div class="bar"><div id="overallBar"></div></div>
          <p class="muted" id="bandText">—</p>
    
          <h3>Category Breakdown</h3>
          <div id="categoryBreakdown"></div>
    
          <div class="toolbar">
            <button type="button" class="btn-outline" id="exportCsvBtn">Export CSV</button>
            <button type="button" class="btn-outline" onclick="window.print()">Print / Save PDF</button>
          </div>
    
          <h3>Raw Answers</h3>
          <pre id="rawAnswers"></pre>
        </section>
      </div>

		</main><!-- #main -->
	</div><!-- #primary -->

  
  <script>
  /* =========================
   *  CONFIG / FLAGS
   * ========================= */
  // Show categories in the final results (on-screen). Set false for stricter MBTI vibe.
  const SHOW_CATEGORY_RESULTS = true;
  // Show the verbose "Raw Answers" block (on-screen). Data still goes to CSV/DB regardless.
  const SHOW_RAW_ANSWERS = false;

  /* =========================
   *  QUESTION BANK (unchanged)
   * ========================= */
  const CATEGORIES = [
    { key:'willingness', name:'Willingness to Learn', weight:15, questions:[
      { text:"Your team implements a new AI tool you've never used before. What do you do?", options:[
        {text:'Stick to old tools and avoid the new AI.',points:1},
        {text:'Learn basics on your own or with colleagues; try it on a small task.',points:5},
        {text:'Wait for someone to explicitly train you before engaging.',points:2},
        {text:"Argue it won't help and suggest skipping it.",points:1}]},
      { text:'A client rolls out an AI-driven reporting process. Your first step is…', options:[
        {text:'Skim the docs later when time allows.',points:2},
        {text:'Block 30 minutes to read docs and test with a sample report.',points:5},
        {text:'Ask your manager to summarize and tell you what to do.',points:2},
        {text:'Ignore until it becomes mandatory.',points:1}]},
      { text:'You notice an AI feature inside a tool you already use.', options:[
        {text:'Disable it so the UI stays the same.',points:1},
        {text:'Open a tutorial and test it on low-risk work.',points:5},
        {text:'Ask someone else to try it and report back.',points:3},
        {text:'Wait for an SOP before touching it.',points:2}]},
      { text:'A teammate shares an AI primer course.', options:[
        {text:'Save the link for someday.',points:2},
        {text:'Enroll and set a completion date this week.',points:5},
        {text:'Skim only the first lesson.',points:3},
        {text:'Decline because courses slow you down.',points:1}]},
      { text:'You are asked about your openness to AI-led change.', options:[
        {text:'Prefer no change to workflows.',points:1},
        {text:'Open to change and willing to learn quickly.',points:5},
        {text:'Okay with change if fully documented.',points:3},
        {text:'Resistant unless mandated.',points:2}]}]},
    { key:'curiosity', name:'Digital Curiosity', weight:15, questions:[
      { text:'When you hear about a new app that could improve productivity, what best describes you?', options:[
        {text:'I immediately look it up and try it on a low-risk task.',points:5},
        {text:'I wait until others have tried it and use it if it becomes common.',points:3},
        {text:'I stick with the tools I already know.',points:2},
        {text:'I feel anxious about new tools and avoid them unless required.',points:1}]},
      { text:'A new productivity extension promises AI email drafting.', options:[
        {text:'Install and A/B test on low-risk emails.',points:5},
        {text:'Wait for team adoption first.',points:3},
        {text:'Ignore because you have a template already.',points:2},
        {text:'Avoid extensions entirely.',points:1}]},
      { text:'Your main tool ships a major update with release notes.', options:[
        {text:'Ignore release notes; keep doing things the old way.',points:1},
        {text:'Skim and share a relevant tip with the team.',points:5},
        {text:'Wait for others to summarize later.',points:3},
        {text:'Disable updates until forced.',points:1}]},
      { text:'You hear about a tool that summarizes calls automatically.', options:[
        {text:'Pilot it in one internal meeting.',points:5},
        {text:'Bookmark for later review.',points:3},
        {text:'Ask someone else to read reviews.',points:2},
        {text:"Assume it's hype and move on.",points:1}]},
      { text:'You come across an AI article relevant to your role.', options:[
        {text:'Share a short take with your team channel.',points:5},
        {text:'Save it privately.',points:3},
        {text:'Skim headline only.',points:2},
        {text:'Ignore.',points:1}]}]},
    { key:'process', name:'Process Thinking', weight:20, questions:[
      { text:'You inherit a 12-step manual workflow.', options:[
        {text:'Document it as-is and continue.',points:2},
        {text:'Map steps, group by function, and flag automation candidates.',points:5},
        {text:"Ask someone else how they'd do it.",points:2},
        {text:'Skip mapping and start executing.',points:1}]},
      { text:'Deadlines are tight on a repetitive task.', options:[
        {text:'Work longer hours to keep up.',points:2},
        {text:'Batch tasks and explore an AI template.',points:5},
        {text:'Delegate without changing the process.',points:3},
        {text:'Push deadlines out.',points:1}]},
      { text:'Two teams duplicate data entry in two tools.', options:[
        {text:'Accept duplication to avoid change risk.',points:2},
        {text:'Propose an integration/connector and RACI with SLAs.',points:5},
        {text:'Export/import manually weekly.',points:3},
        {text:'Let each team manage separately.',points:1}]},
      { text:'Error rate increases on a manual QA task.', options:[
        {text:'Add a second human reviewer.',points:3},
        {text:'Introduce an AI checker with human spot-audits.',points:5},
        {text:'Accept minor errors to move faster.',points:1},
        {text:'Pause the task indefinitely.',points:1}]},
      { text:'A request spans multiple teams and tools.', options:[
        {text:'Handle by email CCs.',points:2},
        {text:'Create a shared workflow diagram and RACI.',points:5},
        {text:'Ask manager to coordinate.',points:3},
        {text:'Let each team manage their part separately.',points:1}]}]},
    { key:'literacy', name:'AI Literacy (Baseline)', weight:10, questions:[
      { text:'Which statement best describes your experience with ChatGPT or Google Gemini?', options:[
        {text:'I regularly use them (writing, research, brainstorming) and can guide with prompts.',points:5},
        {text:'I’ve tried them a few times for simple queries.',points:3},
        {text:'I’ve heard of them but haven’t really used them.',points:2},
        {text:"I don’t trust or see a use for them in my work.",points:1}]},
      { text:'Best practice for prompting?', options:[
        {text:'Give one vague sentence.',points:1},
        {text:'Provide role, goal, constraints, examples, and format.',points:5},
        {text:'Ask the model to “figure it out.”',points:2},
        {text:'Paste everything you have without structure.',points:2}]},
      { text:'Handling sensitive client data with AI:', options:[
        {text:'Paste raw data into any chatbot.',points:1},
        {text:'Redact and use a company-approved, access-controlled environment.',points:5},
        {text:'Use a personal account for convenience.',points:1},
        {text:'Avoid AI even when a safe option exists.',points:2}]},
      { text:'Selecting tools for a task:', options:[
        {text:'Use one general model for everything.',points:2},
        {text:'Choose tools/models based on task (text, vision, agents, automation).',points:5},
        {text:'Pick the cheapest option only.',points:2},
        {text:'Wait for IT to assign.',points:3}]},
      { text:'What does good AI output verification look like?', options:[
        {text:'Trust the first result.',points:1},
        {text:'Cross-check sources and test edge cases before using.',points:5},
        {text:'Ask a friend if it seems fine.',points:2},
        {text:'Rerun the same prompt once.',points:2}]}]},
    { key:'problem', name:'Problem-Solving Ability', weight:15, questions:[
      { text:'You must analyze an unfamiliar dataset by end of day.', options:[
        {text:'Clarify goals, break into parts, and use AI to suggest methods or summaries.',points:5},
        {text:'Break it down and do manual analysis only in Excel.',points:3},
        {text:'Ask a supervisor to handle it since it’s new.',points:2},
        {text:'Avoid AI tools and search the web for hours.',points:2}]},
      { text:'A solution fails in testing.', options:[
        {text:'Abandon the approach entirely.',points:1},
        {text:'Collect logs, isolate variables, iterate with small changes.',points:5},
        {text:'Ask another team to take over.',points:2},
        {text:'Ship anyway if it works sometimes.',points:1}]},
      { text:'You need to prioritize multiple tasks.', options:[
        {text:'Do easiest first.',points:2},
        {text:'Use impact/effort matrix and deadlines to order work.',points:5},
        {text:'Pick what you like most.',points:2},
        {text:'Ask someone else to decide.',points:3}]},
      { text:'A recurring defect slips into production.', options:[
        {text:'Create a checklist and an AI guardrail to catch it.',points:5},
        {text:'Remind people to be careful.',points:2},
        {text:'Assign blame.',points:1},
        {text:'Delay releases indefinitely.',points:1}]},
      { text:'Stakeholders disagree on requirements.', options:[
        {text:'Proceed with your interpretation.',points:2},
        {text:'Facilitate a brief alignment doc and review.',points:5},
        {text:'Wait until they agree.',points:2},
        {text:'Build two versions.',points:1}]}]},
    { key:'communication', name:'Communication Skills', weight:15, questions:[
      { text:'Explaining an AI workflow to a non-technical client looks like…', options:[
        {text:"Use jargon; they'll learn.",points:1},
        {text:'Use plain language, diagrams, and a short example.',points:5},
        {text:'Send a long whitepaper.',points:2},
        {text:'Ask someone else to present.',points:1}]},
      { text:'Handing off a process to a teammate requires…', options:[
        {text:'Explain verbally once.',points:2},
        {text:'A concise SOP with screenshots/templates.',points:5},
        {text:'A long video without chapters.',points:2},
        {text:'Let them figure it out.',points:1}]},
      { text:'A client questions AI accuracy; you…', options:[
        {text:'Get defensive.',points:1},
        {text:'Acknowledge limits; describe controls and QC steps.',points:5},
        {text:"Say it's perfect now.",points:1},
        {text:'Avoid answering.',points:1}]},
      { text:'Proposing automation changes, you provide…', options:[
        {text:'Only the idea.',points:2},
        {text:'Before/after metrics and a rollout plan.',points:5},
        {text:'A meme.',points:1},
        {text:'Ask manager to sell it.',points:2}]},
      { text:'Presenting to executives vs. engineers, you…', options:[
        {text:'Reuse one deck for speed.',points:2},
        {text:'Tailor outcomes/risks for execs and technical steps for engineers.',points:5},
        {text:'Send a long whitepaper to both.',points:1},
        {text:'Rely on jargon for speed.',points:1}]}]},
    { key:'growth', name:'Growth Mindset', weight:10, questions:[
      { text:'You receive constructive feedback on your AI prompts.', options:[
        {text:'Defend your approach.',points:1},
        {text:'Thank them and iterate on a new version.',points:5},
        {text:'Ignore unless mandated.',points:2},
        {text:'Ask them to write the prompts instead.',points:2}]},
      { text:'A pilot fails to reach expected ROI.', options:[
        {text:'Cancel AI initiatives.',points:1},
        {text:'Run a retro; adjust scope and try again.',points:5},
        {text:'Hide the results.',points:1},
        {text:'Blame the tool.',points:1}]},
      { text:"You're assigned a stretch learning goal (e.g., Level 2 → 3).", options:[
        {text:'Decline due to workload.',points:1},
        {text:'Create a learning plan with milestones.',points:5},
        {text:'Skim a video and call it done.',points:2},
        {text:'Ask to postpone indefinitely.',points:1}]},
      { text:"A colleague shares a better workflow you didn't know.", options:[
        {text:'Ignore to avoid rework.',points:1},
        {text:'Adopt it and credit them in the SOP.',points:5},
        {text:'Use it silently.',points:3},
        {text:'Argue the old way is fine.',points:1}]},
      { text:"You're asked to mentor a junior on AI basics.", options:[
        {text:'Decline; not your job.',points:1},
        {text:'Share resources and review their first attempts.',points:5},
        {text:'Point them to a link only.',points:2},
        {text:'Tell them to ask someone else.',points:1}]}]}
  ];

  /* =========================
   *  STATE & SHUFFLING
   * ========================= */
  const TOTAL_QUESTIONS = CATEGORIES.length * 5;

  // Build global flat list: [{catKey, qIdx}, ...] then shuffle
  const ITEMS = [];
  CATEGORIES.forEach(c => c.questions.forEach((_, qIdx) => ITEMS.push({ catKey: c.key, qIdx })));
  function shuffle(arr){ for(let i=arr.length-1;i>0;i--){ const j=Math.floor(Math.random()*(i+1)); [arr[i],arr[j]]=[arr[j],arr[i]]; } return arr; }
  shuffle(ITEMS);

  const LETTERS = ['A','B','C','D'];
  const answers = {};   // {catKey:[{letter,letterPos,points,text}|null,...]}
  const SHUFFLED = {};  // {catKey:[ [optionIndexOrder per q], ...]}
  CATEGORIES.forEach(c=>{
    answers[c.key] = Array(c.questions.length).fill(null);
    SHUFFLED[c.key] = c.questions.map(q => shuffle(q.options.map((_,i)=>i)));
  });

  async function submitResults() {
  if (!window.aiAssess?.restUrl || !window.aiAssess?.nonce) {
    throw new Error('REST config missing (aiAssess.restUrl/nonce)');
  }
  const payload = buildPayloadForSubmit();
  const res = await fetch(aiAssess.restUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': aiAssess.nonce
    },
    body: JSON.stringify(payload)
  });
  if (!res.ok) {
    const txt = await res.text().catch(()=> '');
    throw new Error(`REST ${res.status}: ${txt || 'submit failed'}`);
  }
  return res.json();
}

  /* =========================
   *  DOM REFS
   * ========================= */
  const categoryTitle = document.getElementById('categoryTitle'); // hidden during test
  const questionText  = document.getElementById('questionText');
  const optionsBox    = document.getElementById('optionsBox');
  const stepText      = document.getElementById('stepText');
  const percentText   = document.getElementById('percentText');
  const progressFill  = document.getElementById('progressFill');

  // Candidate fields (may not exist; safe access)
  const candName  = document.getElementById('candName');
  const candEmail = document.getElementById('candEmail');
  const candRole  = document.getElementById('candRole');

  // Remove Skip button if present
  document.getElementById('skipBtn')?.remove();

  if (categoryTitle) categoryTitle.style.display = 'none'; // no category cues while testing

  function getCandidateInfo(){
    // Prefer WordPress localized user if available
    const wpUser = (window.aiAssess && aiAssess.user) ? aiAssess.user : null;
    return {
      name:  (wpUser?.name  ?? candName?.value  ?? '').trim(),
      email: (wpUser?.email ?? candEmail?.value ?? '').trim(),
      role:  (candRole?.value ?? '').trim()
    };
  }

  function validateCandidate(){ return true; } // no required candidate fields

  function countAnswered(){
    let n=0; CATEGORIES.forEach(c=>c.questions.forEach((_,i)=>{ if(answers[c.key][i]) n++; }));
    return n;
  }

  let gIdx = 0; // global index into ITEMS

  function render(){
    const item = ITEMS[gIdx];
    const cat  = CATEGORIES.find(c => c.key === item.catKey);
    const q    = cat.questions[item.qIdx];
    const order = SHUFFLED[cat.key][item.qIdx];

    questionText.textContent = `Q${gIdx+1}. ${q.text}`;
    optionsBox.innerHTML = '';

    order.forEach((optIdx, pos) => {
      const opt = q.options[optIdx];
      const id = `g_${gIdx}_${pos}`;
      const label = document.createElement('label');
      const radio = document.createElement('input');
      radio.type='radio';
      radio.name=`g_${gIdx}`;
      radio.id=id;
      radio.value=String(pos);
      radio.dataset.points=String(opt.points);
      radio.dataset.text=opt.text;
      if(answers[cat.key][item.qIdx] && answers[cat.key][item.qIdx].letterPos === pos){ radio.checked = true; }
      label.append(radio, document.createTextNode(` ${LETTERS[pos]}. ${opt.text}`));
      optionsBox.appendChild(label);
    });

    const answered = countAnswered();
    const pct = Math.round((answered / TOTAL_QUESTIONS) * 100);
    stepText.textContent = `Question ${gIdx+1} of ${TOTAL_QUESTIONS}`;
    percentText.textContent = `${pct}%`;
    progressFill.style.width = pct + '%';

    document.getElementById('prevBtn').disabled = (gIdx===0);
    const isLast = (gIdx === TOTAL_QUESTIONS-1);
    document.getElementById('nextBtn').textContent = isLast ? 'Review & Score ▶' : 'Next ▶';
  }

  function captureCurrent(){
    const item = ITEMS[gIdx];
    const cat  = CATEGORIES.find(c => c.key === item.catKey);
    const sel = document.querySelector(`input[name="g_${gIdx}"]:checked`);
    if(sel){
      const pos = Number(sel.value);
      answers[cat.key][item.qIdx] = {
        letter: LETTERS[pos],
        letterPos: pos,
        points: Number(sel.dataset.points),
        text: sel.dataset.text
      };
    }
  }

  // Require an answer before moving forward
  function next(){
    const sel = document.querySelector(`input[name="g_${gIdx}"]:checked`);
    if(!sel){
      alert('Please choose an option to continue.');
      return;
    }

    captureCurrent();

    const isLast = (gIdx === TOTAL_QUESTIONS-1);
    if(isLast){
      // No need to re-validate; all questions are answered by construction.
      if(!validateCandidate()) return;
      scoreAndShow();
      return;
    }
    gIdx++;
    render();
  }

  function prev(){
    captureCurrent();
    if(gIdx===0) return;
    gIdx--;
    render();
  }

  function computeScores(){
    const breakdown=[]; let overall=0;
    CATEGORIES.forEach(cat=>{
      const raw = answers[cat.key].reduce((a,v)=>a + (v? v.points:0), 0); // 0..25
      const pct = raw / 25; // 0..1
      const weighted = +(pct * cat.weight).toFixed(2);
      breakdown.push({ key:cat.key, name:cat.name, raw, pct: +(pct*100).toFixed(1), weight:cat.weight, weighted });
      overall += weighted;
    });
    return { overall:+overall.toFixed(2), breakdown };
  }

  function readinessBand(score){
    if(score >= 85) return 'High AI Readiness – AI-Amplified Talent ready';
    if(score >= 70) return 'Solid AI Potential – trainable with development';
    if(score >= 55) return 'Moderate Readiness – needs structured upskilling';
    return 'Low Readiness – significant training required';
  }

  // optional: capture start time somewhere near init
  window.__aiStart = window.__aiStart || Date.now();

  function buildPayloadForSubmit() {
    const model = computeScores(); // { overall, breakdown }
    // answers[] in the exact order the user saw them
    const answersPayload = ITEMS.map((it, idx) => {
      const v = answers[it.catKey][it.qIdx] || {};
      return {
        item_index: idx + 1,                 // 1..TOTAL_QUESTIONS
        category_key: it.catKey,             // e.g. 'process'
        question_index: it.qIdx + 1,         // 1..5 within that category
        letter: v.letter || '',
        points: v.points || 0,
        option_text: v.text || ''
      };
    });

    return {
      overall: model.overall,                          // number
      band: readinessBand(model.overall),              // string
      version: 'v1',                                   // optional, for your own versioning
      duration_ms: Date.now() - (window.__aiStart||Date.now()),
      breakdown: model.breakdown.map(b => ({           // ensure clean numeric fields
        key: b.key,
        name: b.name,
        raw: Number(b.raw),
        pct: Number(b.pct),
        weighted: Number(b.weighted),
        weight: Number(b.weight)
      })),
      answers: answersPayload
    };
  }


  function scoreAndShow(){
    const model = computeScores();

    const card = document.getElementById('resultCard');
    if (card) card.style.display='block';

    // Hide the submit (Next / Review & Score) button after scoring
    const nextBtn = document.getElementById('nextBtn');
    if (nextBtn) nextBtn.style.display = 'none';

    // Overall
    document.getElementById('overallScore').textContent = model.overall + ' / 100';
    document.getElementById('bandText').textContent = readinessBand(model.overall);
    document.getElementById('overallBar').style.width = model.overall + '%';

    // Candidate summary (WP user if available, else fields if present)
    const {name,email,role} = getCandidateInfo();
    document.getElementById('candidateSummary').textContent =
      (name || email || role) ? `${name || 'User'}${email ? ' • '+email : ''}${role ? ' • '+role : ''}` : '—';

    // Category breakdown (show/hide based on flag)
    const catBox = document.getElementById('categoryBreakdown');
    const breakdownHeader = catBox.previousElementSibling; // the <h3> Category Breakdown
    catBox.innerHTML='';
    if (SHOW_CATEGORY_RESULTS) {
      breakdownHeader.style.display = '';
      model.breakdown.forEach(c=>{
        const row = document.createElement('div');
        row.className='kpi';
        row.innerHTML = `<div><strong>${c.name}</strong><div class="muted">Raw: ${c.raw}/25 • Weight: ${c.weight}%</div></div><span class="badge">${c.weighted.toFixed(2)} pts</span>`;
        catBox.appendChild(row);
        const barWrap = document.createElement('div');
        barWrap.className='bar';
        const fill = document.createElement('div');
        fill.style.width = c.pct + '%';
        barWrap.appendChild(fill);
        catBox.appendChild(barWrap);
      });
    } else {
      breakdownHeader.style.display = 'none';
    }

    // Raw answers (still generated for CSV/admin; hidden on-screen by default)
    const lines=[];
    lines.push(`Candidate: ${name || 'User'} | ${email || ''} | ${role || ''}`);
    CATEGORIES.forEach(cat=>{
      lines.push(`\n${cat.name}:`);
      cat.questions.forEach((q,i)=>{
        const v = answers[cat.key][i];
        lines.push(`  Q${i+1}: ${v? v.letter:'—'} (${v? v.points:0} pts) — ${v? v.text:''}`);
      });
    });
    const rawEl = document.getElementById('rawAnswers');
    rawEl.textContent = lines.join('\n').trim();
    const rawHeader = rawEl.previousElementSibling;
    rawEl.style.display = SHOW_RAW_ANSWERS ? '' : 'none';
    if (rawHeader) rawHeader.style.display = SHOW_RAW_ANSWERS ? '' : 'none';

    window.scrollTo({ top: card.offsetTop - 8, behavior: 'smooth' });

    submitResults()
  .then(({attempt_id}) => console.log('Saved attempt', attempt_id))
  .catch(err => {
    console.error('Submit failed:', err);
    alert('We could not save your attempt. Please screenshot this and contact admin.');
  });


    // If you wired the WP REST submission earlier, call submitResults(model) here
    // submitResults(model).catch(console.error);
  }

  document.getElementById('nextBtn').addEventListener('click', next);
  document.getElementById('prevBtn').addEventListener('click', prev);

  // Prevent implicit form submit reload
  document.getElementById('assessmentForm')?.addEventListener('submit', e => e.preventDefault());

  // Reset: restore everything + re-show Next button
  document.getElementById('resetBtn').addEventListener('click', ()=>{
    for(const k in answers){ answers[k] = answers[k].map(()=>null); }
    // Re-shuffle options per question
    CATEGORIES.forEach(c=>{
      SHUFFLED[c.key]=c.questions.map(q=>shuffle(q.options.map((_,i)=>i)));
    });
    // Rebuild & reshuffle global order
    ITEMS.length = 0;
    CATEGORIES.forEach(c => c.questions.forEach((_, qIdx) => ITEMS.push({ catKey: c.key, qIdx })));
    shuffle(ITEMS);

    gIdx=0;
    const nextBtn = document.getElementById('nextBtn');
    if (nextBtn) nextBtn.style.display = ''; // show Next again
    document.getElementById('resultCard').style.display='none';

    // If the candidate fields exist and you want them cleared:
    if (candName) candName.value='';
    if (candEmail) candEmail.value='';
    if (candRole) candRole.value='';

    render();
  });

  // Init wizard header & first render
  stepText.textContent = `Question 1 of ${TOTAL_QUESTIONS}`;
  percentText.textContent = `0%`;
  progressFill.style.width = '0%';
  render();
</script>


	

<?php
get_footer();
