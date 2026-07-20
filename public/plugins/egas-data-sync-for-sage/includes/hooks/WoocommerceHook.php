<?php

declare(strict_types=1);

namespace Egas\hooks;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableQuery;
use DateTime;
use Egas\class\SageShippingMethod__index__;
use Egas\class\term\WC_Product_Egas;
use Egas\controllers\AdminController;
use Egas\controllers\WoocommerceController;
use Egas\resources\FArticleResource;
use Egas\Sage;
use Egas\services\GraphqlService;
use Egas\services\SageService;
use Egas\services\TwigService;
use Egas\services\WoocommerceService;
use Egas\utils\SageTranslationUtils;
use stdClass;
use WC_Meta_Data;
use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Product;
use WC_Product;
use WC_Shipping_Rate;

class WoocommerceHook
{
    private array $trans;

    public function __construct()
    {
        add_action('init', function (): void {
            $this->trans = SageTranslationUtils::getTranslations();
        });
        // region link wordpress order to sage order
        $screenId = 'woocommerce_page_wc-orders';
        add_action('add_meta_boxes_' . $screenId, static function (WC_Order $wcOrder) use ($screenId): void { // woocommerce/src/Internal/Admin/Orders/Edit.php: do_action( 'add_meta_boxes_' . $this->screen_id, $this->order );
            add_meta_box(
                'woocommerce-order-' . Sage::TOKEN . '-main',
                __('Egas', 'egas'),
                static function () use ($wcOrder): void {
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo WoocommerceController::getMetaboxFDocentete($wcOrder);
                },
                $screenId,
                'normal',
                'high'
            );
        });
        // action is trigger when click update button on order
        add_action('woocommerce_process_shop_order_meta', static function (int $orderId, WC_Order $wcOrder): void {
            if ($wcOrder->get_status() === 'auto-draft') {
                // handle by the add_action `woocommerce_new_order`
                return;
            }
            WoocommerceService::getInstance()->afterCreateOrEditOrder($wcOrder);
        }, accepted_args: 2);
        add_action('woocommerce_new_order', static function (int $orderId, WC_Order $wcOrder): void {
            WoocommerceService::getInstance()->afterCreateOrEditOrder($wcOrder, true);
        }, accepted_args: 2);
        // endregion
        $updateApiOrder = function (int $orderId): void {
            $order = wc_get_order($orderId);
            $order->update_meta_data('_' . Sage::TOKEN . '_updateApi', (new DateTime())->format('Y-m-d H:i:s'));
            $order->save();
        };
        add_action('woocommerce_payment_complete', function (int $orderId) use ($updateApiOrder): void {
            $updateApiOrder($orderId);
        });
        add_action('woocommerce_order_refunded', function (int $orderId) use ($updateApiOrder): void {
            $updateApiOrder($orderId);
        });
        add_filter('woocommerce_orders_table_query_sql', fn(string $sql, OrdersTableQuery $ordersTableQuery, array $args): ?string => preg_replace(
            "/IN\s*\(\s*('_billing_address_index'\s*,\s*'_shipping_address_index')\s*\)/",
            "IN ($1, '_" . Sage::TOKEN . "_doPiece')",
            $sql
        ), accepted_args: 3);

        add_filter('woocommerce_shipping_rate_cost', static fn(string $cost, WC_Shipping_Rate $wcShippingRate): string => (string)(WoocommerceService::getInstance()->getShippingRateCosts(WC()->cart, $wcShippingRate) ?? $cost), accepted_args: 2);
        add_filter('woocommerce_shipping_rate_label', static function (string $label, WC_Shipping_Rate $wcShippingRate): string {
            if (!str_starts_with($wcShippingRate->get_method_id(), Sage::TOKEN . '-')) {
                return $label;
            }
            $remove = '[Egas] ';
            if (str_starts_with($label, $remove)) {
                return substr($label, strlen($remove));
            }
            return $label;
        }, accepted_args: 2);

        // region Custom Product Tabs In WooCommerce https://aovup.com/woocommerce/add-tabs/
        add_action('add_meta_boxes', static function (string $screen, mixed $obj): void { // remove [Product type | virtual | downloadable] add product arRef
            if ($screen === 'product') {
                global $wp_meta_boxes;
                WoocommerceController::showMetaBoxProduct($wp_meta_boxes, $screen);
            } elseif ($screen === 'woocommerce_page_wc-orders') {
                global $wp_meta_boxes;
                WoocommerceController::showMetaBoxOrder($wp_meta_boxes, $screen);
            }
        }, 40, 2); // woocommerce/includes/admin/class-wc-admin-meta-boxes.php => 40 > 30 : add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 30 );
        add_filter('product_type_selector', static function (array $types): array {
            $arRef = get_post_meta(get_the_ID(), FArticleResource::META_KEY, true);
            if (!empty($arRef)) {
                return [Sage::TOKEN => __('Egas', 'egas')];
            }
            return array_merge([Sage::TOKEN => __('Sage product', 'egas')], $types);
        });
        add_filter('product_type_options', function (array $productOptions): array {
            foreach ($productOptions as &$productOption) {
                $productOption["wrapper_class"] .= ' hide_if_' . Sage::TOKEN;
            }
            return $productOptions;
        });
        add_filter('woocommerce_product_class', function (string $classname, string $product_type): string {
            if ($product_type === Sage::TOKEN) {
                return WC_Product_Egas::class;
            }
            return $classname;
        }, accepted_args: 2);
        add_filter('woocommerce_product_data_tabs', static function (array $tabs): array { // Code to Create Tab in the Backend
            foreach (array_keys($tabs) as $tabName) {
                if (!in_array($tabName, [
                    'linked_product',
                    'advanced',
                ],
                    true)) {
                    $tabs[$tabName]["class"][] = 'hide_if_' . Sage::TOKEN;
                }
            }

            $tabs[Sage::TOKEN] = [
                'label' => __('Egas', 'egas'),
                'target' => Sage::TARGET_PANEL,
                'class' => ['show_if_' . Sage::TOKEN],
                'priority' => 0,
            ];
            return $tabs;
        });

        add_action('woocommerce_product_data_panels', static function (): void { // Code to Add Data Panel to the Tab
            $product = wc_get_product();
            if (!($product instanceof WC_Product)) {
                return;
            }
            $sageService = SageService::getInstance();
            [
                $fArticle,
                $messages,
                $meta,
                $updateApi,
                $hasChanges,
                $changeTypes
            ] = $sageService->importFromSageIfUpdateApi($sageService->getResource(FArticleResource::ENTITY_NAME), $product->get_id());
            $graphqlService = GraphqlService::getInstance();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo TwigService::getInstance()->render('woocommerce/tabs/sage.html.twig', [
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'fArticle' => $fArticle,
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'pCattarifs' => $graphqlService->getPCattarifs(),
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'fCatalogues' => $graphqlService->getFCatalogues(),
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'pCatComptas' => $graphqlService->getPCatComptas(),
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'fFamilles' => $graphqlService->getFFamilles(),
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'pUnites' => $graphqlService->getPUnites(),
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'fDepots' => $graphqlService->getFDepots(),
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'fPays' => $graphqlService->getFPays(),
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'pPreference' => $graphqlService->getPPreference(),
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'panelId' => Sage::TARGET_PANEL,
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'messages' => $messages,
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'meta' => $meta,
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'updateApi' => $updateApi,
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'hasChanges' => $hasChanges,
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'changeTypes' => $changeTypes,
            ]);
        });
        // endregion

        // region taxes
        // woocommerce/includes/admin/settings/views/html-settings-tax.php
        // woocommerce/includes/admin/views/html-admin-settings.php
        add_action('woocommerce_sections_tax', static function (): void {
            WoocommerceService::getInstance()->updateTaxes();
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (array_key_exists('section', $_GET) && $_GET['section'] === Sage::TOKEN) {
                ?>
                <div class="notice notice-info">
                    <p>
                        <?php echo esc_html__("Veuillez ne pas modifier les taxes Sage manuellement ici, elles sont automatiquement mises à jour en fonction des taxes dans Sage ('Stucture' -> 'Comptabilité' -> 'Taux de taxes').", 'egas') ?>
                    </p>
                </div>
                <?php
            }
        });
        // endregion

        // region add sage shipping methods
        add_filter('woocommerce_shipping_methods', static function (array $result): array {
            $className = pathinfo(str_replace('\\', '/', SageShippingMethod__index__::class), PATHINFO_FILENAME);
            $pExpeditions = GraphqlService::getInstance()->getPExpeditions(
                getError: true,
            );
            if (AdminController::showErrors($pExpeditions)) {
                return $result;
            }
            if (
                $pExpeditions !== [] &&
                !class_exists(str_replace('__index__', '0', $className))
            ) {
                preg_match(
                    '/class ' . $className . '[\s\S]*/',
                    file_get_contents(__DIR__ . '/../class/' . $className . '.php'),
                    $skeletonShippingMethod);
                foreach ($pExpeditions as $i => $pExpedition) {
                    $thisSkeletonShippingMethod = str_replace(
                        ['__index__', '__id__', '__name__', '__description__'],
                        [
                            (string)$i,
                            $pExpedition->slug,
                            '[' . __('Egas', 'egas') . '] ' . $pExpedition->eIntitule,
                            '<span style="font-weight: bold">[' . __('Egas', 'egas') . ']</span> ' . $pExpedition->eIntitule,
                        ],
                        $skeletonShippingMethod[0]
                    );
                    // no other way to dynamically create a new shipping method
                    // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found
                    eval($thisSkeletonShippingMethod);
                }
            }
            foreach ($pExpeditions as $i => $pExpedition) {
                $result[$pExpedition->slug] = str_replace('__index__', (string)$i, $className);
            }
            return $result;
        });
        add_action('woocommerce_settings_shipping', static function (): void {
            global $wpdb;
            $like = Sage::TOKEN . '%';
            $sql = "
    SELECT COUNT(instance_id) AS nbInstance
    FROM {$wpdb->prefix}woocommerce_shipping_zone_methods
    WHERE method_id NOT LIKE %s
      AND is_enabled = 1
";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $r = $wpdb->get_var($wpdb->prepare($sql, $like));
            if ((int)$r[0]->nbInstance > 0) {
                echo '
<div class="notice notice-warning"><p>
    <span style="display: block; margin: 0.5em 0.5em 0 0; clear: both;">
        ' . esc_html__('Certain Mode(s) d’expédition qui ne proviennent pas de Sage sont activés. Cliquez sur "Désactiver" pour désactiver les modes d\'expéditions qui ne proviennent pas de Sage', 'egas') . '
    </span>
    <strong>
    <span style="display: block; margin: 0.5em 0.5em 0 0; clear: both;">
        <a href="' . esc_url(get_site_url()) . '/index.php?rest_route=' . urlencode('/' . Sage::TOKEN . '/v1/deactivate-shipping-zones') . '&_wpnonce=' . esc_html(wp_create_nonce('wp_rest')) . '">
        ' . esc_html__('Désactiver', 'egas') . '
        </a>
    </span>
    </strong>
</p></div>
                ';
            }
        });
        // endregion

