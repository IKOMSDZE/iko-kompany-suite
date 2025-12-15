<?php
if (!defined('ABSPATH')) exit;

/**
 * Backward-compatible wrapper functions for branch operations
 * 
 * These functions now call the centralized CompanySuite_Helpers class
 * to ensure consistency across the entire plugin.
 * 
 * This file maintains backward compatibility while using
 * the new helper class as the single source of truth.
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