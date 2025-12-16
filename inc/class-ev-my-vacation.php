<?php
if (!defined('ABSPATH')) exit;

final class EV_My_Vacation_Shortcodes {

    public function __construct() {
        add_shortcode('ev_my_vacation_summary', [$this, 'summary']);
    }

    private function fmt_days($val): int {
        $n = intval($val);
        return $n < 0 ? 0 : $n;
    }

    public function summary($atts = []): string {
        if (!is_user_logged_in()) {
            return '<div class="ev-my-vacation ev-my-vacation--guest">Please log in to view your vacation balance.</div>';
        }

        $user_id  = get_current_user_id();
        $annual   = $this->fmt_days(get_user_meta($user_id, '_ev_vac_annual_allocation', true));
        $remain   = $this->fmt_days(get_user_meta($user_id, '_ev_vac_remaining_days', true));
        $used     = max(0, $annual - $remain);
        $reset_yr = intval(get_user_meta($user_id, '_ev_vac_last_reset_year', true));

        ob_start(); ?>
        <div class="ev-my-vacation" style="border:1px solid #e5e7eb;border-radius:12px;padding:16px;max-width:520px">
            <div style="font-size:18px;font-weight:700;margin-bottom:10px">My Vacation Balance</div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div style="padding:12px;border:1px solid #eee;border-radius:10px">
                    <div style="font-size:12px;opacity:.7">Remaining</div>
                    <div style="font-size:22px;font-weight:800"><?php echo esc_html($remain); ?></div>
                </div>

                <div style="padding:12px;border:1px solid #eee;border-radius:10px">
                    <div style="font-size:12px;opacity:.7">Used</div>
                    <div style="font-size:22px;font-weight:800"><?php echo esc_html($used); ?></div>
                </div>

                <div style="padding:12px;border:1px solid #eee;border-radius:10px">
                    <div style="font-size:12px;opacity:.7">Annual Allocation</div>
                    <div style="font-size:18px;font-weight:700"><?php echo esc_html($annual); ?></div>
                </div>

                <div style="padding:12px;border:1px solid #eee;border-radius:10px">
                    <div style="font-size:12px;opacity:.7">Last Reset Year</div>
                    <div style="font-size:18px;font-weight:700"><?php echo esc_html($reset_yr ?: date('Y')); ?></div>
                </div>
            </div>

            <div style="margin-top:10px;font-size:12px;opacity:.7">
                If your balance looks wrong, contact HR/admin.
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