        // region edit woocommerce price
        // https://stackoverflow.com/a/45807054/6824121
        add_filter('woocommerce_get_price_including_tax', fn(string|float|int $price, $quantity, WC_Product $wcProduct): float|string => WoocommerceService::getInstance()->custom_price($price, $wcProduct, get_current_user_id(), true), 99, 3);
        add_filter('woocommerce_get_price_excluding_tax', fn(string|float|int $price, $quantity, WC_Product $wcProduct): float|string => WoocommerceService::getInstance()->custom_price($price, $wcProduct, get_current_user_id(), false), 99, 3);
        // Simple, grouped and external products
        add_filter('woocommerce_product_get_price', fn(string|float|int $price, WC_Product $wcProduct): float|string => WoocommerceService::getInstance()->custom_price($price, $wcProduct, get_current_user_id()), 99, 2);
        add_filter('woocommerce_product_get_regular_price', fn(string|float|int $price, WC_Product $wcProduct): float|string => WoocommerceService::getInstance()->custom_price($price, $wcProduct, get_current_user_id()), 99, 2);
        // Variations
        add_filter('woocommerce_product_variation_get_regular_price', fn(string|float|int $price, WC_Product $wcProduct): float|string => WoocommerceService::getInstance()->custom_price($price, $wcProduct, get_current_user_id()), 99, 2);
        add_filter('woocommerce_product_variation_get_price', fn(string|float|int $price, WC_Product $wcProduct): float|string => WoocommerceService::getInstance()->custom_price($price, $wcProduct, get_current_user_id()), 99, 2);
        // Variable (price range)
//        add_filter('woocommerce_variation_prices_price', fn($price, $variation, $product) => $this->custom_variable_price($price, $variation, $product), 99, 3);
//        add_filter('woocommerce_variation_prices_regular_price', fn($price, $variation, $product) => $this->custom_variable_price($price, $variation, $product), 99, 3);
        // Handling price caching (see explanations at the end)
//        add_filter('woocommerce_get_variation_prices_hash', fn($price_hash, $product, $for_display) => $this->add_price_multiplier_to_variation_prices_hash($price_hash, $product, $for_display), 99, 3);
        // endregion

