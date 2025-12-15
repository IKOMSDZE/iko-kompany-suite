<?php
if (!defined('ABSPATH')) exit;

class EV_Elementor_Vacations {
    const OPT = 'ev_vacations_settings';

    // User meta keys (store CODES for position/branch)
    const META_ANNUAL   = '_ev_vac_annual_allocation';
    const META_REMAIN   = '_ev_vac_remaining_days';
    const META_YEAR     = '_ev_vac_last_reset_year';
    const META_POSITION = '_ev_vac_position';
    const META_BRANCH   = '_ev_branch';
    const META_BIRTHDAY = '_ev_birthday';
    const META_PHONE    = '_ev_phone';
    const META_BDAY_SENT_YEAR = '_ev_bday_sms_year_sent';

    /* ================= Activation / Deactivation ================= */

    public static function activate() {
        if (!get_option(self::OPT)) {
            add_option(self::OPT, [
                'form_id'            => '',
                'days_field_key'     => 'vacation_days',
                'user_field_key'     => '',
                'block_if_insuff'    => 1,
                'default_annual'     => 24,
                'target'             => 'current',
                'positions'          => [],
                'branches'           => [],
                'branch_codes'       => [],
                'birthdays_enable'   => 1,
                'sms_enabled'        => 1,
                'sms_api_key'        => '',
                'sms_sender'         => '',
                'sms_admin_phone'    => '',
                'sms_user_template'  => 'Happy Birthday, {name}! 🎉',
                'sms_admin_template' => 'დღეს არის {count} თანამშრომლის დაბადების დღე: {list}',
                'sms_send_hour'      => 11,
                'branch_home_map_raw'=> "",
                'start_field_key'    => 'start_date',
                'end_field_key'      => 'date_to_include',
                'type_field_key'     => 'vacation_type',
                'working_days_key'   => 'vacation_days',
                'calendar_days_key'  => 'number_of_calendar_days',
                'status_field_key'   => 'status',
                'branch_field_key'   => 'employee_branch',
            ]);
        }
        if (!wp_next_scheduled('ev_vacations_daily_event')) {
            wp_schedule_event(time() + 60, 'daily', 'ev_vacations_daily_event');
        }
        $opt  = get_option(self::OPT, []);
        $hour = isset($opt['sms_send_hour']) ? max(0, min(23, (int)$opt['sms_send_hour'])) : 11;
        if (!wp_next_scheduled('ev_birthdays_daily_event')) {
            wp_schedule_event(self::next_tbilisi_time_stamp($hour, 0), 'daily', 'ev_birthdays_daily_event');
        }
    }

    public static function deactivate() {
        $ts = wp_next_scheduled('ev_vacations_daily_event');
        if ($ts) wp_unschedule_event($ts, 'ev_vacations_daily_event');
        $ts2 = wp_next_scheduled('ev_birthdays_daily_event');
        if ($ts2) wp_unschedule_event($ts2, 'ev_birthdays_daily_event');
    }

    /* ================= Bootstrap ================= */

