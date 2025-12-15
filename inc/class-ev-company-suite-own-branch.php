<?php
if (!defined('ABSPATH')) exit;

class EV_Company_Suite_Own_Branch {

    public static function init() {
        new self();
    }

    public function __construct() {
        // Frontend shortcode
        add_shortcode('user_own_branch_changer', [$this, 'render_shortcode']);

        // Handle form submit & redirect
        add_action('template_redirect', [$this, 'handle_post']);
    }

    /**
     * Shortcode output
     */
    public function render_shortcode($atts = [], $content = '') {
        if (!is_user_logged_in()) {
            return ''; // Or message if you prefer
        }

        // ✅ FIXED: Get branches using helper and filter
        $branches = CompanySuite_Helpers::get_branches_map();
        
        // Filter to only branches whose code starts with "1"
        $branches = array_filter($branches, function($label, $code) {
            return strpos((string)$code, '1') === 0;
        }, ARRAY_FILTER_USE_BOTH);

        if (empty($branches)) {
            return ''; // No branches configured
        }

        $user_id = get_current_user_id();
        $current_code = get_user_meta($user_id, EV_Elementor_Vacations::META_BRANCH, true);

        ob_start();
        ?>
        <form method="post" class="ev-own-branch-form">
            <?php wp_nonce_field('ev_own_branch_change', 'evobc_nonce'); ?>
            <input type="hidden" name="evobc_action" value="change_branch" />

            <label for="ev-branch-select">
                <?php echo esc_html__('აირჩიეთ ფილიალი', 'company-suite'); ?>
            </label>

            <select name="ev_branch_code" id="ev-branch-select" required>
                <?php foreach ($branches as $code => $label): ?>
                    <option value="<?php echo esc_attr($code); ?>" <?php selected($current_code, $code); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">
                <?php echo esc_html__('შენახვა', 'company-suite'); ?>
            </button>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Process form submit:
     * - Only current user
     * - Only valid branch codes
     * - Redirect home on success
     */
    public function handle_post() {
        if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '')) {
            return;
        }

        if (empty($_POST['evobc_action']) || $_POST['evobc_action'] !== 'change_branch') {
            return;
        }

        if (!is_user_logged_in()) {
            return;
        }

        if (empty($_POST['evobc_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['evobc_nonce'])), 'ev_own_branch_change')) {
            return;
        }

        $user_id = get_current_user_id();

        $raw_code = isset($_POST['ev_branch_code']) ? wp_unslash($_POST['ev_branch_code']) : '';
        $code = sanitize_text_field($raw_code);

        // ✅ FIXED: Get branches using helper and filter
        $branches = CompanySuite_Helpers::get_branches_map();
        
        // Filter to only branches whose code starts with "1"
        $branches = array_filter($branches, function($label, $code) {
            return strpos((string)$code, '1') === 0;
        }, ARRAY_FILTER_USE_BOTH);

        if (empty($branches) || !array_key_exists($code, $branches)) {
            // Invalid code, do nothing
            return;
        }

        // Update ONLY current user's branch
        update_user_meta($user_id, EV_Elementor_Vacations::META_BRANCH, $code);

        // Allow customization of redirect URL if needed
        $redirect_url = apply_filters('company_suite/own_branch_redirect_url', home_url('/'), $user_id, $code);

        wp_safe_redirect($redirect_url);
        exit;
    }
}