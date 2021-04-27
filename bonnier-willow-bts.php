<?php

/**
 * Plugin Name: Bonnier Willow BTS
 * Description: Plugin to add translations to a Willow site, using the BTS service.
 * Version: 1.0.0
 * Author: Bonnier Publications
 * Author URI: https://bonnierpublications.com
 */

// adding aws library to the mix
use Bts\Metabox;

require_once 'vendor/autoload.php';

// adding out rest handling
require_once 'src/Controllers/Bts_Rest_Controller.php';
require_once 'src/Metabox.php';

// NOTE: WP5+
//if (function_exists('enqueue_bts_editor_assets')) {
//    /**
//     * Loads the various scripts to use
//     */
//    function enqueue_bts_editor_assets() {
//        $assets = require_once plugin_dir_path( __FILE__ ) . 'build/index.asset.php';
//
//        // adding the script to the admin
//        wp_enqueue_script(
//            'bts-script',
//            plugin_dir_url( __FILE__ ) . '/build/index.js',
//            $assets['dependencies'],
//            $assets['version'],
//            true
//        );
//    }
//
//    add_action('enqueue_block_editor_assets', 'enqueue_bts_editor_assets');
//} else {
    // handles loading a meta box in WP4.x
    new Metabox();
    wp_enqueue_style('bts_widget', plugin_dir_url(__FILE__) . 'css/admin.css');
    wp_enqueue_script('bts_widget', plugin_dir_url(__FILE__) . 'javascript/admin.js');
// }

// adding rest routes, using a rest controller, so we do not have the entire implementation here
// TODO: add "Resource Discovery"
add_action( 'rest_api_init', function() {
    $restController = new Bts\Controllers\Bts_Rest_Controller();
    $restController->register_routes();
});


/**
 * Handles adding a new settings page for the plugin
 */
function bts_add_settings_page() {
    add_options_page('BTS plugin settings', 'Bonnier Willow BTS', 'manage_options', 'bts-plugin', 'bts_render_plugin_settings_page');
}

add_action('admin_menu', 'bts_add_settings_page');

/**
 * Renders the settings page for the plugin
 */
function bts_render_plugin_settings_page() {
    ?>
    <h1>Bonnier Willow BTS settings</h1>
    <form action="options.php" method="post">
        <?php
        settings_fields('bts_plugin_options');
        do_settings_sections('bts_plugin_settings');
        ?>
        <input name="submit" class="button button-primary" type="submit" value="<?php esc_attr_e( 'Save' ); ?>" />
    </form>

    <?php
}

/**
 * Handles the actual fields on the plugin settings page
 */
function bts_register_settings() {
    register_setting( 'bts_plugin_options', 'bts_plugin_options', 'bts_plugin_options_validate' );
    add_settings_section( 'bts_plugin_site_settings', 'Site Settings', 'bts_plugin_site_section_text', 'bts_plugin_settings' );
    // adding fields to the settings section
    add_settings_field( 'bts_plugin_setting_site_handle', 'Site short handle', 'bts_plugin_setting_site_handle', 'bts_plugin_settings', 'bts_plugin_site_settings' );

    add_settings_section( 'bts_plugin_aws_settings', 'AWS Settings', 'bts_plugin_aws_section_text', 'bts_plugin_settings' );
    // adding fields to the settings section
    add_settings_field( 'bts_plugin_setting_aws_sns_region', 'SNS region', 'bts_plugin_setting_aws_sns_region', 'bts_plugin_settings', 'bts_plugin_aws_settings' );
    add_settings_field( 'bts_plugin_setting_aws_sns_version', 'SNS version', 'bts_plugin_setting_aws_sns_version', 'bts_plugin_settings', 'bts_plugin_aws_settings' );
    add_settings_field( 'bts_plugin_setting_aws_sns_key', 'SNS key', 'bts_plugin_setting_aws_sns_key', 'bts_plugin_settings', 'bts_plugin_aws_settings' );
    add_settings_field( 'bts_plugin_setting_aws_sns_secret', 'SNS secret', 'bts_plugin_setting_aws_sns_secret', 'bts_plugin_settings', 'bts_plugin_aws_settings' );
    add_settings_field( 'bts_plugin_setting_aws_sns_topic_translate', 'SNS topic request', 'bts_plugin_setting_aws_topic_translate', 'bts_plugin_settings', 'bts_plugin_aws_settings' );

    add_settings_section( 'bts_plugin_lw_settings', 'Language Wire Settings', 'bts_plugin_lw_section_text', 'bts_plugin_settings' );
    // adding fields to the lw settings section
    add_settings_field( 'bts_plugin_setting_lw_api_key', 'API key', 'bts_plugin_setting_lw_api_key', 'bts_plugin_settings', 'bts_plugin_lw_settings' );
    add_settings_field( 'bts_plugin_setting_lw_invoicing_account', 'Invoicing account', 'bts_plugin_setting_lw_invoicing_account', 'bts_plugin_settings', 'bts_plugin_lw_settings' );
    add_settings_field( 'bts_plugin_setting_lw_terminology', 'Terminology', 'bts_plugin_setting_lw_terminology', 'bts_plugin_settings', 'bts_plugin_lw_settings' );
    add_settings_field( 'bts_plugin_setting_lw_workarea', 'Workarea', 'bts_plugin_setting_lw_workarea', 'bts_plugin_settings', 'bts_plugin_lw_settings' );
    add_settings_field( 'bts_plugin_setting_lw_service_id', 'Service ID', 'bts_plugin_setting_lw_service_id', 'bts_plugin_settings', 'bts_plugin_lw_settings' );
}

