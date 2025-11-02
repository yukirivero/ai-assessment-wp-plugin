<?php
// If someone tries to load this file directly (outside WordPress), abort for safety.
if (!defined('ABSPATH')) exit;

/**
 * Main plugin class: owns database schema, REST endpoint, and the shortcode renderer.
 * Junior notes:
 * - Keep "wiring" (add_action/add_shortcode) in __construct()
 * - Keep I/O (DB + REST) in dedicated methods
 * - Keep rendering logic in render_shortcode()
 */
class AI_Assessment {
  // Bump this whenever you change table structure. Used to trigger dbDelta again.
  const DB_VERSION = '1.0.0';

  /**
   * Constructor: runs on plugin boot (see plugin bootstrap where we `new AI_Assessment()`).
   * Wires plugin features into WordPress via hooks.
   */
  public function __construct() {
    // --- Database schema management ---
    // register_activation_hook() runs only when the plugin is activated in wp-admin → Plugins.
    // IMPORTANT: The first argument MUST be the main plugin file path, not this class file.
    // We pass the method to create/update DB tables.
    register_activation_hook(AI_ASSESS_PLUGIN_DIR . 'ai-assessment.php', [$this, 'activate']);

    // After all plugins load, check if our stored DB version matches the code's DB_VERSION.
    // If not, re-run dbDelta to create/alter tables as needed.
    add_action('plugins_loaded', [$this, 'maybe_update_schema']);

    // --- REST API + Shortcode ---
    // Register our REST routes under /wp-json/ai-assessment/v1/...
    add_action('rest_api_init', [$this, 'register_routes']);

    // Register the shortcode [ai_assessment] → calls $this->render_shortcode()
    add_shortcode('ai_assessment', [$this, 'render_shortcode']);
  }

  /**
   * If the stored schema version doesn't match our code version, run activate() again.
   * This is a safe way to evolve tables without forcing the admin to "re-activate" the plugin.
   */
  public function maybe_update_schema() {
    $installed = get_option('ai_assessment_db_version');
    if ($installed !== self::DB_VERSION) {
      $this->activate(); // Safe to re-run: dbDelta() handles create/alter idempotently.
    }
  }

  /**
   * Create (or upgrade) the plugin's custom database tables.
   * Runs on plugin activation and whenever DB_VERSION changes.
   */
  public function activate() {
    global $wpdb;                                 // WP's database object
    $charset = $wpdb->get_charset_collate();      // Correct charset/collation for this site

    // Table names with the current site's prefix (handles multisite/single prefixes)
    $a   = $wpdb->prefix . 'ai_assessment_attempts';
    $c   = $wpdb->prefix . 'ai_assessment_categories';
    $ans = $wpdb->prefix . 'ai_assessment_answers';

    // dbDelta() lives here; it can CREATE or ALTER tables based on SQL definitions below.
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // NOTE: dbDelta expects a very specific syntax (PRIMARY KEY, indexes, etc.).
    // We create 3 tables:
    // 1) attempts: one row per test submission (overall score, band, metadata)
    // 2) categories: per-attempt breakdown (willingness/process/etc.)
    // 3) answers: each selected answer in the order the user saw them
    $sql = "
    CREATE TABLE $a (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      overall_score DECIMAL(5,2) NOT NULL,
      band VARCHAR(80) NOT NULL,
      version VARCHAR(20) DEFAULT NULL,
      duration_ms INT UNSIGNED DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      ip VARBINARY(16) NULL,
      user_agent VARCHAR(255) NULL,
      PRIMARY KEY(id), KEY user_id (user_id), KEY created_at (created_at)
    ) $charset;
    CREATE TABLE $c (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      attempt_id BIGINT UNSIGNED NOT NULL,
      category_key VARCHAR(40) NOT NULL,
      category_name VARCHAR(120) NOT NULL,
      raw TINYINT UNSIGNED NOT NULL,
      pct DECIMAL(5,2) NOT NULL,
      weighted DECIMAL(6,2) NOT NULL,
      weight TINYINT UNSIGNED NOT NULL,
      PRIMARY KEY(id), KEY attempt_id (attempt_id), KEY category_key (category_key)
    ) $charset;
    CREATE TABLE $ans (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      attempt_id BIGINT UNSIGNED NOT NULL,
      item_index TINYINT UNSIGNED NOT NULL,
      category_key VARCHAR(40) NOT NULL,
      question_index TINYINT UNSIGNED NOT NULL,
      letter CHAR(1) NOT NULL,
      points TINYINT UNSIGNED NOT NULL,
      option_text TEXT NOT NULL,
      PRIMARY KEY(id), KEY attempt_id (attempt_id), KEY category_q (category_key, question_index)
    ) $charset;";

    // Apply schema; CREATE if missing, ALTER if changed.
    dbDelta($sql);

    // Store the version we just applied so maybe_update_schema() knows we're up to date.
    update_option('ai_assessment_db_version', self::DB_VERSION);
  }

