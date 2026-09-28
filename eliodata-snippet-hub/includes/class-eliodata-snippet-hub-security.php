<?php
/**
 * Security helpers: PHP syntax validation, read-only application passwords,
 * and runtime error tracking for snippets.
 *
 * @package IDESnippets
 * @subpackage Security
 * @since 4.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Eliodata_Snippet_Hub_Security {

    const RUNTIME_ERRORS_OPTION = 'eliodata_snippet_hub_runtime_errors';

    /**
     * Application password used to authenticate the current request, if any.
     *
     * @var array|null
     */
    private static $authenticated_app_password = null;

    public static function init() {
        add_action('application_password_did_authenticate', [__CLASS__, 'remember_app_password'], 10, 2);
        add_filter('rest_pre_dispatch', [__CLASS__, 'enforce_readonly_rest'], 5, 3);
        add_action('xmlrpc_call', [__CLASS__, 'enforce_readonly_xmlrpc'], 1);
    }

    /**
     * Whether snippet execution is disabled with the ELIODATA_SNIPPET_HUB_SAFE_MODE constant.
     *
     * @return bool
     */
    public static function is_safe_mode() {
        return defined('ELIODATA_SNIPPET_HUB_SAFE_MODE') && ELIODATA_SNIPPET_HUB_SAFE_MODE;
    }

    // =========================================================================
    //  CAPABILITIES
    // =========================================================================

    /**
     * Capability required to manage snippets. On multisite, running PHP code is
     * reserved to super admins, as for plugin and theme editing.
     *
     * @return string
     */
    public static function get_required_capability() {
        $capability = is_multisite() ? 'manage_network_options' : 'manage_options';
        return (string) apply_filters('eliodata_snippet_hub_required_capability', $capability);
    }

    /**
     * @return bool
     */
    public static function current_user_can_manage() {
        return current_user_can(self::get_required_capability());
    }

    // =========================================================================
    //  RUNTIME FILES
    // =========================================================================

    /**
     * Private folder in uploads where snippet code is compiled once per version.
     *
     * @return string Folder path, or an empty string when it cannot be used.
     */
    public static function get_runtime_dir() {
        static $dir = null;
        if ($dir !== null) {
            return $dir;
        }

        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            return $dir = '';
        }

        $path = trailingslashit($uploads['basedir']) . 'eliodata-snippet-hub';
        if (!is_dir($path) && !wp_mkdir_p($path)) {
            return $dir = '';
        }

        // No listing and no direct access; file names are also unguessable
        if (!file_exists($path . '/index.php')) {
            file_put_contents($path . '/index.php', "<?php\n// Silence is golden.\n");
        }
        if (!file_exists($path . '/.htaccess')) {
            file_put_contents($path . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }

        return $dir = wp_is_writable($path) ? $path : '';
    }

    /**
     * File containing the given snippet code, written on first use and reused
     * until the code changes. Line 1 is a guard, so the code starts on line 2.
     *
     * @param int    $snippet_id Snippet id.
     * @param string $code       Normalized code, without opening tag.
     * @return string File path, or an empty string when it cannot be written.
     */
    public static function get_runtime_file($snippet_id, $code) {
        $dir = self::get_runtime_dir();
        $snippet_id = absint($snippet_id);
        if ($dir === '' || $snippet_id <= 0) {
            return '';
        }

        $hash = substr(hash_hmac('sha256', $snippet_id . "\n" . $code, wp_salt('auth')), 0, 32);
        $file = $dir . '/snippet-' . $snippet_id . '-' . $hash . '.php';
        if (is_file($file)) {
            return $file;
        }

        // Written under a temporary name then renamed, so a request never includes a partial file
        $tmp_file = $file . '.' . wp_generate_password(8, false, false) . '.tmp';
        if (file_put_contents($tmp_file, "<?php if (!defined('ABSPATH')) { exit; }\n" . $code . "\n") === false) {
            return '';
        }
        if (!rename($tmp_file, $file)) {
            wp_delete_file($tmp_file);
            return '';
        }

        self::delete_runtime_files($snippet_id, $file);
        return $file;
    }

    /**
     * Delete the compiled files of a snippet.
     *
     * @param int    $snippet_id Snippet id.
     * @param string $keep       File to keep.
     */
    public static function delete_runtime_files($snippet_id, $keep = '') {
        $dir = self::get_runtime_dir();
        $snippet_id = absint($snippet_id);
        if ($dir === '' || $snippet_id <= 0) {
            return;
        }

        $files = glob($dir . '/snippet-' . $snippet_id . '-*.php');
        foreach (is_array($files) ? $files : [] as $file) {
            if ($file !== $keep) {
                wp_delete_file($file);
            }
        }
    }

    // =========================================================================
    //  PHP SYNTAX
    // =========================================================================

    /**
     * Strip BOM, opening PHP tag and trailing closing tag, as done before execution.
     *
     * @param string $code Stored snippet code.
     * @return string
     */
    public static function normalize_snippet_code($code) {
        $clean = ltrim((string) $code);
        if (strpos($clean, "\xEF\xBB\xBF") === 0) {
            $clean = substr($clean, 3);
        }
        $clean = (string) preg_replace('/^\s*<\?(?:php)?\s*/i', '', $clean, 1);
        $clean = (string) preg_replace('/\?>\s*$/', '', $clean, 1);
        return trim($clean);
    }

    /**
     * Number of lines removed before the code body by normalize_snippet_code(),
     * used to report line numbers in the code as the user wrote it.
     *
     * @param string $code Stored snippet code.
     * @return int
     */
    public static function get_code_line_offset($code) {
        $code = (string) $code;
        $clean = self::normalize_snippet_code($code);
        if ($clean === '') {
            return 0;
        }

        $body_position = strpos($code, substr($clean, 0, min(strlen($clean), 40)));
        return $body_position === false ? 0 : substr_count(substr($code, 0, $body_position), "\n");
    }

    /**
     * Check snippet code for PHP syntax errors without executing it.
     *
     * Compile errors (for example a function declared twice) cannot be detected
     * here; they are handled at runtime by the fatal error handler.
     *
     * @param string $code Snippet code.
     * @return true|WP_Error
     */
    public static function validate_php_code($code) {
        $code = (string) $code;
        $clean = self::normalize_snippet_code($code);
        if ($clean === '' || !function_exists('token_get_all') || !defined('TOKEN_PARSE')) {
            return true;
        }

        $offset = self::get_code_line_offset($code);

        try {
            token_get_all("<?php\n" . $clean . "\n", TOKEN_PARSE);
        } catch (ParseError $error) {
            $line = max(1, (int) $error->getLine() - 1 + $offset);
            $line = min($line, substr_count($code, "\n") + 1);
            return new WP_Error(
                'invalid_php_syntax',
                sprintf(
                    /* translators: 1: PHP parser message, 2: line number */
                    __('PHP syntax error: %1$s on line %2$d.', 'eliodata-snippet-hub'),
                    preg_replace('/ on line \d+$/', '', $error->getMessage()),
                    $line
                ),
                ['status' => 400, 'line' => $line]
            );
        }

        return true;
    }

    // =========================================================================
    //  READ-ONLY APPLICATION PASSWORDS
    // =========================================================================

    /**
     * @param WP_User $user Authenticated user.
     * @param array   $item Application password used.
     */
    public static function remember_app_password($user, $item) {
        if (is_array($item)) {
            self::$authenticated_app_password = $item;
        }
    }

    /**
     * Application password used by the current request, detected from WordPress
     * authentication itself rather than from request headers.
     *
     * @return array|null
     */
    private static function get_authenticated_app_password() {
        if (is_array(self::$authenticated_app_password)) {
            return self::$authenticated_app_password;
        }

        if (!function_exists('rest_get_authenticated_app_password') || !class_exists('WP_Application_Passwords')) {
            return null;
        }

        $uuid = rest_get_authenticated_app_password();
        $user_id = get_current_user_id();
        if (!$uuid || !$user_id) {
            return null;
        }

        $item = WP_Application_Passwords::get_user_application_password($user_id, $uuid);
        return is_array($item) ? $item : null;
    }

    /**
     * Whether the request is authenticated with an application password whose
     * name contains [readonly] or (readonly).
     *
     * @return bool
     */
    public static function is_readonly_request() {
        $item = self::get_authenticated_app_password();
        if (!$item || empty($item['name'])) {
            return false;
        }

        $name = strtolower((string) $item['name']);
        $readonly = strpos($name, '[readonly]') !== false || strpos($name, '(readonly)') !== false;

        return (bool) apply_filters('eliodata_snippet_hub_is_readonly_app_password', $readonly, $item);
    }

    /**
     * Block every write request made with a read-only application password,
     * on all REST routes of the site (including internal dispatches from MCP tools).
     *
     * @param mixed           $result  Response to replace the requested one.
     * @param WP_REST_Server  $server  Server instance.
     * @param WP_REST_Request $request Request used to generate the response.
     * @return mixed
     */
    public static function enforce_readonly_rest($result, $server, $request) {
        if ($result !== null || !($request instanceof WP_REST_Request) || !self::is_readonly_request()) {
            return $result;
        }

        $method = strtoupper((string) $request->get_method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $result;
        }

        // MCP calls use POST; each tool is checked in Eliodata_Snippet_Hub_MCP::call_tool()
        if (preg_match('#^/eliodata-snippet-hub/v\d+/mcp/call$#', (string) $request->get_route())) {
            return $result;
        }

        return new WP_Error(
            'readonly_forbidden',
            __('This application password is read-only.', 'eliodata-snippet-hub'),
            ['status' => 403]
        );
    }

    public static function enforce_readonly_xmlrpc() {
        if (self::is_readonly_request()) {
            wp_die(
                esc_html__('Read-only application passwords cannot be used with XML-RPC.', 'eliodata-snippet-hub'),
                '',
                ['response' => 403]
            );
        }
    }

    // =========================================================================
    //  RUNTIME ERRORS
    // =========================================================================

    /**
     * @return array Errors keyed by snippet id.
     */
    public static function get_runtime_errors() {
        $errors = get_option(self::RUNTIME_ERRORS_OPTION, []);
        return is_array($errors) ? $errors : [];
    }

    /**
     * Store a runtime error for a snippet.
     *
     * @param int    $snippet_id  Snippet id.
     * @param string $message     Error message.
     * @param int    $line        Line number.
     * @param bool   $deactivated Whether the snippet was deactivated.
     */
    public static function record_runtime_error($snippet_id, $message, $line, $deactivated) {
        $snippet_id = absint($snippet_id);
        if ($snippet_id <= 0) {
            return;
        }

        $errors = self::get_runtime_errors();
        $errors[$snippet_id] = [
            // First line only: the stack trace would expose server paths
            'message' => substr(sanitize_text_field(strtok((string) $message, "\n")), 0, 500),
            'line' => absint($line),
            'time' => current_time('mysql'),
            'deactivated' => (bool) $deactivated,
        ];
        update_option(self::RUNTIME_ERRORS_OPTION, $errors, false);
    }

    /**
     * Forget the runtime error of a snippet, after it was edited or deleted.
     *
     * @param int $snippet_id Snippet id.
     */
    public static function clear_runtime_error($snippet_id) {
        $snippet_id = absint($snippet_id);
        $errors = self::get_runtime_errors();
        if (!isset($errors[$snippet_id])) {
            return;
        }

        unset($errors[$snippet_id]);
        update_option(self::RUNTIME_ERRORS_OPTION, $errors, false);
    }

    /**
     * Clear the object cache entries used by the snippet runtime.
     *
     * @param int $snippet_id Snippet id, or 0 for lists only.
     */
    public static function clear_snippet_cache($snippet_id = 0) {
        $snippet_id = absint($snippet_id);
        if ($snippet_id > 0) {
            wp_cache_delete('eliodata_snippet_hub_snippet_' . $snippet_id, 'eliodata_snippet_hub');
        }
        wp_cache_delete('eliodata_snippet_hub_all_snippets', 'eliodata_snippet_hub');
        wp_cache_delete('eliodata_snippet_hub_active_snippets', 'eliodata_snippet_hub');
    }
}
