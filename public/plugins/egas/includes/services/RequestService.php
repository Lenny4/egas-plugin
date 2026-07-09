<?php

declare(strict_types=1);

namespace Egas\services;

use WP_REST_Request;
use WP_REST_Response;

class RequestService
{
    private static ?RequestService $requestService = null;

    public static function getInstance(): self
    {
        if (self::$requestService === null) {
            self::$requestService = new self();
        }
        return self::$requestService;
    }

    public function selfRequest(string $route, array $params = []): WP_REST_Response
    {
        // $route is the REST route, e.g. '/wc/v3/products'
        // $params can include: 'method', 'body' (array), 'headers' (array)
        $method = $params['method'] ?? 'GET';
        $request = new WP_REST_Request($method, $route);
        // Query params / body params — WP_REST_Request doesn't care whether
        // they came from the query string or POST body, set_param() handles both
        // and the route's own arg schema (validate/sanitize callbacks) still runs.
        if (!empty($params['body']) && is_array($params['body'])) {
            foreach ($params['body'] as $key => $value) {
                $request->set_param($key, $value);
            }
        }
        // Headers, if the callback reads them via $request->get_header()
        if (!empty($params['headers']) && is_array($params['headers'])) {
            foreach ($params['headers'] as $key => $value) {
                $request->set_header($key, $value);
            }
        }
        return rest_do_request($request);
    }
}
