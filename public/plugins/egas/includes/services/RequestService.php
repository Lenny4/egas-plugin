<?php

namespace App\services;

use WP_Error;
use WP_Http;

class RequestService
{
    private static ?RequestService $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function selfRequest(
        string $url,
        array  $params,
    ): WP_Error|array
    {
        // https://developer.wordpress.org/rest-api/key-concepts/
        // If you are using non-pretty permalinks, you should pass the REST API route as a query string parameter. The route http://oursite.com/wp-json/ in the example above would hence be http://oursite.com/?rest_route=/.
        $url = wp_nonce_url(home_url('/index.php', is_ssl() ? 'https' : 'http'), 'wp_rest') . '&rest_route=' . urlencode($url);
        return (new WP_Http)->request($url, [
            'timeout' => 30,
            'cookies' => $_COOKIE,
            'sslverify' => false, // no ssl verification required for local request
            ...$params,
        ]);
    }
}
