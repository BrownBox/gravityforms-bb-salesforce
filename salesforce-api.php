<?php
/*
Plugin Name: Gravity Forms BrownBox Salesforce API Add-On
Plugin URI: http://brownbox.net.au
Description: Integrates <a href="http://formplugin.com?r=salesforce">Gravity Forms</a> with Salesforce allowing form submissions to be automatically sent to your Salesforce account
Version: 0.3.1
Author: Brownbox
Author URI: http://brownbox.net.au

------------------------------------------------------------------------

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/
add_action('init', array('GFBBSalesforce', 'init'));

register_activation_hook(__FILE__, array("GFBBSalesforce", "add_permissions"));
register_activation_hook(__FILE__, array("GFBBSalesforce", "force_refresh_transients"));
register_deactivation_hook(__FILE__, array("GFBBSalesforce", "force_refresh_transients"));

include_once 'data.php';

class GFBBSalesforce {
    private static $name = "Gravity Forms BrownBox Salesforce Add-On";
    private static $api = '';
    private static $path = "gravity-forms-bb-salesforce/salesforce-api.php";
    private static $url = "http://formplugin.com";
    private static $slug = "gravity-forms-bb-salesforce";
    private static $version = "0.3";
    private static $min_gravityforms_version = "1.3.9";
    private static $is_debug = NULL;
    private static $cache_time = 86400; // 24 hours
    static $settings = array(
        //                "username" => '',
        //                "password" => '',
                        'securitytoken' => '',
                        "debug" => false,
                        'notify' => false,
                        "notifyemail" => '',
                        'cache_time' => 86400
    );
    static $apiurl = null;

    //Plugin starting point. Will load appropriate files
    public static function init() {
        global $pagenow;
        require_once (self::get_base_path() . "/edit-form.php");
        if ($pagenow === 'plugins.php' && is_admin()) {
            add_action("admin_notices", array('GFBBSalesforce', 'is_gravity_forms_installed'), 10);
        }

        if (self::is_gravity_forms_installed(false, false) === 0) {
            add_action('after_plugin_row_' . self::$path, array('GFBBSalesforce', 'plugin_row'));
            return;
        }

        if ($pagenow == 'plugins.php' || defined('RG_CURRENT_PAGE') && RG_CURRENT_PAGE == "plugins.php") {
            //loading translations
            load_plugin_textdomain('gravity-forms-bb-salesforce', FALSE, '/gravity-forms-bb-salesforce/languages');

            add_filter('plugin_action_links', array('GFBBSalesforce', 'settings_link'), 10, 2);
        }

        if (!self::is_gravityforms_supported()) {
            return;
        }

        self::$settings = get_option("gf_bb_salesforce_settings");
        self::$apiurl = self::$settings['apiurl'];
        if (is_admin()) {
            //loading translations
            load_plugin_textdomain('gravity-forms-bb-salesforce', FALSE, '/gravity-forms-bb-salesforce/languages');

            //creates a new Settings page on Gravity Forms' settings screen
            if (self::has_access("gravityforms_salesforce")) {
                RGForms::add_settings_page("Salesforce", array("GFBBSalesforce", "settings_page"), self::get_base_url() . "/images/salesforce-50x50.png");
            }

            self::refresh_transients();
        }

        //integrating with Members plugin
        if (function_exists('members_get_capabilities'))
            add_filter('members_get_capabilities', array("GFBBSalesforce", "members_get_capabilities"));

        //handling post submission.
//         add_action("gform_after_submission", array('GFBBSalesforce', 'export'), 10, 2);
        add_action("gform_after_submission", array('GFBBSalesforce', 'send_to_salesforce'), 10, 2);

        add_action('gform_entry_info', array('GFBBSalesforce', 'entry_info_link_to_salesforce'), 10, 2);

        // Salesforce logins
        if (self::$settings['sf_login']) {
            add_action('gform_validation_'.self::$settings['login_form'], array('GFBBSalesforce', 'login_via_salesforce'), 10, 2);
        }
    }

    static function force_refresh_transients() {
        global $wpdb;
        self::refresh_transients(true);
    }

    static private function refresh_transients($force = false) {
        global $wpdb;

        if ($force || (isset($_GET['refresh']) && current_user_can('administrator') && $_GET['refresh'] === 'transients')) {
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE `option_name` LIKE '%_transient_sfgf_%' OR `option_name` LIKE '%_transient_timeout_sfgf_%' OR `option_name` LIKE '%sfgf_lists_fields_%'");
        }
    }

    //Returns true if the current page is one of Gravity Forms pages. Returns false if not
    public static function is_gravity_page($page = array()) {
        if (!class_exists('RGForms')) {
            return false;
        }
        $current_page = trim(strtolower(RGForms::get("page")));
        if (empty($page)) {
            $gf_pages = array(
                            "gf_edit_forms",
                            "gf_new_form",
                            "gf_entries",
                            "gf_settings",
                            "gf_export",
                            "gf_help"
            );
        } else {
            $gf_pages = is_array($page) ? $page : array($page);
        }

        return in_array($current_page, $gf_pages);
    }

    public static function is_gravity_forms_installed($asd = '', $echo = true) {
        global $pagenow, $page, $showed_is_gravity_forms_installed;
        $message = '';

        $installed = 0;
        $name = self::$name;
        if (!class_exists('RGForms')) {
            if (file_exists(WP_PLUGIN_DIR . '/gravityforms/gravityforms.php')) {
                $installed = 1;
                $message .= __(sprintf('%sGravity Forms is installed but not active. %sActivate Gravity Forms%s to use the %s plugin.%s', '<p>', '<strong><a href="' . wp_nonce_url(admin_url('plugins.php?action=activate&plugin=gravityforms/gravityforms.php'), 'activate-plugin_gravityforms/gravityforms.php') . '">', '</a></strong>', $name, '</p>'), 'gravity-forms-bb-salesforce');
            } else {
                $message .= <<<EOD
<p><a href="http://www.gravityforms.com" title="Gravity Forms Contact Form Plugin for WordPress"><img src="http://gravityforms.s3.amazonaws.com/banners/728x90.gif" alt="Gravity Forms Plugin for WordPress" width="728" height="90" style="border:none;" /></a></p>
        <h3><a href="http://www.gravityforms.com" target="_blank">Gravity Forms</a> is required for the $name</h3>
        <p>You do not have the Gravity Forms plugin installed. <a href="www.gravityforms.com">Get Gravity Forms</a> today.</p>
EOD;
            }

            if (!empty($message) && $echo && is_admin() && did_action('admin_notices')) {
                if (empty($showed_is_gravity_forms_installed)) {
                    echo '<div id="message" class="updated">' . $message . '</div>';
                    $showed_is_gravity_forms_installed = true;
                }
            }
        } else {
            return true;
        }
        return $installed;
    }

    public static function plugin_row() {
        if (!self::is_gravityforms_supported()) {
            $message = sprintf(__("%sGravity Forms%s is required. %sPurchase it today!%s"), "<a href='http://www.gravityforms.com'>", "</a>", "<a href='http://www.gravityforms.com'>", "</a>");
            self::display_plugin_message($message, true);
        }
    }

    public static function display_plugin_message($message, $is_error = false) {
        $style = '';
        if ($is_error)
            $style = 'style="background-color: #ffebe8;"';

        echo '</tr><tr class="plugin-update-tr"><td colspan="5" class="plugin-update"><div class="update-message" ' . $style . '>' . $message . '</div></td>';
    }

    //--------------   Automatic upgrade ---------------------------------------------------
    function settings_link($links, $file) {
        static $this_plugin;
        if (!$this_plugin)
            $this_plugin = self::get_base_url();
        if ($file == $this_plugin) {
            $settings_link = '<a href="' . admin_url('admin.php?page=gf_salesforce') . '" title="' . __('Select the Gravity Form you would like to integrate with Salesforce. Contacts generated by this form will be automatically added to your Salesforce account.', 'gravity-forms-bb-salesforce') . '">' . __('Feeds', 'gravity-forms-bb-salesforce') . '</a>';
            array_unshift($links, $settings_link); // before other links
            $settings_link = '<a href="' . admin_url('admin.php?page=gf_settings&addon=Salesforce') . '" title="' . __('Configure your Salesforce settings.', 'gravity-forms-bb-salesforce') . '">' . __('Settings', 'gravity-forms-bb-salesforce') . '</a>';
            array_unshift($links, $settings_link); // before other links
        }
        return $links;
    }

    //Creates or updates database tables. Will only run when version changes
    private static function setup() {
        update_option("gf_bb_salesforce_version", self::$version);
    }

    //Adds feed tooltips to the list of tooltips
    public static function tooltips($tooltips) {
        $salesforce_tooltips = array(
                                    "salesforce_contact_list" => "<h6>" . __("Salesforce Object", "gravity-forms-bb-salesforce") . "</h6>" . __("Select the Salesforce object you would like to add your contacts to.", "gravity-forms-bb-salesforce"),
                                    "salesforce_gravity_form" => "<h6>" . __("Gravity Form", "gravity-forms-bb-salesforce") . "</h6>" . __("Select the Gravity Form you would like to integrate with Salesforce. Contacts generated by this form will be automatically added to your Salesforce account.", "gravity-forms-bb-salesforce"),
                                    "salesforce_map_fields" => "<h6>" . __("Map Standard Fields", "gravity-forms-bb-salesforce") . "</h6>" . __("Associate your Salesforce fields to the appropriate Gravity Form fields by selecting. <a href='http://www.salesforce.com/us/developer/docs/api/Content/field_types.htm'>Learn about the Field Types</a> in Salesforce.", "gravity-forms-bb-salesforce"),
                                    "salesforce_optin_condition" => "<h6>" . __("Opt-In Condition", "gravity-forms-bb-salesforce") . "</h6>" . __("When the opt-in condition is enabled, form submissions will only be exported to Salesforce when the condition is met. When disabled all form submissions will be exported.", "gravity-forms-bb-salesforce")
        )
        ;
        return array_merge($tooltips, $salesforce_tooltips);
    }

    public static function is_debug() {
        if (is_null(self::$is_debug)) {
            self::$is_debug = !empty(self::$settings['debug']) && current_user_can('manage_options');
        }
        return self::$is_debug;
    }

    public static function is_notify_on_error() {
        $settings['notifyemail'] = trim(rtrim(self::$settings['notifyemail']));
        if (!empty($settings['notifyemail']) && is_email($settings['notifyemail'])) {
            return $settings['notifyemail'];
        } else {
            return false;
        }
    }

    public static function settings_page() {
        if (isset($_POST["uninstall"])) {
            check_admin_referer("uninstall", "gf_salesforce_uninstall");
            self::uninstall();
            ?>
<title>get</title>
<div class="updated fade" style="padding: 20px;"><?php _e(sprintf("Gravity Forms Salesforce Add-On has been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravity-forms-bb-salesforce")?></div>
<?php
            return;
        } else if (isset($_POST["gf_salesforce_submit"])) {
            check_admin_referer("update", "gf_salesforce_update");

            // If the new transient time is less than the old, we can assume they want it cleared out.
            if (floatval($_POST["gf_salesforce_cache_time"]) < floatval(self::$settings['cache_time'])) {
                self::refresh_transients(true);
            }
            $settings = array(
                            //                "username" => stripslashes($_POST["gf_salesforce_username"]),
                            //                "password" => stripslashes($_POST["gf_salesforce_password"]),
                            "securitytoken" => stripslashes($_POST["gf_salesforce_securitytoken"]),
                            "debug" => isset($_POST["gf_salesforce_debug"]),
                            "apiurl" => $_POST["gf_bb_salesforce_apiurl"],
                            "notifyemail" => trim(rtrim(esc_html($_POST["gf_salesforce_notifyemail"]))),
                            'cache_time' => floatval($_POST["gf_salesforce_cache_time"]),
                            "sf_login" => isset($_POST["gf_bb_salesforce_login"]),
                            'login_form' => $_POST["gf_bb_salesforce_login_form"],
                            'login_username' => $_POST["gf_bb_salesforce_username"],
                            'login_password' => $_POST["gf_bb_salesforce_password"],
                            'login_temp_password' => $_POST["gf_bb_salesforce_temp_password"],
                            'salt_string' => $_POST["gf_bb_salesforce_saltstring"],
                            'sf_login_fallback' => isset($_POST["gf_bb_salesforce_login_fallback"]),
            );
            update_option("gf_bb_salesforce_settings", $settings);
        } else {
            $settings = get_option("gf_bb_salesforce_settings");
        }

        $settings = wp_parse_args($settings, array(
                                                    //                "username" => '',
                                                    //                "password" => '',
                                                    'securitytoken' => '',
                                                    'apiurl' => '',
                                                    "debug" => false,
                                                    'notify' => false,
                                                    "notifyemail" => '',
                                                    'cache_time' => 86400,
                                                    'sf_login' => false,
                                                    'login_form' => '',
                                                    'login_username' => 'Email',
                                                    'login_password' => 'Website_Password__c',
                                                    'login_temp_password' => '',
                                                    'salt_string' => substr("abcdefghijklmnopqrstuvwxyz", mt_rand(0, 25), 1).substr(md5(time()), 1, 9),
                                                    'sf_login_fallback' => true,
        ));

        $api = self::get_api($settings);

        $message = '';

        if ($message) {
            $message = str_replace('Api', 'API', $message);
            ?>
<div id="message" class="<?php echo $class ?>"><?php echo wpautop($message); ?></div>
<?php
        }
        ?>
<form method="post" action="<?php echo remove_query_arg('refresh'); ?>">
    <?php wp_nonce_field("update", "gf_salesforce_update")?>
    <h3><?php _e("Salesforce Account Information", "gravity-forms-bb-salesforce") ?></h3>
    <table class="form-table">
        <tr>
            <th scope="row"><label for="gf_salesforce_securitytoken"><?php _e("Security Token", "gravity-forms-bb-salesforce"); ?></label></th>
            <td><input type="text" class="code" id="gf_salesforce_securitytoken" name="gf_salesforce_securitytoken" size="40" value="<?php echo !empty($settings["securitytoken"]) ? esc_attr($settings["securitytoken"]) : ''; ?>" /></td>
        </tr>
        <tr>
            <th scope="row"><label for="gf_bb_salesforce_apiurl"><?php _e("BB Salesforce bridge api url", "gravity-forms-bb-salesforce"); ?></label></th>
            <td><input type="text" class="code" id="gf_salesforce_password" name="gf_bb_salesforce_apiurl" size="40" value="<?php echo !empty($settings["apiurl"]) ? esc_attr($settings["apiurl"]) : ''; ?>" /></td>
        </tr>
        <tr>
            <th scope="row"><label for="gf_salesforce_debug"><?php _e("Debug Form Submissions for Administrators", "gravity-forms-bb-salesforce"); ?></label></th>
            <td><input type="checkbox" id="gf_salesforce_debug" name="gf_salesforce_debug" size="40" value="1" <?php checked($settings["debug"], true); ?> /></td>
        </tr>
        <tr>
            <th scope="row"><label for="gf_salesforce_notifyemail"><?php _e("Notify by Email on Errors", "gravity-forms-bb-salesforce"); ?></label></th>
            <td><input type="text" id="gf_salesforce_notifyemail" name="gf_salesforce_notifyemail" size="30" value="<?php echo empty($settings["notifyemail"]) ? '' : esc_attr($settings["notifyemail"]); ?>"/>
                <span class="howto"><?php _e('An email will be sent to this email address if an entry is not properly added to Salesforce. Leave blank to disable.', 'gravity-forms-bb-salesforce'); ?></span>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="gf_salesforce_cache_time"><?php _e("Remote Cache Time", "gravity-forms-bb-salesforce"); ?></label><span class="howto"><?php _e("This is an advanced setting. You likely won't need to change this.", "gravity-forms-bb-salesforce"); ?></span></th>
            <td><select name="gf_salesforce_cache_time" id="gf_salesforce_cache_time">
                    <option value="60" <?php selected($settings["cache_time"] == '60', true); ?>><?php _e('One Minute (for testing only!)', 'gravity-forms-bb-salesforce'); ?></option>
                    <option value="3600" <?php selected($settings["cache_time"] == '3600', true); ?>><?php _e('One Hour', 'gravity-forms-bb-salesforce'); ?></option>
                    <option value="21600" <?php selected($settings["cache_time"] == '21600', true); ?>><?php _e('Six Hours', 'gravity-forms-bb-salesforce'); ?></option>
                    <option value="43200" <?php selected($settings["cache_time"] == '43200', true); ?>><?php _e('12 Hours', 'gravity-forms-bb-salesforce'); ?></option>
                    <option value="86400" <?php selected($settings["cache_time"] == '86400', true); ?>><?php _e('1 Day', 'gravity-forms-bb-salesforce'); ?></option>
                    <option value="172800" <?php selected($settings["cache_time"] == '172800', true); ?>><?php _e('2 Days', 'gravity-forms-bb-salesforce'); ?></option>
                    <option value="259200" <?php selected($settings["cache_time"] == '259200', true); ?>><?php _e('3 Days', 'gravity-forms-bb-salesforce'); ?></option>
                    <option value="432000" <?php selected($settings["cache_time"] == '432000', true); ?>><?php _e('5 Days', 'gravity-forms-bb-salesforce'); ?></option>
                    <option value="604800" <?php selected(empty($settings["cache_time"]) || $settings["cache_time"] == '604800', true); ?>><?php _e('1 Week', 'gravity-forms-bb-salesforce'); ?></option>
                </select>
                <span class="howto"><?php _e('How long should form and field data be stored? This affects how often remote picklists will be checked for the Live Remote Field Mapping feature.', 'gravity-forms-bb-salesforce'); ?></span>
                <span class="howto"><?php _e(sprintf("%sRefresh now%s.", '<a href="'.add_query_arg('refresh', 'transients').'">','</a>'), "gravity-forms-bb-salesforce"); ?></span>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="gf_bb_salesforce_login"><?php _e("Use Salesforce for Wordpress logins", "gravity-forms-bb-salesforce"); ?></label></th>
            <td><input type="checkbox" id="gf_bb_salesforce_login" name="gf_bb_salesforce_login" value="1" <?php checked($settings["sf_login"], true); ?> onclick="ToggleLoginEntry();">
            <span class="howto"><?php _e("Use a Gravity Form to allow users to log in from the front-end of the site using credentials stored in Salesforce. Admin login will still use the standard Wordpress login function.", "gravity-forms-bb-salesforce"); ?></span></td>
        </tr>
        <tr class="child_setting_row login_setting_row">
            <th scope="row"><label for="gf_bb_salesforce_login_form"><?php _e("Login Form", "gravity-forms-bb-salesforce"); ?></label></th>
            <td><select name="gf_bb_salesforce_login_form" id="gf_bb_salesforce_login_form">
                    <option value="" <?php selected($settings['login_form'] == '', true); ?>><?php _e('Please Select', 'gravity-forms-bb-salesforce'); ?></option>
<?php
                    $forms = RGFormsModel::get_forms(null, 'title');
                    foreach($forms as $form)
                        echo '<option value="'.$form->id.'" '.selected($settings['login_form'] == $form->id, true).'>'.$form->title.'</option>'."\n";
?>
                </select>
            </td>
        </tr>
        <tr class="child_setting_row login_setting_row">
            <th scope="row"><label for="gf_bb_salesforce_username"><?php _e("Salesforce Username Field", "gravity-forms-bb-salesforce"); ?></label></th>
            <td><input type="text" class="code" id="gf_bb_salesforce_username" name="gf_bb_salesforce_username" size="40" value="<?php echo !empty($settings["login_username"]) ? esc_attr($settings["login_username"]) : ''; ?>" /></td>
        </tr>
        <tr class="child_setting_row login_setting_row">
            <th scope="row"><label for="gf_bb_salesforce_password"><?php _e("Salesforce Password Field", "gravity-forms-bb-salesforce"); ?></label></th>
            <td><input type="text" class="code" id="gf_salesforce_password" name="gf_bb_salesforce_password" size="40" value="<?php echo !empty($settings["login_password"]) ? esc_attr($settings["login_password"]) : ''; ?>" /></td>
        </tr>
        <tr class="child_setting_row login_setting_row">
            <th scope="row"><label for="gf_bb_salesforce_temp_password"><?php _e("Salesforce Temp Password Field", "gravity-forms-bb-salesforce"); ?></label><span class="howto"><?php _e("(Leave blank if not using)", "gravity-forms-bb-salesforce"); ?></span></th>
            <td><input type="text" class="code" id="gf_bb_salesforce_temp_password" name="gf_bb_salesforce_temp_password" size="40" value="<?php echo !empty($settings["login_temp_password"]) ? esc_attr($settings["login_temp_password"]) : ''; ?>" /></td>
        </tr>
        <tr class="child_setting_row login_setting_row">
            <th scope="row"><label for="gf_bb_salesforce_saltstring"><?php _e("Password Salt String", "gravity-forms-bb-salesforce"); ?></label><span class="howto"><?php _e("Changing this will INVALIDATE all current passwords in Salesforce. You probably really don't want to do that.", "gravity-forms-bb-salesforce"); ?></span></th>
            <td><input type="text" class="code" id="gf_bb_salesforce_saltstring" name="gf_bb_salesforce_saltstring" size="40" value="<?php echo !empty($settings["salt_string"]) ? esc_attr($settings["salt_string"]) : ''; ?>" /></td>
        </tr>
        <tr class="child_setting_row login_setting_row">
            <th scope="row"><label for="gf_bb_salesforce_login_fallback"><?php _e("Fallback to regular WP login if Salesforce login fails?", "gravity-forms-bb-salesforce"); ?></label></th>
            <td><input type="checkbox" id="gf_bb_salesforce_login_fallback" name="gf_bb_salesforce_login_fallback" value="1" <?php checked($settings["sf_login_fallback"], true); ?>>
            <span class="howto"><?php _e("When this option is selected, in the case that the user's credentials are not successfully authenticated against Salesforce data the plugin will attempt to log the user in using the credentials stored in Wordpress instead.", "gravity-forms-bb-salesforce"); ?></span></td>
        </tr>
        <tr>
            <td colspan="2"><input type="submit" name="gf_salesforce_submit" class="button-primary" value="<?php _e("Save Settings", "gravity-forms-bb-salesforce") ?>" /></td>
        </tr>
    </table>
    <div></div>
    <script type="text/javascript">
        function ToggleLoginEntry() {
            if (jQuery('#gf_bb_salesforce_login').prop('checked'))
                jQuery('.login_setting_row').show();
            else
            	jQuery('.login_setting_row').hide();
        }
        ToggleLoginEntry();
    </script>
</form>
<form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_salesforce_uninstall")?>
            <?php if(GFCommon::current_user_can_any("gravityforms_salesforce_uninstall")){ ?>
                <div class="hr-divider"></div>
    <h3><?php _e("Uninstall Salesforce Add-On", "gravity-forms-bb-salesforce") ?></h3>
    <div class="delete-alert"><?php _e("Warning! This operation deletes ALL Salesforce Feeds.", "gravity-forms-bb-salesforce")?>
                    <?php
            $uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall Salesforce Add-On", "gravity-forms-bb-salesforce") . '" class="button" onclick="return confirm(\'' . __("Warning! ALL Salesforce Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravity-forms-bb-salesforce") . '\');"/>';
            echo apply_filters("gform_salesforce_uninstall_button", $uninstall_button);
            ?>
                </div>
            <?php } ?>
        </form>
<?php
    }

    public static function BBSFapicall($url, $method, $data = null) {
        $settings = get_option("gf_bb_salesforce_settings");
        $apiurl = $settings['apiurl'];
        $rest = new RestRequest($apiurl . $url, $method, $data);
        $rest->execute();
        $body = $rest->getResponseBody();
        $body = json_decode($body);
        return $body;
    }

    public static function get_api($settings = array()) {
        return true;
    }

    public function r($content, $die = false) {
        echo '<pre>';
        print_r($content);
        echo '</pre>';
        if ($die) {
            die();
        }
        return;
    }

    public function getField($objectType = 'account', $field_name = '') {

        // Cache the field to save lookups.
        // MD5 is to ensure length is correct.
        $field = get_site_transient('sfgf_' . md5('lists_' . $objectType . '_' . $field_name));
        if ($field && !is_wp_error($field) && !(current_user_can('administrator') && (isset($_REQUEST['refresh']) || isset($_REQUEST['cache'])))) {
            return $field;
        }

        $fields = self::getFields($objectType);

        foreach ($fields as $field) {
            if ($field['tag'] === $field_name) {
                set_site_transient('sfgf_' . md5('lists_' . $objectType . '_' . $field_name), $field, self::$settings['cache_time']);
                return $field;
            }
        }
    }

    public function getAllTypeFields($feedtype) {
        $objs = GFBBSalesforceData::get_objects_list_for_feed_type($feedtype);
        $list = array();
        foreach ($objs['objects'] as $obj['type']) {
            $listo = self::getFields($obj);
            $list = array_merge($list, $listo);
        }
        return $list;
    }

    public function getFields($objectType = 'account', $type = null) {
        $lists = maybe_unserialize(get_site_transient('sfgf_lists_fields_' . $objectType));
        if ($lists && !empty($lists) && is_array($lists) && (!isset($_REQUEST['refresh']) || (isset($_REQUEST['refresh']) && $_REQUEST['refresh'] !== 'lists'))) {
            foreach ($lists as $key => $list) {
                // If you only want one type of field, and it's not that type, keep going
                if (!empty($type)) {
                    if ((is_string($type) && $list['type'] !== $type) || (is_array($type) && !in_array($list['type'], $type))) {
                        unset($lists[$key]);
                    }
                }
            }
            return $lists;
        }

        $accountdescribe = json_decode(file_get_contents(self::$apiurl . 'meta/' . $objectType)); //$api->describeSObject($objectType);
        if (!is_object($accountdescribe) || !isset($accountdescribe->fields)) {
            return false;
        }

        $lists = $field_details = array();
        foreach ($accountdescribe->fields as $Field) {

            if (!is_object($Field)) {
                continue;
            }

            $field_details = array(
                                'name' => esc_js($objectType . '.' . $Field->label),
                                'req' => (!empty($Field->createable) && empty($Field->nillable) && empty($Field->defaultedOnCreate)),
                                'tag' => esc_js($Field->name),
                                'obj' => $objectType,
                                'type' => $Field->type,
                                'length' => $Field->length,
                                'picklistValues' => isset($Field->picklistValues) ? $Field->picklistValues : null,
                                'updateable' => isset($Field->updateable) ? $Field->updateable : null
            );

            $all_lists[] = $field_details;

            // If you only want one type of field, and it's not that type, keep going
            if (!empty($type)) {
                if ((is_string($type) && $Field->type !== $type) || (is_array($type) && !in_array($Field->type, $type))) {
                    continue;
                }
            }

            $lists[] = $field_details;
        }

        asort($lists);

        set_site_transient('sfgf_lists_fields_' . $objectType, $all_lists, self::$settings['cache_time']);

        return $lists;
    }

    public function getObjectTypes() {
        $lists = get_site_transient('sfgf_objects');

        if ($lists && (!isset($_REQUEST['refresh']) || (isset($_REQUEST['refresh']) && $_REQUEST['refresh'] !== 'lists'))) {
            return $lists;
        }

        try {
            $objects = json_decode(file_get_contents(self::$apiurl . 'meta')); // $api->describeGlobal();


            if (empty($objects) || !is_object($objects) || !isset($objects->sobjects)) {
                return false;
            }

            $lists = array();
            foreach ($objects->sobjects as $object) {
                if (!is_object($object) || empty($object->createable)) {
                    continue;
                }
                $lists[$object->name] = esc_html($object->name); //esc_html( $object->label );
            }

            asort($lists);

            set_site_transient('sfgf_objects', $lists, self::$settings['cache_time']);

            return $lists;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function login_via_salesforce($validation_result) {
        if ($validation_result["is_valid"] == false)
            return $validation_result;

        $form = $validation_result["form"];
        $fieldcnt = 0;
        $wsun = null;
        $username_field = self::$settings['login_username'];
        $password_field = self::$settings['login_password'];
        $temp_password_field = self::$settings['login_temp_password'];
        foreach ($form['fields'] as &$field) {
            if ($field['type'] == 'text' && !$field['enablePasswordInput']) { // Username
                $submitted_username = rgpost("input_{$field['id']}");

                $fields = 'Id,AccountId,'.$username_field.','.$password_field.',FirstName,LastName';
                if ($username_field != 'Email')
                    $fields .= ',Email';
                if (!empty($temp_password_field))
                    $fields .= ','.$temp_password_field;
                $fields = apply_filters('gfbbsf_login_fields', $fields);
                // First check contact
                $testarray = array(
                        'data' => array(
                                    $username_field => $submitted_username,
                                ),
                        'fields' => $fields,
                );
                $queryy = http_build_query($testarray);
                $body = GFBBSalesforce::BBSFapicall('/object/Contact?' . $queryy, "GET");
                if (isset($body->records[0]->Id)) {
                    $wsun = $body->records[0];
                }
            }
            if ($field['type'] == 'text' && $field['enablePasswordInput']) { // Password
                $submitted_password = rgpost("input_{$field['id']}");

                if (!is_null($wsun)) {
                    // Check temp password first if configured
                    if (!empty($temp_password_field) && !empty($wsun->$temp_password_field)) {
                        if ($wsun->$temp_password_field == $submitted_password) {
                            $wsun->pw_reset_required = true;
                            self::login_user($wsun, $submitted_password);
                        }
                    } else {
                        if ($wsun->$password_field == self::encrypt_password($submitted_password))
                            self::login_user($wsun, $submitted_password);
                    }
                }
            }

            $fieldcnt++;
        }

        if (!is_user_logged_in()) {
            if (!self::login_via_wordpress($submitted_username, $submitted_password)) {
                $validation_result["is_valid"] = false;
                $form["fields"][$fieldcnt]["failed_validation"] = true;
                $form["fields"][$fieldcnt]["validation_message"] = 'Username and/or Password Incorrect';

                $validation_result["form"] = $form;
            }
        }

        return $validation_result;
    }

    private static function login_via_wordpress($username, $password) {
        if (self::$settings['sf_login_fallback']) {
            // Salesforce login failed, try regular WP login
            $creds = array();
            $creds['user_login'] = $username;
            $creds['user_password'] = $password;
            $creds['remember'] = true;
            $user = wp_signon($creds, false);
            return !is_wp_error($user);
        }
        return false;
    }

    public static function encrypt_password($password) {
        return md5($password.self::$settings['salt_string']);
    }

    private static function login_user($wsun, $password) {
        $wsun->logged = TRUE;
        $email = $wsun->Email;
        $user_id = email_exists($email);
        if($user_id) { // $email already exists in wp_users
            $user_info = get_userdata($user_id);
            $user_login = $user_info->user_login;
        } else { // $email does not exist in wp_users -> create user as $email
            $user_login = $email;
            wp_create_user($user_login, $password, $email);
        }

        // set login variables
        $user = get_userdatabylogin($user_login);
        $user_id = $user->ID;

        // login as user
        wp_set_current_user($user_id, $user_login);
        wp_set_auth_cookie($user_id);
        do_action('wp_login', $user_login);
        if (!session_id()) // [MP] For some reason the session doesn't seem to exist yet
            session_start();
        $_SESSION['USER'] = $wsun;
        do_action('gfbbsf_login', $user_login);
    }

    public static function reset_password($email) {
        $testarray = array(
                'data' => array(
                            'Email' => $email,
                        ),
                'fields' => 'Id',
        );
        $queryy = http_build_query($testarray);
        $body = GFBBSalesforce::BBSFapicall('/object/Contact?' . $queryy, "GET");
        if (isset($body->records[0]->Id)) {
            $contact = $body->records[0];
            $new_password = substr("abcdefghijklmnopqrstuvwxyz", mt_rand(0, 25), 1).substr(md5(time()), 1, 9);
            $data = array(
                    'Contact' => array(
                            'ID' => $contact->Id,
                            self::$settings['login_temp_password'] => $new_password,
                            self::$settings['login_password'] => '',
                    ),
            );
            self::update_salesforce($data);
        }
    }

    public static function add_permissions() {
        global $wp_roles;
        $wp_roles->add_cap("administrator", "gravityforms_salesforce");
        $wp_roles->add_cap("administrator", "gravityforms_salesforce_uninstall");
    }

    //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
    public static function members_get_capabilities($caps) {
        return array_merge($caps, array(
                                        "gravityforms_salesforce",
                                        "gravityforms_salesforce_uninstall"
        ));
    }

    public static function disable_salesforce() {
        delete_option("gf_bb_salesforce_settings");
    }

    private static function show_field_type_desc($field = '') {
        $types = array(
                    'anyType',
                    'calculated',
                    'combobox',
                    'currency',
                    'DataCategoryGroupReference',
                    'email',
                    'encryptedstring',
                    'ID',
                    'masterrecord',
                    'multipicklist',
                    'percent',
                    'phone',
                    'picklist',
                    'reference',
                    'textarea',
                    'url'
        );
        return in_array($field, $types);
    }

    private function getNewTag($tag, $used = array()) {
        if (isset($used[$tag])) {
            $i = 1;
            while ($i < 1000) {
                if (!isset($used[$tag . '_' . $i])) {
                    return $tag . '_' . $i;
                }
                $i++;
            }
        }
        return $tag;
    }

    public static function send_to_salesforce($entry, $form) {
        $objectIds = array();
    	$sfData = array();
    	foreach ($form['fields'] as $field) {
    		if ($field['salesforceMapEnabled']) {
    			$sfFields = $field['salesforceMapField'];
    			if (!is_array($sfFields))
    				$sfFields = array($sfFields);

    			if (!empty($field['inputs'])) {
    				$values = array();
    				foreach ($field['inputs'] as $input) {
    					if (!empty($entry[$input['id']]))
    						$values[] = $entry[$input['id']];
    				}
    				$post_data = implode(';',$values);
    			} else
    				$post_data = $entry[$field['id']];

    			if (!empty($post_data)) {
    			    foreach ($sfFields as $sfField)
    				    $sfData[$field['salesforceMapObject']][$sfField] = $post_data;
    			}
    		}
    	}

    	if (!empty($sfData)) {
    		foreach ($sfData as $obj => $data) {
    			if (array_key_exists($obj, $objectIds))
    				$sfData[$obj]['ID'] = $objectIds[$obj];

    			if ($obj == 'Contact') { // Set some default values for contacts
    				$sfData[$obj]['s360a__EmailAddressPreferredType__c'] = 'Personal';
    				$sfData[$obj]['s360a__AddressPrimaryActive__c'] = 'boolean:TRUE';
    				$sfData[$obj]['s360a__AddressPrimaryPreferredMailingAddress__c'] = 'boolean:TRUE';
    				$sfData[$obj]['s360a__AddressPrimaryPreferredStreetAddress__c'] = 'boolean:TRUE';
    			}
    		}
    		$result = self::update_salesforce($sfData);
    	}
    }

    public static function update_salesforce(array $data) {
        $result = array();
        foreach ($data as $object => $dataArr) {
            if (isset($dataArr['ID'])) { // Existing record
                $obj_id = $dataArr['ID'];
                unset($dataArr['ID']);
                $result[$object] = self::BBSFapicall('/object/'.$object.'/'.$obj_id, 'PUT', array('obj' => $dataArr));
            } else // New record
                $result[$object] = self::BBSFapicall('/object/'.$object, 'POST', array('obj' => $dataArr));
        }
        return $result;
    }

    public static function get_form_fields($form_id) {
        $form = RGFormsModel::get_form_meta($form_id);
        $fields = array();

        //Adding default fields
        array_push($form["fields"], array(
                                        "id" => "date_created",
                                        "label" => __("Entry Date", "gravity-forms-bb-salesforce")
        ));
        array_push($form["fields"], array(
                                        "id" => "ip",
                                        "label" => __("User IP", "gravity-forms-bb-salesforce")
        ));
        array_push($form["fields"], array(
                                        "id" => "source_url",
                                        "label" => __("Source Url", "gravity-forms-bb-salesforce")
        ));

        if (is_array($form["fields"])) {
            foreach ($form["fields"] as $field) {
                if (isset($field["inputs"]) && is_array($field["inputs"]) && $field['type'] !== 'checkbox' && $field['type'] !== 'select') {

                    //If this is an address field, add full name to the list
                    if (RGFormsModel::get_input_type($field) == "address")
                        $fields[] = array(
                                        $field["id"],
                                        GFCommon::get_label($field) . " (" . __("Full", "gravity-forms-bb-salesforce") . ")"
                        );

                    foreach ($field["inputs"] as $input)
                        $fields[] = array(
                                        $input["id"],
                                        GFCommon::get_label($field, $input["id"])
                        );
                }                 //                else if(empty($field["displayOnly"])){
                else if (true) {
                    $fields[] = array(
                                    $field["id"],
                                    GFCommon::get_label($field)
                    );
                }
            }
        }
        return $fields;
    }

    private static function get_address($entry, $field_id) {
        $street_value = str_replace("  ", " ", trim($entry[$field_id . ".1"]));
        $street2_value = str_replace("  ", " ", trim($entry[$field_id . ".2"]));
        $city_value = str_replace("  ", " ", trim($entry[$field_id . ".3"]));
        $state_value = str_replace("  ", " ", trim($entry[$field_id . ".4"]));
        $zip_value = trim($entry[$field_id . ".5"]);
        $country_value = GFCommon::get_country_code(trim($entry[$field_id . ".6"]));

        $address = $street_value;
        $address .= !empty($address) && !empty($street2_value) ? "  $street2_value" : $street2_value;
        $address .= !empty($address) && (!empty($city_value) || !empty($state_value)) ? "  $city_value" : $city_value;
        $address .= !empty($address) && !empty($city_value) && !empty($state_value) ? "  $state_value" : $state_value;
        $address .= !empty($address) && !empty($zip_value) ? "  $zip_value" : $zip_value;
        $address .= !empty($address) && !empty($country_value) ? "  $country_value" : $country_value;

        return $address;
    }

    public static function get_mapped_field_list($variable_name, $selected_field, $fields) {
        $field_name = "salesforce_map_field_" . $variable_name;
        $str = "<select name='$field_name' id='$field_name'><option value=''>" . __("", "gravity-forms-bb-salesforce") . "</option>";
        foreach ($fields as $field) {
            $field_id = $field[0];
            $field_label = $field[1];
            $str .= "<option value='" . $field_id . "' " . selected(($field_id == $selected_field), true, false) . ">" . $field_label . "</option>";
        }
        $str .= "</select>";
        return $str;
    }

    public static function get_mapped_field_checkbox($variable_name, $selected_field, $field) {
        $field_name = "salesforce_map_field_" . $variable_name;
        $field_id = $field[0];
        $str = "<input name='$field_name' id='$field_name' type='checkbox' value='$field_id'";
        $selected = $field_id == $selected_field ? " checked='checked'" : false;
        if ($selected) {
            $str .= $selected;
        }

        $str .= " />";
        return $str;
    }

    public static function export($entry, $form) {
        //Login to Salesforce
        $api = self::get_api();

        //loading data class
        require_once (self::get_base_path() . "/data.php");

        //getting all active feeds
        $feeds = GFBBSalesforceData::get_feed_by_form($form["id"], true);

        foreach ($feeds as $feed) {
            //only export if user has opted in
            if (self::is_optin($form, $feed)) {
                self::export_feed($entry, $form, $feed, $api);
            }
        }
    }

    public static function export_feed($entry, $form, $feed, $api) {
        if (empty($feed["meta"]["contact_object_name"])) {
            return false;
        }

        $contactId = self::create($entry, $form, $feed, $api);

        return $contactId;
    }

    private function create($entry, $form, $feed, $api) {
        $merge_vars = array();
        $IFBOOLEAN = false;
        foreach ($feed["meta"]["field_map"] as $obj => $objn) {
            foreach ($objn as $var_tag => $field_id) {

                $field = RGFormsModel::get_field($form, $field_id);
                $input_type = RGFormsModel::get_input_type($field);

                if ($field['choices'][0]['value'] == 'boolean:TRUE')
                    $IFBOOLEAN = true;
                if ($var_tag == 'address_full') {
                    $merge_vars[$obj][$var_tag] = self::get_address($entry, $field_id);
                } else if ($var_tag == 'country') {
                    $merge_vars[$obj][$var_tag] = empty($entry[$field_id]) ? '' : GFCommon::get_country_code(trim($entry[$field_id]));
                }                 // If, for example an user enters 0 in a text field type expecting a number
                else if (isset($entry[$field_id]) && $entry[$field_id] === "0") {
                    $merge_vars[$obj][$var_tag] = "0";
                } else if ($var_tag != "email") {
                    if (!empty($entry[$field_id]) && !($entry[$field_id] == "0")) {
                        switch ($input_type) {
                            case 'multiselect':
                                // If there are commas in the value, this makes it so it can be comma exploded.
                                // Values cannot contain semicolons: http://boards.developerforce.com/t5/NET-Development/Salesforce-API-inserting-values-into-multiselect-fields-using/td-p/125910
                                foreach ($field['choices'] as $choice) {
                                    $entry[$field_id] = str_replace($choice, str_replace(',', '&#44;', $choice), $entry[$field_id]);
                                }
                                // Break into an array
                                $elements = explode(",", $entry[$field_id]);

                                // We decode first so that the commas are commas again, then
                                // implode the array to be picklist format for SF
                                $merge_vars[$obj][$var_tag] = implode(';', array_map('html_entity_decode', array_map('htmlspecialchars', $elements)));
                                break;
                            default:
                                $value = htmlspecialchars($entry[$field_id]);
                                $merge_vars[$obj][$var_tag] = $value;
                        }
                    } else {

                        // This is for checkboxes
                        $elements = array();
                        foreach ($entry as $key => $value) {
                            if (floor($key) == floor($field_id) && !empty($value)) {
                                $elements[] = htmlspecialchars($value);
                            }
                        }
                        $merge_vars[$obj][$var_tag] = implode(';', array_map('htmlspecialchars', $elements));
                        if ($IFBOOLEAN) {
                            if ($merge_vars[$obj][$var_tag] == "") {
                                $merge_vars[$obj][$var_tag] = 'boolean:FALSE';
                            } else {
                                $merge_vars[$obj][$var_tag] = 'boolean:TRUE';
                            }
                        }
                    }
                }
            }
        }

        dispatchSFrequest($entry, $form, $feed, $merge_vars);
    }

    public static function feedforform($form_id, $isactive = false) {
        return GFBBSalesforceData::get_feed_by_form($form_id, $isactive);
    }

    public static function fetchRecodtypes($objectType) {
        $rtypes = maybe_unserialize(get_site_transient('sfgf_rtypes_' . $objectType));
        if ($rtypes)
            return $rtypes;

        $accountdescribe = json_decode(file_get_contents(self::$apiurl . 'meta/' . $objectType)); //$api->describeSObject($objectType);

        if (isset($accountdescribe->recordTypeInfos)) {
            foreach ($accountdescribe->recordTypeInfos as $RecordType) {
                $rtypes[$RecordType->name] = $RecordType;
                $rtypes[$RecordType->recordTypeId] = $RecordType;
            }
        }

        set_site_transient('sfgf_rtypes_' . $objectType, $rtypes, self::$settings['cache_time']);

        return $rtypes;
    }

    public static function getAttachments($objId) {
        $arrayreq = array(
                'fields' => 'Id,ParentId,ContentType,IsDeleted,Name',
                'data' => array(
                        'ParentId' => $objId
                )
        );
        $queryy = http_build_query($arrayreq);
        $body = self::BBSFapicall('/object/Attachment?' . $queryy, "GET");
        $upload_dir = wp_upload_dir();
        foreach ($body->records as &$attachment) {
            $fna = $attachment->Id.'.pdf'; // @todo use content type to determine extension
            $filename = trailingslashit($upload_dir['path']).$fna;
            $url = trailingslashit($upload_dir['url']).$fna;

            if (!file_exists($filename)) {
                $file = file_get_contents(self::$apiurl.'attachment/'.$attachment->Id);
                file_put_contents($filename, $file);
            }
            $attachment->url = $url;
        }
        return $body->records;
    }

    public static function fetchObjImageUrl($objId) {
        $val = false;
        if (!empty($objId)) {
            $trans_name = 'sfgf_objIM_' . $objId;
            $upload_dir = wp_upload_dir();
            $val = maybe_unserialize(get_site_transient($trans_name));
            $path = trailingslashit($upload_dir['path']) . $val;

            if ($val == 'noimageattached')
                return false;
            if ($val && file_exists($path)) {
                return $val;
            }

            $arrayreq = array(
                            'fields' => 'Id,ParentId,ContentType,IsDeleted,Name',
                            'data' => array(
                                            'ParentId' => $objId
                            )
            );
            $queryy = http_build_query($arrayreq);
            $body = self::BBSFapicall('/object/Attachment?' . $queryy, "GET");
            $ATTACHM = null;
            if (isset($body->records[0]->Id)) {
                foreach ($body->records as $value) {
                    if (!$value->IsDeleted && $value->ContentType == 'image/jpeg') {
                        $ATTACHM = $value;
                        break;
                    }
                }
            }

            if ($ATTACHM) {
                $fna = $ATTACHM->Id . '.jpg';
                $filename = trailingslashit($upload_dir['path']) . $fna;

                if (!file_exists($filename)) {
                    $IMG = file_get_contents(self::$apiurl . 'attachment/' . $ATTACHM->Id);

                    file_put_contents($filename, $IMG);
                }
                $val = $fna;
            } else {
                $val = false;
            }

            if ($val == FALSE) {
                set_site_transient($trans_name, "noimageattached", self::$settings['cache_time']);
            } else {
                set_site_transient($trans_name, $val, self::$settings['cache_time']);
            }
        }
        return $val;
    }

    public static function raw_query($soql) {
        $query = http_build_query(array('query' => $soql));
        return self::BBSFapicall('/query?' . $query, "GET");
    }

    function _remove_empty_fields($merge_var) {
        return ((function_exists('mb_strlen') && mb_strlen($merge_var) > 0) || !function_exists('mb_strlen') && strlen($merge_var) > 0);
    }

    function _convert_to_utf_8($string) {
        if (function_exists('mb_convert_encoding') && !seems_utf8($string)) {
            $string = mb_convert_encoding($string, "UTF-8");
        }

        // Salesforce can't handle newlines in SOAP; we encode them instead.
        $string = str_replace("\n", '&#x0a;', $string);
        $string = str_replace("\r", '&#x0d;', $string);
        $string = str_replace("\t", '&#09;', $string);

        // Remove control characters (like page break, etc.)
        $string = preg_replace('/[[:cntrl:]]+/', '', $string);

        // Escape XML characters like `< ' " & >`
        $string = esc_attr($string);

        return $string;
    }

    function entry_info_link_to_salesforce($form_id, $lead) {
        $salesforce_id = gform_get_meta($lead['id'], 'salesforce_id');
        if (!empty($salesforce_id)) {
            echo sprintf(__('Salesforce ID: <a href="https://na9.salesforce.com/' . $salesforce_id . '">%s</a><br /><br />', 'gravity-forms-bb-salesforce'), $salesforce_id);
        }
    }

    private function add_note($id, $note) {
        if (!apply_filters('gravityforms_salesforce_add_notes_to_entries', true)) {
            return;
        }

        RGFormsModel::add_note($id, 0, __('Gravity Forms Salesforce Add-on'), $note);
    }

    public static function uninstall() {

        //loading data lib
        require_once (self::get_base_path() . "/data.php");

        if (!GFBBSalesforce::has_access("gravityforms_salesforce_uninstall"))
            die(__("You don't have adequate permission to uninstall Salesforce Add-On.", "gravity-forms-bb-salesforce"));

        //dropping all tables
        GFBBSalesforceData::drop_tables();

        //removing options
        delete_option("gf_bb_salesforce_settings");
        delete_option("gf_bb_salesforce_version");

        //Deactivating plugin
        $plugin = "gravity-forms-bb-salesforce/salesforce.php";
        deactivate_plugins($plugin);
        update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
    }

    public static function is_optin($form, $settings) {
        $config = $settings["meta"];
        $operator = $config["optin_operator"];

        $field = RGFormsModel::get_field($form, $config["optin_field_id"]);
        $field_value = RGFormsModel::get_field_value($field, array());
        $is_value_match = is_array($field_value) ? in_array($config["optin_value"], $field_value) : $field_value == $config["optin_value"];

        return !$config["optin_enabled"] || empty($field) || ($operator == "is" && $is_value_match) || ($operator == "isnot" && !$is_value_match);
    }

    private static function is_gravityforms_installed() {
        return class_exists("RGForms");
    }

    private static function is_gravityforms_supported() {
        if (class_exists("GFCommon")) {
            $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
            return $is_correct_version;
        } else {
            return false;
        }
    }

    protected static function has_access($required_permission) {
        $has_members_plugin = function_exists('members_get_capabilities');
        $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
        if ($has_access)
            return $has_members_plugin ? $required_permission : "level_7";
        else
            return false;
    }

    //Returns the url of the plugin's root folder
    static public function get_base_url() {
        return plugins_url(null, __FILE__);
    }

    //Returns the physical path of the plugin's root folder
    static protected function get_base_path() {
        $folder = basename(dirname(__FILE__));
        return WP_PLUGIN_DIR . "/" . $folder;
    }
}

class RestRequest {
    protected $url;
    protected $verb;
    protected $requestBody;
    protected $requestLength;
    protected $username;
    protected $password;
    protected $acceptType;
    protected $responseBody;
    protected $responseInfo;

    public function __construct($url = null, $verb = 'GET', $requestBody = null) {
        $this->url = $url;
        $this->verb = $verb;
        $this->requestBody = $requestBody;
        $this->requestLength = 0;
        $this->username = null;
        $this->password = null;
        $this->acceptType = 'application/json';
        $this->responseBody = null;
        $this->responseInfo = null;

        if ($this->requestBody !== null) {
            $this->buildPostBody();
        }
    }

    public function flush() {
        $this->requestBody = null;
        $this->requestLength = 0;
        $this->verb = 'GET';
        $this->responseBody = null;
        $this->responseInfo = null;
    }

    public function execute() {
        $ch = curl_init();
        $this->setAuth($ch);

        try {
            switch (strtoupper($this->verb)) {
                case 'GET':
                    $this->executeGet($ch);
                    break;
                case 'POST':
                    $this->executePost($ch);
                    break;
                case 'PUT':
                    $this->executePut($ch);
                    break;
                case 'DELETE':
                    $this->executeDelete($ch);
                    break;
                default:
                    throw new InvalidArgumentException('Current verb (' . $this->verb . ') is an invalid REST verb.');
            }
        } catch (InvalidArgumentException $e) {
            curl_close($ch);
            throw $e;
        } catch (Exception $e) {
            curl_close($ch);
            throw $e;
        }
    }

    public function buildPostBody($data = null) {
        $data = ($data !== null) ? $data : $this->requestBody;

        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid data input for postBody.  Array expected');
        }

        $data = json_encode($data);
        $this->requestBody = $data;
    }

    protected function executeGet($ch) {
        $this->doExecute($ch);
    }

    protected function executePost($ch) {
        if (!is_string($this->requestBody)) {
            $this->buildPostBody();
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, $this->requestBody);
        curl_setopt($ch, CURLOPT_POST, 1);

        $this->doExecute($ch);
    }

    protected function executePut($ch) {
        if (!is_string($this->requestBody)) {
            $this->buildPostBody();
        }

        $this->requestLength = strlen($this->requestBody);

        $fh = fopen('php://memory', 'rw');
        fwrite($fh, $this->requestBody);
        rewind($fh);

        curl_setopt($ch, CURLOPT_INFILE, $fh);
        curl_setopt($ch, CURLOPT_INFILESIZE, $this->requestLength);
        curl_setopt($ch, CURLOPT_PUT, true);

        $this->doExecute($ch);

        fclose($fh);
    }

    protected function executeDelete($ch) {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

        $this->doExecute($ch);
    }

    protected function doExecute(&$curlHandle) {
        $this->setCurlOpts($curlHandle);
        $this->responseBody = curl_exec($curlHandle);
        $this->responseInfo = curl_getinfo($curlHandle);
        curl_close($curlHandle);
    }

    protected function setCurlOpts(&$curlHandle) {
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 10);
        curl_setopt($curlHandle, CURLOPT_URL, $this->url);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, array('Accept: ' . $this->acceptType));
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, array('Content-type: ' . $this->acceptType));
    }

    protected function setAuth(&$curlHandle) {
        if ($this->username !== null && $this->password !== null) {
            curl_setopt($curlHandle, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curlHandle, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        }
    }

    public function getAcceptType() {
        return $this->acceptType;
    }

    public function setAcceptType($acceptType) {
        $this->acceptType = $acceptType;
    }

    public function getPassword() {
        return $this->password;
    }

    public function setPassword($password) {
        $this->password = $password;
    }

    public function getResponseBody() {
        return $this->responseBody;
    }

    public function getResponseInfo() {
        return $this->responseInfo;
    }

    public function getUrl() {
        return $this->url;
    }

    public function setUrl($url) {
        $this->url = $url;
    }

    public function getUsername() {
        return $this->username;
    }

    public function setUsername($username) {
        $this->username = $username;
    }

    public function getVerb() {
        return $this->verb;
    }

    public function setVerb($verb) {
        $this->verb = $verb;
    }
}