  /**
   * Define our REST route(s). Called on rest_api_init.
   * After this, you can POST to /wp-json/ai-assessment/v1/submit (logged-in only).
   */
  public function register_routes() {
    register_rest_route('ai-assessment/v1', '/submit', [
      'methods'  => 'POST',                       // HTTP verb
      'callback' => [$this, 'handle_submit'],     // method that handles the request
      // Simple auth: only logged-in users can submit. Adjust if you need public access.
      'permission_callback' => function() { return is_user_logged_in(); }
    ]);
  }

  /**
   * REST callback: receives JSON payload from the front-end and writes it to DB.
   * IMPORTANT:
   * - Always validate/sanitize input.
   * - Use $wpdb->prepare or format arrays for inserts.
   * - Use transactions when writing to multiple tables so you can roll back on failure.
   */
  public function handle_submit(WP_REST_Request $req) {
    // Identify the user making the request (WordPress session/nonce required)
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_REST_Response(['error'=>'auth_required'], 401);

    // Parse JSON body into an array; default to [] to avoid undefined index notices.
    $p = (array)$req->get_json_params();

    // Extract and type-cast fields from payload.
    $overall   = isset($p['overall']) ? floatval($p['overall']) : null;
    $band      = isset($p['band']) ? sanitize_text_field($p['band']) : '';
    $version   = isset($p['version']) ? sanitize_text_field($p['version']) : null;
    $duration  = isset($p['duration_ms']) ? intval($p['duration_ms']) : null;
    $breakdown = isset($p['breakdown']) ? (array)$p['breakdown'] : [];
    $answers   = isset($p['answers']) ? (array)$p['answers'] : [];

    // Basic validation: all of these must be present (and non-empty).
    if ($overall === null || $band === '' || empty($breakdown) || empty($answers)) {
      return new WP_REST_Response(['error'=>'invalid_payload'], 400);
    }

    // Prepare table names again (prefix-aware).
    global $wpdb;
    $a   = $wpdb->prefix . 'ai_assessment_attempts';
    $c   = $wpdb->prefix . 'ai_assessment_categories';
    $ans = $wpdb->prefix . 'ai_assessment_answers';

    // Helpful in local dev: ensure the tables exist before inserting.
    foreach ([$a,$c,$ans] as $t) {
      $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
      if ($exists !== $t) return new WP_REST_Response(['error'=>'missing_table','table'=>$t], 500);
    }

    // Collect request metadata to aid auditing (IP + user agent).
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ip_bin = $ip ? @inet_pton($ip) : null;                 // store as binary supports IPv4/IPv6
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    // Start a transaction since we insert into 3 tables.
    $wpdb->query('START TRANSACTION');

    // Insert the main attempt row; use format array to prevent SQL injection.
    $ok = $wpdb->insert($a, [
      'user_id'       => $user_id,
      'overall_score' => $overall,
      'band'          => $band,
      'version'       => $version,
      'duration_ms'   => $duration,
      'ip'            => $ip_bin,
      'user_agent'    => $ua,
      'created_at'    => current_time('mysql', 1), // 1 = GMT
    ], ['%d','%f','%s','%s','%d','%s','%s','%s']);
    if (!$ok) {
      // On failure, roll back and return a 500 with the DB error string for debugging.
      $err = $wpdb->last_error;
      $wpdb->query('ROLLBACK');
      return new WP_REST_Response(['error'=>'insert_attempt_failed','detail'=>$err], 500);
    }
    // Get the auto-increment ID of the attempt we just inserted.
    $attempt_id = (int)$wpdb->insert_id;

    // Insert each category breakdown row (child records).
    foreach ($breakdown as $row) {
      $ok = $wpdb->insert($c, [
        'attempt_id'   => $attempt_id,
        'category_key' => sanitize_key($row['key'] ?? ''),           // machine key (e.g. 'process')
        'category_name'=> sanitize_text_field($row['name'] ?? ''),    // human label
        'raw'          => intval($row['raw'] ?? 0),                   // 0..25
        'pct'          => floatval($row['pct'] ?? 0),                 // 0..100
        'weighted'     => floatval($row['weighted'] ?? 0),            // weight-applied points
        'weight'       => intval($row['weight'] ?? 0),                // % weight of that category
      ], ['%d','%s','%s','%d','%f','%f','%d']);
      if (!$ok) {
        $err = $wpdb->last_error;
        $wpdb->query('ROLLBACK');
        return new WP_REST_Response(['error'=>'insert_category_failed','detail'=>$err], 500);
      }
    }

    // Insert every chosen answer in the order the user saw them (good for audits).
    foreach ($answers as $r) {
      $ok = $wpdb->insert($ans, [
        'attempt_id'     => $attempt_id,
        'item_index'     => intval($r['item_index'] ?? 0),                // 1..N across all categories
        'category_key'   => sanitize_key($r['category_key'] ?? ''),       // category of the question
        'question_index' => intval($r['question_index'] ?? 0),            // 1..5 within the category
        'letter'         => substr(sanitize_text_field($r['letter'] ?? ''), 0, 1), // A/B/C/D
        'points'         => intval($r['points'] ?? 0),                    // numeric points
        'option_text'    => wp_kses_post($r['option_text'] ?? ''),        // the actual option text (escaped)
      ], ['%d','%d','%s','%d','%s','%d','%s']);
      if (!$ok) {
        $err = $wpdb->last_error;
        $wpdb->query('ROLLBACK');
        return new WP_REST_Response(['error'=>'insert_answer_failed','detail'=>$err], 500);
      }
    }

    // All good: commit the transaction so the three tables stay consistent.
    $wpdb->query('COMMIT');

    // Return 201 Created with the attempt ID (useful for admin tools or debug).
    return new WP_REST_Response(['attempt_id'=>$attempt_id], 201);
  }

