<?php
if (!defined('ABSPATH')) exit;

class EV_Elementor_Vacations_Autofill {

    // Optional: restrict to specific pages (post IDs). Empty = all pages.
    private $page_ids = array(); // e.g. array(1473);

    // Form IDs / names to target. Filled from settings or fallback.
    private $form_ids = array();

    const FIELD_EMPLOYEE_SELECT = 'employee_name';
    const FIELD_EMAIL           = 'user_email';
    const FIELD_POSITION_LABEL  = 'employee_position';
    const FIELD_BRANCH_LABEL    = 'employee_branch';
    const FIELD_PHONE           = 'employee_phone';
    const FIELD_DAYS_LEFT       = 'days_left';

    const TRANSIENT_KEY = 'ev_vac_employees_cache_v1';
    const CACHE_TTL     = 0; // 0 = no caching; set seconds if you want caching

    public function __construct() {
        // Load form targeting from main Company Suite settings
        $opt        = get_option(EV_Elementor_Vacations::OPT, array());
        $form_match = '';
        if (isset($opt['form_id'])) {
            $form_match = trim((string) $opt['form_id']);
        }

        if ($form_match !== '') {
            $this->form_ids = array($form_match);
        } else {
            // Fallback to your current Elementor form_id if settings empty
            $this->form_ids = array('0e16255');
        }

        add_filter('elementor_pro/forms/render/item/select', array($this, 'populate_employee_select'), 10, 3);
        add_action('elementor_pro/forms/validation',        array($this, 'validate_and_force_fields'), 9, 2);
        add_action('wp_footer',                             array($this, 'frontend_script'), 20);

        // Cache busting hooks
        add_action('user_register',  array($this, 'bust_cache'));
        add_action('profile_update', array($this, 'bust_cache'));
        add_action('deleted_user',   array($this, 'bust_cache'));
        add_action('company_suite/lists_updated', array($this, 'bust_cache'));
    }

    /* -------------------------------------------------------------------------
     * Helpers: positions / branches mapping (match main plugin)
     * ---------------------------------------------------------------------- */


    private function enabled_here() {
        if (!empty($this->page_ids) && is_page()) {
            $id = (int) get_queried_object_id();
            if (!in_array($id, $this->page_ids, true)) {
                return false;
            }
        }
        return true;
    }

    /* -------------------------------------------------------------------------
     * Employees list
     * ---------------------------------------------------------------------- */

    private function get_employees() {
        if (self::CACHE_TTL > 0) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $posMap = CompanySuite_Helpers::get_positions_map();
        $brMap  = CompanySuite_Helpers::get_branches_map();

        $users = get_users(array(
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'number'  => 300,
            'fields'  => array('ID', 'display_name', 'user_login', 'first_name', 'last_name', 'user_email'),
        ));

        $list = array();
        foreach ($users as $u) {
            $name = trim($u->display_name);
            if ($name === '') {
                $name = trim($u->first_name . ' ' . $u->last_name);
            }
            if ($name === '') {
                $name = $u->user_login;
            }

            $posCode = get_user_meta($u->ID, EV_Elementor_Vacations::META_POSITION, true);
            $brCode  = get_user_meta($u->ID, EV_Elementor_Vacations::META_BRANCH,   true);
            $phone   = get_user_meta($u->ID, EV_Elementor_Vacations::META_PHONE,    true);
            $remain  = get_user_meta($u->ID, EV_Elementor_Vacations::META_REMAIN,   true);

            $positionLabel = CompanySuite_Helpers::get_position_name($posCode);
            $branchLabel   = CompanySuite_Helpers::get_branch_name($brCode);

            $list[] = array(
                'ID'        => (int) $u->ID,
                'name'      => $name,
                'email'     => (string) $u->user_email,
                'position'  => $positionLabel,                      // label only
                'branch'    => $branchLabel,                        // label only
                'phone'     => is_scalar($phone) ? (string) $phone : '',
                'remaining' => (is_numeric($remain) && (int)$remain > 0) ? (int)$remain : 0, // 0 if empty or <=0
            );
        }

        if (self::CACHE_TTL > 0) {
            set_transient(self::TRANSIENT_KEY, $list, self::CACHE_TTL);
        }

        return $list;
    }

    public function bust_cache() {
        delete_transient(self::TRANSIENT_KEY);
    }

    /* -------------------------------------------------------------------------
     * 1) Populate employee select (label = name, value = user ID)
     * ---------------------------------------------------------------------- */

