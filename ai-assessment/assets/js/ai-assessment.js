/* AI Assessment front-end (shortcode-powered, multi-instance, hardened)
 * -----------------------------------------------------------------------------
 * This file mounts the assessment UI inside any element with class
 * `.ai-assessment-root`. It supports multiple instances on a page, but by
 * default we hard-dedupe so only the *first* instance remains (you can allow
 * multiples by setting data-allow-multiple="1" on a root).
 *
 * High-level flow:
 * 1) Dedupe roots (and keep watching for late-injected duplicates).
 * 2) For each remaining root, initInstance():
 *    - Build randomized question order across categories.
 *    - Render one question at a time.
 *    - Require answers before moving forward.
 *    - Compute scores, render results, POST to WordPress REST API.
 * -----------------------------------------------------------------------------
 */
(function(){
  'use strict';

  // ====================================================================================
  // HARD DE-DUPE (runtime): keep only the first root unless any instance allows multiples
  // ====================================================================================

  // Remove duplicate root containers at initial load.
  function dedupeRoots() {
    const roots = Array.from(document.querySelectorAll('.ai-assessment-root'));
    if (!roots.length) return;

    // If *any* root explicitly allows multiples, do nothing (developer opted in).
    const anyAllows = roots.some(r => r.dataset.allowMultiple === '1');
    if (anyAllows) return;

    // Otherwise keep just the first root; remove the rest to avoid duplicate UIs/results.
    roots.slice(1).forEach(r => { if (r.isConnected) r.remove(); });
  }

  // Some builders inject content late (after our first pass). Keep watching and deduping.
  function observeForLateDuplicates() {
    const mo = new MutationObserver(() => {
      const roots = Array.from(document.querySelectorAll('.ai-assessment-root'));
      if (roots.length <= 1) return;
      const anyAllows = roots.some(r => r.dataset.allowMultiple === '1');
      if (anyAllows) return;
      roots.slice(1).forEach(r => { if (r.isConnected) r.remove(); });
    });
    // Observe the whole document for added nodes.
    mo.observe(document.documentElement, { childList: true, subtree: true });
  }

  // ====================================================================================
  // CONFIG FLAGS (UI behavior toggles)
  // ====================================================================================

  // Whether to show category breakdown bars in the final results view.
  const SHOW_CATEGORY_RESULTS = true;

  // Whether to display the verbose "Raw Answers" block in the final results view.
  const SHOW_RAW_ANSWERS = false;

  // ====================================================================================
  // QUESTION BANK (7 categories × 5 questions each)
  // ====================================================================================
  // NOTE: Question text and point weights define the scoring model. Do not mutate at runtime.
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

  // Build a quick lookup: key → category object (faster than repeated finds).
  const CAT_BY_KEY = CATEGORIES.reduce((acc, c) => (acc[c.key]=c, acc), {});

  // Total number of questions (used for progress UI and validations).
  const TOTAL_QUESTIONS = CATEGORIES.length * 5;

  // Fisher–Yates shuffle: in-place, unbiased.
  function shuffle(arr){
    for(let i=arr.length-1;i>0;i--){
      const j = Math.floor(Math.random()*(i+1));
      [arr[i],arr[j]] = [arr[j],arr[i]];
    }
    return arr;
  }

  // ====================================================================================
  // BOOT ALL INSTANCES ON PAGE
  // ====================================================================================

  // Find all roots on the page and initialize each once.
  function initAll(){
    document.querySelectorAll('.ai-assessment-root').forEach((root) => {
      if (root.dataset.booted === '1') return; // guard: prevent double init
      root.dataset.booted = '1';
      initInstance(root);
    });
  }

  // ====================================================================================
  // SINGLE INSTANCE BOOT
  // ====================================================================================

  function initInstance(root){
    // Small query helper scoped to this root (no collisions across instances).
    const $ = (sel) => root.querySelector(sel);

    // Cache DOM references inside this instance for speed/clarity.
    const els = {
      wizardCard:       $('[data-el="wizardCard"]'),
      stepText:         $('[data-el="stepText"]'),
      percentText:      $('[data-el="percentText"]'),
      progressFill:     $('[data-el="progressFill"]'),
      form:             $('[data-el="form"]'),
      questionCard:     $('[data-el="questionCard"]'),
      categoryTitle:    $('[data-el="categoryTitle"]'),
      questionText:     $('[data-el="questionText"]'),
      optionsBox:       $('[data-el="optionsBox"]'),
      prevBtn:          $('[data-el="prevBtn"]'),
      nextBtn:          $('[data-el="nextBtn"]'),
      resetBtn:         $('[data-el="resetBtn"]'),
      results:          $('[data-el="results"]'),
      overallScore:     $('[data-el="overallScore"]'),
      bandText:         $('[data-el="bandText"]'),
      overallBar:       $('[data-el="overallBar"]'),
      candidateSummary: $('[data-el="candidateSummary"]'),
      breakdownHeader:  $('[data-el="breakdownHeader"]'),
      categoryBreakdown:$('[data-el="categoryBreakdown"]'),
      exportCsvBtn:     $('[data-el="exportCsvBtn"]'),
      rawHeader:        $('[data-el="rawHeader"]'),
      rawAnswers:       $('[data-el="rawAnswers"]'),
    };

    // Prevent native form submission (Enter key or implicit submit) from reloading the page.
    els.form.addEventListener('submit', (e)=>{ e.preventDefault(); e.stopPropagation(); });
    els.form.addEventListener('keydown', (e)=>{ if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); }});

    // Per-instance state container: NO cross-instance globals.
    const state = {
      ITEMS: [],           // flat randomized order of questions across categories
      SHUFFLED: {},        // per-question randomized option indexes
      answers: {},         // recorded answers by category → question index
      gIdx: 0,             // global index into ITEMS (current question pointer)
      startedAt: Date.now(),// timestamp for duration_ms
      completed: false     // lock flag after scoring
    };

    // Build flat ITEMS list of { catKey, qIdx } and shuffle to randomize order.
    CATEGORIES.forEach(c => c.questions.forEach((_, qIdx) => state.ITEMS.push({ catKey:c.key, qIdx })));
    shuffle(state.ITEMS);

    // Initialize empty answers and shuffle the options per question.
    CATEGORIES.forEach(c=>{
      state.answers[c.key] = Array(c.questions.length).fill(null);
      state.SHUFFLED[c.key] = c.questions.map(q => shuffle(q.options.map((_,i)=>i)));
    });

    // Hide category title to avoid leaking category context (MBTI-like experience).
    if (els.categoryTitle) els.categoryTitle.setAttribute('hidden','hidden');

    // Count how many questions have a recorded answer (for progress bar).
    function countAnswered(){
      let n=0; CATEGORIES.forEach(c=>c.questions.forEach((_,i)=>{ if(state.answers[c.key][i]) n++; }));
      return n;
    }

    // Render the current question based on state.gIdx.
    function render(){
      if (state.completed) return; // do not re-render questions after completion

      // Guard against out-of-bounds state.
      if (state.gIdx < 0 || state.gIdx >= state.ITEMS.length) state.gIdx = 0;

      const item = state.ITEMS[state.gIdx];
      if (!item) return;

      const cat = CAT_BY_KEY[item.catKey];
      const q   = cat?.questions?.[item.qIdx];
      if (!cat || !q) { state.gIdx = 0; return; }

      const order = state.SHUFFLED[cat.key][item.qIdx];

      // Write the question text.
      els.questionText.textContent = `Q${state.gIdx+1}. ${q.text}`;
      // Replace the options list with current shuffled options.
      els.optionsBox.innerHTML = '';

      // Create one label+radio per option in shuffled order.
      order.forEach((optIdx, pos) => {
        const opt = q.options[optIdx];
        const wrap = document.createElement('label');
        const radio = document.createElement('input');
        radio.type='radio';
        radio.name=`g_${state.gIdx}`;          // scope to this question by global index
        radio.value=String(pos);               // position in the shuffled order
        radio.dataset.points=String(opt.points);
        radio.dataset.text=opt.text;

        // If user already answered this question, check the saved option.
        const prev = state.answers[cat.key][item.qIdx];
        if(prev && prev.letterPos === pos){ radio.checked = true; }

        // Display as "A. Text", "B. Text", etc.
        wrap.append(radio, document.createTextNode(` ${String.fromCharCode(65+pos)}. ${opt.text}`));
        els.optionsBox.appendChild(wrap);
      });

      // Update progress UI.
      const pct = Math.round((countAnswered() / TOTAL_QUESTIONS) * 100);
      els.stepText.textContent = `Question ${state.gIdx+1} of ${TOTAL_QUESTIONS}`;
      els.percentText.textContent = `${pct}%`;
      els.progressFill.style.width = pct + '%';

      // Prev disabled on first question; Next label changes on last question.
      els.prevBtn.disabled = (state.gIdx===0);
      els.nextBtn.textContent = (state.gIdx === TOTAL_QUESTIONS-1) ? 'Review & Score ▶' : 'Next ▶';
    }

    // Persist the selected option for the current question into state.answers.
    function captureCurrent(){
      if (state.completed) return;
      const item = state.ITEMS[state.gIdx];
      const cat  = CAT_BY_KEY[item.catKey];
      const sel = root.querySelector(`input[name="g_${state.gIdx}"]:checked`);
      if(sel){
        const pos = Number(sel.value);
        state.answers[cat.key][item.qIdx] = {
          letter: String.fromCharCode(65+pos), // A/B/C/D
          letterPos: pos,                      // 0..3 within current shuffled order
          points: Number(sel.dataset.points),  // numeric points for scoring
          text: sel.dataset.text               // the option text (for auditing/CSV)
        };
      }
    }

    // Advance to next question; on last question, compute and reveal results.
    function next(){
      if (state.completed) return;

      // Require an answer before allowing progress.
      const sel = root.querySelector(`input[name="g_${state.gIdx}"]:checked`);
      if(!sel){ alert('Please choose an option to continue.'); return; }

      captureCurrent();

      const isLast = (state.gIdx === TOTAL_QUESTIONS-1);
      if(isLast){
        // Lock question UI and progress bar at 100%, then show results.
        els.percentText.textContent = '100%';
        els.progressFill.style.width = '100%';
        els.form.style.display = 'none';
        els.wizardCard.style.display = 'none';
        state.completed = true;
        root.classList.add('is-complete');
        scoreAndShow();
        return;
      }

      // Otherwise move forward and re-render.
      state.gIdx++;
      render();
    }

    // Go back a question (if not on the first).
    function prev(){
      if (state.completed) return;
      captureCurrent();
      if(state.gIdx===0) return;
      state.gIdx--;
      render();
    }

    // Calculate per-category and overall scores.
    function computeScores(){
      const breakdown=[]; let overall=0;
      CATEGORIES.forEach(cat=>{
        // Sum the points the user earned in this category (null answers count as 0).
        const raw = state.answers[cat.key].reduce((a,v)=>a + (v? v.points:0), 0);
        const pct = raw / 25;                               // normalize to 0..1
        const weighted = +(pct * cat.weight).toFixed(2);    // apply category weight
        breakdown.push({ key:cat.key, name:cat.name, raw, pct: +(pct*100).toFixed(1), weight:cat.weight, weighted });
        overall += weighted;
      });
      return { overall:+overall.toFixed(2), breakdown };
    }

    // Map the overall numeric score to a band (label).
    function readinessBand(score){
      if(score >= 85) return 'High AI Readiness – AI-Amplified Talent ready';
      if(score >= 70) return 'Solid AI Potential – trainable with development';
      if(score >= 55) return 'Moderate Readiness – needs structured upskilling';
      return 'Low Readiness – significant training required';
    }

    // Build the payload we POST to WordPress (attempt + breakdown + each answer).
    function buildPayload(){
      const model = computeScores();

      // Flatten answers in the *seen* order (ITEMS) for better auditing.
      const answersPayload = state.ITEMS.map((it, idx) => {
        const v = state.answers[it.catKey][it.qIdx] || {};
        return {
          item_index: idx + 1,              // 1..N across the entire test
          category_key: it.catKey,          // e.g., 'process'
          question_index: it.qIdx + 1,      // 1..5 within its category
          letter: v.letter || '',           // A/B/C/D
          points: v.points || 0,            // numeric points
          option_text: v.text || ''         // the option text (for admins)
        };
      });

      return {
        overall: model.overall,
        band: readinessBand(model.overall),
        version: 'v1',                                      // put your content version here
        duration_ms: Date.now() - (state.startedAt||Date.now()),
        breakdown: model.breakdown.map(b=>({
          key:b.key, name:b.name, raw:Number(b.raw), pct:Number(b.pct),
          weighted:Number(b.weighted), weight:Number(b.weight)
        })),
        answers: answersPayload
      };
    }

    // POST to /wp-json/ai-assessment/v1/submit (requires localized aiAssess.*)
    async function submitResults(payload){
      if (!window.aiAssess?.restUrl || !window.aiAssess?.nonce) {
        console.error('REST config missing (aiAssess.restUrl/nonce)');
        return {attempt_id:null};
      }
      const res = await fetch(aiAssess.restUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': aiAssess.nonce },
        body: JSON.stringify(payload)
      });
      if (!res.ok) {
        const txt = await res.text().catch(()=> '');
        throw new Error(`REST ${res.status}: ${txt || 'submit failed'}`);
      }
      return res.json();
    }

    // Finalize: show results UI and attempt to save to REST.
    function scoreAndShow(){
      const model = computeScores();

      // Reveal results section and hide the "Next" button to prevent resubmits.
      els.results.hidden = false;
      els.nextBtn.style.display = 'none';

      // Show candidate info (from localized WP user, if provided).
      const name  = (aiAssess?.user?.name  || '').trim();
      const email = (aiAssess?.user?.email || '').trim();
      els.candidateSummary.textContent = (name || email) ? `${name || 'User'}${email ? ' • ' + email : ''}` : '—';

      // Overall KPI
      els.overallScore.textContent = model.overall + ' / 100';
      els.bandText.textContent = readinessBand(model.overall);
      els.overallBar.style.width = model.overall + '%';

      // Category breakdown (optional UI)
      els.categoryBreakdown.innerHTML = '';
      if (SHOW_CATEGORY_RESULTS) {
        els.breakdownHeader.style.display = '';
        model.breakdown.forEach(c=>{
          const row = document.createElement('div');
          row.className = 'kpi';
          row.innerHTML = `<div><strong>${c.name}</strong><div class="muted">Raw: ${c.raw}/25 • Weight: ${c.weight}%</div></div><span class="badge">${c.weighted.toFixed(2)} pts</span>`;
          els.categoryBreakdown.appendChild(row);

          const barWrap = document.createElement('div');
          barWrap.className = 'bar';
          const fill = document.createElement('div');
          fill.style.width = c.pct + '%';
          barWrap.appendChild(fill);
          els.categoryBreakdown.appendChild(barWrap);
        });
      } else {
        els.breakdownHeader.style.display = 'none';
      }

      // Raw answers (optional UI — still saved to DB regardless)
      const lines=[];
      lines.push(`Candidate: ${name || 'User'}${email ? ' | ' + email : ''}`);
      CATEGORIES.forEach(cat=>{
        lines.push(`\n${cat.name}:`);
        cat.questions.forEach((q,i)=>{
          const v = state.answers[cat.key][i];
          lines.push(`  Q${i+1}: ${v? v.letter:'—'} (${v? v.points:0} pts) — ${v? v.text:''}`);
        });
      });
      els.rawAnswers.textContent = lines.join('\n').trim();
      els.rawAnswers.style.display = SHOW_RAW_ANSWERS ? '' : 'none';
      els.rawHeader.style.display = SHOW_RAW_ANSWERS ? '' : 'none';

      // Scroll results into view (nice UX).
      els.results.scrollIntoView({ behavior:'smooth', block:'start' });

      // Attempt to save; UI does not regress if it fails.
      const payload = buildPayload();
      submitResults(payload).then(({attempt_id})=>{
        console.log('Saved attempt', attempt_id);
      }).catch(err=>{
        console.error('Submit failed:', err);
        alert('We could not save your attempt. Please screenshot this and contact admin.');
      });
    }

    // Build and download a CSV for the current attempt (client-side).
    function exportCsv(){
      const payload = buildPayload();

      // CSV header fields (attempt meta + category rolls + answers).
      const headers = [
        'timestamp','candidate_name','candidate_email',
        'overall_score','overall_band',
        ...CATEGORIES.flatMap(c=>[`${c.key}_raw`,`${c.key}_weighted`]),
        ...CATEGORIES.flatMap(c=>c.questions.map((_,i)=>`${c.key}_q${i+1}_letter`)),
        ...CATEGORIES.flatMap(c=>c.questions.map((_,i)=>`${c.key}_q${i+1}_points`))
      ];

      const now = new Date().toISOString();
      const name = (aiAssess?.user?.name || '').replace(/,/g,' ');
      const email = (aiAssess?.user?.email || '');

      // One flat row of values aligned to the headers.
      const row = [];
      row.push(now, name, email, payload.overall, payload.band);
      CATEGORIES.forEach(c=>{
        const cb = payload.breakdown.find(x=>x.key===c.key);
        row.push(cb.raw, cb.weighted);
      });
      CATEGORIES.forEach(c=>{ state.answers[c.key].forEach(v=>row.push(v? v.letter:'')); });
      CATEGORIES.forEach(c=>{ state.answers[c.key].forEach(v=>row.push(v? v.points:'')); });

      // Create & download the CSV file.
      const csv = [headers.join(','), row.map(v => (typeof v==='string' && v.includes(',')) ? `"${v.replace(/"/g,'""')}"` : v).join(',')].join('\n');
      const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = 'ai_readiness_assessment.csv';
      document.body.appendChild(a); a.click(); a.remove();
      URL.revokeObjectURL(url);
    }

    // Wire up click handlers (prevent default so buttons never submit the form).
    els.nextBtn.addEventListener('click', (e)=>{ e.preventDefault(); e.stopPropagation(); next(); });
    els.prevBtn.addEventListener('click', (e)=>{ e.preventDefault(); e.stopPropagation(); prev(); });

    // Reset returns the UI to the initial state and reshuffles items/options.
    els.resetBtn.addEventListener('click', (e)=>{
      e.preventDefault(); e.stopPropagation();

      // Reset state
      state.completed = false;
      state.startedAt = Date.now();
      CATEGORIES.forEach(c=>{
        state.answers[c.key] = state.answers[c.key].map(()=>null);
        state.SHUFFLED[c.key] = c.questions.map(q => shuffle(q.options.map((_,i)=>i)));
      });
      state.ITEMS.length = 0;
      CATEGORIES.forEach(c => c.questions.forEach((_, qIdx) => state.ITEMS.push({ catKey: c.key, qIdx })));
      shuffle(state.ITEMS);
      state.gIdx = 0;

      // Restore UI panels/labels
      els.results.hidden = true;
      els.form.style.display = '';
      els.wizardCard.style.display = '';
      els.nextBtn.style.display = '';
      els.stepText.textContent = `Question 1 of ${TOTAL_QUESTIONS}`;
      els.percentText.textContent = '0%';
      els.progressFill.style.width = '0%';
      root.classList.remove('is-complete');

      render();
    });

    // Export CSV button (optional).
    if (els.exportCsvBtn) els.exportCsvBtn.addEventListener('click', (e)=>{ e.preventDefault(); e.stopPropagation(); exportCsv(); });

    // Initial header state + first question render.
    els.stepText.textContent = `Question 1 of ${TOTAL_QUESTIONS}`;
    els.percentText.textContent = '0%';
    els.progressFill.style.width = '0%';
    render();
  }

  // Boot sequence on this page:
  // 1) Remove duplicate roots.
  // 2) Keep watching for late duplicates.
  // 3) Initialize all instances.
  function boot() {
    dedupeRoots();
    observeForLateDuplicates();
    initAll();
  }

  // Run after DOM is ready; if already ready, run immediately.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once:true });
  } else {
    boot();
  }
})();
