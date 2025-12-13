<?php
if (!defined('ABSPATH')) exit;

class IKO_Vacation_Shortcodes {

    const OPT = 'ev_vacations_settings';

    // Must match EV_Elementor_Vacations meta keys
    const META_ANNUAL = '_ev_vac_annual_allocation';
    const META_REMAIN = '_ev_vac_remaining_days';
    const META_BRANCH = '_ev_branch';

    public static function init() {
        // Simple values
        add_shortcode('ev_remaining_days', [__CLASS__, 'sc_remaining_days']);
        add_shortcode('ev_my_branch',      [__CLASS__, 'sc_branch']);
        add_shortcode('ev_annual_days',    [__CLASS__, 'sc_annual_days']);
        add_shortcode('ev_used_days',      [__CLASS__, 'sc_used_days']);

        // Tables / dashboard
        add_shortcode('ev_my_vacations',       [__CLASS__, 'sc_vacations_table']);
        add_shortcode('ev_my_vacation_board',  [__CLASS__, 'sc_dashboard']);
    }

    private static function settings(): array {
        $s = get_option(self::OPT, []);
        return is_array($s) ? $s : [];
    }

    private static function resolved_form_id(array $atts): string {
        $s = self::settings();
        $from_sc = isset($atts['form_id']) ? trim((string)$atts['form_id']) : '';
        if ($from_sc !== '') return $from_sc;
        return trim((string)($s['form_id'] ?? '')); // can be '' => no filter
    }

