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
        $n = (int)preg_replace('/[^\d\-]/', '', (string)$raw);
        return max(0, $n);
    }
    
    private static function calc_days_between($start, $end): int {
        return CompanySuite_Helpers::calc_days_between($start, $end);
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
        
        
        return esc_html(CompanySuite_Helpers::get_branch_name($branch_code));
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
        $start_key  = trim((string)($atts['start_key']  !== '' ? $atts['start_key']  : ($s['start_field_key'] ?? 'start_date')));
        $end_key    = trim((string)($atts['end_key']    !== '' ? $atts['end_key']    : ($s['end_field_key']   ?? 'date_to_include')));
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
        if (!is_user_logged_in()) return '<p>გთხოვთ შეხვიდეთ სისტემაში.</p>';

        $atts = shortcode_atts([
            'form_id'          => '',
            'limit'            => 50,
            'branch_key'       => '',
            'start_key'        => '',
            'end_key'          => '',
            'type_key'         => '',
            'working_days_key' => '',
            'calendar_days_key'=> '',
            'status_key'       => '',
        ], (array)$atts);

        if (!self::elementor_tables_exist()) {
            return '<p>Elementor submissions tables not found.</p>';
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $s = self::settings();

        $form_id = self::resolved_form_id($atts);

        // Field key resolution with user's actual field names as defaults
        $branch_key    = trim((string)($atts['branch_key']       ?: ($s['branch_field_key']   ?? 'employee_branch')));
        $start_key     = trim((string)($atts['start_key']        ?: ($s['start_field_key']    ?? 'start_date')));
        $end_key       = trim((string)($atts['end_key']          ?: ($s['end_field_key']      ?? 'date_to_include')));
        $type_key      = trim((string)($atts['type_key']         ?: ($s['type_field_key']     ?? 'vacation_type')));
        $working_key   = trim((string)($atts['working_days_key'] ?: ($s['working_days_key']   ?? 'vacation_days')));
        $calendar_key  = trim((string)($atts['calendar_days_key']?: ($s['calendar_days_key']  ?? 'number_of_calendar_days')));
        $status_key    = trim((string)($atts['status_key']       ?: ($s['status_field_key']   ?? 'status')));

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
        
        if (!$subs) return '<p>შვებულებები არ მოიძებნა.</p>';

        ob_start(); ?>
        <style>
            .ev-vac-table-wrap {
                overflow-x: auto;
                margin: 20px 0;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                border-radius: 8px;
            }
            .ev-vacations-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 14px;
                background: #fff;
            }
            .ev-vacations-table th {
                text-align: left;
                padding: 12px 10px;
                border-bottom: 2px solid #ddd;
                background: #f9f9f9;
                font-weight: 600;
                white-space: nowrap;
                font-size: 13px;
            }
            .ev-vacations-table td {
                padding: 12px 10px;
                border-bottom: 1px solid #f0f0f0;
                vertical-align: middle;
            }
            .ev-vacations-table tbody tr:hover {
                background: #fafafa;
            }
            .ev-vacations-table tbody tr:last-child td {
                border-bottom: none;
            }
            .ev-date-range {
                font-weight: 600;
                color: #333;
                white-space: nowrap;
            }
            .ev-type-badge {
                display: inline-block;
                padding: 5px 12px;
                border-radius: 14px;
                font-size: 12px;
                font-weight: 600;
                white-space: nowrap;
            }
            /* Vacation type colors */
            .ev-type-earned { 
                background: #e7f5ff; 
                color: #1971c2;
                border: 1px solid #b3d9ff;
            }
            .ev-type-sick { 
                background: #fff9db; 
                color: #f59f00;
                border: 1px solid #ffe066;
            }
            .ev-type-bonus { 
                background: #d3f9d8; 
                color: #2f9e44;
                border: 1px solid #b2f2bb;
            }
            .ev-type-maternity { 
                background: #ffe3e3; 
                color: #c92a2a;
                border: 1px solid #ffc9c9;
            }
            .ev-type-unpaid { 
                background: #f8f9fa; 
                color: #666;
                border: 1px solid #dee2e6;
            }
            .ev-type-other { 
                background: #f3f3f3; 
                color: #666;
                border: 1px solid #ddd;
            }
            .ev-days-cell {
                text-align: center;
                font-weight: 600;
                font-size: 16px;
                color: #333;
            }
            .ev-calendar-days-cell {
                text-align: center;
                font-size: 15px;
                color: #555;
            }
            .ev-date-submitted {
                font-size: 13px;
                color: #666;
            }
            .ev-time-submitted {
                font-size: 11px;
                color: #999;
                margin-top: 2px;
            }
            @media (max-width: 768px) {
                .ev-vacations-table {
                    font-size: 12px;
                }
                .ev-vacations-table th,
                .ev-vacations-table td {
                    padding: 8px 6px;
                }
            }
        </style>
        <div class="ev-vac-table-wrap">
            <table class="ev-vacations-table">
                <thead>
                    <tr>
                        <th style="width:120px;">თარიღი</th>
                        <th>დაწყება → დასრულება</th>
                        <th>ტიპი</th>
                        <th style="text-align:center;width:90px;">სამუშაო<br>დღეები</th>
                        <th style="text-align:center;width:100px;">კალენდარული<br>დღეები</th>
                        <th>ფილიალი</th>
                        <?php if ($status_key !== ''): ?>
                        <th>სტატუსი</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($subs as $sub):
                    $vals = self::val_for_submission((int)$sub['id']);

                    // Branch
                    $branch_raw = $vals[$branch_key] ?? get_user_meta($user_id, self::META_BRANCH, true);
                    $branch = ev_get_branch_name($branch_raw);

                    // Dates
                    $date_from = $vals[$start_key] ?? '';
                    $date_to   = $vals[$end_key] ?? '';
                    
                    // Format dates nicely
                    if ($date_from) {
                        $dt_from = CompanySuite_Helpers::parse_date($date_from);
                        $date_from_display = $dt_from ? $dt_from->format('d/m/Y') : $date_from;
                    } else {
                        $date_from_display = '—';
                    }

                    if ($date_to) {
                        $dt_to = CompanySuite_Helpers::parse_date($date_to);
                        $date_to_display = $dt_to ? $dt_to->format('d/m/Y') : $date_to;
                    } else {
                        $date_to_display = '—';
                    }

                    // Vacation Type
                    $type_raw = $vals[$type_key] ?? '';
                    $type_info = self::format_vacation_type($type_raw);

                    // Days
                    $working_days  = self::parse_days($vals[$working_key] ?? '');
                    $calendar_days = self::parse_days($vals[$calendar_key] ?? '');
                    
                    // If not explicitly set, try to calculate from date range
                    if ($calendar_days <= 0 && $date_from && $date_to) {
                        $calendar_days = self::calc_days_between($date_from, $date_to);
                    }

                    // Status (if field exists)
                    $status_html = '';
                    if ($status_key !== '') {
                        $status_raw = $vals[$status_key] ?? '';
                        $status_html = self::format_status($status_raw);
                    }
                ?>
                    <tr>
                        <td>
                            <div class="ev-date-submitted">
                                <?php echo esc_html(date_i18n('d/m/Y', strtotime($sub['created_at']))); ?>
                            </div>
                            <div class="ev-time-submitted">
                                <?php echo esc_html(date_i18n('H:i', strtotime($sub['created_at']))); ?>
                            </div>
                        </td>
                        <td>
                            <span class="ev-date-range">
                                <?php echo esc_html($date_from_display); ?> → <?php echo esc_html($date_to_display); ?>
                            </span>
                        </td>
                        <td>
                            <span class="ev-type-badge ev-type-<?php echo esc_attr($type_info['class']); ?>">
                                <?php echo esc_html($type_info['label']); ?>
                            </span>
                        </td>
                        <td class="ev-days-cell">
                            <?php echo $working_days > 0 ? esc_html($working_days) : '—'; ?>
                        </td>
                        <td class="ev-calendar-days-cell">
                            <?php echo $calendar_days > 0 ? esc_html($calendar_days) : '—'; ?>
                        </td>
                        <td>
                            <?php echo esc_html($branch); ?>
                        </td>
                        <?php if ($status_key !== ''): ?>
                        <td>
                            <?php echo $status_html; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Format vacation type for display with Georgian labels
     */
    private static function format_vacation_type($raw): array {
        $type = trim((string)$raw);
        
        // Georgian vacation type labels (based on user's form)
        $type_map = [
            '1' => ['label' => 'კუთვნილი შვებულება', 'class' => 'earned'],      // Earned Leave
            '2' => ['label' => 'ბიულეტინი', 'class' => 'sick'],                // Sick Leave/Medical
            '3' => ['label' => 'ბონუს დღე', 'class' => 'bonus'],               // Bonus Day
            '4' => ['label' => 'დეკრეტული შვებულება', 'class' => 'maternity'], // Maternity/Paternity Leave
            '5' => ['label' => 'უხელფასო შვებულება', 'class' => 'unpaid'],     // Unpaid Leave
        ];
        
        if (isset($type_map[$type])) {
            return $type_map[$type];
        }
        
        // Return as-is if it's already a label
        return ['label' => $type ?: '—', 'class' => 'other'];
    }

    /**
     * Format status with color badge
     */
    private static function format_status($raw): string {
        $status = trim(strtolower((string)$raw));
        
        if (!$status || $status === '—') {
            return '<span style="color:#999;">—</span>';
        }
        
        $colors = [
            'approved'  => '#46b450',
            'pending'   => '#f0b849',
            'rejected'  => '#dc3232',
            'cancelled' => '#999',
            'დამტკიცებული' => '#46b450',
            'მოლოდინში' => '#f0b849',
            'უარყოფილი' => '#dc3232',
            'გაუქმებული' => '#999',
        ];
        
        $labels = [
            'approved'  => 'დამტკიცებული',
            'pending'   => 'მოლოდინში',
            'rejected'  => 'უარყოფილი',
            'cancelled' => 'გაუქმებული',
            'დამტკიცებული' => 'დამტკიცებული',
            'მოლოდინში' => 'მოლოდინში',
            'უარყოფილი' => 'უარყოფილი',
            'გაუქმებული' => 'გაუქმებული',
        ];
        
        $color = $colors[$status] ?? '#999';
        $label = $labels[$status] ?? ucfirst($status);
        
        return sprintf(
            '<span style="display:inline-block;padding:5px 12px;border-radius:14px;font-size:12px;font-weight:600;color:#fff;background:%s;">%s</span>',
            esc_attr($color),
            esc_html($label)
        );
    }

    public static function sc_dashboard($atts): string {
        if (!is_user_logged_in()) return '<p>გთხოვთ შეხვიდეთ სისტემაში.</p>';

        $atts = shortcode_atts([
            'form_id' => '',
        ], (array)$atts);

        $form_attr = trim((string)$atts['form_id']);
        $form_part = $form_attr !== '' ? ' form_id="'.esc_attr($form_attr).'"' : '';

        ob_start(); ?>
        <style>
            .ev-vac-dashboard {
                display: grid;
                gap: 20px;
                margin: 20px 0;
            }
            .ev-vac-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 15px;
            }
            .ev-vac-stat-card {
                padding: 20px;
                border: 1px solid #e0e0e0;
                border-radius: 12px;
                background: #fff;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
                transition: all 0.3s ease;
            }
            .ev-vac-stat-card:hover {
                box-shadow: 0 4px 8px rgba(0,0,0,0.1);
                transform: translateY(-2px);
            }
            .ev-vac-stat-label {
                opacity: 0.7;
                font-size: 13px;
                margin-bottom: 8px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .ev-vac-stat-value {
                font-size: 32px;
                font-weight: 700;
                color: #1971c2;
            }
            .ev-vac-branch-value {
                font-size: 20px;
                font-weight: 600;
                color: #333;
            }
        </style>
        <div class="ev-vac-dashboard">
            <div class="ev-vac-stats">
                <div class="ev-vac-stat-card">
                    <div class="ev-vac-stat-label">დარჩენილი შვებულება</div>
                    <div class="ev-vac-stat-value"><?php echo do_shortcode('[ev_remaining_days]'); ?></div>
                </div>
                <div class="ev-vac-stat-card">
                    <div class="ev-vac-stat-label">ფილიალი</div>
                    <div class="ev-vac-branch-value"><?php echo do_shortcode('[ev_my_branch]'); ?></div>
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