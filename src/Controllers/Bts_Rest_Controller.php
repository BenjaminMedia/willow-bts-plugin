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

    public const STATE_READY_FOR_TRANSLATION = 1;
    public const STATE_SENT_TO_TRANSLATION = 2;
    public const STATE_TRANSLATED = 3;
    const LINE_BREAKS = ["\r", "\n", "\r\n"];
    const LW_LINE_BREAKS = ["&amp;#13;", "&amp;#10;", "&amp;#13;&amp;#10;"];
    const LW_LINE_BREAKS_DECODED = ["&#13;", "&#10;", "&#13;&#10;"];

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

        // route for copying acf data from one article to the next (mostly for test purposes)
        register_rest_route(
            $routeNamespace,
            '/articles/(?P<from_id>\d+)/copyto/(?P<to_id>\d+)',
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'copyPostAction'],
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

        // building the message data to send to BTS
        $messageData = $this->buildMessageData($post, $locales, $request->get_body_params());
        // sending the message data to BTS
        $this->getClient()->publish([
            'TopicArn' => $this->getTopicTranslateRequest(),
            'Message' => \json_encode($messageData),
        ]);

        // fetching the current list of translations.
        $translations = pll_get_post_translations($postId);

        foreach ($locales as $locale) {
            $translatedPostId = $translations[$locale] ?? null;

            if (! $translatedPostId) {
                // making a copy of the original post, to a new one, and setting the new locale on it
                $translatedPost = $this->copyPost($post, $locale);
                // saving thew newly created post, for the new language
                $translatedPostId = $translatedPost->ID;
            }

            // setting the locale and post id associated.
            // if the locale already exists, then we just update the post id.
            // if it's a new post, then it's added.
            $translations[$locale] = $translatedPostId;

            $this->setMetaState($translatedPostId, self::STATE_SENT_TO_TRANSLATION);
            // saving deadline as metadata on the translations, so we can see when we are expecting them back
            if (! empty($messageData['deadline'])) {
                $this->setPostMetaData($translatedPostId, 'bts_deadline', $messageData['deadline']);
            }
        }

        pll_save_post_translations($translations);

        return new WP_REST_Response('Post sent to translation');
    }

    /**
     * Copies the given post, to a new post, with the given locale.
     * @param WP_POST $fromPost the post to copy the contents from
     * @param string $locale the locale string to copy the post to
     * @return array|WP_Post|null
     */
    public function copyPost($fromPost, $locale)
    {
        // creates or updates the post
        $postId = wp_insert_post([
            'post_content' => $fromPost->post_content,
            'post_title' => $fromPost->post_title,
            'post_type' => $fromPost->post_type,
            'post_date' => $fromPost->post_date,
            'post_status' => $fromPost->post_status,
            'post_author' => $fromPost->post_author,
        ]);

        pll_set_post_language($postId, $locale);

        set_post_type($postId, $fromPost->post_type);

        // updates the translated post's slug
        $this->setPostSlug($postId);

        // handles copying custom meta data from other metaboxes
        $this->copyMetaBoxData($fromPost->ID, $postId);

        // setting default content on the new post translation, using polylang
        $post = get_post($postId);
        $poly = PLL();
        $pd = new \PLL_Duplicate($poly);
        $pd->copy_content($fromPost, $post, $locale);

        // updating the post
        wp_update_post($post);

        //  copying the ACF content from the original post, to the newly created post.
        $this->copyAcfContent($fromPost->ID, $postId);

        // returning the newly created post, refreshed from the database
        return get_post($post->ID);
    }

    /**
     * Handles copying the acf post content from one post to the other.
     * This is only used in test/support purposes. The underlying method is
     * run when you call copyPost()
     * @param WP_REST_Request $request
     * @return WP_Error
     */
    public function copyPostAction(WP_REST_Request $request)
    {
        $fromPostId = $request->get_param('from_id');
        $toPostId = $request->get_param('to_id');

        $fromPost = get_post($fromPostId);
        $toPost = get_post($toPostId);

        if (empty($fromPost)) {
            return new WP_Error('Cannot find FROM post with id: ' . $fromPostId, 404);
        }
        if (empty($toPost)) {
            return new WP_Error('Cannot find TO post with id: ' . $toPostId, 404);
        }

        $this->copyAcfContent($fromPostId, $toPostId);

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

        // fetching all currently know translations, for the given post.
        // NOTE: the given post is set as the "main/original" post, so there is no need for the step after this.
        $translations = pll_get_post_translations($post->ID);
        // adding the original post to our list of translations, as the "post to update",
        // since we need a post to save all the associated translations to. (@see pll_save_post_translations)
//        $translations[pll_get_post_language($post->ID)] = $post->ID;

        // running through the translations, updating them using the given message data
        foreach ($messageData->translations as $translation) {
            // handling XLIFF documents from BTS
            $xml = new \SimpleXMLElement($translation->content);
            $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');

            // post title/content goes here
            $title = '';
            $content = '';
            $metaInternalComment = '';
            // list of acf fields goes here
            $acfFields = [];
            /** @var \SimpleXMLElement $element */

            foreach ($xml->xpath('//x:trans-unit') as $element) {
                // handling post content here, which should just be saved on the post
                if ($element['field_key'] . '' === 'post-title') {
                    $title = $this->convertToLineBreaks($element->source .'');
                } elseif ($element['field_key'] . '' === 'post-content') {
                    $content = $this->convertToLineBreaks($element->source . '');
                } elseif ($element['field_key'] . '' === 'meta-internal_comment') {
                    $metaInternalComment = $this->convertToLineBreaks($element->source .'');
                } elseif (in_array($element['field_type'], ['image', 'url', 'file', 'taxonomy'])) {
                    // skipping a few fields, since urls and images and taxonomy should not be changed
                    continue;
                } else {
                    // handling ACF fields
                    $acfFields[] = [
                        'content' => $this->convertToLineBreaks($element->source .''),
                        // these attributes are mostly for debugging if something happens
                        'field_key' => $element['field_key'] .'',
                        'is_subfield' => $element['is_subfield'] .'',
                        'field_path' => $element['path'] . '',
                    ];
                }
            }


            $language = $this->translateLanguageSlug($translation->language);
            // fetching the id of the translated post
            $translatedPostId = pll_get_post($post->ID, $language);

            // creates or updates the post
            $translatedPostId = wp_update_post([
                'ID' => $translatedPostId,
                'post_content' => $content,
                // NOTE: the title is no longer gotten from the "translation" document, but instead a field sent in the document.
                'post_title' => $title,
//                'post_title' => $translation->title,
                'post_type' => $post->post_type,
            ]);

            // handling custom metabox called "meta" with field "internal_comment"
            $this->setPostMetaData($translatedPostId, 'internal_comment', $metaInternalComment);

            // if a post id is NOT returned after calling wp_insert_post, log the error and skip to next language
            if (0 === $translatedPostId || $translatedPostId instanceof WP_Error) {
                error_log('Could not save translation for post ' . $post->ID . ':' . $language);
                continue;
            }

            // sets the post type of the translation, to be the same as the current post
            set_post_type($translatedPostId, $post->post_type);

            // updates the translated post's slug
            $this->setPostSlug($translatedPostId);

            // running through the list of found ACF fields, and updates the values on the current translated post
            foreach ($acfFields as $acfField) {
                // handles subfields, which are basically the widgets they can add.
                if (! empty($acfField['is_subfield'])) {
                    // updates the sub field with new data
                    update_sub_field(
                        explode(',', $acfField['field_path']),
                        $acfField['content'],
                        $translatedPostId
                    );
                } else {
                    // if the field id is empty, try to use the field key instead
                    $field = acf_get_field($acfField['field_path']);
                    // updating the act field data on the post
                    acf_update_value($acfField['content'], $translatedPostId, $field);
                }
            }

            // NOTE: how should we handle post status? by setting it to draft? new translation should NOT be auto published.

            // updating the language on the post new/updated post
            pll_set_post_language($translatedPostId, $language);

            // adding the translated post, to the list of translations
            $translations[$language] = $translatedPostId;

            // setting the current state of the translation
            $this->setMetaState($translatedPostId, self::STATE_TRANSLATED);
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
            // handling the request as XML, if xml parameter is added to the request
            if ($request->get_param('xml') !== null) {
                $xml = $this->toXliff(get_post($request->get_param('id')));
                $dom = new \DOMDocument();
                $dom->loadXML($xml);
                $dom->preserveWhiteSpace = false;
                $dom->formatOutput = false;
                return new WP_REST_Response($xml);
            }

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

        $deadline = get_post_meta($postId, 'bts_deadline');
        if (! empty($deadline)) {
//            list($deadlineDate, $deadlineTime) = explode(' ', array_shift($deadline));
//            $deadlineString = (new \DateTime($deadlineDate))->format('d/m/Y');
//
//            if (! empty($deadlineTime)) {
//                $deadlineString .= ' ' . $deadlineTime;
//            }

            $deadline = array_shift($deadline);
        }

        return [
            'post_id' => $post->ID,
            'deadline' => (!empty($deadline) ? $deadline : ''),
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

            // fetching the translated post, so we can display a few details from it.
            $translatedPost = get_post($translationId);

            // handling nullpointers on the translated post
            $languages[] = [
                'id' => $translationId,
                'post_title' => $translatedPost->post_title ?? '',
                'post_slug' => $translatedPost->post_name ?? '',
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

        $xliff = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2"></xliff>');
        $fileElement = $xliff->addChild('file');
        $fileElement->addAttribute('datatype', 'x-HTML/html-utf8-b_i');
        $fileElement->addAttribute('source-language', $postLanguage);
        $bodyElement = $fileElement->addChild('body');

        // adding "normal" post title to the document
        $this->addXliffTransUnit(
            $bodyElement,
            [
                'key' => 'post-title',
                'content' => $post->post_title,
            ],
            false
        );
        // adding "normal" post content to the document
        $this->addXliffTransUnit(
            $bodyElement,
            [
                'key' => 'post-content',
                'content' => $post->post_content,
            ],
            false
        );
        // adding "normal" post content to the document
        $this->addXliffTransUnit(
            $bodyElement,
            [
                'key' => 'meta-internal_comment',
                'content' => get_post_meta($post->ID, 'internal_comment'),
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
        // fetching the fields on the given post
        $fields = get_fields($post->ID);

        if (empty($fields)) {
            return [];
        }

        // running through all fields found
        foreach ($fields as $fieldName => $fieldValue) {
            $field = acf_get_field($fieldName);

            $this->addAcfField($post, $field, $fieldName, $data, 0, $field['key']);
        }

        return $data;
    }

    /**
     * Adds the given $field to the list of ACF field, if it meets the requirements.
     * @param WP_Post $post
     * @param array $field
     * @param string $fieldName
     * @param array $data
     * @param int $position
     * @param string|null $path
     */
    private function addAcfField($post, $field, $fieldName, &$data, $position, $path = null)
    {
        if (empty($field)) {
            return;
        }

        // we have a field, that has rows in it. Run through the rows, adding those fields
        if (have_rows($field['key'], $post->ID)) {
            $rowIndex = 0;

            while (have_rows($field['key'], $post->ID)) {
                // fetching the row data (a row is a widget)
                $row = the_row();
                $rowIndex++;

                if (! is_array($row)) {
                    continue;
                }

                foreach ($row as $rowkey => $rowValue) {
                    // removing layout items
                    if (! acf_is_field_key($rowkey)) {
                        continue;
                    }

                    $rowField = acf_maybe_get_field($rowkey);

                    $fieldPath = $rowkey;
                    if ($path) {
                        $fieldPath = $path.','.$rowIndex.','.$fieldPath;
                    }

                    // adds the row "field", by calling the same method again
                    $this->addAcfField($post, $rowField, $rowkey, $data, $rowIndex, $fieldPath);
                }
            }
        } else {
            // do not add "hard coded" types to the translations #BTS-57
            if (in_array($field['type'], ['select', 'true_false', 'radio', 'checkbox', 'user', 'embed_url', 'code'])) {
                return;
            }
            // also skipping (text) fields with the following names, as they can contain "bad" content, such as iframe code.
            if (in_array($field['name'], ['embed_url', 'code'])) {
                return;
            }

            // checking if the field is a subfield.
            $isSubField = (count(explode(',', $path)) > 1 ? 1 : 0);

            if ($isSubField) {
                $content = get_sub_field($field['key']);
            } else {
                // getting the field content to use
                $content = acf_get_value($post->ID, $field);
            }

            // if the field is an image, get the url to the image instead
            if ($field['type'] === 'image') {
                // skipping if the image content is "false"
                if ($content === false) {
                    $content = '';
                } elseif (! empty($content['sizes']['medium']) && ! is_int($content['sizes']['medium'])) {
                    // handling int's returned from the sizes as well
                    $content = $content['sizes']['medium'];
                } elseif (! empty($content['url'])) {
                    $content = $content['url'];
                }
            }
            // if the field is a file, get the url to the file instead
            if ($field['type'] === 'file') {
                // skipping if the image content is "false"
                if ($content === false) {
                    $content = '';
                } elseif (! empty($content['url'])) {
                    $content = $content['url'];
                }
            }
            // handling line breaks in LW format - BTS-65
//            $content = $this->convertToLWLineBreaks($content);
            // skipping a list of fields, that should not be included
            if (in_array($field['name'], [
                'translation_deadline',
                'canonical_url',
                'internal_comment',
            ])) {
                return;
            }

            $data[] = [
                'key' => $field['key'],
                'name' => $field['name'],
                'type' => $field['type'],
                'content' => $content,
                'acf' => 1,
                'is_subfield' => $isSubField,
                'subfield_position' => $position,
                'path' => $path ?? $field['key'],
            ];
        }
    }

    /**
     * Copies ACF fields and their content, from one post to the other
     * @param int $fromPostId the post to copy content from.
     * @param int $toPostId the post to copy content to.
     */
    public function copyAcfContent($fromPostId, $toPostId)
    {
        // fetching the language of the post, acf data should be copied to
        $toPostLanguage = pll_get_post_language($toPostId);
        // NOTE: perhaps test with get_fields($fromPostId, false) to avoid formatting
        $fields = get_fields($fromPostId);
        // runs through the fields, copying them from the original post, to the new copy
        foreach ($fields as $fieldName => $fieldValue) {
            // handles "singular" taxonomy fields, by getting the correct polylang version of the field, and manually
            // updating the field.
            if ($fieldValue instanceof \WP_Term) {
                // finding the term id, in the given language
                $pllTermId = pll_get_term($fieldValue->term_id, $toPostLanguage);
                // fetching the found term
                $pllTerm = get_term($pllTermId, $fieldValue->taxonomy);
                // updating the term value, on the translated post
                update_field($fieldName, $pllTerm, $toPostId);
            }
            // testing for terms, on multi select fields
            elseif (is_array($fieldValue) && !empty($fieldValue) && $fieldValue[0] instanceof \WP_Term) {
                // creating a list of terms to add to the field
                $terms = [];
                foreach ($fieldValue as $fieldTerm) {
                    // finding the term id, in the given language
                    $pllTermId = pll_get_term($fieldTerm->term_id, $toPostLanguage);
                    // fetching the found term
                    $terms[] = (string)$pllTermId;
                }

                // updating the term value, on the translated post
                update_field($fieldName, $terms, $toPostId);
            } else {
                // a flexible content field, is just a single field.
                // the value of it, should just be copied 1to1, as this will create
                // all the different "rows" in the blocks. Easy peasy... :)
                update_field($fieldName, $fieldValue, $toPostId);
            }
        }
    }

    /**
     * Adds a new trans-unit to the given $xmlElement.
     * @param \SimpleXMLElement $xmlElement the parent xml element to add the trans-unit element to.
     * @param array $field the field itself (ACF related)
     * @param bool $isAcf set this to false to say it's not an ACF field (e.g. $post->post_content)
     */
    private function addXliffTransUnit($xmlElement, $field, $isAcf = true)
    {
        // skip the field, if we cannot send the content because it's not a string
        // NOTE: array content has been found on images.
        if (! is_string($field['content'])) {
            return;
        }

        $element = $xmlElement->addChild('trans-unit');

        $element->addAttribute('field_key', $field['key'] ?? '');
        $element->addAttribute('field_name', $field['name'] ?? '');
        $element->addAttribute('field_type', $field['type'] ?? '');
        $element->addAttribute('path', $field['path'] ?? '');
        $element->addAttribute('is_subfield', $field['is_subfield'] ?? '0');
        $element->addAttribute('acf', (int)$isAcf);

        $element->addChild('source', $this->convertToLWLineBreaks($field['content'] . ''));
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
                return 'da';
            default:
                // if we do not know the language, just try to set it "as is"
                return $language;
        }
    }

    /**
     * Sets a new meta state for the given post translation
     * @param string|int|WP_Post $translatedPostId the id of the translation "copy"
     * @param int $state the new state to set. See STATE_X consts
     */
    private function setMetaState($translatedPostId, $state)
    {
        $stateText = '';
        $stateToKey = '';
        // finds the proper text to display in the BTS metabox
        switch ($state) {
            case (self::STATE_READY_FOR_TRANSLATION):
                $stateText = 'Ready for translation';
                $stateToKey = 'ready';
                break;
            case (self::STATE_SENT_TO_TRANSLATION):
                $stateText = 'Sent to BTS';
                $stateToKey = 'progress';
                break;
            case (self::STATE_TRANSLATED):
                $stateText = 'Translated';
                $stateToKey = 'translated';
                break;
        }

        $this->setPostMetaData($translatedPostId, 'bts_translation_state', $stateText);

        // finding the translation_state acf field
        $field = acf_maybe_get_field('translation_state', $translatedPostId);
        if ($field) {
            // updates the translated state acf field, so it can be seen as normal, other places in the system.
            update_field($field['key'], $stateToKey, $translatedPostId);
        }
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

    /**
     * Converts the given content's linebreaks from LW standard into \r etc
     * @param string $content the content to convert from LW linebreaks into \r etc
     * @return string the converted content
     */
    private function convertToLineBreaks($content)
    {
        return str_replace(self::LW_LINE_BREAKS_DECODED, self::LINE_BREAKS, $content);
    }

    /**
     * Converts the given content from normal linebreaks (\r \r\n) into a LW readable format.
     * @param string $content the content to convert to LW linebreaks.
     * @return string the converted content
     */
    private function convertToLWLineBreaks($content)
    {
        // doing normal line break replacements
        return str_replace(self::LINE_BREAKS, self::LW_LINE_BREAKS, $content);
    }

    /**
     * Sets a post slug on the post with the given id.
     * The slug is generated from the given post id's title.
     * NOTE: this updates the post in the database, using wp_update_post()
     * @param $translatedPostId
     */
    private function setPostSlug($translatedPostId)
    {
        // fetching the translated post, with the given translatedPostId
        $translatedPost = get_post($translatedPostId);

        // creating the translated post slug (post_name) using the post title given
        $slug = wp_unique_post_slug(str_slug($translatedPost->post_title), $translatedPostId, $translatedPost->post_status, $translatedPost->post_type, $translatedPost->post_parent);
        // updating the translated post, with the new slug
        wp_update_post([
            'ID' => $translatedPostId,
            'post_name' => $slug,
        ]);
    }

    /**
     * Copies various other meta data, from fromPost to postID
     * @param int $fromPostId the post id to copy the data from
     * @param int $postId the post id to copy the data to
     */
    private function copyMetaBoxData($fromPostId, $postId)
    {
        $authorKey = 'post_author_override';
        // fetching the author value
        $author = get_post_meta($fromPostId, $authorKey);
        // saves the post meta data
        $this->setPostMetaData($postId, $authorKey, $author);

        // handling meta data as well
        $metaKey = 'internal_comment';
        // fetching the author value
        $metaValue = get_post_meta($fromPostId, $metaKey);
        // saves the post meta data
        $this->setPostMetaData($postId, $metaKey, $metaValue);
    }

    /**
     * Adds meta data to the given post.
     * NOTE: The current meta data with the same key on the given post, is removed!
     * @param int $postId the post id to add the metadata to
     * @param string $metaKey the key of the metadata
     * @param string $metaValue the value of the metadata
     */
    private function setPostMetaData($postId, $metaKey, $metaValue)
    {
        delete_post_meta($postId, $metaKey);
        add_post_meta($postId, $metaKey, $metaValue);
    }
}
