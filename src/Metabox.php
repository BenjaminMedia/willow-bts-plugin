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
            'post',
            'side'
        );
    }

    /**
     * Handles the actions that should run (e.g. on ajax calls)
     */
    public function initActions()
    {
        if ($_POST['action'] === 'bts_translate_request_action') {
        	// verifying the nonce that was created
            if(! wp_verify_nonce($_POST['bts_nonce'], 'bts_nonce_action')) {
                die(-1);
            }
            // getting the languages as a string
			$languages = implode(',', array_keys($_POST['bts_languages']));

            $request = new WP_REST_Request(
	       		'POST',
				'/bonnier-willow-bts/v1/translation/create'
			);

            $postId = $_POST['post_ID'];

            $request->set_param('post_id', $postId);
            $request->set_param('languages', $languages);
            $request->set_param('comment', $_POST['bts_comment']);
            $request->set_param('deadline', $_POST['bts_deadline']);
            $response = rest_do_request($request);

            if ($response->get_status() === 200) {
            	// since all went well, we refetch the given article data, so we can show translation states
                $btsService = new Bts_Rest_Controller();
                $data = $btsService->getArticleRaw($postId);

				echo json_encode([
					'message' => $response->get_data(),
					'status' => $response->get_status(),
					'article' => $data,
				]);
			} else {
            	echo json_encode([
            		'message' => $response->get_data(),
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
				<div class="bts_extra_fields">
					<div>
						<label for="bts_comment">Skriv kommentar til oversætter</label>
						<textarea name="bts_comment" id="js-bts_comment"></textarea>
					</div>
					<div>
						<label for="bts_date">Vælg deadline</label>
						<input type="date" name="bts_deadline" id="js-bts_deadline" />
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