add_action('admin_init', 'bts_register_settings');

/**
 * Handles "validating"/fixing the fields in the settings
 */
function bts_plugin_options_validate($input) {
	return $input;
}

/**
 * intro text to display for the AWS settings
 */
function bts_plugin_site_section_text() {
    echo '<p>Settings for the current site</p>';
}

/**
 * Callback method for handling the AWS SNS region field
 */
function bts_plugin_setting_site_handle() {
    $options = get_option('bts_plugin_options');
    echo "<input id='bts_plugin_setting_site_handle' name='bts_plugin_options[site_handle]' type='text' value='" . esc_attr($options['site_handle']) . "' />";
}

/**
 * intro text to display for the AWS settings
 */
function bts_plugin_aws_section_text() {
    echo '<p>Bonnier Willow plugin relies heavily on AWS SNS.<br/>The settings here are needed, so we can push to the correct SNS service.</p>';
}

/**
 * Callback method for handling the AWS SNS region field
 */
function bts_plugin_setting_aws_sns_region() {
    $options = get_option('bts_plugin_options');
    echo "<input id='bts_plugin_setting_aws_sns_region' name='bts_plugin_options[aws_sns_region]' type='text' value='" . esc_attr($options['aws_sns_region']) . "' />";
}
/**
 * Callback method for handling the AWS SNS version field
 */
function bts_plugin_setting_aws_sns_version() {
    $options = get_option('bts_plugin_options');
    echo "<input id='bts_plugin_setting_aws_sns_version' name='bts_plugin_options[aws_sns_version]' type='text' value='" . esc_attr($options['aws_sns_version']) . "' />";
}
/**
 * Callback method for handling the AWS SNS key field
 */
function bts_plugin_setting_aws_sns_key() {
    $options = get_option('bts_plugin_options');
    echo "<input id='bts_plugin_setting_aws_sns_key' name='bts_plugin_options[aws_sns_key]' type='text' value='" . esc_attr($options['aws_sns_key']) . "' />";
}
/**
 * Callback method for handling the AWS SNS secret field
 */
function bts_plugin_setting_aws_sns_secret() {
    $options = get_option('bts_plugin_options');
    echo "<input id='bts_plugin_setting_aws_sns_secret' name='bts_plugin_options[aws_sns_secret]' type='text' value='" . esc_attr($options['aws_sns_secret']) . "' />";
}
/**
 * Callback method for handling the AWS SNS topic translate field
 */
function bts_plugin_setting_aws_topic_translate() {
    $options = get_option('bts_plugin_options');
    echo "<p>Topic ARN that translation requests are sent to:</p>";
    echo "<input id='bts_plugin_setting_aws_topic_translate' name='bts_plugin_options[aws_topic_translate]' type='text' value='" . esc_attr($options['aws_topic_translate']) . "' />";
}


/**
 * intro text for Language Wire section
 */
function bts_plugin_lw_section_text() {
    echo '<p>Bonnier Willow plugin relies heavily on AWS SNS.<br/>The settings here are needed, so we can push to the correct SNS service.</p>';
}
/**
 * Callback method for handling the Language Wire invoicing account field
 */
function bts_plugin_setting_lw_invoicing_account() {
    $options = get_option('bts_plugin_options');
    echo "<input id='bts_plugin_setting_lw_invoicing_account' name='bts_plugin_options[lw_invoicing_account]' type='text' value='" . esc_attr($options['lw_invoicing_account']) . "' />";
}
/**
 * Callback method for handling the Language Wire invoicing account field
 */
function bts_plugin_setting_lw_api_key() {
    $options = get_option('bts_plugin_options');
    echo "<input id='bts_plugin_setting_lw_api_key' name='bts_plugin_options[lw_api_key]' type='text' value='" . esc_attr($options['lw_api_key']) . "' />";
}
/**
 * Callback method for handling the Language Wire service id field
 */
function bts_plugin_setting_lw_service_id() {
    $options = get_option('bts_plugin_options');
    echo "<input id='bts_plugin_setting_lw_service_id' name='bts_plugin_options[lw_service_id]' type='text' value='" . esc_attr($options['lw_service_id']) . "' />";
}
/**
 * Callback method for handling the Language Wire workarea field
 */
function bts_plugin_setting_lw_workarea() {
    $options = get_option('bts_plugin_options');
    echo "<input id='bts_plugin_setting_lw_workarea' name='bts_plugin_options[lw_workarea]' type='text' value='" . esc_attr($options['lw_workarea']) . "' />";
}
/**
 * Callback method for handling the Language Wire termninology field
 */
function bts_plugin_setting_lw_terminology() {
    $options = get_option('bts_plugin_options');
    echo "<input id='bts_plugin_setting_lw_terminology' name='bts_plugin_options[lw_terminology]' type='text' value='" . esc_attr($options['lw_terminology']) . "' />";
}
