<?php

namespace Bts\Controllers;

use Aws\Sns\SnsClient;
use Exception;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Controller;
use WP_REST_Response;
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
     * @return WP_Error|WP_REST_Response
     */
    public function sendPostToTranslation(WP_REST_Request $request)
    {
        $postId = $request->get_param('post_id');
        $post = get_post($postId);

        if (empty($post)) {
            return new WP_Error('Cannot find post with post id: ' . $postId, 404);
        }

        // fetches the list of languages to translate the post into.
        $locales = explode(',', $request->get_param('languages'));

        $this->getClient()->publish([
            'TopicArn' => $this->getTopicTranslateRequest(),
            'Message' => \json_encode($this->buildMessageData($post, $locales, $request->get_body_params())),
        ]);

        // fetching the current list of translations.
        $translations = pll_get_post_translations($postId);

        foreach ($locales as $locale) {
            $translatedPostId = $translations[$locale] ?? null;

            if (! $translatedPostId) {
                // creates or updates the post
                $translatedPostId = wp_insert_post([
                    'ID' => $translatedPostId,
                    'post_content' => $post->post_content,
                    'post_title' => $post->post_title,
                    'post_type' => $post->post_type,
                ]);

                pll_set_post_language($translatedPostId, $locale);
            }

            // sets the post type of the translation, to be the same as the current post
            set_post_type($translatedPostId, $post->post_type);

            // setting the locale and post id associated.
            // if the locale already exists, then we just update the post id.
            // if it's a new post, then it's added.
            $translations[$locale] = $translatedPostId;

            $this->setMetaState($translatedPostId, 'Sent to BTS');
        }

        pll_save_post_translations($translations);

        return new WP_REST_Response('Post sent to translation');
    }

    /**
     * Handles incoming calls from AWS SNS
     * @param WP_REST_Request $request
     * @return WP_Error|WP_REST_Response
     */
    public function handle(WP_REST_Request $request)
    {
        $json = \json_decode($request->get_body());

        // handling subscription requests
        if ($this->isSubscriptionRequest($json)) {
            // handles new subscriptions, by calling the SubscribeUrl
            file_get_contents($json->SubscribeURL);
            // returning "a-ok" :)
            return new WP_REST_Response();
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
            // handling XLIFF documents from BTS
            $xml = new \SimpleXMLElement($translation->content);
            $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');

            // post content goes here
            $content = '';
            // list of acf fields goes here
            $acfFields = [];
            /** @var \SimpleXMLElement $element */
//            foreach ($xml->xliff->file->body->{'trans-unit'} as $element) {
            foreach ($xml->xpath('//x:trans-unit') as $element)
                // handling post content here, which should just be saved on the post
                if ($element['field_key'] . '' === 'post-content') {
                    $content = $element->source .'';
                } else {
                    // handling ACF fields
                    $acfFields[] = [
                        'field_id' => $element['field_id'] .'',
                        'content' => $element->source .'',
                        // these attributes are mostly for debugging if something happens
                        'field_key' => $element['field_key'] .'',
                        'group_id' => $element['group_id'] .'',
                        'group_key' => $element['group_key'] .'',
                    ];
                }
            }


            $language = $this->translateLanguageSlug($translation->language);
            // fetching the id of the translated post
            $translatedPostId = pll_get_post($post->ID, $language);

            // creates or updates the post
            $translatedPostId = wp_insert_post([
                'ID' => $translatedPostId,
                'post_content' => $content,
                'post_title' => $translation->title,
                'post_type' => $post->post_type,
            ]);

            // sets the post type of the translation, to be the same as the current post
            set_post_type($translatedPostId, $post->post_type);

            // if a post id is NOT returned after calling wp_insert_post, log the error and skip to next language
            if (0 === $translatedPostId || $translatedPostId instanceof WP_Error) {
                error_log('Could not save translation for post ' . $post->ID . ':' . $language);
                continue;
            }

            // running through the list of found ACF fields, and updates the values on the current translated post
            foreach ($acfFields as $acfField) {
                $field = acf_get_field($acfField['field_id']);
                // updating the act field data on the post
                acf_update_value($acfField['content'], $translatedPostId, $field);
            }


            // NOTE: how should we handle post status? by setting it to draft? new translation should NOT be auto published.

            // updating the language on the post new/updated post
            pll_set_post_language($translatedPostId, $language);

            // adding the translated post, to the list of translations
            $translations[$language] = $translatedPostId;

            // setting the current state of the translation
            $this->setMetaState($translatedPostId, 'Translated');
        }

        // updating the original/main post, with all the available languages
        pll_save_post_translations($translations);

        // returning a success response
        return new WP_REST_Response('Translations saved', 200);
    }

    /**
     * Fetches an article, based on an "id" parameter in the current request
     * @param WP_REST_Request $request
     * @return WP_Error|WP_REST_Response
     */
    public function getArticle(WP_REST_Request $request)
    {
        if (! function_exists('pll_get_post_language')) {
            return new WP_Error('500', 'Cannot find Polylang method pll_get_post_language');
        }

        try {
            return new WP_REST_Response(
                array_merge(
                    $this->getArticleRaw(
                        $request->get_param('id')
                    ),
                    [
                        'params' => $request->get_params(),
                    ]
                )
            );
        } catch (Exception $e) {
            // handling exception, by returning an WP_Error, using the details set in the exception
            return new WP_Error($e->getCode(), $e->getMessage());
        }
    }

    /**
     * Fetches the article, with the given post id (this is also pages...)
     * @param string $postId the id of the page/post to fetch
     * @return array an array of details about the article
     * @throws Exception exception is thrown, if polylang is not working properly
     */
    public function getArticleRaw($postId)
    {
        if (! function_exists('pll_get_post_language')) {
            throw new Exception('Cannot find Polylang method pll_get_post_language', 500);
        }

        $post = get_post($postId);
        $postLanguage = pll_get_post_language($post->ID);

        return [
            'post_id' => $post->ID,
            'language' => $postLanguage,
            'languages' => $this->getLanguages($post->ID),
            'acf_data' => $this->getAcfContent($post),
        ];
    }

    /**
     * Fetches the languages available AND already translated info state for the given postId
     * @param $postId
     * @return array|WP_Error
     */
    private function getLanguages($postId)
    {
        if (! function_exists('pll_languages_list')) {
            return new WP_Error('500', 'Cannot find Polylang method pll_languages_list');
        }
        if (! function_exists('pll_get_post')) {
            return new WP_Error('500', 'Cannot find Polylang method pll_get_post');
        }

        $languageSlugs = pll_languages_list(['fields' => 'slug']);

        $languages = [];
        foreach ($languageSlugs as $languageSlug) {
            // fetches the language itself, using PLL's internal methods
            $language = PLL()->model->get_language($languageSlug);

            // fetching the translation for the given post, for the given language slug
            $translationId = pll_get_post($postId, $languageSlug);

            $languages[] = [
                'id' => $translationId,
                'code' => $language->slug,
                'name' => $language->name,
                'state' => $translationId ? get_post_meta($translationId, 'bts_translation_state', true) : null,
            ];
        }

        return $languages;
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
            'content' => $this->toXliff($post),
            'language' => [
                'to' => $locales,
                'from' => $postLanguage,
            ],
            'external_id' => $this->generateExternalIdFromPost($post),
            'fast' => $options['fast'] ?? false,
            'comment' => $options['comment'],
            'deadline' => $options['deadline'],
            'invoicing_account' => $this->getInvoicingAccount(),
            'api_key' => $this->getLwApiKey(),
            'service_id' => $this->getLwServiceId(),
            'work_area' => $this->getLwWorkArea(),
            'terminology' => $this->getLwTerminology(),
        ];
    }

    /**
     * Takes the given post and converts the content and ACF fields into an XLIFF document
     * @param WP_Post $post
     * @return string
     */
    private function toXliff($post)
    {
        // fetches the given post's language
        $postLanguage = pll_get_post_language($post->ID);

        $xliff = new \SimpleXMLElement('<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2"></xliff>');
        $fileElement = $xliff->addChild('file');
        $fileElement->addAttribute('datatype', 'x-HTML/html-utf8-b_i');
        $fileElement->addAttribute('source-language', $postLanguage);
        $bodyElement = $fileElement->addChild('body');

        // adding "normal" post content to the document
        $this->addXliffTransUnit(
            $bodyElement,
            [
                'key' => 'post-content',
                'content' => $post->post_content,
                '_type' => 'post-content'
            ],
            false
        );

        // fetching the collection of ACF fields we have on the current post
        $fields = $this->getAcfContent($post);
        foreach ($fields as $field) {
            $this->addXliffTransUnit(
                $bodyElement,
                $field
            );
        }

        return $xliff->asXML();
    }

    /**
     * Fetches the ACF content, from the given WP_Post
     * @param WP_Post $post
     * @return array
     */
    private function getAcfContent($post)
    {
        // the data array to return to the caller
        $data = [];

        // using act_get_field_groups
        $groups = acf_get_field_groups();
        foreach ($groups as $group) {
            $fields = acf_get_fields($group);

            foreach ($fields as $field) {
                $data[] = [
                    'ID' => $field['ID'],
                    'key' => $field['key'],
                    'name' => $field['name'],
                    'content' => acf_get_value($post->ID, $field),
                    '_type' => 'acf_get_field_groups',
                    'acf' => 1,
                ];
            }
        }

        return $data;
    }

    /**
     * Adds a new trans-unit to the given $xmlElement.
     * @param \SimpleXMLElement $xmlElement the parent xml element to add the trans-unit element to.
     * @param array $field the field itself (ACF related)
     * @param bool $isAcf set this to false to say it's not an ACF field (e.g. $post->post_content)
     */
    private function addXliffTransUnit($xmlElement, $field, $isAcf = true)
    {
        $element = $xmlElement->addChild('trans-unit');

        $element->addAttribute('field_id', $field['ID'] ?? '');
        $element->addAttribute('field_key', $field['key'] ?? '');
        $element->addAttribute('field_name', $field['name'] ?? '');
        $element->addAttribute('_type', $field['_type'] ?? '');
        $element->addAttribute('acf', (int)$isAcf);

        $element->addChild('source', is_string($field['content']) ? $field['content']: '');
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

    /**
     * Sets a new meta state for the given post translation
     * @param string|int|WP_Post $translatedPostId the id of the translation "copy"
     * @param string $state the new state message to set
     */
    private function setMetaState($translatedPostId, $state)
    {
        delete_post_meta($translatedPostId, 'bts_translation_state');
        add_post_meta($translatedPostId, 'bts_translation_state', $state);
    }

    /**
     * Fetches the service id to use on LW
     * @return mixed
     */
    private function getLwServiceId()
    {
        // fetches the saved options
        $options = get_option('bts_plugin_options');
        // returns the service id
        return $options['lw_service_id'];
    }

    /**
     * Fetches the work are to use on LW
     * @return mixed
     */
    private function getLwWorkArea()
    {
        // fetches the saved options
        $options = get_option('bts_plugin_options');
        // returns the work area
        return $options['lw_workarea'];
    }

    /**
     * Fetches the terminology to use on LW
     * @return mixed
     */
    private function getLwTerminology()
    {
        // fetches the saved options
        $options = get_option('bts_plugin_options');
        // returns the work area
        return $options['lw_terminology'];
    }
}
