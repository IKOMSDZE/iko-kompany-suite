<?php
/**
 * Centralized Helper Functions for Company Suite
 * 
 * This file provides a single source of truth for all common operations
 * across the plugin, including branch/position mapping, date parsing,
 * phone normalization, and caching.
 * 
 * @package Company_Suite
 * @version 1.0.0
 */

if (!defined('ABSPATH')) exit;

class CompanySuite_Helpers {
    
    private static $settings_cache = null;
    private static $branches_cache = null;
    private static $positions_cache = null;

    /**
     * Get plugin settings (cached)
     * 
     * @return array Plugin settings
     */
    public static function get_settings(): array {
        if (self::$settings_cache === null) {
            self::$settings_cache = get_option('ev_vacations_settings', []);
            if (!is_array(self::$settings_cache)) {
                self::$settings_cache = [];
            }
        }
        return self::$settings_cache;
    }

    /**
     * Clear all caches (call after saving settings)
     * 
     * @return void
     */
    public static function clear_cache(): void {
        self::$settings_cache = null;
        self::$branches_cache = null;
        self::$positions_cache = null;
    }

    /**
     * Get branches map (cached) - SINGLE SOURCE OF TRUTH
     * 
     * @return array Branch code => label map
     */
    public static function get_branches_map(): array {
        if (self::$branches_cache !== null) {
            return self::$branches_cache;
        }

        // Try to get from running core instance first
        if (isset($GLOBALS['ev_elementor_vacations']) && is_object($GLOBALS['ev_elementor_vacations'])) {
            $core = $GLOBALS['ev_elementor_vacations'];
            if (method_exists($core, 'branches_map')) {
                try {
                    $map = $core->branches_map();
                    if (is_array($map)) {
                        self::$branches_cache = $map;
                        return $map;
                    }
                } catch (Throwable $e) {}
            }
        }

        $settings = self::get_settings();
        $labels = self::parse_list_setting($settings['branches'] ?? []);
        $codes  = isset($settings['branch_codes']) && is_array($settings['branch_codes']) 
                  ? $settings['branch_codes'] : [];

        $map = [];
        foreach ($labels as $i => $label) {
            $label = trim($label);
            if ($label === '') continue;

            $raw_code = isset($codes[$i]) ? trim((string)$codes[$i]) : '';
            $code = $raw_code !== '' ? preg_replace('/\D+/', '', $raw_code) : '';

            if ($code === '') {
                $code = (string)(101 + (int)$i);
            }

            $map[$code] = $label;
        }

        self::$branches_cache = apply_filters('company_suite/branches_map', $map);
        return self::$branches_cache;
    }

    /**
     * Get positions map (cached) - SINGLE SOURCE OF TRUTH
     * 
     * @return array Position code => label map
     */
    public static function get_positions_map(): array {
        if (self::$positions_cache !== null) {
            return self::$positions_cache;
        }

        $settings = self::get_settings();
        $labels = self::parse_list_setting($settings['positions'] ?? []);

        $map = [];
        foreach ($labels as $i => $label) {
            $code = sprintf('%03d', $i + 1);
            $map[$code] = $label;
        }

        self::$positions_cache = apply_filters('company_suite/positions_map', $map);
        return self::$positions_cache;
    }

    /**
     * Parse list setting (handles string or array)
     * 
     * @param mixed $value Setting value
     * @return array Parsed list
     */
    private static function parse_list_setting($value): array {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n/', $value);
        }

        $out = [];
        foreach ((array)$value as $item) {
            $label = trim(wp_strip_all_tags((string)$item));
            if ($label !== '') {
                $out[] = $label;
            }
        }

