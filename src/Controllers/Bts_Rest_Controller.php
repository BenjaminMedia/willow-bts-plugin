<?php

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
    }

    /**
     * Handles incoming calls from AWS SNS
     * @param WP_REST_Request $request
     */
    public function handle(WP_REST_Request $request)
    {
        $json = json_decode($request->get_body());

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
        $messageData = json_decode($json->Message);

        // the message id was not verified, return false
        if (! $this->verifyMessageId($messageData->external_id)) {
            error_log('Message is could not be verified: ' . $messageData->external_id);
            return new WP_Error(404, 'Post not found with message id: ' . $messageData->external_id);
        }


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
        $postId = substr($messageId, strpos('__', $messageId));

        return get_post($postId);
    }

    /**
     * Checks if the given json object, is of type SubscriptionConfirmation
     * @param stdClass $json
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
        // TODO: get actual wordpress prefix from plugin settings!
        // TODO: also check Willow site's name... eg. ILI, HIS etc.
        return self::SITE_PREFIX . 'test';
    }
}
