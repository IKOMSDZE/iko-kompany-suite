<?php
/**
 * Plugin Name: Company Suite
 * Plugin URI:  https://iko.ge
 * Description: Vacations with yearly reset, Positions & Branches (coded values), Elementor Pro deduction (only for earned leave), birthdays, phone field (supports two numbers via CSV), REST hooks, front-end user list/editor with delete (confirm), Birthday SMS (users + admin summary), admin Settings + Logs, Elementor Autofill (employee select + dependent fields), own-branch changer, and per-branch homepages.
 * Version:     1.7.5
 * Author:      iko
 * Author URI:  https://iko.ge
 * License:     GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

if (!defined('IKO_CS_DIR')) {
    define('IKO_CS_DIR', plugin_dir_path(__FILE__));
}
if (!defined('IKO_CS_URL')) {
    define('IKO_CS_URL', plugin_dir_url(__FILE__));
}

// Includes
require_once IKO_CS_DIR . 'inc/class-ev-elementor-vacations.php';
require_once IKO_CS_DIR . 'inc/class-ev-elementor-vacations-autofill.php';
require_once IKO_CS_DIR . 'inc/class-ev-company-suite-own-branch.php';

// Bootstrap
add_action('plugins_loaded', function () {
    $core = new EV_Elementor_Vacations();
    $GLOBALS['ev_elementor_vacations'] = $core;

    if (class_exists('EV_Elementor_Vacations_Autofill')) {
        new EV_Elementor_Vacations_Autofill();
    }

    if (class_exists('EV_Company_Suite_Own_Branch')) {
        EV_Company_Suite_Own_Branch::init();
    }
});

// Activation / deactivation
register_activation_hook(__FILE__, ['EV_Elementor_Vacations', 'activate']);
register_deactivation_hook(__FILE__, ['EV_Elementor_Vacations', 'deactivate']);