  /**
   * Shortcode renderer: outputs the assessment UI container and boots the front-end app.
   * Notes:
   * - We refuse to render in feeds/search/excerpts to avoid duplicate instances.
   * - We gate by login (assessment is for logged-in users only).
   * - We add a global one-per-request guard to prevent accidental duplicates on the same page.
   * - We enqueue CSS/JS and localize REST info (nonce + user) for the front-end.
   */
  public function render_shortcode($atts = []) {
    // (0) Don’t render in feeds/search/excerpts — many themes/plugins run shortcodes there.
    if (is_feed() || is_search() || doing_filter('get_the_excerpt') || doing_filter('the_excerpt')) {
      return '';
    }
  
    // (1) Login gate — keep UI and submissions scoped to authenticated users.
    if (!is_user_logged_in()) {
      $login = esc_url(wp_login_url(get_permalink()));
      return '<p>Please <a href="'. $login .'">log in</a> to take the assessment.</p>';
    }
  
    // (2) Shortcode attributes with sane defaults.
    // - show_header: whether to render a small internal header (you may already have a page title)
    // - allow_multiple: if "1", lets you intentionally render multiple assessments on one page
    $atts = shortcode_atts([
      'show_header'    => '0',
      'allow_multiple' => '0',
    ], $atts, 'ai_assessment');
    $show_header    = (string)$atts['show_header'] === '1';
    $allow_multiple = (string)$atts['allow_multiple'] === '1';
  
    // (3) One-per-request guard. Some builders or filters call the_content twice.
    // We use a global flag to avoid rendering a second instance unless explicitly allowed.
    global $AI_ASSESS_ALREADY_RENDERED;
    if (!$allow_multiple) {
      if (!empty($AI_ASSESS_ALREADY_RENDERED)) {
        // Already rendered once; refuse to output again.
        return '';
      }
      $AI_ASSESS_ALREADY_RENDERED = true;
    }
  
    // (4) Enqueue front-end assets. WordPress dedupes by handle automatically.
    wp_enqueue_style('ai-assessment-css', AI_ASSESS_PLUGIN_URL . 'assets/css/ai-assessment.css', [], '1.1.1');
    wp_enqueue_script('ai-assessment-js', AI_ASSESS_PLUGIN_URL . 'assets/js/ai-assessment.js', [], '1.1.1', true);
  
    // (5) Pass runtime data to JS.
    // - restUrl + nonce: for authenticated REST POST
    // - user info: so the UI can show name/email (or at least know who submitted)
    $u = wp_get_current_user();
    wp_localize_script('ai-assessment-js', 'aiAssess', [
      'restUrl' => rest_url('ai-assessment/v1/submit'),
      'nonce'   => wp_create_nonce('wp_rest'),
      'user'    => ['id'=>$u->ID, 'name'=>$u->display_name, 'email'=>$u->user_email],
    ]);
  
    // (6) Render a single root container; the view file contains only HTML skeleton.
    // JS binds to it, renders questions, handles scoring, and posts to REST.
    $instance_id         = 'ai-assess-' . wp_generate_uuid4(); // helpful if you ever allow multiples
    $show_header_bool    = $show_header;
    $allow_multiple_bool = $allow_multiple;
  
    // Capture the view output and return it as the shortcode content.
    ob_start();
    include AI_ASSESS_PLUGIN_DIR . 'views/markup.php';
    return ob_get_clean();
  }
}
