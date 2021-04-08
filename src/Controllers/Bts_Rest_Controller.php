<?php

namespace Bts\Controllers;

use Aws\Sns\SnsClient;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Controller;
use WP_REST_Server;
use WP_Error;
use WP_Post;

/**
 * A controller for handling various requests to and from BTS
 * Class Bts_Rest_Controller
 */
class Bts_Rest_Controller extends WP_REST_Controller
{
    public const SITE_PREFIX = 'WILLOW_';

    /**
     * Registers the routes of the controller
     */
    public function register_routes()
    {
        $routeNamespace = 'bonnier-willow-bts/v1';
        // adding route for handling callbacks from AWS SNS
        register_rest_route(
            $routeNamespace,
            '/aws/sns',
            [
                // allowing multiple requests types from AWS SNS - just to make life easier
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'handle'],
            ]
        );

        // route for sending posts to be translated on the translation service
        register_rest_route(
            $routeNamespace,
            '/translation/create',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'sendPostToTranslation'],
            ]
        );



        // route for fetching info about a single article
        register_rest_route(
            $routeNamespace,
            '/articles/(?P<id>\d+)',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'getArticle'],
            ]
        );
    }

    /**
     * Sends the given post to the translation service
     * @param WP_REST_Request $request
     * @return WP_Error|WP_HTTP_Response
     */
    public function sendPostToTranslation(WP_REST_Request $request)
    {
        $postId = $request->get_param('post_id');
        $post = get_post($postId);

        if (empty($post)) {
            return new WP_Error('Cannot find post with post id: ' . $postId, 404);
        }

        // fetches the list of languages to translate the post into.
        $locales = $request->get_param('language');

        $this->getClient()->publish([
            'TopicArn' => $this->getTopicTranslateRequest(),
            'Message' => \json_encode($this->buildMessageData($post, $locales)),
        ]);

        return new WP_HTTP_Response('Post sent to translation');
    }

    /**
     * Handles incoming calls from AWS SNS
     * @param WP_REST_Request $request
     * @return WP_Error|WP_HTTP_Response
     */
    public function handle(WP_REST_Request $request)
    {
        $json = \json_decode($request->get_body());

        // handling subscription requests
        if ($this->isSubscriptionRequest($json)) {
            // handles new subscriptions, by calling the SubscribeUrl
            file_get_contents($json->SubscribeURL);
            // returning "a-ok" :)
            return new WP_HTTP_Response();
        }

        // TODO: remove this error_log statement
        error_log('Body json: ' . print_r($json,1));

        // decoding the actual message (again), since it's sent as json
        $messageData = \json_decode($json->Message);

        // the message id was not verified, return false
        if (! $this->verifyMessageId($messageData->external_id)) {
            error_log('Message ID could not be verified for current site: ' . $messageData->external_id);
            return new WP_Error(404, 'Err: 1: Post not found with message id: ' . $messageData->external_id);
        }

        // getting the post with the given external_id
        $post = $this->getPostFromMessageId($messageData->external_id);
        if (null === $post) {
            return new WP_Error(404, 'Err: 2: Could not find post with message id: ' . $messageData->external_id);
        }

        $translations = [];
        // adding the original post to our list of translations, as the "post to update",
        // since we need a post to save all the associated translations to. (@see pll_save_post_translations)
        $translations[pll_get_post_language($post->ID)] = $post->ID;

        // running through the translations, updating them using the given message data
        foreach ($messageData->translations as $translation) {
            $content = $translation->content;
            $language = $this->translateLanguageSlug($translation->language);
            // fetching the id of the translated post
            $translatedPostId = pll_get_post($post->ID, $language);

            // creates or updates the post
            $translatedPostId = wp_insert_post([
                'ID' => $translatedPostId,
                'post_content' => $content,
                'post_title' => $translation->title,
            ]);

            // if a post id is NOT returned after calling wp_insert_post, log the error and skip to next language
            if (0 === $translatedPostId || $translatedPostId instanceof WP_Error) {
                error_log('Could not save translation for post ' . $post->ID . ':' . $language);
                continue;
            }

            // TODO: handle post status, by setting it to draft? new translation should NOT be auto published.

            // updating the language on the post new/updated post
            pll_set_post_language($translatedPostId, $language);

            // adding the translated post, to the list of translations
            $translations[$language] = $translatedPostId;

            // TODO: check if we should somehow connect the master-post with this translated-post
        }

        // updating the original/main post, with all the available languages
        pll_save_post_translations($translations);

        // returning a success response
        return new WP_HTTP_Response('Translations saved', 200);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_HTTP_Response
     */
    public function getArticle(WP_REST_Request $request)
    {
        $post = get_post($request->get_param('id'));
        $postLanguage = pll_get_post_language($post->ID);

        $translations = pll_get_post_translations($post->ID);
        return new WP_HTTP_Response([
            'post_id' => $post->ID,
            'language' => $postLanguage,
            'available_languages' => array_diff(array_keys($translations), [$postLanguage]),
            'params' => $request->get_params(),
        ]);
    }

    /**
     * Verifies the given message id to make sure it belongs to this site.
     * We use a mix of SITE_PREFIX and the actual message id
     * @param string $messageId the message id to check
     * @return bool true if the message id belongs to here, else false
     */
    private function verifyMessageId(string $messageId)
    {
        // checking if the message id belongs to this site
        if (strpos($messageId, $this->getSiteMessageIdPrefix()) !== 0) {
            return false;
        }

        // trying to find the post, within the current message id
        return null !== $this->getPostFromMessageId($messageId);
    }

    /**
     * Fetches the post, matching the parsed post id in the given $messageId
     * @param string $messageId the message id, we need to parse, to get the post id from.
     * @return array|WP_Post|null
     */
    private function getPostFromMessageId($messageId)
    {
        // finds the post id, using __ as an ID splitter
        $postId = substr($messageId, strpos($messageId, '__') + 2);

        return get_post($postId);
    }

    /**
     * Checks if the given json object, is of type SubscriptionConfirmation
     * @param \stdClass $json
     * @return bool
     */
    private function isSubscriptionRequest($json): bool
    {
        return $json->Type === 'SubscriptionConfirmation';
    }

    /**
     * Fetches the current site's message prefix
     * @return string
     */
    private function getSiteMessageIdPrefix(): string
    {
        return self::SITE_PREFIX . $this->getSiteHandle();
    }

    /**
     * Fetches the SNS client
     * @return SnsClient
     */
    private function getClient(): SnsClient
    {
        // fetches the saved options
        $options = get_option('bts_plugin_options');

        // creating a SnsClient to use
        return new SnsClient([
            'region'        => $options['aws_sns_region'],
            'version'       => $options['aws_sns_version'],
            'credentials'   => [
                'key' => $options['aws_sns_key'],
                'secret' => $options['aws_sns_secret'],
            ],
        ]);
    }

    /**
     * Builds the message data, for the given $post, that we need to have translated
     * @param array|WP_Post $post
     * @param string|array $locales a list of languages to translate the given post to
     * @param array|null $options a list of options to use, when sending data to SNS
     * @return array
     */
    private function buildMessageData($post, $locales, $options = []): array
    {
        // fetches the given post's language
        $postLanguage = pll_get_post_language($post->ID);
        // returns the message data to send to SNS
        return [
            'title' => $post->post_title,
            'content' => $post->post_content,
            'language' => [
                'to' => $locales,
                'from' => $postLanguage,
            ],
            'external_id' => $this->generateExternalIdFromPost($post),
            'fast' => $options['fast'] ?? false,
            'invoicing_account' => $this->getInvoicingAccount(),
            'api_key' => $this->getLwApiKey(),
        ];
    }

    /**
     * Fetches the topic arn for new translation requests
     * @return string the topic arn
     */
    private function getTopicTranslateRequest(): string
    {
        // fetches the saved options
        $options = get_option('bts_plugin_options');
        // returns the topic arn
        return $options['aws_topic_translate'];
    }

    /**
     * Fetches the invoicing account for Language Wire
     * @return string the invoicing account
     */
    private function getInvoicingAccount(): string
    {
        // fetches the saved options
        $options = get_option('bts_plugin_options');
        // returns the topic arn
        return $options['lw_invoicing_account'];
    }

    /**
     * Fetches the Language Wire API key to use
     * @return string API key to use within BTS
     */
    private function getLwApiKey(): string
    {
        // fetches the saved options
        $options = get_option('bts_plugin_options');
        // returns the topic arn
        return $options['lw_api_key'];
    }

    /**
     * Generates the given post's external id, used when sending to BTS for translating
     * @param array|WP_Post $post the post to generate the external id for
     * @return string the generated external id
     */
    private function generateExternalIdFromPost($post): string
    {
        return $this->getSiteMessageIdPrefix() . '__' . $post->ID;
    }

    /**
     * Fetches the current site's handle
     * @return string the site handle
     */
    private function getSiteHandle(): string
    {
        // fetches the saved options
        $options = get_option('bts_plugin_options');
        // returns the current site's handle. E.g. WILLOW, HIST, ILI etc...
        return $options['site_handle'];
    }

    /**
     * Translates the given language slug, into something polylang can understand
     * @param $language
     * @return string
     */
    private function translateLanguageSlug($language)
    {
        // handling known languages (short form)
        switch ($language) {
            case 'en':
                return 'en';
            case 'sv':
            case 'se':
            case 'sv-SE':
                return 'sv';
            case 'no':
            case 'nb':
                return 'nb';
            case 'fi':
                return 'fi';
            case 'da':
            default:
                return 'da';
        }
    }
}