    public function populate_employee_select($item, $item_index, $form) {
        if (!$this->enabled_here()) {
            return $item;
        }

        // Identify the field by custom_id / field_id / id
        $ids = array();
        if (isset($item['custom_id'])) $ids[] = $item['custom_id'];
        if (isset($item['field_id']))  $ids[] = $item['field_id'];
        if (isset($item['id']))        $ids[] = $item['id'];

        $ids = array_filter($ids, 'strlen');

        if (!in_array(self::FIELD_EMPLOYEE_SELECT, $ids, true)) {
            return $item;
        }

        // If options already defined in Elementor, don't overwrite
        if (!empty($item['field_options'])) {
            return $item;
        }

        // Check form targeting
        if (!empty($this->form_ids)) {
            $candidates = array();

            if (method_exists($form, 'get_id')) {
                $candidates[] = (string) $form->get_id();
            }

            if (method_exists($form, 'get_settings_for_display')) {
                $fs = (array) $form->get_settings_for_display();
                if (!empty($fs['form_id'])) {
                    $candidates[] = (string) $fs['form_id'];
                }
                if (!empty($fs['form_name'])) {
                    $candidates[] = (string) $fs['form_name'];
                }
            }

            $match = false;
            foreach ($candidates as $c) {
                $c = trim((string) $c);
                if ($c !== '' && in_array($c, $this->form_ids, true)) {
                    $match = true;
                    break;
                }
            }

            if (!$match) {
                return $item;
            }
        }

        $employees = $this->get_employees();
        $lines     = array('აირჩიეთ დასაქმებული|');

        foreach ($employees as $e) {
            // LABEL = name, VALUE = user ID
            $label = $e['name'];
            $lines[] = $label . '|' . $e['ID'];
        }

        $item['field_options'] = implode("\n", $lines);
        if (empty($item['placeholder'])) {
            $item['placeholder'] = 'აირჩიეთ დასაქმებული';
        }

        return $item;
    }

    /* -------------------------------------------------------------------------
     * 2) Server-side: validate & force dependent fields to correct values
     * ---------------------------------------------------------------------- */

    public function validate_and_force_fields($record, $ajax_handler) {
        $fields = $record->get('fields');

        if (!isset($fields[self::FIELD_EMPLOYEE_SELECT]['value']) || $fields[self::FIELD_EMPLOYEE_SELECT]['value'] === '') {
            return;
        }

        $user_id = absint($fields[self::FIELD_EMPLOYEE_SELECT]['value']);
        $user    = $user_id ? get_user_by('ID', $user_id) : null;

        if (!$user) {
            $ajax_handler->add_error(self::FIELD_EMPLOYEE_SELECT, __('Invalid employee selected.', 'ev-vac'));
            return;
        }

        $posMap = CompanySuite_Helpers::get_positions_map();
        $brMap  = CompanySuite_Helpers::get_branches_map();

        $email   = (string) $user->user_email;
        $posCode = get_user_meta($user_id, EV_Elementor_Vacations::META_POSITION, true);
        $brCode  = get_user_meta($user_id, EV_Elementor_Vacations::META_BRANCH,   true);
        $phone   = get_user_meta($user_id, EV_Elementor_Vacations::META_PHONE,    true);
        $remain  = get_user_meta($user_id, EV_Elementor_Vacations::META_REMAIN,   true);

        $positionLabel = CompanySuite_Helpers::get_position_name($posCode);
        $branchLabel   = CompanySuite_Helpers::get_branch_name($brCode);
        $phoneStr      = is_scalar($phone) ? (string) $phone : '';
        $remainingDays = (is_numeric($remain) && (int)$remain > 0) ? (int)$remain : 0;

        $force = array(
            self::FIELD_EMAIL          => $email,
            self::FIELD_POSITION_LABEL => $positionLabel,
            self::FIELD_BRANCH_LABEL   => $branchLabel,
            self::FIELD_PHONE          => $phoneStr,
            self::FIELD_DAYS_LEFT      => $remainingDays,
        );

        foreach ($force as $key => $val) {
            if (!isset($fields[$key])) {
                continue;
            }
            if (!is_array($fields[$key])) {
                $fields[$key] = array('value' => $val);
            } else {
                $fields[$key]['value'] = $val;
            }
        }

        $record->set('fields', $fields);
    }

    /* -------------------------------------------------------------------------
     * 3) Front-end: JS for hiding / showing and filling dependent fields
     * ---------------------------------------------------------------------- */

