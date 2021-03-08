<?php

/**
 * Plugin Name: Bonnier Willow BTS
 * Description: Plugin to add translations to a Willow site, using the BTS service.
 * Version: 0.1.0
 * Author: Dwarf A/S
 * Author URI: https://dwarf.dk
 */

// adding out rest handling
require_once 'src/Controllers/Bts_Rest_Controller.php';

/**
 * Loads the various scripts to use
 */
function enqueue_bts_editor_assets() {
    $assets = require_once plugin_dir_path( __FILE__ ) . 'build/index.asset.php';

    // adding the script to the admin
    wp_enqueue_script(
        'bts-script',
        plugin_dir_url( __FILE__ ) . '/build/index.js',
        $assets['dependencies'],
        $assets['version'],
        true
    );
}
add_action('enqueue_block_editor_assets', 'enqueue_bts_editor_assets');


// adding rest routes, using a rest controller, so we do not have the entire implementation here
// TODO: add "Resource Discovery"
add_action( 'rest_api_init', function() {
    $restController = new Bts_Rest_Controller();
    $restController->register_routes();
});
