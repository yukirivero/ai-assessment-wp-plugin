<?php
/**
 * Plugin Name: AI Assessment
 * Description: Shortcode-based AI Readiness assessment that stores results in custom DB tables and renders randomized questions.
 * Version: 1.1.0
 *
 * What this file is:
 * - The plugin "bootstrap" file. WordPress reads this to:
 *   1) identify the plugin (header above),
 *   2) define constants (paths/URLs),
 *   3) load the main class file,
 *   4) and initialize the plugin once WP is ready.
 *
 * Tip for junior devs:
 * - Keep this file tiny and focused. Heavy logic should live in /includes classes.
 */

// Safety check: if this file is accessed directly (not via WordPress), stop execution.
// This prevents someone hitting the file URL and running PHP out of context.
if (!defined('ABSPATH')) exit;

// -----------------------------------------------------------------------------
// Define plugin-wide constants
// -----------------------------------------------------------------------------

// Absolute filesystem path to this plugin directory.
// Example: /var/www/html/wp-content/plugins/ai-assessment/
define('AI_ASSESS_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Public URL to this plugin directory.
// Example: https://example.com/wp-content/plugins/ai-assessment/
define('AI_ASSESS_PLUGIN_URL', plugin_dir_url(__FILE__));

// -----------------------------------------------------------------------------
// Load the main plugin class
// -----------------------------------------------------------------------------

// Require the core class that contains all plugin logic (hooks, REST routes, shortcode rendering, etc.).
// Using require_once ensures the file is included only once and throws a fatal error if missing
// (which is good: you want to fail fast during development).
require_once AI_ASSESS_PLUGIN_DIR . 'includes/class-ai-assessment.php';

// -----------------------------------------------------------------------------
// Bootstrapping the plugin after WordPress and other plugins load
// -----------------------------------------------------------------------------

/**
 * plugins_loaded fires after all active plugins are loaded, but before most of WordPress initializes.
 * It's a safe hook to instantiate your plugin's main class:
 * - WP core is available,
 * - other plugins’ classes/functions are loaded (useful for integrations),
 * - but no output has been sent yet.
 *
 * We store the instance in $GLOBALS so other code (themes, MU plugins) can reference it if necessary.
 * Alternative patterns:
 *   - Use a singleton pattern in your class,
 *   - Or use a static accessor method (e.g., AI_Assessment::instance()).
 */
add_action('plugins_loaded', function () {
  // Bootstrap the plugin class. Any hooks/filters are typically registered in the class constructor.
  $GLOBALS['ai_assessment'] = new AI_Assessment();
});
