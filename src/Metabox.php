<?php

namespace Bts;

use Bts\Controllers\Bts_Rest_Controller;
use WP_Post;
use WP_REST_Request;

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

            add_action('init', [$this, 'initActions']);
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
            ['post', 'page', 'contenthub_composite'],
            'side'
        );
    }

    /**
     * Handles the actions that should run (e.g. on ajax calls)
     */
    public function initActions()
    {
        if (!empty($_POST['action']) && $_POST['action'] === 'bts_translate_request_action') {
            // verifying the nonce that was created
            if(! wp_verify_nonce($_POST['bts_nonce'], 'bts_nonce_action')) {
                die(-1);
            }
            // getting the languages as a string
            $languages = implode(',', array_keys($_POST['bts_languages']));

            $postId = $_POST['post_ID'];
            $comment = $_POST['bts_comment'];
            $deadline = $_POST['bts_deadline'];
            $time = $_POST['bts_deadline_time'];

            // if we have a date and time, we add these together so we can create a proper datetime object in BTS.
            if (! empty($deadline) && ! empty($time)) {
            	$deadline .= ' ' . $time;
			}

            $curl = curl_init();
            $url = get_rest_url() . 'bonnier-willow-bts/v1/translation/create';
            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => "post_id=$postId&languages=$languages&comment=$comment&deadline=$deadline",
                CURLOPT_HTTPHEADER => array(
                ),
            ));
            $response = curl_exec($curl);
            $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            if ($httpcode === 200) {
                // since all went well, we refetch the given article data, so we can show translation states
                $btsService = new Bts_Rest_Controller();
                $data = $btsService->getArticleRaw($postId);

                echo json_encode([
                    'message' => $response,
                    'status' => $httpcode,
                    'article' => $data,
                ]);
            } else {
                echo json_encode([
                    'message' => $response,
                    'status' => 400,
                ]);
            }


            wp_die();
        }
    }

    /**
     * Renders the meta box the the user
     * @param WP_Post $post the current post we are editing
     */
    public function renderMetabox($post)
    {

        $btsService = new Bts_Rest_Controller();
        $data = $btsService->getArticleRaw($post->ID);

        ?>
        <div class="bts-widget" id="bts-widget-area">
            <div class="bts-widget-content">
                <div class="bts-languages">
                    <?php

                    // Add nonce for security and authentication.
                    wp_nonce_field('bts_nonce_action', 'bts_nonce');

                    foreach ($data['languages'] as $language) {
                        // skipping the articles current language
                        if ($language['code'] === $data['language']) {
                            continue;
                        }
                        // no longer preselecting translated content, since we want the user to pick the ones to translate "now"
                        //$selected = ! empty($language['state']) ? 'checked="checked"' : '';
                        ?>
                        <div>
                            <label>
                                <input type="checkbox" class="bts-language-checkbox" name="bts_languages[<?php echo $language['code']; ?>]" data-language="<?php echo $language['code']; ?>"  />
                                <?php echo $language['name']; ?>
                            </label>

                            <span class="bts-status js-bts-status" data-language="<?php echo $language['code']; ?>"><?php echo (! empty($language['state'])) ? '('. __($language['state'], 'bts_states') .')' : ''; ?></span>
                        </div>

                    <?php } ?>
                </div>
				<div class="bts-deadline <?php echo (empty($data['deadline']) ? 'bts-field_hide' : ''); ?>">
					<span>Deadline set:</span><span class="bts-deadline-value"><?php echo (!empty($data['deadline']) ? $data['deadline'] : ''); ?></span>
				</div>
                <div class="bts_extra_fields">
                    <div>
                        <label for="bts_comment">Skriv kommentar til oversætter</label>
                        <textarea name="bts_comment" id="js-bts_comment"></textarea>
                    </div>
                    <div>
                        <label for="bts_date">Vælg deadline</label>
                        <input type="date" name="bts_deadline" id="js-bts_deadline" />
                        <input type="time" name="bts_deadline_time" id="js-bts_deadline_time" />
                    </div>
                </div>
            </div>
            <div class="bts-actions-area">
                <span id="js-bts-form-submit-status" class="bts-form-submit-status"></span>
                <input id="bts-form-submit" type="submit" class="button button-large" value="Send til oversættelse" />
            </div>
        </div>
        <?php
    }
}