    public function __construct() {
        // Admin/Profile
        add_action('show_user_profile', [$this, 'profile_fields']);
        add_action('edit_user_profile',  [$this, 'profile_fields']);
        add_action('personal_options_update', [$this, 'save_profile_fields']);
        add_action('edit_user_profile_update', [$this, 'save_profile_fields']);

        // Settings + Logs menu
        add_action('admin_menu',  [$this, 'add_admin_menus']);
        add_action('admin_init',  [$this, 'register_settings']);

        // Elementor form hooks
        add_action('elementor_pro/forms/validation',  [$this, 'validate_vacation_days_limit'], 10, 2);
        add_action('elementor_pro/forms/new_record',  [$this, 'on_elementor_form'], 10, 2);

        // Cron ensure & yearly reset
        add_action('init', [$this, 'ensure_cron']);
        add_action('ev_vacations_daily_event', [$this, 'maybe_reset_all_users']);
        add_action('init', [$this, 'maybe_reset_all_users']);

        // Branch-based homepage redirect
        add_action('template_redirect', [$this, 'maybe_redirect_branch_home']);

        // REST
        add_action('init',          [$this, 'register_user_meta_rest']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('update_option_' . self::OPT, [$this, 'on_settings_saved'], 10, 2);

        // Shortcodes
        add_shortcode('vacation_days',    [$this, 'shortcode_vacation_days']);
        add_shortcode('ev_vacation_days', [$this, 'shortcode_vacation_days']);
        add_shortcode('ev_user_admin',    [$this, 'shortcode_user_admin']);

        // Birthday SMS cron
        add_action('ev_birthdays_daily_event', [$this, 'cron_send_birthday_sms']);

        // Admin-post test handlers
        add_action('admin_post_ev_sms_test',       [$this, 'handle_sms_test']);
        add_action('admin_post_ev_sms_run_daily',  [$this, 'handle_sms_run_daily']);

        // Global delete logging
        add_action('deleted_user', [$this, 'on_deleted_user'], 10, 3);

        // Elementor control noise workaround
        add_action('elementor/controls/register', function(){
            if (defined('WP_DEBUG') && WP_DEBUG) @ini_set('display_errors', '0');
        }, 1000);

        // Datepicker
        add_action('admin_enqueue_scripts', [$this, 'enqueue_bday_picker']);
        add_action('wp_enqueue_scripts',    [$this, 'enqueue_bday_picker']);
    }

    /* ================= Helpers ================= */

    private static function next_tbilisi_time_stamp(int $hour = 11, int $minute = 0): int {
        try {
            $tz  = new DateTimeZone('Asia/Tbilisi');
            $now = new DateTime('now', $tz);
            $run = (clone $now)->setTime($hour, $minute, 0);
            if ($run <= $now) { $run->modify('+1 day'); }
            return $run->getTimestamp();
        } catch (\Throwable $e) {
            return time() + 60;
        }
    }

    public function ensure_cron() {
        if (!wp_next_scheduled('ev_vacations_daily_event')) {
            wp_schedule_event(time() + 60, 'daily', 'ev_vacations_daily_event');
        }
        $opt  = get_option(self::OPT, []);
        $hour = isset($opt['sms_send_hour']) ? max(0, min(23, (int)$opt['sms_send_hour'])) : 11;
        if (!wp_next_scheduled('ev_birthdays_daily_event')) {
            wp_schedule_event(self::next_tbilisi_time_stamp($hour, 0), 'daily', 'ev_birthdays_daily_event');
        }
    }

    private function get_user_annual_allocation(int $user_id): int {
    return CompanySuite_Helpers::get_user_annual_days($user_id);
    }

    private function get_user_remaining(int $user_id): int {
        $remain = get_user_meta($user_id, self::META_REMAIN, true);
        if ($remain === '') {
            $this->maybe_reset_user($user_id, (int) current_time('Y'));
            $remain = get_user_meta($user_id, self::META_REMAIN, true);
        }
        return max(0, (int)$remain);
    }

    private function get_full_name($u): string {
        $first = get_user_meta($u->ID, 'first_name', true);
        $last  = get_user_meta($u->ID, 'last_name', true);
        $full  = trim(($first ? $first : '').' '.($last ? $last : ''));
        if ($full !== '') return $full;
        return $u->display_name ?: $u->user_email;
    }


   

    /* ================= Yearly Reset ================= */

    public function maybe_reset_all_users() {
        $current_year = (int) current_time('Y');
        $args = ['fields' => ['ID'], 'number' => 200, 'offset' => 0];
        do {
            $users = get_users($args);
            if (empty($users)) break;
            foreach ($users as $u) $this->maybe_reset_user((int)$u->ID, $current_year);
            $args['offset'] += $args['number'];
        } while (count($users) === $args['number']);
    }

    private function maybe_reset_user(int $user_id, int $year) {
        $last_reset = (int) get_user_meta($user_id, self::META_YEAR, true);
        if ($last_reset === $year) return;
        $annual = $this->get_user_annual_allocation($user_id);
        update_user_meta($user_id, self::META_REMAIN, (int)$annual);
        update_user_meta($user_id, self::META_YEAR, $year);
    }

    /* ================= Resolve user from form ================= */

    private function resolve_user_from_field($value): int {
        $val = trim((string)$value);
        if ($val === '') return 0;
        if (ctype_digit($val)) { $u = get_user_by('id', (int)$val); if ($u) return (int)$u->ID; }
        if (is_email($val))    { $u = get_user_by('email', $val);   if ($u) return (int)$u->ID; }
        $u = get_user_by('login', $val); if ($u) return (int)$u->ID;
        return 0;
    }

    private function find_target_user_id_from_submission(array $fields, array $settings): int {
        $candidates = [];
        $user_key = trim((string)($settings['user_field_key'] ?? ''));
        if ($user_key !== '') $candidates[] = $user_key;
        foreach (['employee_name','employee','user','user_id','userId','employee_id'] as $k) {
            if (!in_array($k, $candidates, true)) $candidates[] = $k;
        }
        foreach ($candidates as $k) {
            if (isset($fields[$k]) && $fields[$k] !== '') {
                $uid = $this->resolve_user_from_field($fields[$k]);
                if ($uid > 0) return $uid;
            }
        }
        foreach (['user_email','email'] as $k) {
            if (isset($fields[$k]) && is_email($fields[$k])) {
                $u = get_user_by('email', $fields[$k]);
                if ($u) return (int)$u->ID;
            }
        }
        foreach ($fields as $v) {
            $val = is_scalar($v) ? (string)$v : '';
            if ($val !== '' && ctype_digit($val)) {
                $u = get_user_by('id', (int)$val);
                if ($u) return (int)$u->ID;
            }
        }
        return 0;
    }

    /* ================= Form matching helper ================= */

    private function form_matches(string $form_match, $record): bool {
    return CompanySuite_Helpers::form_matches($form_match, $record);
}

    /* ================= Elementor Validation ================= */

    public function validate_vacation_days_limit( $record, $ajax_handler ) {
        $settings   = get_option(self::OPT, []);
        $form_match = trim((string)($settings['form_id'] ?? ''));

        // Check if this form matches our target
        if (!$this->form_matches($form_match, $record)) {
            return;
        }

        // Flatten fields
        $raw_fields = (array) $record->get('fields');
        $fields = [];
        foreach ($raw_fields as $id => $data) {
            $fields[$id] = is_array($data) ? ($data['value'] ?? ($data['raw_value'] ?? '')) : $data;
        }

        $user_id = $this->find_target_user_id_from_submission($fields, $settings);
        if ($user_id <= 0) {
            if (method_exists($ajax_handler, 'add_error'))        { $ajax_handler->add_error('form', __('Please select an employee.', 'ev-vac')); }
            if (method_exists($ajax_handler, 'add_error_message')) { $ajax_handler->add_error_message(__('Employee selection is required.', 'ev-vac')); }
            if (method_exists($ajax_handler, 'remove_action'))     { $ajax_handler->remove_action('email'); $ajax_handler->remove_action('webhook'); $ajax_handler->remove_action('redirect'); }
            return;
        }

        // Enforce only for earned leave (radio '1')
        $vac_type_key = 'vacation_type';
        $is_earned    = !isset($fields[$vac_type_key]) || (string)$fields[$vac_type_key] === '1';
        if ( ! $is_earned ) {
            return;
        }

        $days_key = trim($settings['days_field_key'] ?? 'vacation_days');
        $days_raw = (string)($fields[$days_key] ?? '');
        $days     = (int) preg_replace('/[^\d\-]/', '', $days_raw);
        if ($days <= 0) {
            return;
        }

        $this->maybe_reset_user($user_id, (int) current_time('Y'));
        $remaining = $this->get_user_remaining($user_id);

        if ( ! empty($settings['block_if_insuff']) && $days > $remaining ) {
            if (method_exists($ajax_handler, 'add_error'))        { $ajax_handler->add_error($days_key, __('Requested vacation days exceed remaining balance.', 'ev-vac')); }
            if (method_exists($ajax_handler, 'add_error_message')) { $ajax_handler->add_error_message(__('Not enough vacation days remaining.', 'ev-vac')); }
            if (method_exists($ajax_handler, 'remove_action'))     { $ajax_handler->remove_action('email'); $ajax_handler->remove_action('webhook'); $ajax_handler->remove_action('redirect'); }
            return;
        }
    }

    /* ================= Elementor Handling (deduct after pass) ================= */

    public function on_elementor_form( $record, $handler ) {
        if ( ! class_exists('\ElementorPro\Plugin') ) {
            return;
        }

        $settings     = get_option(self::OPT, []);
        $days_key     = trim($settings['days_field_key'] ?? 'vacation_days');
        $form_id_set  = trim($settings['form_id'] ?? '');
        $vac_type_key = 'vacation_type';

        // Check if this form matches our target
        if (!$this->form_matches($form_id_set, $record)) {
            return;
        }

        $raw_fields = (array) $record->get('fields');
        $fields = [];
        foreach ($raw_fields as $id => $data) {
            $fields[$id] = is_array($data) ? ($data['value'] ?? ($data['raw_value'] ?? '')) : $data;
        }

        $user_id = $this->find_target_user_id_from_submission($fields, $settings);
        if ($user_id <= 0) {
            if (method_exists($handler, 'add_error_message')) { $handler->add_error_message(__('Please select an employee.', 'ev-vac')); }
            if (method_exists($handler, 'add_error'))         { $handler->add_error('form', __('Employee is required.', 'ev-vac')); }
            if (method_exists($handler, 'remove_action'))     { $handler->remove_action('email'); $handler->remove_action('webhook'); }
            return;
        }

        // Only deduct for earned leave
        $vac_type = isset($fields[$vac_type_key]) ? (string)$fields[$vac_type_key] : '';
        if ($vac_type !== '1') {
            return;
        }

        $days_raw = $fields[$days_key] ?? '';
        $days     = (int) preg_replace('/[^\d\-]/', '', (string)$days_raw);
        if ($days <= 0) {
            return;
        }

        $remaining = $this->get_user_remaining($user_id);

        if ( ! empty($settings['block_if_insuff']) && $days > $remaining ) {
            if (method_exists($handler, 'add_error_message')) { $handler->add_error_message(__('Not enough vacation days remaining.', 'ev-vac')); }
            if (method_exists($handler, 'add_error'))         { $handler->add_error($days_key ?: 'form', __('Not enough vacation days.', 'ev-vac')); }
            if (method_exists($handler, 'remove_action'))     { $handler->remove_action('email'); $handler->remove_action('webhook'); }
            return;
        }

        $new_remaining = max(0, $remaining - $days);
        update_user_meta($user_id, self::META_REMAIN, $new_remaining);

        // Log with form identifier for debugging
        $form_identifier = (string)$record->get_form_settings('id') ?: (string)$record->get_form_settings('form_id') ?: (string)$record->get_form_settings('form_name');
        
        if (method_exists($record, 'add_note')) {
            $record->add_note(sprintf('EV: Deducted %d day(s) from user #%d (earned leave). Remaining: %d. Form: %s', $days, $user_id, $new_remaining, $form_identifier));
        }
    }

    /* ================= REST ================= */

    public function register_user_meta_rest() {
        register_meta('user', self::META_POSITION, [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => function($val){ return preg_replace('/[^0-9]/', '', (string)$val); },
            'auth_callback'     => function($allowed, $meta_key, $object_id) {
                return current_user_can('edit_user', $object_id);
            },
        ]);
        register_meta('user', self::META_BRANCH, [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => function($val){ return preg_replace('/[^0-9]/', '', (string)$val); },
            'auth_callback'     => function($allowed, $meta_key, $object_id) {
                return current_user_can('edit_user', $object_id);
            },
        ]);
    }

    public function register_rest_routes() {
        register_rest_route('company-suite/v1', '/maps', [
            'methods'  => 'GET',
            'permission_callback' => function(){ return current_user_can('list_users'); },
            'callback' => function() {
                return new WP_REST_Response([
                    'positions' => CompanySuite_Helpers::get_positions_map(),
                    'branches'  => CompanySuite_Helpers::get_branches_map(),
                ], 200);
            }
        ]);
    }

    
   public function on_settings_saved($old, $new) {
    CompanySuite_Helpers::clear_cache();    
    do_action('company_suite/lists_updated', [
        'old_positions' => $old['positions'] ?? [],
            'new_positions' => $new['positions'] ?? [],
            'old_branches'  => $old['branches']  ?? [],
            'new_branches'  => $new['branches']  ?? [],
        ]);
        $hour = isset($new['sms_send_hour']) ? max(0, min(23, (int)$new['sms_send_hour'])) : 11;
        $ts = wp_next_scheduled('ev_birthdays_daily_event');
        if ($ts) wp_unschedule_event($ts, 'ev_birthdays_daily_event');
        wp_schedule_event(self::next_tbilisi_time_stamp($hour, 0), 'daily', 'ev_birthdays_daily_event');
    }

    /* ================= Profile fields ================= */

    public function profile_fields($user) {
    if (!current_user_can('list_users')) return;
   
    $pos_code = CompanySuite_Helpers::normalize_position_code(get_user_meta($user->ID, self::META_POSITION, true));
    $br_code  = CompanySuite_Helpers::normalize_branch_code(get_user_meta($user->ID, self::META_BRANCH,  true));
    
    if ($pos_code !== '' && $pos_code !== get_user_meta($user->ID, self::META_POSITION, true)) update_user_meta($user->ID, self::META_POSITION, $pos_code);
    if ($br_code  !== '' && $br_code  !== get_user_meta($user->ID, self::META_BRANCH,  true)) update_user_meta($user->ID, self::META_BRANCH,  $br_code);

    $annual    = get_user_meta($user->ID, self::META_ANNUAL, true);
    $remaining = get_user_meta($user->ID, self::META_REMAIN, true);
    $year      = (int) get_user_meta($user->ID, self::META_YEAR, true);
    $birthday  = get_user_meta($user->ID, self::META_BIRTHDAY, true);
    $phone     = get_user_meta($user->ID, self::META_PHONE, true);

    $annual    = ($annual === '') ? '' : (int)$annual;
    $remaining = ($remaining === '') ? '' : (int)$remaining;

    
    $pmap = CompanySuite_Helpers::get_positions_map();
    $bmap = CompanySuite_Helpers::get_branches_map();
    
    $opt  = get_option(self::OPT, []);
    $bd_on = !empty($opt['birthdays_enable']);

   
    $birthday_ui = CompanySuite_Helpers::format_birthday((string)$birthday);

        ?>
        <h2><?php esc_html_e('Employee Details', 'ev-vac'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="ev_position"><?php esc_html_e('Position', 'ev-vac'); ?></label></th>
                <td>
                    <select name="ev_position" id="ev_position">
                        <option value=""><?php esc_html_e('— Select —', 'ev-vac'); ?></option>
                        <?php foreach ($pmap as $code=>$label): ?>
                            <option value="<?php echo esc_attr($code); ?>" <?php selected($pos_code, $code); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('Select a position. Internal codes are stored invisibly.', 'ev-vac'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ev_branch"><?php esc_html_e('Branch', 'ev-vac'); ?></label></th>
                <td>
                    <select name="ev_branch" id="ev_branch">
                        <option value=""><?php esc_html_e('— Select —', 'ev-vac'); ?></option>
                        <?php foreach ($bmap as $code=>$label): ?>
                            <option value="<?php echo esc_attr($code); ?>" <?php selected($br_code, $code); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('Select a branch. Internal codes are stored invisibly.', 'ev-vac'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ev_phone"><?php esc_html_e('Phone', 'ev-vac'); ?></label></th>
                <td><input type="text" id="ev_phone" name="ev_phone" value="<?php echo esc_attr($phone); ?>" class="regular-text" placeholder="+9955XXXXXXXX, +9955YYYYYYYY"></td>
            </tr>
            <?php if ($bd_on): ?>
            <tr>
                <th><label for="ev_birthday"><?php esc_html_e('Birthday', 'ev-vac'); ?></label></th>
                <td>
                    <input type="text"
                           class="ev-bday"
                           name="ev_birthday"
                           id="ev_birthday"
                           value="<?php echo esc_attr($birthday_ui); ?>"
                           placeholder="dd/mm/yyyy"
                           inputmode="numeric"
                           autocomplete="off">
                    <p class="description"><?php esc_html_e('Use format: dd/mm/yyyy (e.g., 05/11/1999)', 'ev-vac'); ?></p>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <th><label for="ev_annual"><?php esc_html_e('Annual Allocation (days)', 'ev-vac'); ?></label></th>
                <td>
                    <input name="ev_annual" id="ev_annual" type="number" min="0" step="1" value="<?php echo esc_attr($annual); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('Default is 24 if left blank.', 'ev-vac'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ev_remaining"><?php esc_html_e('Remaining Days (this year)', 'ev-vac'); ?></label></th>
                <td>
                    <input name="ev_remaining" id="ev_remaining" type="number" min="0" step="1" value="<?php echo esc_attr($remaining); ?>" class="regular-text">
                    <p class="description"><?php printf(esc_html__('Last reset year: %d', 'ev-vac'), $year ?: 0); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_profile_fields($user_id) {
    if (!current_user_can('edit_user', $user_id)) return false;

    if (isset($_POST['ev_position'])) {
        $code = sanitize_text_field(wp_unslash($_POST['ev_position']));
        $this->set_user_position_code($user_id, $code);
    }
    if (isset($_POST['ev_branch'])) {
        $code = sanitize_text_field(wp_unslash($_POST['ev_branch']));
        $this->set_user_branch_code($user_id, $code);
    }
    if (isset($_POST['ev_phone'])) {
        $val = wp_unslash($_POST['ev_phone']);
        
        // OLD CODE - REPLACE THIS:
        // $val = $this->normalize_phone_csv_two($val);
        
        // NEW CODE - USE THIS:
        $val = CompanySuite_Helpers::normalize_phone($val);
        
        if ($val === '') delete_user_meta($user_id, self::META_PHONE);
        else update_user_meta($user_id, self::META_PHONE, $val);
    }
    if (isset($_POST['ev_birthday'])) {
        $raw  = sanitize_text_field(wp_unslash($_POST['ev_birthday']));
        
        // OLD CODE - REPLACE THIS:
        // $norm = $this->parse_ui_birthday($raw);
        
        // NEW CODE - USE THIS:
        $norm = CompanySuite_Helpers::normalize_birthday($raw);
        
        if ($norm === '') delete_user_meta($user_id, self::META_BIRTHDAY);
        else update_user_meta($user_id, self::META_BIRTHDAY, $norm);
    }
    if (isset($_POST['ev_annual'])) {
        $raw = wp_unslash($_POST['ev_annual']);
        if ($raw === '' || $raw === null) delete_user_meta($user_id, self::META_ANNUAL);
        else update_user_meta($user_id, self::META_ANNUAL, max(0, (int)$raw));
    }
    if (isset($_POST['ev_remaining'])) {
        $remaining = max(0, (int) wp_unslash($_POST['ev_remaining']));
        update_user_meta($user_id, self::META_REMAIN, $remaining);
        if (!get_user_meta($user_id, self::META_YEAR, true)) {
            update_user_meta($user_id, self::META_YEAR, (int) current_time('Y'));
        }
    }
}

    /* ================= Settings & Logs Pages ================= */

    public function add_admin_menus() {
        add_menu_page(
            __('Company Suite', 'ev-vac'),
            __('Company Suite', 'ev-vac'),
            'manage_options',
            'ev-vacations',
            [$this, 'render_settings_page'],
            'dashicons-groups',
            58
        );
        add_submenu_page(
            'ev-vacations',
            __('Settings', 'ev-vac'),
            __('Settings', 'ev-vac'),
            'manage_options',
            'ev-vacations',
            [$this, 'render_settings_page']
        );
        add_submenu_page(
            'ev-vacations',
            __('Logs', 'ev-vac'),
            __('Logs', 'ev-vac'),
            'manage_options',
            'ev-vac-logs',
            [$this, 'render_logs_page']
        );
    }

    public function register_settings() {
        register_setting(self::OPT, self::OPT, function($input){
            $out = [];
            $out['form_id']         = isset($input['form_id']) ? sanitize_text_field($input['form_id']) : '';
            $out['days_field_key']  = isset($input['days_field_key']) ? sanitize_key($input['days_field_key']) : 'vacation_days';
            $out['user_field_key']  = isset($input['user_field_key']) ? sanitize_key($input['user_field_key']) : '';
            $out['block_if_insuff'] = !empty($input['block_if_insuff']) ? 1 : 0;
            $out['default_annual']  = max(0, (int)($input['default_annual'] ?? 24));
            $out['target']          = ($input['target'] ?? 'current');

            // Field mappings
            $out['start_field_key']    = isset($input['start_field_key']) ? sanitize_key($input['start_field_key']) : 'start_date';
            $out['end_field_key']      = isset($input['end_field_key']) ? sanitize_key($input['end_field_key']) : 'date_to_include';
            $out['type_field_key']     = isset($input['type_field_key']) ? sanitize_key($input['type_field_key']) : 'vacation_type';
            $out['working_days_key']   = isset($input['working_days_key']) ? sanitize_key($input['working_days_key']) : 'vacation_days';
            $out['calendar_days_key']  = isset($input['calendar_days_key']) ? sanitize_key($input['calendar_days_key']) : 'number_of_calendar_days';
            $out['status_field_key']   = isset($input['status_field_key']) ? sanitize_key($input['status_field_key']) : 'status';
            $out['branch_field_key']   = isset($input['branch_field_key']) ? sanitize_key($input['branch_field_key']) : 'employee_branch';

            $positions_raw = isset($input['positions_text']) ? (string)$input['positions_text'] : '';
            $pos = [];
            foreach (preg_split('/\r\n|\r|\n/', $positions_raw) as $line) {
                $label = trim(wp_strip_all_tags($line));
                if ($label !== '' && !in_array($label, $pos, true)) $pos[] = $label;
            }
            $out['positions'] = $pos;

            $branches_raw = isset($input['branches_text']) ? (string)$input['branches_text'] : '';
            $br    = [];
            $codes = [];
            foreach (preg_split('/\r\n|\r|\n/', $branches_raw) as $line) {
                $line = trim((string)$line);
                if ($line === '') {
                    continue;
                }

                $code  = '';
                $label = $line;

                if (strpos($line, '|') !== false) {
                    list($c, $l) = array_map('trim', explode('|', $line, 2));
                    if ($l !== '') {
                        $label = $l;
                        $code  = preg_replace('/\D+/', '', $c);
                    }
                }

                $label = trim(wp_strip_all_tags($label));
                if ($label === '') {
                    continue;
                }

                $br[]    = $label;
                $codes[] = $code;
            }
            $out['branches']     = $br;
            $out['branch_codes'] = $codes;

            $out['birthdays_enable'] = !empty($input['birthdays_enable']) ? 1 : 0;

            $out['sms_enabled']        = !empty($input['sms_enabled']) ? 1 : 0;
            $out['sms_api_key']        = isset($input['sms_api_key'])     ? sanitize_text_field($input['sms_api_key'])   : '';
            $out['sms_sender']         = isset($input['sms_sender'])      ? preg_replace('/[^A-Za-z0-9 ]/', '', substr($input['sms_sender'], 0, 11)) : '';
            $out['sms_admin_phone']    = isset($input['sms_admin_phone']) ? preg_replace('/[^0-9+\s\-\(\),]/', '', $input['sms_admin_phone']) : '';
            $out['sms_user_template']  = isset($input['sms_user_template'])  ? wp_kses_post($input['sms_user_template'])  : 'Happy Birthday, {name}! 🎉';
            $out['sms_admin_template'] = isset($input['sms_admin_template']) ? wp_kses_post($input['sms_admin_template']) : 'დღეს არის {count} თანამშრომლის დაბადების დღე: {list}';
            $out['sms_send_hour']      = isset($input['sms_send_hour']) ? max(0, min(23, (int)$input['sms_send_hour'])) : 11;

            $out['branch_home_map_raw'] = isset($input['branch_home_map_raw']) ? $this->sanitize_branch_home_map_raw((string)$input['branch_home_map_raw']) : '';

            return $out;
        });
    }

    public function render_settings_page() {
        $opt = get_option(self::OPT, []);
        $positions_text = implode("\n", array_map('strval', (array)($opt['positions'] ?? [])));
        $branches_labels = (array)($opt['branches'] ?? []);
        $branches_codes  = (array)($opt['branch_codes'] ?? []);
        $branches_lines  = [];
        foreach ($branches_labels as $i => $label) {
            $label = (string)$label;
            if ($label === '') {
                continue;
            }
            $code = isset($branches_codes[$i]) ? trim((string)$branches_codes[$i]) : '';
            if ($code !== '') {
                $branches_lines[] = $code . ' | ' . $label;
            } else {
                $branches_lines[] = $label;
            }
        }
        $branches_text  = implode("\n", $branches_lines);
        $tz_label = 'Asia/Tbilisi';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Company Suite — Settings', 'ev-vac'); ?></h1>

            <?php if (isset($_GET['ev_sms_test'])): ?>
                <div class="notice notice-info"><p><?php echo esc_html($_GET['ev_sms_test']); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['ev_sms_daily'])): ?>
                <div class="notice notice-info"><p><?php esc_html_e('Birthday check executed.', 'ev-vac'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::OPT); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="form_id"><?php esc_html_e('Elementor Form ID or Name', 'ev-vac'); ?></label></th>
                        <td>
                            <input name="<?php echo self::OPT; ?>[form_id]" id="form_id" class="regular-text" type="text" value="<?php echo esc_attr($opt['form_id'] ?? ''); ?>">
                            <p class="description"><?php esc_html_e('Can be form_id, form_name, or element_id (like 99ffe91). Leave empty to match all forms.', 'ev-vac'); ?></p>
                        </td>
                    </tr>
                    
                    <tr><th colspan="2"><h2 style="margin-top:20px;"><?php esc_html_e('Form Field Mappings', 'ev-vac'); ?></h2></th></tr>
                    
                    <tr>
                        <th scope="row"><label for="start_field_key"><?php esc_html_e('Start Date Field ID', 'ev-vac'); ?></label></th>
                        <td>
                            <input name="<?php echo self::OPT; ?>[start_field_key]" id="start_field_key" class="regular-text" type="text" value="<?php echo esc_attr($opt['start_field_key'] ?? 'start_date'); ?>">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="end_field_key"><?php esc_html_e('End Date Field ID', 'ev-vac'); ?></label></th>
                        <td>
                            <input name="<?php echo self::OPT; ?>[end_field_key]" id="end_field_key" class="regular-text" type="text" value="<?php echo esc_attr($opt['end_field_key'] ?? 'date_to_include'); ?>">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="type_field_key"><?php esc_html_e('Vacation Type Field ID', 'ev-vac'); ?></label></th>
                        <td>
                            <input name="<?php echo self::OPT; ?>[type_field_key]" id="type_field_key" class="regular-text" type="text" value="<?php echo esc_attr($opt['type_field_key'] ?? 'vacation_type'); ?>">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="working_days_key"><?php esc_html_e('Working Days Field ID', 'ev-vac'); ?></label></th>
                        <td>
                            <input name="<?php echo self::OPT; ?>[working_days_key]" id="working_days_key" class="regular-text" type="text" value="<?php echo esc_attr($opt['working_days_key'] ?? 'vacation_days'); ?>">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="calendar_days_key"><?php esc_html_e('Calendar Days Field ID', 'ev-vac'); ?></label></th>
                        <td>
                            <input name="<?php echo self::OPT; ?>[calendar_days_key]" id="calendar_days_key" class="regular-text" type="text" value="<?php echo esc_attr($opt['calendar_days_key'] ?? 'number_of_calendar_days'); ?>">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="status_field_key"><?php esc_html_e('Status Field ID', 'ev-vac'); ?></label></th>
                        <td>
                            <input name="<?php echo self::OPT; ?>[status_field_key]" id="status_field_key" class="regular-text" type="text" value="<?php echo esc_attr($opt['status_field_key'] ?? 'status'); ?>">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="branch_field_key"><?php esc_html_e('Branch Field ID', 'ev-vac'); ?></label></th>
                        <td>
                            <input name="<?php echo self::OPT; ?>[branch_field_key]" id="branch_field_key" class="regular-text" type="text" value="<?php echo esc_attr($opt['branch_field_key'] ?? 'employee_branch'); ?>">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="days_field_key"><?php esc_html_e('Days Field ID (for deduction)', 'ev-vac'); ?></label></th>
                        <td>
                            <input name="<?php echo self::OPT; ?>[days_field_key]" id="days_field_key" class="regular-text" type="text" value="<?php echo esc_attr($opt['days_field_key'] ?? 'vacation_days'); ?>">
                            <p class="description"><?php esc_html_e('Used for deducting days from user balance', 'ev-vac'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="user_field_key"><?php esc_html_e('Employee Field ID', 'ev-vac'); ?></label></th>
                        <td>
                            <input name="<?php echo self::OPT; ?>[user_field_key]" id="user_field_key" class="regular-text" type="text" value="<?php echo esc_attr($opt['user_field_key'] ?? 'employee_name'); ?>">
                            <p class="description"><?php esc_html_e('Custom ID of the Employee select (optional)', 'ev-vac'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="default_annual"><?php esc_html_e('Default Annual Allocation', 'ev-vac'); ?></label></th>
                        <td><input name="<?php echo self::OPT; ?>[default_annual]" id="default_annual" type="number" min="0" step="1" value="<?php echo esc_attr($opt['default_annual'] ?? 24); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Block if insufficient days', 'ev-vac'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo self::OPT; ?>[block_if_insuff]" value="1" <?php checked(!empty($opt['block_if_insuff'])); ?>> <?php esc_html_e('Prevent submission if requested days exceed remaining.', 'ev-vac'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="positions_text"><?php esc_html_e('Positions (one per line)', 'ev-vac'); ?></label></th>
                        <td>
                            <textarea name="<?php echo self::OPT; ?>[positions_text]" id="positions_text" rows="6" cols="50" class="large-text code"><?php echo esc_textarea($positions_text); ?></textarea>
                            <p class="description"><?php esc_html_e('Order defines the internal codes (001, 002, 003…)', 'ev-vac'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="branches_text"><?php esc_html_e('Branches (one per line)', 'ev-vac'); ?></label></th>
                        <td>
                            <textarea name="<?php echo self::OPT; ?>[branches_text]" id="branches_text" rows="6" cols="50" class="large-text code"><?php echo esc_textarea($branches_text); ?></textarea>
                            <p class="description"><?php esc_html_e('Format: 1001 | Branch Name or just Branch Name', 'ev-vac'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ev_branch_home_map_raw"><?php esc_html_e('Branch Home Pages', 'ev-vac'); ?></label></th>
                        <td>
                            <textarea name="<?php echo self::OPT; ?>[branch_home_map_raw]" id="ev_branch_home_map_raw" rows="5" cols="50" class="large-text code"><?php echo esc_textarea($opt['branch_home_map_raw'] ?? ''); ?></textarea>
                            <p class="description"><?php esc_html_e('Format: BRANCH_CODE = PAGE_ID (e.g. 1001 = 123)', 'ev-vac'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Birthdays', 'ev-vac'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo self::OPT; ?>[birthdays_enable]" value="1" <?php checked(!empty($opt['birthdays_enable'])); ?>> <?php esc_html_e('Enable Birthday field & filters.', 'ev-vac'); ?></label></td>
                    </tr>

                    <tr><th colspan="2"><h2 style="margin-top:20px;"><?php esc_html_e('Birthday SMS', 'ev-vac'); ?></h2></th></tr>

                    <tr>
                      <th scope="row"><?php esc_html_e('Enable Birthday SMS', 'ev-vac'); ?></th>
                      <td><label><input type="checkbox" name="<?php echo self::OPT; ?>[sms_enabled]" value="1" <?php checked(!empty($opt['sms_enabled'])); ?>>
                        <?php esc_html_e('Send SMS to users on their birthday', 'ev-vac'); ?></label></td>
                    </tr>

                    <tr>
                      <th scope="row"><label for="sms_send_hour"><?php esc_html_e('Send Time', 'ev-vac'); ?></label></th>
                      <td>
                        <select id="sms_send_hour" name="<?php echo self::OPT; ?>[sms_send_hour]">
                          <?php for ($h=0;$h<=23;$h++): ?>
                            <option value="<?php echo $h; ?>" <?php selected((int)($opt['sms_send_hour'] ?? 11), $h); ?>>
                              <?php printf('%02d:00 %s', $h, esc_html($tz_label)); ?>
                            </option>
                          <?php endfor; ?>
                        </select>
                      </td>
                    </tr>

                    <tr>
                      <th scope="row"><label for="sms_api_key"><?php esc_html_e('SMSOffice API Key', 'ev-vac'); ?></label></th>
                      <td><input type="text" id="sms_api_key" class="regular-text" name="<?php echo self::OPT; ?>[sms_api_key]" value="<?php echo esc_attr($opt['sms_api_key'] ?? ''); ?>"></td>
                    </tr>

                    <tr>
                      <th scope="row"><label for="sms_sender"><?php esc_html_e('Sender (max 11 chars)', 'ev-vac'); ?></label></th>
                      <td><input type="text" id="sms_sender" class="regular-text" maxlength="11" name="<?php echo self::OPT; ?>[sms_sender]" value="<?php echo esc_attr($opt['sms_sender'] ?? ''); ?>"></td>
                    </tr>

                    <tr>
                      <th scope="row"><label for="sms_admin_phone"><?php esc_html_e('Admin Phone', 'ev-vac'); ?></label></th>
                      <td><input type="text" id="sms_admin_phone" class="regular-text" name="<?php echo self::OPT; ?>[sms_admin_phone]" value="<?php echo esc_attr($opt['sms_admin_phone'] ?? ''); ?>"></td>
                    </tr>

                    <tr>
                      <th scope="row"><label for="sms_user_template"><?php esc_html_e('User Message Template', 'ev-vac'); ?></label></th>
                      <td>
                        <textarea id="sms_user_template" name="<?php echo self::OPT; ?>[sms_user_template]" rows="3" class="large-text code"><?php echo esc_textarea($opt['sms_user_template'] ?? 'Happy Birthday, {name}! 🎉'); ?></textarea>
                        <p class="description">
                          <?php esc_html_e('Placeholders: {name}, {first_name}, {last_name}', 'ev-vac'); ?>
                        </p>
                      </td>
                    </tr>

                    <tr>
                      <th scope="row"><label for="sms_admin_template"><?php esc_html_e('Admin Summary Template', 'ev-vac'); ?></label></th>
                      <td>
                        <textarea id="sms_admin_template" name="<?php echo self::OPT; ?>[sms_admin_template]" rows="3" class="large-text code"><?php echo esc_textarea($opt['sms_admin_template'] ?? 'დღეს არის {count} თანამშრომლის დაბადების დღე: {list}'); ?></textarea>
                        <p class="description"><?php esc_html_e('Placeholders: {count}, {list}', 'ev-vac'); ?></p>
                      </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>

            <h2><?php esc_html_e('Birthday SMS — Tools', 'ev-vac'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
                <?php wp_nonce_field('ev_sms_test'); ?>
                <input type="hidden" name="action" value="ev_sms_test">
                <input type="text" name="ev_test_phone" placeholder="+9955XXXXXXX" class="regular-text" required>
                <input type="text" name="ev_test_message" placeholder="<?php esc_attr_e('Test message', 'ev-vac'); ?>" class="regular-text" value="Test from Company Suite">
                <button class="button button-primary" type="submit"><?php esc_html_e('Send Test SMS', 'ev-vac'); ?></button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ev_sms_run_daily'); ?>
                <input type="hidden" name="action" value="ev_sms_run_daily">
                <label><input type="checkbox" name="ev_force_send" value="1"> <?php esc_html_e('Force today (clear sent flags)', 'ev-vac'); ?></label>
                <button class="button" type="submit"><?php esc_html_e('Run birthday check now', 'ev-vac'); ?></button>
            </form>
        </div>
        <?php
    }

    public function render_logs_page() {
        if (!current_user_can('manage_options')) return;
        $base = defined('IKO_CS_DIR') ? IKO_CS_DIR : plugin_dir_path(__FILE__);
        $files = ['deletion.log','birthday.log'];
        if (isset($_POST['ev_clear_log'], $_POST['ev_log_name']) && check_admin_referer('ev_clear_log')) {
            $name = basename(sanitize_text_field($_POST['ev_log_name']));
            if (in_array($name, $files, true)) {
                @file_put_contents($base.$name, '');
                echo '<div class="notice notice-success"><p>'.esc_html__('Log cleared.', 'ev-vac').'</p></div>';
            }
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Company Suite — Logs', 'ev-vac'); ?></h1>
            <p><?php esc_html_e('View or download logs below.', 'ev-vac'); ?></p>
            <?php foreach ($files as $fname): ?>
                <?php $path = $base.$fname; $size = file_exists($path) ? filesize($path) : 0; ?>
                <h2><?php echo esc_html($fname); ?> (<?php echo esc_html(size_format($size)); ?>)</h2>
                <p>
                    <?php if ($size > 0): ?>
                        <a class="button" href="<?php echo esc_url(plugins_url(basename($path), __FILE__)); ?>" download><?php esc_html_e('Download', 'ev-vac'); ?></a>
                    <?php else: ?>
                        <em><?php esc_html_e('Empty', 'ev-vac'); ?></em>
                    <?php endif; ?>
                </p>
                <pre style="max-height:400px;overflow:auto;background:#fff;border:1px solid #ddd;padding:10px;"><?php
                    if ($size > 0) echo esc_html(file_get_contents($path)); else echo esc_html__('(no entries)', 'ev-vac');
                ?></pre>
                <form method="post" style="margin-bottom:24px;">
                    <?php wp_nonce_field('ev_clear_log'); ?>
                    <input type="hidden" name="ev_log_name" value="<?php echo esc_attr($fname); ?>">
                    <button class="button button-secondary" name="ev_clear_log" value="1" type="submit" onclick="return confirm('<?php echo esc_js(__('Clear this log?', 'ev-vac')); ?>');"><?php esc_html_e('Clear log', 'ev-vac'); ?></button>
                </form>
                <hr>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /* ================= Admin helpers ================= */

    private function ensure_user_admin_functions_loaded(): void {
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        if (is_multisite() && !function_exists('wpmu_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/ms.php';
        }
    }

    private function can_delete_user_row(int $target_user_id): bool {
        if (!is_user_logged_in()) return false;
        if ($target_user_id === get_current_user_id()) return false;
        $u = get_userdata($target_user_id);
        if (!$u) return false;
        if (is_array($u->roles) && in_array('administrator', $u->roles, true)) {
            return current_user_can('manage_options');
        }
        return true;
    }

    /* ================= Shortcodes ================= */

    public function shortcode_vacation_days($atts = []) {
        $a = shortcode_atts([
            'user_id' => '',
            'format'  => '%d/%d',
            'label'   => '',
        ], $atts, 'vacation_days');

        $user_id = 0;
        if ($a['user_id'] !== '' && ctype_digit((string)$a['user_id'])) $user_id = (int)$a['user_id'];
        elseif (is_user_logged_in()) $user_id = get_current_user_id();

        if ($user_id <= 0) return '';
        $this->maybe_reset_user($user_id, (int) current_time('Y'));
        $remaining = $this->get_user_remaining($user_id);
        $annual    = $this->get_user_annual_allocation($user_id);

        $core = sprintf($a['format'], (int)$remaining, (int)$annual);
        if ($a['label'] !== '') {
            return sprintf(esc_html($a['label']), esc_html($core));
        }
        return esc_html($core);
    }

    public function shortcode_user_admin($atts = []) {

    if (isset($_POST['_evua_nonce']) && wp_verify_nonce($_POST['_evua_nonce'], 'evua_update') && (!isset($_POST['evua_action']) || $_POST['evua_action'] === 'save')) {
        $edit_user = isset($_POST['evua_user']) ? (int) $_POST['evua_user'] : 0;
        if ($edit_user > 0 && current_user_can('edit_user', $edit_user)) {
            $pos_code      = isset($_POST['evua_position'])   ? sanitize_text_field(wp_unslash($_POST['evua_position']))   : '';
            $br_code       = isset($_POST['evua_branch'])     ? sanitize_text_field(wp_unslash($_POST['evua_branch']))     : '';
            $new_phone     = isset($_POST['evua_phone'])      ? wp_unslash($_POST['evua_phone'])                            : '';
            $new_annual    = isset($_POST['evua_annual'])     ? max(0, (int) $_POST['evua_annual'])                         : null;
            $new_remaining = isset($_POST['evua_remaining'])  ? max(0, (int) $_POST['evua_remaining'])                      : null;
            $new_birthday_raw  = isset($_POST['evua_birthday'])   ? sanitize_text_field(wp_unslash($_POST['evua_birthday']))   : '';

            $this->set_user_position_code($edit_user, $pos_code);
            $this->set_user_branch_code($edit_user,   $br_code);

           
            $new_phone = CompanySuite_Helpers::normalize_phone($new_phone);
            
            if ($new_phone === '') delete_user_meta($edit_user, self::META_PHONE);
            else update_user_meta($edit_user, self::META_PHONE, $new_phone);

            if ($new_annual !== null)   update_user_meta($edit_user, self::META_ANNUAL, $new_annual);
            if ($new_remaining !== null) {
                update_user_meta($edit_user, self::META_REMAIN, $new_remaining);
                if (!get_user_meta($edit_user, self::META_YEAR, true)) {
                    update_user_meta($edit_user, self::META_YEAR, (int) current_time('Y'));
                }
            }

            if ($new_birthday_raw === '') {
                delete_user_meta($edit_user, self::META_BIRTHDAY);
            } else {
               
                $norm = CompanySuite_Helpers::normalize_birthday($new_birthday_raw);
                
                if ($norm !== '') update_user_meta($edit_user, self::META_BIRTHDAY, $norm);
            }

            add_action('wp_footer', function(){
                echo '<div class="evua-flash ok">', esc_html__('Saved.', 'ev-vac') ,'</div>';
            });
        }
    }
    
   
    $pmap = CompanySuite_Helpers::get_positions_map();
    $bmap = CompanySuite_Helpers::get_branches_map();
    
    
    foreach ($users as $u) {
       
        $pc = CompanySuite_Helpers::normalize_position_code(get_user_meta($u->ID, self::META_POSITION, true));
        $bc = CompanySuite_Helpers::normalize_branch_code(get_user_meta($u->ID, self::META_BRANCH,  true));
        
        if ($pc !== '' && $pc !== get_user_meta($u->ID, self::META_POSITION, true)) update_user_meta($u->ID, self::META_POSITION, $pc);
        if ($bc !== '' && $bc !== get_user_meta($u->ID, self::META_BRANCH,  true)) update_user_meta($u->ID, self::META_BRANCH,  $bc);
    }

            // SAVE
            if (isset($_POST['_evua_nonce']) && wp_verify_nonce($_POST['_evua_nonce'], 'evua_update') && (!isset($_POST['evua_action']) || $_POST['evua_action'] === 'save')) {
                $edit_user = isset($_POST['evua_user']) ? (int) $_POST['evua_user'] : 0;
                if ($edit_user > 0 && current_user_can('edit_user', $edit_user)) {
                    $pos_code      = isset($_POST['evua_position'])   ? sanitize_text_field(wp_unslash($_POST['evua_position']))   : '';
                    $br_code       = isset($_POST['evua_branch'])     ? sanitize_text_field(wp_unslash($_POST['evua_branch']))     : '';
                    $new_phone     = isset($_POST['evua_phone'])      ? wp_unslash($_POST['evua_phone'])                            : '';
                    $new_annual    = isset($_POST['evua_annual'])     ? max(0, (int) $_POST['evua_annual'])                         : null;
                    $new_remaining = isset($_POST['evua_remaining'])  ? max(0, (int) $_POST['evua_remaining'])                      : null;
                    $new_birthday_raw  = isset($_POST['evua_birthday'])   ? sanitize_text_field(wp_unslash($_POST['evua_birthday']))   : '';

                    $this->set_user_position_code($edit_user, $pos_code);
                    $this->set_user_branch_code($edit_user,   $br_code);

                    $new_phone = $this->CompanySuite_Helpers::get_positions_map()($new_phone);
                    if ($new_phone === '') delete_user_meta($edit_user, self::META_PHONE);
                    else update_user_meta($edit_user, self::META_PHONE, $new_phone);

                    if ($new_annual !== null)   update_user_meta($edit_user, self::META_ANNUAL, $new_annual);
                    if ($new_remaining !== null) {
                        update_user_meta($edit_user, self::META_REMAIN, $new_remaining);
                        if (!get_user_meta($edit_user, self::META_YEAR, true)) {
                            update_user_meta($edit_user, self::META_YEAR, (int) current_time('Y'));
                        }
                    }

                    if ($new_birthday_raw === '') {
                        delete_user_meta($edit_user, self::META_BIRTHDAY);
                    } else {
                        $norm = CompanySuite_Helpers::normalize_birthday($new_birthday_raw);
                        if ($norm !== '') update_user_meta($edit_user, self::META_BIRTHDAY, $norm);
                    }

                    add_action('wp_footer', function(){
                        echo '<div class="evua-flash ok">', esc_html__('Saved.', 'ev-vac') ,'</div>';
                    });
                } else {
                    add_action('wp_footer', function(){
                        echo '<div class="evua-flash err">', esc_html__('Permission denied.', 'ev-vac') ,'</div>';
                    });
                }
            }
        }

        // ===== Filters & query =====
        $s         = isset($_GET['s'])   ? sanitize_text_field(wp_unslash($_GET['s']))   : '';
        $filterPos = isset($_GET['pos']) ? sanitize_text_field(wp_unslash($_GET['pos'])) : '';
        $filterBr  = isset($_GET['br'])  ? sanitize_text_field(wp_unslash($_GET['br']))  : '';
        $bdMonth   = isset($_GET['bdm']) ? (int) $_GET['bdm'] : 0;
        $bdSoon    = !empty($_GET['bdsoon']) ? 1 : 0;
        $paged     = max(1, (int) ($_GET['paged'] ?? 1));
        $per_page  = 20;
        $offset    = ($paged - 1) * $per_page;

        $args = [
            'number'         => $per_page,
            'offset'         => $offset,
            'fields'         => 'all_with_meta',
            'search'         => $s ? '*'.esc_sql($s).'*' : '',
            'search_columns' => ['user_login','user_nicename','user_email','display_name'],
            'count_total'    => true,
        ];

        $meta_query = [];
        if ($filterPos !== '') $meta_query[] = ['key' => self::META_POSITION, 'value' => $filterPos, 'compare' => '='];
        if ($filterBr  !== '') $meta_query[] = ['key' => self::META_BRANCH,  'value' => $filterBr,  'compare' => '='];

        $opt = get_option(self::OPT, []);
        $bd_on = !empty($opt['birthdays_enable']);
        if ($bd_on && $bdMonth >= 1 && $bdMonth <= 12) {
            $mm = sprintf('-%02d-', $bdMonth);
            $meta_query[] = ['key' => self::META_BIRTHDAY, 'value' => $mm, 'compare' => 'LIKE'];
        }
        if (!empty($meta_query)) {
            $args['meta_query'] = count($meta_query) > 1 ? array_merge(['relation' => 'AND'], $meta_query) : $meta_query;
        }

        $user_query = new WP_User_Query($args);
        $users = (array) $user_query->get_results();
        $total = (int) $user_query->get_total();

        // --- filter "birthdays in next 30 days" ---
        if ($bd_on && $bdSoon) {
            $self = $this;

            $users = array_values(array_filter($users, function($u) use ($self) {
                $bd = get_user_meta($u->ID, EV_Elementor_Vacations::META_BIRTHDAY, true);
                $md = $self->md_from_birthday((string)$bd);
                if (!$md) return false;

                try {
                    $today = new \DateTime(current_time('Y-m-d'));
                } catch (\Throwable $e) {
                    $today = new \DateTime('now');
                }

                $y = (int) $today->format('Y');
                list($mm, $dd) = array_map('intval', explode('-', $md));
                $next = \DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $y, $mm, $dd));
                if (!$next) return false;

                if ($next < $today) {
                    $next->modify('+1 year');
                }

                $diff_days = (int) $today->diff($next)->format('%a');
                return $diff_days <= 30;
            }));

            $total = count($users);
        }

        $max_pages = max(1, (int) ceil($total / $per_page));
        $pmap = CompanySuite_Helpers::get_positions_map();
        $bmap = CompanySuite_Helpers::get_branches_map();

        // Normalize stored codes
        foreach ($users as $u) {
            $pc = CompanySuite_Helpers::normalize_position_code(get_user_meta($u->ID, self::META_POSITION, true));
            $bc = CompanySuite_Helpers::normalize_branch_code(get_user_meta($u->ID, self::META_BRANCH,  true));
            if ($pc !== '' && $pc !== get_user_meta($u->ID, self::META_POSITION, true)) update_user_meta($u->ID, self::META_POSITION, $pc);
            if ($bc !== '' && $bc !== get_user_meta($u->ID, self::META_BRANCH,  true)) update_user_meta($u->ID, self::META_BRANCH,  $bc);
        }

        ob_start();
        ?>
        <style>
          .evua-table{width:100%;border-collapse:collapse;margin:12px 0}
          .evua-table th,.evua-table td{border:1px solid #ddd;padding:6px 8px;vertical-align:top}
          .evua-wrap .toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
          .evua-wrap .toolbar input,.evua-wrap .toolbar select{min-height:32px}
          .evua-actions{display:flex;gap:6px;align-items:center}
          .evua-flash{position:fixed;right:16px;bottom:16px;padding:10px 14px;border-radius:6px;background:#fff;border:1px solid #ddd;box-shadow:0 2px 8px rgba(0,0,0,.08);z-index:9999}
          .evua-flash.ok{border-color:#46b450}
          .evua-flash.err{border-color:#d63638}
          .evua-pager{margin-top:10px}
          .evua-pager a,.evua-pager span{display:inline-block;padding:4px 8px;border:1px solid #ddd;margin-right:4px;text-decoration:none}
          .evua-pager .current{background:#f0f0f0}
          .evua-badge{display:inline-block;padding:2px 6px;border:1px solid #ddd;border-radius:4px;background:#fafafa}
          .evua-small{font-size:12px;color:#666}
        </style>

        <div class="evua-wrap">
          <form class="toolbar" method="get">
            <?php
              foreach (['page_id','p','page'] as $keep) {
                  if (isset($_GET[$keep])) {
                      echo '<input type="hidden" name="'.esc_attr($keep).'" value="'.esc_attr($_GET[$keep]).'">';
                  }
              }
            ?>
            <input type="text" name="s" value="<?php echo esc_attr($s); ?>" placeholder="<?php esc_attr_e('Search user…', 'ev-vac'); ?>">
            <select name="pos">
              <option value=""><?php esc_html_e('All positions', 'ev-vac'); ?></option>
              <?php foreach($pmap as $code=>$label): ?>
                <option value="<?php echo esc_attr($code); ?>" <?php selected($filterPos,$code); ?>><?php echo esc_html($label); ?></option>
              <?php endforeach; ?>
            </select>
            <select name="br">
              <option value=""><?php esc_html_e('All branches', 'ev-vac'); ?></option>
              <?php foreach($bmap as $code=>$label): ?>
                <option value="<?php echo esc_attr($code); ?>" <?php selected($filterBr,$code); ?>><?php echo esc_html($label); ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($bd_on): ?>
              <select name="bdm">
                <option value="0"><?php esc_html_e('All months', 'ev-vac'); ?></option>
                <?php for ($m=1;$m<=12;$m++): ?>
                  <option value="<?php echo $m; ?>" <?php selected($bdMonth, $m); ?>><?php echo esc_html(date_i18n('F', mktime(0,0,0,$m,1))); ?></option>
                <?php endfor; ?>
              </select>
              <label class="evua-small"><input type="checkbox" name="bdsoon" value="1" <?php checked($bdSoon,1); ?>> <?php esc_html_e('Birthdays in next 30 days', 'ev-vac'); ?></label>
            <?php endif; ?>
            <button class="button"><?php esc_html_e('Filter', 'ev-vac'); ?></button>
          </form>

          <?php if (!$users): ?>
            <p><em><?php esc_html_e('No users found with the current filters.', 'ev-vac'); ?></em></p>
          <?php else: ?>
            <table class="evua-table">
              <thead>
                <tr>
                  <th><?php esc_html_e('User', 'ev-vac'); ?></th>
                  <th><?php esc_html_e('Email / Phone', 'ev-vac'); ?></th>
                  <th><?php esc_html_e('Position / Branch', 'ev-vac'); ?></th>
                  <th><?php esc_html_e('Annual / Remaining', 'ev-vac'); ?></th>
                  <?php if ($bd_on): ?><th><?php esc_html_e('Birthday', 'ev-vac'); ?></th><?php endif; ?>
                  <th><?php esc_html_e('Actions', 'ev-vac'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $u): ?>
                  <?php
                    $uid   = (int)$u->ID;
                    $name  = $this->get_full_name($u);
                    $email = $u->user_email;

                    $pos_code = get_user_meta($uid, self::META_POSITION, true);
                    $br_code  = get_user_meta($uid, self::META_BRANCH,   true);
                    $pos_lab  = isset($pmap[$pos_code]) ? $pmap[$pos_code] : '';
                    $br_lab   = isset($bmap[$br_code])  ? $bmap[$br_code]  : '';

                    $annual    = get_user_meta($uid, self::META_ANNUAL, true);
                    $remaining = get_user_meta($uid, self::META_REMAIN, true);
                    $annual    = ($annual === '') ? '' : (int)$annual;
                    $remaining = ($remaining === '') ? '' : (int)$remaining;

                    $phone     = (string) get_user_meta($uid, self::META_PHONE, true);
                    $bday_raw  = (string) get_user_meta($uid, self::META_BIRTHDAY, true);
                    $bday_ui   = CompanySuite_Helpers::format_birthday($bday_raw);
                    $can_delete = $this->can_delete_user_row($uid);
                  ?>
                  <tr>
                    <td>
                      <strong><?php echo esc_html($name); ?></strong><br>
                      <span class="evua-small">ID: <?php echo (int)$uid; ?> · <?php echo esc_html($u->user_login); ?></span>
                    </td>
                    <td>
                      <form method="post" class="evua-rowform" style="margin:0;display:flex;flex-direction:column;gap:6px;">
                        <?php wp_nonce_field('evua_update', '_evua_nonce'); ?>
                        <input type="hidden" name="evua_user" value="<?php echo (int)$uid; ?>">
                        <input type="email" value="<?php echo esc_attr($email); ?>" disabled>
                        <input type="text" name="evua_phone" value="<?php echo esc_attr($phone); ?>" placeholder="+9955XXXXXXX, +9955YYYYYYY">
                    </td>
                    <td>
                        <select name="evua_position">
                          <option value=""><?php esc_html_e('— Select —', 'ev-vac'); ?></option>
                          <?php foreach ($pmap as $code=>$lab): ?>
                            <option value="<?php echo esc_attr($code); ?>" <?php selected($pos_code,$code); ?>><?php echo esc_html($lab); ?></option>
                          <?php endforeach; ?>
                        </select>
                        <select name="evua_branch" style="margin-top:6px;">
                          <option value=""><?php esc_html_e('— Select —', 'ev-vac'); ?></option>
                          <?php foreach ($bmap as $code=>$lab): ?>
                            <option value="<?php echo esc_attr($code); ?>" <?php selected($br_code,$code); ?>><?php echo esc_html($lab); ?></option>
                          <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="number" min="0" step="1" name="evua_annual" value="<?php echo esc_attr($annual); ?>" placeholder="24">
                        <input type="number" min="0" step="1" name="evua_remaining" value="<?php echo esc_attr($remaining); ?>" placeholder="24" style="margin-top:6px;">
                    </td>
                    <?php if ($bd_on): ?>
                    <td>
                        <input type="text" class="ev-bday" name="evua_birthday" value="<?php echo esc_attr($bday_ui); ?>" placeholder="dd/mm/yyyy" inputmode="numeric" autocomplete="off">
                    </td>
                    <?php endif; ?>
                    <td>
                        <div class="evua-actions">
                          <button class="button button-primary" type="submit" name="evua_action" value="save"><?php esc_html_e('Save', 'ev-vac'); ?></button>
                          <?php if ($can_delete): ?>
                            <?php $del_nonce = wp_create_nonce('evua_delete_' . $uid); ?>
                            <button class="button" type="submit" name="evua_action" value="delete" onclick="return confirm('<?php echo esc_js(__('Delete this user permanently?', 'ev-vac')); ?>');"><?php esc_html_e('Delete', 'ev-vac'); ?></button>
                            <input type="hidden" name="_evua_del" value="<?php echo esc_attr($del_nonce); ?>">
                          <?php else: ?>
                            <span class="evua-badge"><?php esc_html_e('Cannot delete', 'ev-vac'); ?></span>
                          <?php endif; ?>
                        </div>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

            <?php if ($max_pages > 1): ?>
              <div class="evua-pager">
                <?php
                  $link_base = remove_query_arg(['paged']);
                  for ($p=1; $p <= $max_pages; $p++) {
                      $url = add_query_arg('paged', $p, $link_base);
                      if ($p == $paged) {
                          echo '<span class="current">'.(int)$p.'</span>';
                      } else {
                          echo '<a href="'.esc_url($url).'">'.(int)$p.'</a>';
                      }
                  }
                ?>
              </div>
            <?php endif; ?>

          <?php endif; ?>
        </div>

        <script>
          setTimeout(function(){
            document.querySelectorAll('.evua-flash').forEach(function(el){ el.parentNode && el.parentNode.removeChild(el); });
          }, 3500);
          jQuery(function($){ $(document).trigger('evua:rows-updated', [document]); });
        </script>
        <?php
        return ob_get_clean();
    }

    private function set_user_position_code(int $user_id, string $code): void {
    $code = trim($code);
    
    // OLD CODE - REPLACE THIS:
    // $pmap = $this->positions_map();
    
    // NEW CODE - USE THIS:
    $pmap = CompanySuite_Helpers::get_positions_map();
    
    if ($code !== '' && !isset($pmap[$code])) return;
    $old = get_user_meta($user_id, self::META_POSITION, true);
    if ($old === $code) return;
    update_user_meta($user_id, self::META_POSITION, $code);
    $label = $code !== '' && isset($pmap[$code]) ? $pmap[$code] : '';
    do_action('company_suite/position_updated', $user_id, (string)$old, $code, $label, $pmap);
}

private function set_user_branch_code(int $user_id, string $code): void {
    $code = trim($code);
    
    // OLD CODE - REPLACE THIS:
    // $bmap = $this->branches_map();
    
    // NEW CODE - USE THIS:
    $bmap = CompanySuite_Helpers::get_branches_map();
    
    if ($code !== '' && !isset($bmap[$code])) return;
    $old = get_user_meta($user_id, self::META_BRANCH, true);
    if ($old === $code) return;
    update_user_meta($user_id, self::META_BRANCH, $code);
    $label = $code !== '' && isset($bmap[$code]) ? $bmap[$code] : '';
    do_action('company_suite/branch_updated', $user_id, (string)$old, $code, $label, $bmap);
}
    /* ================= Birthday SMS ================= */

    private function sms_send(string $phone, string $message, string $sender, string $api_key) {
        if ($phone === '' || $message === '' || $sender === '' || $api_key === '') return new WP_Error('sms_args', 'Missing SMS args');
        $url = add_query_arg([
            'key'        => $api_key,
            'destination'=> $phone,
            'sender'     => $sender,
            'content'    => $message,
            'urgent'     => 'true',
        ], 'https://smsoffice.ge/api/v2/send/');

        $resp = wp_remote_get($url, ['timeout' => 20]);
        if (is_wp_error($resp)) return $resp;

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);
        return ($code >= 200 && $code < 300) ? ($json ?: $body) : new WP_Error('sms_http', 'HTTP '.$code, ['body'=>$body]);
    }


private function bday_template(string $tpl, array $vars): string {
    $out = $tpl;
    foreach ($vars as $k=>$v) $out = str_replace('{'.$k.'}', $v, $out);
    return $out;
}

public function cron_send_birthday_sms() {
    $opt = get_option(self::OPT, []);
    if (empty($opt['birthdays_enable']) || empty($opt['sms_enabled'])) return;

    $api_key     = trim((string)($opt['sms_api_key'] ?? ''));
    $sender      = trim((string)($opt['sms_sender'] ?? ''));
    $admin_phone = trim((string)($opt['sms_admin_phone'] ?? ''));
    $tpl_user    = (string)($opt['sms_user_template']  ?? 'Happy Birthday, {name}! 🎉');
    $tpl_admin   = (string)($opt['sms_admin_template'] ?? 'დღეს არის {count} თანამშრომლის დაბადების დღე: {list}');

    if ($api_key === '' || $sender === '') {
        $this->append_log_file('birthday.log', sprintf("[%s] SKIP: Missing sender/api.\n", current_time('Y-m-d H:i:s')));
        return;
    }

    try { $tz = new \DateTimeZone('Asia/Tbilisi'); } catch (\Throwable $e) { $tz = null; }
    $todayObj = $tz ? new \DateTime('now', $tz) : new \DateTime('now');
    $todayY   = (int) $todayObj->format('Y');
    $todayMD  = $todayObj->format('m-d');

    $args = ['number' => 500, 'offset' => 0, 'fields' => ['ID']];
    $sent_names = [];

    do {
        $q = new \WP_User_Query($args);
        $users = (array) $q->get_results();
        if (!$users) break;

        foreach ($users as $u) {
            $uid = (int) $u->ID;
            $raw = (string) get_user_meta($uid, self::META_BIRTHDAY, true);
            
        
            $md  = CompanySuite_Helpers::get_birthday_month_day($raw);
            
            if (!$md) continue;
            if ($md !== $todayMD) continue;

            $sent_year = (int) get_user_meta($uid, self::META_BDAY_SENT_YEAR, true);
            if ($sent_year === $todayY) continue;

            $user  = get_userdata($uid);
            if (!$user) continue;

            $name  = $this->get_full_name($user);
            $first = trim((string) get_user_meta($uid, 'first_name', true));
            $last  = trim((string) get_user_meta($uid, 'last_name',  true));

           
            $phones_raw = trim((string) get_user_meta($uid, self::META_PHONE, true));
            if ($phones_raw !== '') {
                $msg = $this->bday_template($tpl_user, [
                    'name'        => $name,
                    'first_name'  => ($first !== '' ? $first : $name),
                    'last_name'   => $last,
                ]);

                $targets = array_map('trim', explode(',', CompanySuite_Helpers::normalize_phone($phones_raw)));
                
                $any_sent = false;
                foreach ($targets as $phone_one) {
                    if ($phone_one === '') continue;
                    $r = $this->sms_send($phone_one, $msg, $sender, $api_key);
                    if (is_wp_error($r)) {
                        $this->append_log_file('birthday.log', sprintf("[%s] ERROR UID %d (%s): %s\n", current_time('Y-m-d H:i:s'), $uid, $phone_one, $r->get_error_message()));
                    } else {
                        $this->append_log_file('birthday.log', sprintf("[%s] SENT UID %d (%s)\n", current_time('Y-m-d H:i:s'), $uid, $phone_one));
                        $any_sent = true;
                    }
                }

                if ($any_sent) {
                    update_user_meta($uid, self::META_BDAY_SENT_YEAR, $todayY);
                    $sent_names[] = $name;
                }
            } else {
                $this->append_log_file('birthday.log', sprintf("[%s] SKIP UID %d: no phone\n", current_time('Y-m-d H:i:s'), $uid));
            }
        }

        $args['offset'] += $args['number'];
    } while (count($users) === $args['number']);

    if ($sent_names && $admin_phone !== '') {
        $count = count($sent_names);
        $list  = implode(', ', $sent_names);
        $admin_msg = $this->bday_template($tpl_admin, ['count' => (string)$count, 'list' => $list]);
        $r = $this->sms_send($admin_phone, $admin_msg, $sender, $api_key);
        if (is_wp_error($r)) {
            $this->append_log_file('birthday.log', sprintf("[%s] ADMIN ERROR: %s\n", current_time('Y-m-d H:i:s'), $r->get_error_message()));
        } else {
            $this->append_log_file('birthday.log', sprintf("[%s] ADMIN SENT: %d users\n", current_time('Y-m-d H:i:s'), $count));
        }
    }
}

public function handle_sms_test() {
    if (!current_user_can('manage_options') || !check_admin_referer('ev_sms_test')) {
        wp_die(__('Permission denied.', 'ev-vac'));
    }

    $opt = get_option(self::OPT, []);
    $api = trim((string)($opt['sms_api_key'] ?? ''));
    $snd = trim((string)($opt['sms_sender']  ?? ''));

    $to  = isset($_POST['ev_test_phone'])   ? preg_replace('/[^0-9+\s\-\(\)]/', '', wp_unslash($_POST['ev_test_phone'])) : '';
    $msg = isset($_POST['ev_test_message']) ? wp_unslash($_POST['ev_test_message']) : '';

    $res  = $this->sms_send($to, $msg, $snd, $api);
    $text = is_wp_error($res) ? ('ERROR: '.$res->get_error_message()) : 'OK';

    wp_safe_redirect(add_query_arg('ev_sms_test', rawurlencode($text), admin_url('admin.php?page=ev-vacations')));
    exit;
}

public function handle_sms_run_daily() {
    if (!current_user_can('manage_options') || !check_admin_referer('ev_sms_run_daily')) {
        wp_die(__('Permission denied.', 'ev-vac'));
    }
    $force = !empty($_POST['ev_force_send']);
    if ($force) {
        try { $tz = new DateTimeZone('Asia/Tbilisi'); } catch (\Throwable $e) { $tz = null; }
        $todayMD = ($tz ? new DateTime('now', $tz) : new DateTime('now'))->format('m-d');

        $q = new WP_User_Query(['number' => 9999, 'fields' => ['ID']]);
        foreach ((array)$q->get_results() as $u) {
            $uid = (int)$u->ID;
            
          
            $md  = CompanySuite_Helpers::get_birthday_month_day((string)get_user_meta($uid, self::META_BIRTHDAY, true));
            
            if ($md === $todayMD) {
                delete_user_meta($uid, self::META_BDAY_SENT_YEAR);
            }
        }
        $this->append_log_file('birthday.log', sprintf("[%s] FORCE: cleared sent flags for today\n", current_time('Y-m-d H:i:s')));
    }

    $this->cron_send_birthday_sms();
    wp_safe_redirect(add_query_arg('ev_sms_daily', rawurlencode('ran'), admin_url('admin.php?page=ev-vacations')));
    exit;
}

private function append_log_file(string $filename, string $line): void {
    $file = plugin_dir_path(__FILE__) . $filename;
    @file_put_contents($file, $line, FILE_APPEND);
}

public function on_deleted_user($deleted_user_id, $reassign = null, $user_obj = null) {
    if (get_transient('ev_del_guard_'.$deleted_user_id)) {
        delete_transient('ev_del_guard_'.$deleted_user_id);
    }

    if (function_exists('clean_user_cache')) clean_user_cache((int)$deleted_user_id);
    wp_cache_delete((int)$deleted_user_id, 'users');
    wp_cache_delete((int)$deleted_user_id, 'user_meta');

    $target_name  = $user_obj ? ($user_obj->display_name ?: $user_obj->user_login) : ('#'.$deleted_user_id);
    $target_email = $user_obj ? $user_obj->user_email : '';
    $deleter      = wp_get_current_user();
    $line = sprintf(
        "[%s] User %s (ID %d; %s) deleted by %s (ID %d)\n",
        current_time('Y-m-d H:i:s'),
        $target_name,
        (int)$deleted_user_id,
        $target_email,
        $deleter && $deleter->ID ? $deleter->user_login : 'system',
        $deleter && $deleter->ID ? (int)$deleter->ID : 0
    );
    $this->append_log_file('deletion.log', $line);
}

/* ================= Datepicker ================= */

public function enqueue_bday_picker($hook = '') {
    $need = is_admin();
    if (!$need && !is_admin()) {
        $post_id = get_queried_object_id();
        if ($post_id) {
            $content = get_post_field('post_content', $post_id);
            if ($content && has_shortcode($content, 'ev_user_admin')) {
                $need = true;
            }
        }
    }
    if (!$need) return;

    wp_enqueue_style( 'flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css', [], '4.6.13' );
    wp_enqueue_script('flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.js', ['jquery'], '4.6.13', true);
    wp_enqueue_script('flatpickr-ka', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/ka.js', ['flatpickr'], '4.6.13', true);

    $init = <<<'JS'
jQuery(function($){
function initBdayPicker(ctx){
var $inputs = $('.ev-bday', ctx || document);
if (!$inputs.length || typeof flatpickr === 'undefined') return;
$inputs.each(function(){
if (this.hasAttribute('data-fp')) return;
this.setAttribute('data-fp','1');
flatpickr(this, {
dateFormat: 'd/m/Y',
allowInput: true,
locale: (window.flatpickr && window.flatpickr.l10ns && window.flatpickr.l10ns.ka) ? window.flatpickr.l10ns.ka : 'ka',
altInput: false
});
});
}
initBdayPicker(document);
$(document).on('evua:rows-updated', function(e, ctx){ initBdayPicker(ctx || document); });
});
JS;
wp_add_inline_script('flatpickr-ka', $init);
}
private function sanitize_branch_home_map_raw(string $raw): string {
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $clean = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;

        $branch_code = preg_replace('/\D+/', '', trim($parts[0]));
        $page_id     = (int) trim($parts[1]);

        if ($branch_code === '' || $page_id <= 0) continue;

        $clean[] = $branch_code . ' = ' . $page_id;
    }

    return implode("\n", $clean);
}

private function get_branch_home_map(): array {
    $opt = get_option(self::OPT, []);
    $raw = isset($opt['branch_home_map_raw']) ? (string)$opt['branch_home_map_raw'] : '';

    $map = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;

        $branch_code = preg_replace('/\D+/', '', trim($parts[0]));
        $page_id     = (int) trim($parts[1]);

        if ($branch_code === '' || $page_id <= 0) continue;

        $map[$branch_code] = $page_id;
    }

    return $map;
}

public function maybe_redirect_branch_home(): void {
    if (!is_user_logged_in()) {
        return;
    }

    if (!is_front_page() && !is_home()) {
        return;
    }

    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return;
    }

    $branch_code = get_user_meta($user_id, self::META_BRANCH, true);
    $branch_code = is_string($branch_code) ? preg_replace('/\D+/', '', $branch_code) : '';

    if ($branch_code === '') {
        return;
    }

    $map = $this->get_branch_home_map();
    if (empty($map[$branch_code])) {
        return;
    }

    $page_id = (int) $map[$branch_code];
    if ($page_id <= 0) {
        return;
    }

    if (is_page($page_id)) {
        return;
    }

    $url = get_permalink($page_id);
    if (!$url) {
        return;
    }

    $url = apply_filters('company_suite/branch_home_redirect_url', $url, $branch_code, $user_id, $page_id);
    if (!$url) {
        return;
    }

    wp_safe_redirect($url);
    exit;
}
}