<?php

declare(strict_types=1);

namespace Egas\hooks;

use Egas\controllers\WoocommerceController;
use Egas\enum\Sage\DocumentProvenanceTypeEnum;
use Egas\enum\Sage\DomaineTypeEnum;
use Egas\resources\FComptetResource;
use Egas\Sage;
use Egas\services\GraphqlService;
use Egas\services\SageService;
use Egas\services\WoocommerceService;
use Egas\services\WordpressService;
use Egas\utils\FDocenteteUtils;
use Symfony\Component\HttpFoundation\Response;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;

class RestApiHook
{
    public function __construct()
    {
        // add meta_data for .line_items for request https://localhost/?rest_route=/wc/v2/orders/1996
        add_filter('woocommerce_rest_prepare_shop_order_object', function ($response, $order, $request) {
            $data = $response->get_data();
            if (empty($data['line_items'])) {
                return $response;
            }
            foreach ($data['line_items'] as $index => $li) {
                if (empty($li['product_id'])) {
                    continue;
                }
                $product_id = (int)$li['product_id'];
                $meta = get_post_meta($product_id);
                $metaDataList = [];
                foreach ($meta as $meta_key => $values) {
                    $value = maybe_unserialize($values[0]);
                    if (is_string($value) && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
                        $decoded = json_decode($value, true, 512, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $value = $decoded;
                        }
                    }
                    $metaDataList[] = [
                        'key' => $meta_key,
                        'value' => maybe_unserialize($value ?? null),
                    ];
                }
                $existing = $data['line_items'][$index]['meta_data'] ?? [];
                $data['line_items'][$index]['meta_data'] = array_merge($existing, $metaDataList);
            }
            $response->set_data($data);
            return $response;
        }, 10, 3);
        add_filter('rest_pre_dispatch', function ($result) {
            GraphqlService::getInstance()->ping();
            return $result; // must return $result
        });
        add_filter('allowed_redirect_hosts', function ($hosts) {
            $hosts[] = wp_parse_url(site_url(), PHP_URL_HOST);
            return $hosts;
        });
        add_action('rest_api_init', function (): void {
            register_rest_route(Sage::TOKEN . '/v1', '/search-entities/(?P<entityName>[A-Za-z0-9]+)', [
                'methods' => 'GET',
                'callback' => static function (WP_REST_Request $wprestRequest): WP_REST_Response {
                    $entityName = $wprestRequest['entityName'];
                    $selectionSet = '_get' . ucfirst(substr($entityName, 0, -1)) . 'SelectionSet';
                    $graphqlService = GraphqlService::getInstance();
                    $result = $graphqlService->searchEntities(
                        $entityName,
                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                        $_GET,
                        $graphqlService->{$selectionSet}(),
                    );
                    if (isset($result->data->{$entityName})) {
                        return new WP_REST_Response($result->data->{$entityName}, Response::HTTP_OK);
                    }
                    // todo return error message
                    return new WP_REST_Response(null, Response::HTTP_INTERNAL_SERVER_ERROR);
                },
                'permission_callback' => static fn(WP_REST_Request $wprestRequest) => current_user_can('manage_options'),
            ]);
            register_rest_route(Sage::TOKEN . '/v1', '/search/sage-entity-menu/(?P<resourceName>[A-Za-z0-9]+)', [
                'methods' => 'GET',
                'callback' => static function (WP_REST_Request $wprestRequest): WP_REST_Response {
                    $resourceName = $wprestRequest['resourceName'];
                    $resource = SageService::getInstance()->getResource($resourceName);
                    [
                        $data,
                        $showFields,
                        $filterFields,
                        $hideFields,
                        $perPage,
                        $queryParams,
                    ] = GraphqlService::getInstance()->getResourceWithQuery($resource);
                    if (isset($data["data"][$resourceName])) {
                        return new WP_REST_Response($data["data"][$resourceName], Response::HTTP_OK);
                    }
                    return new WP_REST_Response($data, Response::HTTP_SERVICE_UNAVAILABLE);
                },
                'permission_callback' => static fn(WP_REST_Request $wprestRequest) => current_user_can('manage_options'),
            ]);
            register_rest_route(Sage::TOKEN . '/v1', '/farticles/(?P<arRef>([^&]*))/available', args: [ // https://stackoverflow.com/a/10126995/6824121
                'methods' => 'GET',
                'callback' => static fn(WP_REST_Request $wprestRequest): WP_REST_Response => new WP_REST_Response([
                    'availableArRef' => GraphqlService::getInstance()->getAvailableArRef(arRef: $wprestRequest['arRef']),
                ], Response::HTTP_OK),
                'permission_callback' => static fn(WP_REST_Request $wprestRequest) => current_user_can('manage_options'),
            ]);
            register_rest_route(Sage::TOKEN . '/v1', '/orders/(?P<id>\d+)/sync', [
                'methods' => 'GET',
                'callback' => static function (WP_REST_Request $wprestRequest): WP_REST_Response {
                    $order = new WC_Order($wprestRequest['id']);
                    $woocommerceService = WoocommerceService::getInstance();
                    $fDocenteteIdentifier = $woocommerceService->getFDocenteteIdentifierFromOrder($order);
                    $result = $woocommerceService->importFDocenteteIntoOrder($fDocenteteIdentifier["doPiece"], $fDocenteteIdentifier["doType"], $order);
                    return new WP_REST_Response([
                        // we create a new order here to be sure to refresh all data from bdd
                        'html' => WoocommerceController::getMetaboxFDocentete(new WC_Order($result->getId() ?? $order->get_id()), message: $result->getMessage()),
                    ], Response::HTTP_OK);
                },
                'permission_callback' => static fn(WP_REST_Request $wprestRequest) => current_user_can('manage_options'),
            ]);
            register_rest_route(Sage::TOKEN . '/v1', '/fdocentetes/(?P<doPiece>[A-Za-z0-9]+$)', [
                'methods' => 'GET',
                'callback' => static function (WP_REST_Request $wprestRequest): WP_REST_Response {
                    $extended = false;
                    if (
                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                        array_key_exists('extended', $_GET) &&
                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                        ($_GET['extended'] === '1' || $_GET['extended'] === 'true')
                    ) {
                        $extended = true;
                    }
                    $fDocentetes = GraphqlService::getInstance()->getFDocentetes(
                        strtoupper(trim((string)$wprestRequest['doPiece'])),
                        doTypes: FDocenteteUtils::DO_TYPE_MAPPABLE,
                        doDomaine: DomaineTypeEnum::DomaineTypeVente->value,
                        doProvenance: DocumentProvenanceTypeEnum::DocProvenanceNormale->value,
                        getError: true,
                        getWordpressIds: true,
                        extended: $extended,
                    );
                    if (is_string($fDocentetes)) {
                        return new WP_REST_Response([
                            'message' => $fDocentetes
                        ], Response::HTTP_BAD_REQUEST);
                    }
                    if (is_null($fDocentetes)) {
                        return new WP_REST_Response([
                            'message' => 'Unknown error'
                        ], Response::HTTP_INTERNAL_SERVER_ERROR);
                    }
                    if ($fDocentetes === []) {
                        return new WP_REST_Response(null, Response::HTTP_NOT_FOUND);
                    }
                    return new WP_REST_Response($fDocentetes, Response::HTTP_OK);
                },
                'permission_callback' => static fn(WP_REST_Request $wprestRequest) => current_user_can('manage_options'),
            ]);
            register_rest_route(Sage::TOKEN . '/v1', '/orders/(?P<id>\d+)/desynchronize', [
                'methods' => 'GET',
                'callback' => static function (WP_REST_Request $wprestRequest): WP_REST_Response {
                    $order = new WC_Order($wprestRequest['id']);
                    $order = WoocommerceService::getInstance()->desynchronizeOrder($order);
                    return new WP_REST_Response([
                        // we create a new order here to be sure to refresh all data from bdd
                        'html' => WoocommerceController::getMetaboxFDocentete($order)
                    ], Response::HTTP_OK);
                },
                'permission_callback' => static fn(WP_REST_Request $wprestRequest) => current_user_can('manage_options'),
            ]);
            register_rest_route(Sage::TOKEN . '/v1', '/orders/(?P<id>\d+)/fdocentete', [
                'methods' => 'POST',
                'callback' => static function (WP_REST_Request $wprestRequest): WP_REST_Response {
                    $body = json_decode($wprestRequest->get_body(), false, 512, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                    $doPiece = $body->{Sage::TOKEN . "-fdocentete-dopiece"};
                    $doType = (int)$body->{Sage::TOKEN . "-fdocentete-dotype"};
                    $order = new WC_Order($wprestRequest['id']);
                    $result = WoocommerceService::getInstance()->importFDocenteteIntoOrder($doPiece, $doType, $order);
                    return new WP_REST_Response([
                        // we create a new order here to be sure to refresh all data from bdd
                        'html' => WoocommerceController::getMetaboxFDocentete(new WC_Order($result->getId() ?? $order->get_id()))
                    ], Response::HTTP_OK);
                },
                'permission_callback' => static fn(WP_REST_Request $wprestRequest) => current_user_can('manage_options'),
            ]);
            register_rest_route(Sage::TOKEN . '/v1', '/deactivate-shipping-zones', [
                'methods' => 'GET',
                'callback' => static function (WP_REST_Request $wprestRequest): void {
                    global $wpdb;
                    $like = Sage::TOKEN . '%';
                    $sql = "
    UPDATE {$wpdb->prefix}woocommerce_shipping_zone_methods
    SET is_enabled = 0
    WHERE method_id NOT LIKE %s
      AND is_enabled = 1
";
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $wpdb->query($wpdb->prepare($sql, $like));
                    $redirect = wp_get_referer();
                    wp_safe_redirect($redirect);
                    exit();
                },
                'permission_callback' => static fn(WP_REST_Request $wprestRequest) => current_user_can('manage_options'),
            ]);
            register_rest_route(Sage::TOKEN . '/v1', '/add-website-sage-api', [
                'methods' => 'GET',
                'callback' => static function (WP_REST_Request $wprestRequest): WP_REST_Response {
                    $result = WordpressService::getInstance()->addWebsiteSageApi(true);
                    if ($result !== true) {
                        return new WP_REST_Response($result, Response::HTTP_INTERNAL_SERVER_ERROR);
                    }
                    return new WP_REST_Response(null, Response::HTTP_OK);
                },
                'permission_callback' => static fn(WP_REST_Request $wprestRequest) => current_user_can('manage_options'),
            ]);
            register_rest_route(Sage::TOKEN . '/v1', '/healthz', [
                'methods' => 'GET',
                'callback' => static fn(): WP_REST_Response => new WP_REST_Response(null, Response::HTTP_OK),
                'permission_callback' => static fn(): true => true,
            ]);
            register_rest_route(Sage::TOKEN . '/v1', '/orders/(?P<id>\d+)/meta-box-order', [
                'methods' => 'GET',
                'callback' => static function (WP_REST_Request $wprestRequest): WP_REST_Response {
                    // this includes import woocommerce_wp_text_input
                    include_once __DIR__ . '/../../woocommerce/includes/admin/wc-meta-box-functions.php';
                    $order = new WC_Order($wprestRequest['id']);
                    $orderHtml = WoocommerceController::getMetaBoxOrder($order);
                    $itemHtml = WoocommerceController::getMetaBoxOrderItems($order);
                    return new WP_REST_Response([
                        'orderHtml' => $orderHtml,
                        'itemHtml' => $itemHtml
                    ], Response::HTTP_OK);
                },
                'permission_callback' => static fn(WP_REST_Request $wprestRequest) => current_user_can('manage_options'),
            ]);
            register_rest_route(Sage::TOKEN . '/v1', '/user/(?P<ctNum>([^&]*))', [
                'methods' => 'GET',
                'callback' => static function (WP_REST_Request $wprestRequest): WP_REST_Response {
                    $ctNum = $wprestRequest['ctNum'];
                    $fComptet = GraphqlService::getInstance()->getFComptet($ctNum);
                    $user = get_users([
                        'meta_key' => FComptetResource::META_KEY,
                        'meta_value' => strtoupper($ctNum)
                    ]);
                    $user = empty($user) ? null : $user[0];
                    return new WP_REST_Response([
                        'fComptet' => $fComptet,
                        'user' => $user,
                    ], Response::HTTP_OK);
                },
                'permission_callback' => static fn(WP_REST_Request $wprestRequest) => current_user_can('manage_options'),
            ]);
            register_rest_route(Sage::TOKEN . '/v1', '/import/(?P<entityName>[A-Za-z0-9]+)/(?P<identifier>.+)', [
                'methods' => 'GET',
                'callback' => static function (WP_REST_Request $wprestRequest): WP_REST_Response {
                    $resource = SageService::getInstance()->getResource($wprestRequest['entityName']);
                    if ($resource === null) {
                        return new WP_REST_Response([
                            'message' => __('Ressource inconnue', 'egas-data-sync-for-sage'),
                        ], Response::HTTP_NOT_FOUND);
                    }
                    $result = $resource->import($wprestRequest['identifier']);
                    return new WP_REST_Response([
                        'id' => $result->getId(),
                        'message' => $result->getMessage(),
                    ], $result->getStatus() ?? ($result->isSuccess() ? Response::HTTP_OK : Response::HTTP_INTERNAL_SERVER_ERROR));
                },
                'permission_callback' => static fn(WP_REST_Request $wprestRequest) => current_user_can('manage_options'),
            ]);
            $this->expose_all_user_meta_in_rest(); // must be call after all register_rest_route
        });
    }

    private function expose_all_user_meta_in_rest(): void
    {
        $sage = Sage::getInstance();
        $plugin_data = get_plugin_data($sage->file);
        $version = $plugin_data['Version'];
        $cache_key = Sage::TOKEN . '_all_user_meta_keys_' . md5((string)$version);
        $meta_keys = get_transient($cache_key);
        if (empty($meta_keys)) {
            global $wpdb;
            $meta_keys = $wpdb->get_col('SELECT DISTINCT meta_key FROM ' . $wpdb->usermeta);
            if (!empty($meta_keys)) {
                set_transient($cache_key, $meta_keys, 24 * 3_600);
            }
        }
        if (!empty($meta_keys)) {
            foreach ($meta_keys as $metum_key) {
                register_meta('user', $metum_key, [
                    'show_in_rest' => true,
                    'single' => true,
                    'type' => 'string',
                ]);
            }
        }
    }
}
