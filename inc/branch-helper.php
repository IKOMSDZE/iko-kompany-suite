<?php
if (!defined('ABSPATH')) exit;

/**
 * Returns branch map from settings using the same logic as EV_Elementor_Vacations::branches_map().
 * Falls back to the running core instance if available.
 */
function ev_get_branches_map_from_settings(): array {
    // Prefer core instance map if available
    if (isset($GLOBALS['ev_elementor_vacations']) && is_object($GLOBALS['ev_elementor_vacations'])) {
        $core = $GLOBALS['ev_elementor_vacations'];
        if (method_exists($core, 'branches_map')) {
            try {
                $map = $core->branches_map();
                if (is_array($map)) return $map;
            } catch (Throwable $e) {}
        }
    }

    $opt = get_option('ev_vacations_settings', []);
    if (!is_array($opt)) $opt = [];

    $labels = isset($opt['branches']) && is_array($opt['branches']) ? $opt['branches'] : [];
    $codes  = isset($opt['branch_codes']) && is_array($opt['branch_codes']) ? $opt['branch_codes'] : [];

    $map = [];
    foreach ($labels as $i => $label) {
        $label = trim((string)$label);
        if ($label === '') continue;

        $raw_code = isset($codes[$i]) ? trim((string)$codes[$i]) : '';
        $code = $raw_code !== '' ? preg_replace('/\D+/', '', $raw_code) : '';

        if ($code === '') {
            $code = (string)(101 + (int)$i);
        }

        $map[$code] = $label;
    }

    return apply_filters('company_suite/branches_map', $map);
}

/**
 * Convert branch ID/code to branch NAME using settings map.
 * If Elementor stored a name already, returns it.
 */
function ev_get_branch_name($branch_value): string {
    $branch_value = trim((string)$branch_value);
    if ($branch_value === '') return '';

    $map = ev_get_branches_map_from_settings();

    if (isset($map[$branch_value])) return (string)$map[$branch_value];

    $digits = preg_replace('/\D+/', '', $branch_value);
    if ($digits !== '' && isset($map[$digits])) return (string)$map[$digits];

    return $branch_value;
}