        // region edit woocommerce product display
        add_action('woocommerce_after_order_itemmeta', function (int $item_id, WC_Order_Item $wcOrderItem, WC_Product|bool|null $product): void {
            if (
                is_bool($product) ||
                is_null($product) ||
                !($wcOrderItem instanceof WC_Order_Item_Product)
            ) {
                return;
            }
            $arRef = SageService::getInstance()->get_post_meta_single($product->get_id(), FArticleResource::META_KEY, true);
            if (!empty($arRef)) {
                echo esc_html__('Sage ref', 'egas') . ': ' . esc_html($arRef);
            }
        }, 10, 3);
        // endregion

        // region add column to product list
        add_filter('manage_edit-product_columns', function (array $columns): array { // https://stackoverflow.com/a/44702012/6824121
            $columns[Sage::TOKEN] = __('Sage', 'egas');
            return $columns;
        }, 10, 1);

        add_action('manage_product_posts_custom_column', function (string $column, int $postId): void { // https://www.conicsolutions.net/tutorials/woocommerce-how-to-add-custom-columns-on-the-products-list-in-dashboard/
            if ($column === Sage::TOKEN) {
                $arRef = get_post_meta($postId, FArticleResource::META_KEY, true);
                if (!empty($arRef)) {
//                    echo '<span class="dashicons dashicons-yes" style="color: green"></span>';
                    echo esc_html($arRef);
                } else {
                    echo '<span class="dashicons dashicons-no" style="color: red"></span>';
                }
            }
        }, 10, 2);
        // endregion

