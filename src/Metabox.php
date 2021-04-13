<?php

namespace Bts;

use Bts\Controllers\Bts_Rest_Controller;
use WP_Post;

/**
 * A wordpress 4.9 meta box, that handles translations using BTS and LW
 */
class Metabox
{
    /**
     * Handles starting the meta box script, if we are in "admin mode"
     */
    public function __construct()
    {
        if (is_admin()) {
            add_action('load-post.php', [$this, 'initMetabox']);
            add_action('load-post-new.php', [$this, 'initMetabox']);
        }
    }

    public function initMetabox()
    {
        add_action('add_meta_boxes', [$this, 'addMetabox']);
    }

    /**
     * Adds the meta box to the editor screen
     */
    public function addMetabox()
    {
        add_meta_box(
            'bonnier-willow-bts',
            __('BTS Translations', 'bts'),
            [$this, 'renderMetabox'],
            'post',
            'side'
        );
    }

    /**
     * Renders the meta box the the user
     * @param WP_Post $post the current post we are editing
     */
    public function renderMetabox($post)
    {
        // Add nonce for security and authentication.
        wp_nonce_field('custom_nonce_action', 'custom_nonce');

        $btsService = new Bts_Rest_Controller();
        $data = $btsService->getArticleRaw($post->ID);
        var_dump($data);

        ?>
        <div class="bts-widget">
            <div class="bts-widget-content">
                <?php

                foreach ($data['languages'] as $language) {
                    // skipping the articles current language
                    if ($language['code'] === $data['language']) {
                        continue;
                    }
                    $selected = ! empty($language['state']) ? 'checked="checked"' : '';
                    ?>
                    <div>
                        <label>
                            <input type="checkbox" name="languages[<?php echo $language['code']; ?>]" <?php echo $selected; ?> />
                            <?php echo $language['name']; ?>
                        </label>

                        <?php if (! empty($language['state'])) { ?>
                            <span class="bts-status">(<?php echo __($language['state'], 'bts_states'); ?>)</span>
                        <?php } ?>
                    </div>

                <?php } ?>
            </div>
            <div class="bts-actions-area">
                <input type="submit" class="button button-large" value="Send til oversættelse" />
            </div>
        </div>
        <?php
    }
}