        return $out;
    }

    /**
     * Get branch name from code or label
     * 
     * @param mixed $branch_value Branch code, ID, or formatted string
     * @return string Branch name
     */
    public static function get_branch_name($branch_value): string {
        $branch_value = trim((string)$branch_value);
        if ($branch_value === '') return '';

        $map = self::get_branches_map();

        // Direct code match
        if (isset($map[$branch_value])) {
            return $map[$branch_value];
        }

        // Extract digits and try again
        $digits = preg_replace('/\D+/', '', $branch_value);
        if ($digits !== '' && isset($map[$digits])) {
            return $map[$digits];
        }

        // Return as-is if not found
        return $branch_value;
    }

    /**
     * Get position name from code
     * 
     * @param mixed $position_code Position code
     * @return string Position name
     */
    public static function get_position_name($position_code): string {
        $position_code = trim((string)$position_code);
        if ($position_code === '') return '';

        $map = self::get_positions_map();
        return $map[$position_code] ?? $position_code;
    }

    /**
     * Normalize branch code (convert label to code if needed)
     * 
     * @param mixed $value Branch code or label
     * @return string Normalized branch code
     */
    public static function normalize_branch_code($value): string {
        $value = trim((string)$value);
        if ($value === '') return '';

        $map = self::get_branches_map();

        // Already a valid code
        if (isset($map[$value])) return $value;

        // Try to find code by label
        foreach ($map as $code => $label) {
            if ($label === $value) return $code;
        }

        // Extract digits as fallback
        $digits = preg_replace('/\D+/', '', $value);
        return isset($map[$digits]) ? $digits : '';
    }

    /**
     * Normalize position code (convert label to code if needed)
     * 
     * @param mixed $value Position code or label
     * @return string Normalized position code
     */
    public static function normalize_position_code($value): string {
        $value = trim((string)$value);
        if ($value === '') return '';

        $map = self::get_positions_map();

        // Already a valid code
        if (isset($map[$value])) return $value;

        // Try to find code by label
        foreach ($map as $code => $label) {
            if ($label === $value) return $code;
        }

        return '';
    }

    /**
     * Normalize phone number(s) - supports 2 comma-separated numbers
     * 
     * @param mixed $raw Phone number(s)
     * @return string Normalized phone number(s)
     */
    public static function normalize_phone($raw): string {
        $s = (string)$raw;
        if ($s === '') return '';

        $parts = array_map('trim', explode(',', $s));
        $out = [];

        foreach ($parts as $p) {
            if ($p === '') continue;
            $clean = preg_replace('/[^0-9+\s\-\(\)]/', '', $p);
            $clean = trim($clean);
            if ($clean !== '') {
                $out[] = $clean;
            }
            if (count($out) >= 2) break;
        }

        return implode(', ', $out);
    }

    /**
     * Parse date from various formats
     * 
     * @param mixed $value Date string
     * @return DateTime|null Parsed DateTime object or null
     */
    public static function parse_date($value): ?DateTime {
        $value = trim((string)$value);
        if ($value === '') return null;

        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'j/n/Y', 'j-m-Y', 'j.n.Y', 'm/d/Y', 'Y/m/d'];
        
        foreach ($formats as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $value);
            if ($dt instanceof DateTime) {
                $errors = DateTime::getLastErrors();
                if (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                    $dt->setTime(0, 0, 0);
                    return $dt;
                }
            }
        }

        $ts = strtotime($value);
        if ($ts) {
            $dt = new DateTime();
            $dt->setTimestamp($ts);
            $dt->setTime(0, 0, 0);
            return $dt;
        }

        return null;
    }

    /**
     * Format birthday for storage (Y-m-d)
     * 
     * @param mixed $raw Birthday string in any format
     * @return string Normalized birthday (Y-m-d) or empty string
     */
    public static function normalize_birthday($raw): string {
        $s = trim((string)$raw);
        if ($s === '') return '';

        // Already normalized
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;

        $dt = self::parse_date($s);
        return $dt ? $dt->format('Y-m-d') : '';
    }

    /**
     * Format birthday for display (d/m/Y)
     * 
     * @param mixed $stored Stored birthday (Y-m-d format)
     * @return string Formatted birthday (d/m/Y) or empty string
     */
    public static function format_birthday($stored): string {
        $stored = trim((string)$stored);
        if ($stored === '') return '';

        $norm = self::normalize_birthday($stored);
        if ($norm === '') return '';

        $dt = self::parse_date($norm);
        return $dt ? $dt->format('d/m/Y') : '';
    }

    /**
     * Extract month-day from birthday (MM-DD format)
     * 
     * @param mixed $raw Birthday string
     * @return string Month-day (MM-DD) or empty string
     */
    public static function get_birthday_month_day($raw): string {
        $norm = self::normalize_birthday((string)$raw);
        if ($norm && preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $norm, $m)) {
            return $m[1] . '-' . $m[2];
        }
        return '';
    }

    /**
     * Calculate days between two dates (inclusive)
     * 
     * @param mixed $start Start date
     * @param mixed $end End date
     * @return int Number of days (inclusive)
     */
    public static function calc_days_between($start, $end): int {
        $ds = self::parse_date($start);
        $de = self::parse_date($end);
        if (!$ds || !$de) return 0;
        
        $diff = $ds->diff($de);
        return max(0, (int)$diff->days + 1);
    }

    /**
     * Get annual allocation for user
     * 
     * @param int $user_id User ID
     * @return int Annual vacation days
     */
    public static function get_user_annual_days(int $user_id): int {
        $custom = get_user_meta($user_id, '_ev_vac_annual_allocation', true);
        if ($custom !== '' && $custom !== null) {
            return max(0, (int)$custom);
        }

        $settings = self::get_settings();
        return max(0, (int)($settings['default_annual'] ?? 24));
    }

    /**
     * Get remaining days for user
     * 
     * @param int $user_id User ID
     * @return int Remaining vacation days
     */
    public static function get_user_remaining_days(int $user_id): int {
        $remain = get_user_meta($user_id, '_ev_vac_remaining_days', true);
        if ($remain === '' || $remain === null) {
            return self::get_user_annual_days($user_id);
        }
        return max(0, (int)$remain);
    }

    /**
     * Check if form matches target form ID
     * 
     * @param string $target_form_id Target form ID to match
     * @param mixed $record Elementor form record object
     * @return bool True if matches
     */
    public static function form_matches(string $target_form_id, $record): bool {
        if ($target_form_id === '') {
            return true; // No filter = match all
        }

        if (!is_object($record) || !method_exists($record, 'get_form_settings')) {
            return false;
        }

        $form_id   = (string)$record->get_form_settings('form_id');
        $form_name = (string)$record->get_form_settings('form_name');
        $element_id = (string)$record->get_form_settings('id');

        return ($target_form_id === $form_id || 
                $target_form_id === $form_name || 
                $target_form_id === $element_id);
    }
}

/**
 * Backward-compatible wrapper functions
 * These maintain compatibility with code that uses the old function names
 */

/**
 * Get branches map from settings
 * 
 * @return array Branch code => label map
 */
function ev_get_branches_map_from_settings(): array {
    return CompanySuite_Helpers::get_branches_map();
}

/**
 * Convert branch ID/code to branch name
 * 
 * @param mixed $branch_value Branch code, ID, or name
 * @return string Branch name
 */
function ev_get_branch_name($branch_value): string {
    return CompanySuite_Helpers::get_branch_name($branch_value);
}