        add_filter('woocommerce_order_item_display_meta_key', fn(string $key): string|array => str_replace(' ', ' ', SageTranslationUtils::trans($this->trans, 'words', $key)));
        add_filter('woocommerce_order_item_display_meta_value', function (string $value, WC_Meta_Data $wcMetaData) {
            $data = $wcMetaData->get_data();
            if ($data['key'] === '_' . Sage::TOKEN . '_fLotseriesOut' && $data['value'] !== 'null') {
                $data = json_decode((string)$data['value'], true, 512, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                if (is_array($data) && !empty($data)) {
                    return $data[0]['lsNoSerie'];
                }
            }
            return $value;
        }, accepted_args: 3);

        add_filter('manage_edit-product_cat_columns', function (array $columns): array {
            // Ajouter la colonne 'Egas' après le nom
            $columns[Sage::TOKEN] = __("Sage", 'egas');
            return $columns;
        });
        add_action('manage_product_cat_custom_column', function (string $content, string $column_name, int $term_id): void {
            if (Sage::TOKEN === $column_name) {
                $clNo = get_term_meta($term_id, '_' . Sage::TOKEN . '_clNo', true);
                echo $clNo
                    ? sprintf(
                        '<div style="display: inline-block;" data-tippy-content="<div><strong>fCatalogue: %s</strong></div>">
            <span class="dashicons dashicons-yes" style="color: green"></span>
        </div>',
                        esc_attr($clNo)
                    )
                    : '<span class="dashicons dashicons-no" style="color: red"></span>';
            }
        }, 10, 3);
        add_filter('woocommerce_order_item_get_formatted_meta_data', fn(array $metaDatas): array => array_filter($metaDatas, fn(stdClass $metaData): bool => $metaData->value !== 'null'));
    }
}