    public function frontend_script() {
        if (is_admin() || !$this->enabled_here()) {
            return;
        }

        $employees = $this->get_employees();
        $map = array();
        foreach ($employees as $e) {
            $map[(string) $e['ID']] = array(
                'email'     => $e['email'],
                'position'  => $e['position'],
                'branch'    => $e['branch'],
                'phone'     => $e['phone'],
                'remaining' => $e['remaining'],
            );
        }

        // Selectors must match your form HTML (Elementor IDs)
        $selEmp  = 'select[name="form_fields[' . self::FIELD_EMPLOYEE_SELECT . ']"]';
        $selMail = 'input[name="form_fields[' . self::FIELD_EMAIL           . ']"]';
        $selPos  = 'input[name="form_fields[' . self::FIELD_POSITION_LABEL  . ']"]';
        $selBr   = 'input[name="form_fields[' . self::FIELD_BRANCH_LABEL    . ']"]';
        $selPh   = 'input[name="form_fields[' . self::FIELD_PHONE           . ']"]';
        $selRem  = 'input[name="form_fields[' . self::FIELD_DAYS_LEFT       . ']"]';

        ?>
        <script>
        (function($){
          const map = <?php echo wp_json_encode($map, JSON_UNESCAPED_UNICODE); ?> || {};

          function lockAndHide($input){
            if (!$input.length) return null;
            const $group = $input.closest('.elementor-field-group, [class*="elementor-field-group"]');
            $input.prop('readonly', true)
                  .css({'background':'#F5F5F5','pointer-events':'none'});
            if ($group.length) $group.hide(); // hide whole group
            return $group.length ? $group : null;
          }

          function showGroup($group){
            if ($group && $group.length) $group.show();
          }

          function hideGroup($group){
            if ($group && $group.length) $group.hide();
          }

          function fill($input, value){
            if (!$input || !$input.length) return;
            const val = (value === undefined || value === null) ? '' : String(value);
            $input.attr('autocomplete','off');
            $input.val(val).attr('value', val);
            $input.trigger('input').trigger('change');
            try {
              $input[0].dispatchEvent(new Event('input', { bubbles: true }));
              $input[0].dispatchEvent(new Event('change', { bubbles: true }));
            } catch(e){}
          }

          function bind($root){
            $root.each(function(){
              const $form = $(this);
              const $emp  = $form.find('<?php echo $selEmp; ?>');
              if (!$emp.length) return;

              const $mail = $form.find('<?php echo $selMail; ?>');
              const $pos  = $form.find('<?php echo $selPos; ?>');
              const $br   = $form.find('<?php echo $selBr; ?>');
              const $ph   = $form.find('<?php echo $selPh; ?>');
              const $rem  = $form.find('<?php echo $selRem; ?>');

              const gMail = lockAndHide($mail);
              const gPos  = lockAndHide($pos);
              const gBr   = lockAndHide($br);
              const gPh   = lockAndHide($ph);
              const gRem  = lockAndHide($rem); // days_left hidden initially too

              function clearAll(){
                fill($mail, ''); fill($pos, ''); fill($br, ''); fill($ph, ''); fill($rem, '');
                hideGroup(gMail); hideGroup(gPos); hideGroup(gBr); hideGroup(gPh); hideGroup(gRem);
              }

              function applyFrom(idStr){
                const id  = String(idStr || '');
                const row = map[id] || null;

                if (!row) {
                  // No row (e.g. placeholder selected) -> clear & hide
                  clearAll();
                  return;
                }

                fill($mail, row.email    || '');
                fill($pos,  row.position || '');
                fill($br,   row.branch   || '');
                fill($ph,   row.phone    || '');
                fill($rem,  row.remaining); // 0 or positive int

                // Show groups after selecting employee
                showGroup(gMail);
                showGroup(gPos);
                showGroup(gBr);
                showGroup(gPh);
                showGroup(gRem);
              }

              $emp.on('change.evVacAutofill', function(){
                applyFrom($(this).val());
              });

              // Initial (if already selected)
              applyFrom($emp.val());
            });
          }

          // Initial bind
          bind($('#vacation_form, form.elementor-form'));

          // Rebind if Elementor rerenders
          const mo = new MutationObserver(function(){
            bind($('#vacation_form, form.elementor-form'));
          });
          mo.observe(document.body, {childList:true, subtree:true});
        })(jQuery);
        </script>
        <?php
    }
}
