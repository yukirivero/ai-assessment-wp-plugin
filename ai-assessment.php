<?php
/**
 * Plugin Name: AI Assessment Results
 * Description: Stores AI readiness assessment attempts in custom tables and exposes REST endpoint.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

class AI_Assessment_Plugin {
  const DB_VERSION = '1.0.0';

  public function __construct() {
    register_activation_hook(__FILE__, [$this, 'activate']);
    add_action('plugins_loaded', [$this, 'maybe_update_schema']);
    add_action('rest_api_init', [$this, 'register_routes']);
    add_shortcode('ai_assessment', [$this, 'shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'register_scripts']);
  }

  public function maybe_update_schema() {
    $installed = get_option('ai_assessment_db_version');
    if ($installed !== self::DB_VERSION) {
      $this->activate(); // safely re-run dbDelta
    }
  }
  

  public function activate() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $a   = $wpdb->prefix . 'ai_assessment_attempts';
    $c   = $wpdb->prefix . 'ai_assessment_categories';
    $ans = $wpdb->prefix . 'ai_assessment_answers';
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
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
    dbDelta($sql);
    add_option('ai_assessment_db_version', self::DB_VERSION);
  }

  public function register_routes() {
    register_rest_route('ai-assessment/v1', '/submit', [
      'methods'  => 'POST',
      'callback' => [$this, 'handle_submit'],
      'permission_callback' => function() { return is_user_logged_in(); }
    ]);
  }

  public function handle_submit(WP_REST_Request $req) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_REST_Response(['error'=>'auth_required'], 401);
  
    $p = (array)$req->get_json_params();
    $overall   = isset($p['overall']) ? floatval($p['overall']) : null;
    $band      = isset($p['band']) ? sanitize_text_field($p['band']) : '';
    $version   = isset($p['version']) ? sanitize_text_field($p['version']) : null;
    $duration  = isset($p['duration_ms']) ? intval($p['duration_ms']) : null;
    $breakdown = isset($p['breakdown']) ? (array)$p['breakdown'] : [];
    $answers   = isset($p['answers']) ? (array)$p['answers'] : [];
    if ($overall === null || $band === '' || empty($breakdown) || empty($answers)) {
      return new WP_REST_Response(['error'=>'invalid_payload'], 400);
    }
  
    global $wpdb;
    $a   = $wpdb->prefix . 'ai_assessment_attempts';
    $c   = $wpdb->prefix . 'ai_assessment_categories';
    $ans = $wpdb->prefix . 'ai_assessment_answers';
  
    // Check the tables exist
    foreach ([$a,$c,$ans] as $t) {
      $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
      if ($exists !== $t) {
        return new WP_REST_Response(['error'=>'missing_table', 'table'=>$t], 500);
      }
    }
  
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ip_bin = $ip ? @inet_pton($ip) : null;
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
  
    $wpdb->query('START TRANSACTION');
  
    $ok = $wpdb->insert($a, [
      'user_id'      => $user_id,
      'overall_score'=> $overall,
      'band'         => $band,
      'version'      => $version,
      'duration_ms'  => $duration,
      'ip'           => $ip_bin,
      'user_agent'   => $ua,
      'created_at'   => current_time('mysql', 1),
    ], ['%d','%f','%s','%s','%d','%s','%s','%s']);
    if (!$ok) {
      $err = $wpdb->last_error;
      $wpdb->query('ROLLBACK');
      return new WP_REST_Response(['error'=>'insert_attempt_failed','detail'=>$err], 500);
    }
    $attempt_id = (int)$wpdb->insert_id;
  
    foreach ($breakdown as $row) {
      $ok = $wpdb->insert($c, [
        'attempt_id'   => $attempt_id,
        'category_key' => sanitize_key($row['key'] ?? ''),
        'category_name'=> sanitize_text_field($row['name'] ?? ''),
        'raw'          => intval($row['raw'] ?? 0),
        'pct'          => floatval($row['pct'] ?? 0),
        'weighted'     => floatval($row['weighted'] ?? 0),
        'weight'       => intval($row['weight'] ?? 0),
      ], ['%d','%s','%s','%d','%f','%f','%d']);
      if (!$ok) {
        $err = $wpdb->last_error;
        $wpdb->query('ROLLBACK');
        return new WP_REST_Response(['error'=>'insert_category_failed','detail'=>$err], 500);
      }
    }
  
    foreach ($answers as $r) {
      $ok = $wpdb->insert($ans, [
        'attempt_id'     => $attempt_id,
        'item_index'     => intval($r['item_index'] ?? 0),
        'category_key'   => sanitize_key($r['category_key'] ?? ''),
        'question_index' => intval($r['question_index'] ?? 0),
        'letter'         => substr(sanitize_text_field($r['letter'] ?? ''), 0, 1),
        'points'         => intval($r['points'] ?? 0),
        'option_text'    => wp_kses_post($r['option_text'] ?? ''),
      ], ['%d','%d','%s','%d','%s','%d','%s']);
      if (!$ok) {
        $err = $wpdb->last_error;
        $wpdb->query('ROLLBACK');
        return new WP_REST_Response(['error'=>'insert_answer_failed','detail'=>$err], 500);
      }
    }
  
    $wpdb->query('COMMIT');
    return new WP_REST_Response(['attempt_id'=>$attempt_id], 201);
  }
  

  public function register_scripts() {
    // We’ll localize the REST nonce + current user info for the frontend JS
    if (!is_user_logged_in()) return;
    wp_register_script(
      'ai-assessment-front',
      '', // we’re using inline JS on the page; this handle just carries localized data
      ['wp-api'], '1.0.0', true
    );
    $u = wp_get_current_user();
    wp_localize_script('ai-assessment-front', 'aiAssess', [
      'restUrl' => rest_url('ai-assessment/v1/submit'),
      'nonce'   => wp_create_nonce('wp_rest'),
      'user'    => ['id'=>$u->ID, 'name'=>$u->display_name, 'email'=>$u->user_email],
    ]);
  }

  public function shortcode() {
    if (!is_user_logged_in()) {
      return '<p>Please <a href="'. esc_url(wp_login_url(get_permalink())) .'">log in</a> to take the assessment.</p>';
    }
    // Ensure our localized data exists for your inline JS to use.
    wp_enqueue_script('ai-assessment-front');
    // You’ll paste your existing HTML markup on the page content itself.
    return ''; // shortcode just ensures localization + login gate
  }
}
new AI_Assessment_Plugin();