    private static function val_for_submission(int $submission_id): array {
        global $wpdb;
        $tbl = $wpdb->prefix . 'e_submissions_values';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT `key`, `value` FROM {$tbl} WHERE submission_id = %d",
            $submission_id
        ), ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $k = (string)$r['key'];
            $v = $r['value'];
            $out[$k] = is_scalar($v) ? (string)$v : '';
        }
        return $out;
    }

    private static function elementor_tables_exist(): bool {
        global $wpdb;
        $t1 = $wpdb->prefix . 'e_submissions';
        $t2 = $wpdb->prefix . 'e_submissions_values';
        return ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t1)) === $t1)
            && ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t2)) === $t2);
    }

    private static function parse_days($raw): int {
        $n = (int)preg_replace('/[^0-9\\-]/', '', (string)$raw);
        return max(0, $n);
    }

    private static function parse_date($value): ?DateTime {
        $value = trim((string)$value);
        if ($value === '') return null;
        $formats = ['Y-m-d', 'd/m/Y', 'd.m.Y', 'm/d/Y', 'Y/m/d'];
        foreach ($formats as $f) {
            $dt = DateTime::createFromFormat($f, $value);
            if ($dt instanceof DateTime) {
                $dt->setTime(0,0,0);
                return $dt;
            }
        }
        $ts = strtotime($value);
        if ($ts) {
            $dt = new DateTime();
            $dt->setTimestamp($ts);
            $dt->setTime(0,0,0);
            return $dt;
        }
        return null;
    }

    private static function calc_days_between($start, $end): int {
        $ds = self::parse_date($start);
        $de = self::parse_date($end);
        if (!$ds || !$de) return 0;
        $diff = $ds->diff($de);
        return max(0, (int)$diff->days + 1); // inclusive
    }

    public static function sc_remaining_days($atts): string {
        if (!is_user_logged_in()) return '';

        $atts = shortcode_atts([
            'fallback' => '',
        ], (array)$atts);

        $user_id = get_current_user_id();
        $remain = get_user_meta($user_id, self::META_REMAIN, true);

        if ($remain === '' || $remain === null) {
            $annual = self::get_annual_days($user_id);
            $fb = trim((string)$atts['fallback']);
            return $fb !== '' ? esc_html($fb) : (string)$annual;
        }

        return (string)max(0, (int)$remain);
    }

    public static function sc_annual_days(): string {
        if (!is_user_logged_in()) return '';
        $user_id = get_current_user_id();
        return (string)self::get_annual_days($user_id);
    }

    private static function get_annual_days(int $user_id): int {
        $annual = get_user_meta($user_id, self::META_ANNUAL, true);
        if ($annual !== '' && $annual !== null) return max(0, (int)$annual);

        $s = self::settings();
        return max(0, (int)($s['default_annual'] ?? 24));
    }

    public static function sc_branch($atts): string {
        if (!is_user_logged_in()) return '';
        $atts = shortcode_atts([
            'meta_key' => self::META_BRANCH,
        ], (array)$atts);

        $user_id = get_current_user_id();
        $branch_code = get_user_meta($user_id, (string)$atts['meta_key'], true);
        return esc_html(ev_get_branch_name($branch_code));
    }

    public static function sc_used_days($atts): string {
        if (!is_user_logged_in()) return '';

        $atts = shortcode_atts([
            'form_id'      => '',
            'days_key'     => '',
            'start_key'    => '',
            'end_key'      => '',
            'status_key'   => '',
            'approved_val' => '',
        ], (array)$atts);

        $user_id = get_current_user_id();
        return (string)self::compute_used_days($user_id, $atts);
    }

    private static function compute_used_days(int $user_id, array $atts): int {
        if (!self::elementor_tables_exist()) return 0;

        global $wpdb;
        $s = self::settings();

        $form_id = self::resolved_form_id($atts);

        $days_key   = trim((string)($atts['days_key']   !== '' ? $atts['days_key']   : ($s['days_field_key'] ?? 'vacation_days')));
        $start_key  = trim((string)($atts['start_key']  !== '' ? $atts['start_key']  : ($s['start_field_key'] ?? 'vacation_start')));
        $end_key    = trim((string)($atts['end_key']    !== '' ? $atts['end_key']    : ($s['end_field_key']   ?? 'vacation_end')));
        $status_key = trim((string)($atts['status_key'] !== '' ? $atts['status_key'] : ($s['status_field_key'] ?? 'status')));
        $approved   = trim((string)($atts['approved_val'] !== '' ? $atts['approved_val'] : ($s['approved_value'] ?? '')));

        $tbl = $wpdb->prefix . 'e_submissions';
        $sql = "SELECT id FROM {$tbl} WHERE user_id = %d";
        $args = [$user_id];
        if ($form_id !== '') {
            $sql .= " AND element_id = %s";
            $args[] = $form_id;
        }
        $sql .= " ORDER BY created_at DESC LIMIT 500";
        $subs = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];

        $sum = 0;
        foreach ($subs as $sub) {
            $vals = self::val_for_submission((int)$sub['id']);

            if ($approved !== '' && $status_key !== '') {
                $st = strtolower(trim((string)($vals[$status_key] ?? '')));
                if ($st !== strtolower($approved)) continue;
            }

            $days = self::parse_days($vals[$days_key] ?? '');
            if ($days <= 0) {
                $days = self::calc_days_between($vals[$start_key] ?? '', $vals[$end_key] ?? '');
            }
            $sum += $days;
        }

        return max(0, (int)$sum);
    }

    public static function sc_vacations_table($atts): string {
        if (!is_user_logged_in()) return '<p>Please login.</p>';

        $atts = shortcode_atts([
            'form_id'     => '',
            'limit'       => 50,
            'branch_key'  => '',
            'days_key'    => '',
            'start_key'   => '',
            'end_key'     => '',
            'status_key'  => '',
        ], (array)$atts);

        if (!self::elementor_tables_exist()) {
            return '<p>Elementor submissions tables not found.</p>';
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $s = self::settings();

        $form_id = self::resolved_form_id($atts);

        $branch_key = trim((string)($atts['branch_key'] !== '' ? $atts['branch_key'] : ($s['branch_field_key'] ?? 'employee_branch')));
        $days_key   = trim((string)($atts['days_key']   !== '' ? $atts['days_key']   : ($s['days_field_key']   ?? 'vacation_days')));
        $start_key  = trim((string)($atts['start_key']  !== '' ? $atts['start_key']  : ($s['start_field_key']  ?? 'vacation_start')));
        $end_key    = trim((string)($atts['end_key']    !== '' ? $atts['end_key']    : ($s['end_field_key']    ?? 'vacation_end')));
        $status_key = trim((string)($atts['status_key'] !== '' ? $atts['status_key'] : ($s['status_field_key'] ?? 'status')));

        $tbl = $wpdb->prefix . 'e_submissions';
        $sql = "SELECT id, created_at FROM {$tbl} WHERE user_id = %d";
        $args = [$user_id];
        if ($form_id !== '') {
            $sql .= " AND element_id = %s";
            $args[] = $form_id;
        }
        $sql .= " ORDER BY created_at DESC LIMIT %d";
        $args[] = (int)$atts['limit'];

        $subs = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];
        if (!$subs) return '<p>No vacations found.</p>';

        ob_start(); ?>
        <div class="ev-vac-table-wrap">
            <table class="ev-vacations-table" style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Date</th>
                        <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Branch</th>
                        <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">From</th>
                        <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">To</th>
                        <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Days</th>
                        <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($subs as $sub):
                    $vals = self::val_for_submission((int)$sub['id']);

                    $branch_raw = $branch_key !== '' ? ($vals[$branch_key] ?? '') : '';
                    if ($branch_raw === '') {
                        $branch_raw = get_user_meta($user_id, self::META_BRANCH, true);
                    }
                    $branch = ev_get_branch_name($branch_raw);

                    $days = self::parse_days($vals[$days_key] ?? '');
                    if ($days <= 0) $days = self::calc_days_between($vals[$start_key] ?? '', $vals[$end_key] ?? '');
                ?>
                    <tr>
                        <td style="padding:8px;border-bottom:1px solid #eee;"><?php echo esc_html($sub['created_at']); ?></td>
                        <td style="padding:8px;border-bottom:1px solid #eee;"><?php echo esc_html($branch); ?></td>
                        <td style="padding:8px;border-bottom:1px solid #eee;"><?php echo esc_html($vals[$start_key] ?? ''); ?></td>
                        <td style="padding:8px;border-bottom:1px solid #eee;"><?php echo esc_html($vals[$end_key] ?? ''); ?></td>
                        <td style="padding:8px;border-bottom:1px solid #eee;"><?php echo esc_html($days); ?></td>
                        <td style="padding:8px;border-bottom:1px solid #eee;"><?php echo esc_html($vals[$status_key] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function sc_dashboard($atts): string {
        if (!is_user_logged_in()) return '<p>Please login.</p>';

        $atts = shortcode_atts([
            'form_id' => '',
        ], (array)$atts);

        $form_attr = trim((string)$atts['form_id']);
        $form_part = $form_attr !== '' ? ' form_id="'.esc_attr($form_attr).'"' : '';

        ob_start(); ?>
        <div class="ev-vac-dashboard" style="display:grid;gap:12px;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
                <div style="padding:14px;border:1px solid #eee;border-radius:12px;">
                    <div style="opacity:.7;font-size:13px;">Vacation left</div>
                    <div style="font-size:28px;font-weight:700;"><?php echo do_shortcode('[ev_remaining_days]'); ?></div>
                </div>
                <div style="padding:14px;border:1px solid #eee;border-radius:12px;">
                    <div style="opacity:.7;font-size:13px;">Branch</div>
                    <div style="font-size:18px;font-weight:600;"><?php echo do_shortcode('[ev_my_branch]'); ?></div>
                </div>
            </div>
            <div>
                <?php echo do_shortcode('[ev_my_vacations'.$form_part.']'); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

IKO_Vacation_Shortcodes::init();
