<?php

declare(strict_types=1);

namespace Egas\services;

use Automattic\WooCommerce\Admin\Overrides\OrderRefund;
use Egas\controllers\AdminController;
use Egas\enum\Sage\DocumentFraisTypeEnum;
use Egas\enum\Sage\DocumentProvenanceTypeEnum;
use Egas\enum\Sage\DomaineTypeEnum;
use Egas\enum\Sage\ETypeCalculEnum;
use Egas\enum\Sage\TaxeTauxType;
use Egas\resources\FArticleResource;
use Egas\resources\FComptetResource;
use Egas\resources\FDocenteteResource;
use Egas\resources\Resource;
use Egas\Sage;
use Egas\utils\OrderUtils;
use Egas\utils\TaxeUtils;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use WC_Cart;
use WC_Meta_Data;
use WC_Order;
use WC_Order_Item_Shipping;
use WC_Order_Item_Tax;
use WC_Product;
use WC_Product_Simple;
use WC_Shipping_Rate;
use WC_Tax;
use WP_Error;
use WP_REST_Response;
use WP_Term;
use WP_User;

class WoocommerceService
{
    private static ?WoocommerceService $woocommerceService = null;
    private array $prices = [];

    private function __construct()
    {
    }

    public function convertFComptetToUser(
        StdClass $stdClass,
        ?int     $userId = null,
    ): array
    {
        $sageService = SageService::getInstance();
        $email = $sageService->getEmailFromFComptet($stdClass);
        $i = 1;
        $newEmail = $email;
        while (email_exists($newEmail)) {
            $emailArray = explode('@', $email);
            $emailArray[0] .= $i;
            $newEmail = implode('@', $emailArray);
        }
        $email = $newEmail;
        $fComptetAddress = $sageService->createAddressWithFComptet($stdClass);
        $address = [];
        $fPays = GraphqlService::getInstance()->getFPays(false);
        foreach (OrderUtils::ALL_ADDRESS_TYPE as $addressType) {
            $thisAdress = current(array_filter($stdClass->fLivraisons, static function (StdClass $stdClass) use ($addressType): bool {
                if ($addressType === OrderUtils::BILLING_ADDRESS_TYPE) {
                    return $stdClass->liAdresseFact === 1;
                }
                return $stdClass->liPrincipal === 1;
            }));
            if ($thisAdress === false) {
                $thisAdress = $fComptetAddress;
            }
            $address[$addressType] = $thisAdress;
        }
        $meta = [];
        $fComptetResource = FComptetResource::getInstance();
        foreach ($fComptetResource->getMetadata()() as $metadata) {
            $value = $metadata->getValue();
            if (!is_null($value)) {
                $meta['_' . Sage::TOKEN . $metadata->getField()] = $value($stdClass);
            }
        }
        foreach (OrderUtils::ALL_ADDRESS_TYPE as $addressType) {
            $thisAddress = $address[$addressType];
            [$firstName, $lastName] = $sageService->getFirstNameLastName(
                $thisAddress->liIntitule,
                $thisAddress->liContact
            );
            $fPay = current(array_filter($fPays, static fn(StdClass $stdClass): bool => $stdClass->paIntitule === $thisAddress->liPays));
            $meta = [
                ...$meta,
                // region woocommerce (got from: woocommerce/includes/class-wc-privacy-erasers.php)
                $addressType . '_first_name' => $firstName,
                $addressType . '_last_name' => $lastName,
                $addressType . '_company' => $sageService->getName(intitule: $thisAddress->liIntitule, contact: $thisAddress->liContact),
                $addressType . '_address_1' => $thisAddress->liAdresse,
                $addressType . '_address_2' => $thisAddress->liComplement,
                $addressType . '_city' => $thisAddress->liVille,
                $addressType . '_postcode' => $thisAddress->liCodePostal,
                $addressType . '_state' => $thisAddress->liCodeRegion,
                $addressType . '_country' => $fPay !== false ? $fPay->paCode : $thisAddress->liPaysCode,
                $addressType . '_phone' => $thisAddress->liTelephone,
                $addressType . '_email' => $thisAddress->liEmail,
                // endregion
            ];
        }
        [$firstName, $lastName] = $sageService->getFirstNameLastName(
            $stdClass->ctIntitule,
            $stdClass->ctContact
        );
        $wpUser = new WP_User($userId ?? 0);
        $wpUser->display_name = $sageService->getName(intitule: $stdClass->ctIntitule, contact: $stdClass->ctContact);
        $wpUser->first_name = $firstName;
        $wpUser->last_name = $lastName;
        $wpUser->user_email = $email;

        if (is_null($userId)) {
            $wpUser->user_login = $sageService->getAvailableUserName($stdClass->ctNum);
            $wpUser->user_pass = bin2hex(random_bytes(5));
        }

        return [$userId, $wpUser, $meta];
    }

    public static function getInstance(): self
    {
        if (self::$woocommerceService === null) {
            self::$woocommerceService = new self();
        }
        return self::$woocommerceService;
    }

    public function afterCreateOrEditOrder(WC_Order $wcOrder, bool $isNewOrder = false): void
    {
        $nonce = isset($_POST['woocommerce_meta_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['woocommerce_meta_nonce']))
            : '';
        if (
            !$nonce ||
            !wp_verify_nonce($nonce, 'woocommerce_save_data')
        ) {
            return;
        }

        if (
            isset(
                $_POST[Sage::TOKEN . '-fdocentete-dotype'],
                $_POST[Sage::TOKEN . '-fdocentete-dopiece']
            ) &&
            is_numeric($_POST[Sage::TOKEN . '-fdocentete-dotype']) &&
            $_POST[Sage::TOKEN . '-fdocentete-dopiece'] !== ''
        ) {
            $doPiece = isset($_POST[Sage::TOKEN . '-fdocentete-dopiece'])
                ? sanitize_text_field(wp_unslash($_POST[Sage::TOKEN . '-fdocentete-dopiece']))
                : '';
            $doType = isset($_POST[Sage::TOKEN . '-fdocentete-dotype'])
                ? (int)sanitize_text_field(wp_unslash($_POST[Sage::TOKEN . '-fdocentete-dotype']))
                : 0;
            $this->importFDocenteteFromSage($doPiece, $doType, $wcOrder);
        }
    }

    public function importFDocenteteFromSage(string $doPiece, string|int $doType, WC_Order|null $order = null, ?string $origin = null): array
    {
        // if the document is big it can take quite some time
        set_time_limit(60 * 10);
        if (is_null($order)) {
            $orders = wc_get_orders([
                'limit' => 1,
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => '_' . Sage::TOKEN . '_doPiece',
                        'value' => $doPiece,
                    ],
                    [
                        'key' => '_' . Sage::TOKEN . '_doType',
                        'value' => $doType,
                    ]
                ]
            ]);
            $order = empty($orders) ? new WC_Order() : $orders[0];
        }
        $graphqlService = GraphqlService::getInstance();
        $extendedFDocentetes = $graphqlService->getFDocentetes(
            $doPiece,
            [$doType],
            doDomaine: DomaineTypeEnum::DomaineTypeVente->value,
            doProvenance: DocumentProvenanceTypeEnum::DocProvenanceNormale->value,
            getError: true,
            getFDoclignes: true,
            getExpedition: true,
            addWordpressProductId: true,
            getUser: true,
            getLivraison: true,
            getLotSerie: true,
            extended: true,
            getFDocregls: true,
        );
        if (!is_array($extendedFDocentetes)) {
            return [null, null, $extendedFDocentetes, 0];
        }
        $fDocentete = null;
        if (!empty($extendedFDocentetes)) {
            $fDocentete = array_values(array_filter($extendedFDocentetes, fn($fDocentete): bool => $fDocentete->doPiece === $doPiece && $fDocentete->doType === (int)$doType));
            if (!empty($fDocentete)) {
                $fDocentete = $fDocentete[0];
            } else {
                return [null, null, $extendedFDocentetes, 0];
            }
        }
        $resource = SageService::getInstance()->getResource(FDocenteteResource::ENTITY_NAME);
        foreach ($extendedFDocentetes as $extendedFDocentete) {
            $canImportFDocentete = $resource->getCanImport()($extendedFDocentete);
            if (!empty($canImportFDocentete)) {
                return [Response::HTTP_CONFLICT, null, "<div class='error'>
                        " . implode(' ', $canImportFDocentete) . "
                                </div>", 0];
            }
        }
        if (!empty($origin) && empty($order->get_created_via())) {
            $order->add_meta_data('_wc_order_attribution_source_type', 'utm');
            $order->add_meta_data('_wc_order_attribution_utm_source', $origin);
        }
        $order->update_meta_data(FDocenteteResource::META_KEY, json_encode([
            'doPiece' => $fDocentete->doPiece,
            'doType' => $fDocentete->doType,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        $order->update_meta_data('_' . Sage::TOKEN . '_doPiece', $fDocentete->doPiece);
        $order->update_meta_data('_' . Sage::TOKEN . '_doType', $fDocentete->doType);
        $order->save();
        $tasksSynchronizeOrder = $this->getTasksSynchronizeOrder($order, $extendedFDocentetes);
        return $this->applyTasksSynchronizeOrder($order, $tasksSynchronizeOrder);
    }

    public function getTasksSynchronizeOrder(
        WC_Order          $wcOrder,
        array|null|string $extendedFDocentetes,
        bool              $allChanges = true,
        bool              $getProductChanges = false,
        bool              $getShippingChanges = false,
        bool              $getFeeChanges = false,
        bool              $getCouponChanges = false,
        bool              $getTaxesChanges = false,
        bool              $getSerialChanges = false,
        bool              $getUserChanges = false,
        bool              $getPaymentChanges = false,
    ): array
    {
        $result = [
            'allProductsExistInWordpress' => true,
            'syncChanges' => [],
            'products' => [],
        ];
        if (empty($extendedFDocentetes) || is_string($extendedFDocentetes)) {
            return $result;
        }
        $taxeCodesProduct = [];
        $taxeCodesShipping = [];
        $getProductChanges = $allChanges || $getProductChanges;
        $getShippingChanges = $allChanges || $getShippingChanges;
        $getFeeChanges = $allChanges || $getFeeChanges;
        $getCouponChanges = $allChanges || $getCouponChanges;
        $getTaxesChanges = $allChanges || $getTaxesChanges;
        $getSerialChanges = $allChanges || $getSerialChanges;
        $getUserChanges = $allChanges || $getUserChanges;
        $getPaymentChanges = $allChanges || $getPaymentChanges;
        $sageService = SageService::getInstance();
        $fDoclignes = $sageService->getFDoclignes($extendedFDocentetes);
        $fDoclignes = array_values(array_filter($fDoclignes, fn(StdClass $stdClass): bool => empty($stdClass->canImport)));
        $mainFDocentete = $sageService->getMainFDocenteteOfExtendedFDocentetes($wcOrder, $extendedFDocentetes);
        if ($getProductChanges || $getTaxesChanges || $getSerialChanges) {
            [$productChanges, $products, $taxeCodesProduct] = $sageService->getTasksSynchronizeOrder_Products($wcOrder, $fDoclignes);
            $result['products'] = $products;
            if ($getProductChanges) {
                $result['syncChanges'] = [...$result['syncChanges'], ...$productChanges];
            }
        }
        if ($getShippingChanges || $getTaxesChanges) {
            [$shippingChanges, $taxeCodesShipping] = $sageService->getTasksSynchronizeOrder_Shipping($wcOrder, $mainFDocentete);
            if ($getShippingChanges) {
                $result['syncChanges'] = [...$result['syncChanges'], ...$shippingChanges];
            }
        }
        if ($getFeeChanges) {
            $feeChanges = $sageService->getTasksSynchronizeOrder_Fee($wcOrder);
            $result['syncChanges'] = [...$result['syncChanges'], ...$feeChanges];
        }
        if ($getCouponChanges) {
            $couponChanges = $sageService->getTasksSynchronizeOrder_Coupon($wcOrder);
            $result['syncChanges'] = [...$result['syncChanges'], ...$couponChanges];
        }
        if ($getTaxesChanges) {
            $taxeCodesProduct = array_values(array_unique([...$taxeCodesProduct, ...$taxeCodesShipping]));
            $taxesChanges = $sageService->getTasksSynchronizeOrder_Taxes($wcOrder, $taxeCodesProduct);
            $result['syncChanges'] = [...$result['syncChanges'], ...$taxesChanges];
        }
        if ($getUserChanges) {
            $userChanges = $sageService->getTasksSynchronizeOrder_User($wcOrder, $mainFDocentete);
            $result['syncChanges'] = [...$result['syncChanges'], ...$userChanges];
        }
        if ($getPaymentChanges) {
            $paymentChanges = $sageService->getTasksSynchronizeOrder_Payment($wcOrder, $mainFDocentete);
            $result['syncChanges'] = [...$result['syncChanges'], ...$paymentChanges];
        }

        $result['allProductsExistInWordpress'] = array_filter($fDoclignes, static fn(stdClass $fDocligne): bool => is_null($fDocligne->postId)) === [];

        return $result;
    }

    public function applyTasksSynchronizeOrder(WC_Order $wcOrder, array $tasksSynchronizeOrder): array
    {
        $message = '';
        $syncChanges = $tasksSynchronizeOrder["syncChanges"];
        usort($syncChanges, static function (array $a, array $b): int {
            $lastA = in_array(OrderUtils::UPDATE_WC_ORDER_ITEM_TAX_ACTION, $a['changes'], true);
            $lastB = in_array(OrderUtils::UPDATE_WC_ORDER_ITEM_TAX_ACTION, $b['changes'], true);
            if ($lastA && !$lastB) {
                return 1;
            }
            if (!$lastA && $lastB) {
                return -1;
            }
            return 0;
        });
        $alreadyAddedTaxes = [];

        // region create missing products
        foreach ($tasksSynchronizeOrder["syncChanges"] as $i => $syncChange) {
            foreach ($syncChange['changes'] as $change) {
                switch ($change) {
                    case OrderUtils::ADD_PRODUCT_ACTION:
                    case OrderUtils::REPLACE_PRODUCT_ACTION:
                        if (is_null($syncChange["new"]->postId)) {
                            [$response, $responseError, $message2, $postId] = $this->importFArticleFromSage($syncChange["new"]->arRef);
                            $tasksSynchronizeOrder["syncChanges"][$i]["new"]->postId = $postId;
                            $message .= $message2;
                        }
                        break;
                }
            }
        }
        // endregion

        foreach ($tasksSynchronizeOrder["syncChanges"] as $syncChange) {
            foreach ($syncChange['changes'] as $change) {
                // todo use $order->add_order_note ?
                switch ($change) {
                    case OrderUtils::ADD_PRODUCT_ACTION:
                        $message .= $this->addProductToOrder($wcOrder, $syncChange["new"]->postId, $syncChange["new"]->quantity, $syncChange["new"], $alreadyAddedTaxes);
                        break;
                    case OrderUtils::CHANGE_PRICE_PRODUCT_ACTION:
                        $message .= $this->changePriceProductOrder($wcOrder, $syncChange["old"]->itemId, $syncChange["new"]->linePriceHt);
                        break;
                    case OrderUtils::CHANGE_TAXES_PRODUCT_ACTION:
                        $message .= $this->changeTaxesProductOrder($wcOrder, $syncChange["old"]->itemId, $syncChange["new"]->taxes, $alreadyAddedTaxes);
                        break;
                    case OrderUtils::CHANGE_SERIAL_PRODUCT_OUT_ACTION:
                        $message .= $this->changeSerialOutProductOrder($wcOrder, $syncChange["old"]->itemId, $syncChange["new"]->fLotseriesOut);
                        break;
                    case OrderUtils::REPLACE_PRODUCT_ACTION:
                        $message .= $this->replaceProductToOrder($wcOrder, $syncChange["old"]->itemId, $syncChange["new"]->postId, $syncChange["new"], $alreadyAddedTaxes);
                        break;
                    case OrderUtils::ADD_SHIPPING_ACTION:
                        $message .= $this->addShippingToOrder($wcOrder, $syncChange["new"], $alreadyAddedTaxes);
                        break;
                    case OrderUtils::REMOVE_SHIPPING_ACTION:
                        $message .= $this->removeShippingToOrder($wcOrder, $syncChange["old"]->id);
                        break;
                    case OrderUtils::UPDATE_WC_ORDER_ITEM_TAX_ACTION:
                        $message .= $this->updateWcOrderItemTaxToOrder($wcOrder, $syncChange["new"], $alreadyAddedTaxes);
                        break;
                    case OrderUtils::REMOVE_PRODUCT_ACTION:
                        $message .= $this->removeProductOrder($wcOrder, $syncChange["old"]->itemId);
                        break;
                    case OrderUtils::CHANGE_QUANTITY_PRODUCT_ACTION:
                        $message .= $this->changeQuantityProductOrder($wcOrder, $syncChange["old"]->itemId, $syncChange["new"]->quantity);
                        break;
                    case OrderUtils::REMOVE_FEE_ACTION:
                        $message .= $this->removeFeeOrder($wcOrder, $syncChange["old"]->id);
                        break;
                    case OrderUtils::REMOVE_COUPON_ACTION:
                        $message .= $this->removeCouponOrder($wcOrder, $syncChange["old"]->id);
                        break;
                    case OrderUtils::CHANGE_CUSTOMER_ACTION:
                        $message .= $this->changeCustomerOrder($wcOrder, $syncChange["new"]);
                        break;
                    case OrderUtils::CHANGE_USER_ACTION . '_' . OrderUtils::BILLING_ADDRESS_TYPE:
                    case OrderUtils::CHANGE_USER_ACTION . '_' . OrderUtils::SHIPPING_ADDRESS_TYPE:
                        $message .= $this->updateUserMetas($wcOrder, $syncChange["new"]);
                        break;
                    case OrderUtils::CHANGE_ORDER_ADDRESS_TYPE_ACTION . '_' . OrderUtils::BILLING_ADDRESS_TYPE:
                        $message .= $this->updateOrderMetas($wcOrder, $syncChange["new"], OrderUtils::BILLING_ADDRESS_TYPE);
                        break;
                    case OrderUtils::CHANGE_ORDER_ADDRESS_TYPE_ACTION . '_' . OrderUtils::SHIPPING_ADDRESS_TYPE:
                        $message .= $this->updateOrderMetas($wcOrder, $syncChange["new"], OrderUtils::SHIPPING_ADDRESS_TYPE);
                        break;
                    case OrderUtils::CHANGE_PAYMENT_ACTION:
                        [$wcOrder, $message2] = $this->changePaymentsOrder($wcOrder, $syncChange["new"]);
                        $message .= $message2;
                        break;
                    default:
                        $message .= "<div class='notice notice-error is-dismissible'>
    <p>" . __('Aucune action définie pour', 'egas-data-sync-for-sage') . ': ' . esc_html(json_encode($syncChange['changes'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) . "</p>
</div>";
                        break;
                }
            }
        }

        $message .= $this->removeDuplicateWcOrderItemTaxToOrder($wcOrder);

        // region woocommerce/includes/admin/wc-admin-functions.php:455 function wc_save_order_items
        $wcOrder = new WC_Order($wcOrder->get_id()); // to refresh order with data in bdd
        $wcOrder->update_taxes();
        $wcOrder->calculate_totals(false);
        // endregion

        return [null, "", $message, $wcOrder];
    }

    public function importFArticleFromSage(
        string        $arRef,
        stdClass|null $fArticle = null,
        bool          $showSuccessMessage = true,
    ): array
    {
        if (is_null($fArticle)) {
            $fArticle = GraphqlService::getInstance()->getFArticle($arRef);
        }
        if (is_null($fArticle)) {
            return [null, null, "<div class='error'>
                    " . __("L'article n'a pas pu être importé", 'egas-data-sync-for-sage') . "
                            </div>", 0];
        }
        $resource = SageService::getInstance()->getResource(FArticleResource::ENTITY_NAME);
        $canImportFArticle = $resource->getCanImport()($fArticle);
        if (!empty($canImportFArticle)) {
            return [Response::HTTP_CONFLICT, null, "<div class='error'>
                    " . implode(' ', $canImportFArticle) . "
                            </div>", 0];
        }
        $articlePostId = $this->getWooCommerceIdArticle($arRef);
        $article = $this->convertSageArticleToWoocommerce($fArticle, SageService::getInstance()->getResource(FArticleResource::ENTITY_NAME), $articlePostId);
        $dismissNotice = "<button type='button' class='notice-dismiss " . Sage::TOKEN . "-notice-dismiss'><span class='screen-reader-text'>" . __('Ignorer cet avis.', 'egas-data-sync-for-sage') . "</span></button>";
        $urlArticle = "<strong><span style='display: block; clear: both;'><a href='" . get_admin_url() . "post.php?post=%id%&action=edit'>" . __("Voir l'article", 'egas-data-sync-for-sage') . "</a></span></strong>";
        $message = '';
        if (is_null($articlePostId)) {
            // cannot create an article without request
            // ========================================
            // created with: (new WC_REST_Products_Controller())->create_item($request);
            // woocommerce/includes/rest-api/Controllers/Version3/class-wc-rest-crud-controller.php : public function create_item( $request )
            // which extends
            // woocommerce/includes/rest-api/Controllers/Version3/class-wc-rest-products-controller.php
            $postArticle = $article;
            $postArticle["categories"] = array_map(fn(int $categoryId): array => ['id' => $categoryId], $postArticle["categories"]);
            [$response, $responseError] = SageService::getInstance()->createResource(
                '/wc/v3/products',
                'POST',
                $postArticle,
                FArticleResource::META_KEY,
                $arRef,
            );
            if (is_string($responseError)) {
                $message = $responseError;
            } elseif ($response->get_status() === 201) {
                $body = $response->get_data();
                $urlArticle = str_replace('%id%', (string)$body['id'], $urlArticle);
                $articlePostId = $body['id'];
                if ($showSuccessMessage) {
                    $message = "<div class='notice notice-success is-dismissible'>
                <p>" . __('Article créé: ', 'egas-data-sync-for-sage') . $body['name'] . "</p>" . $urlArticle . "
                {$dismissNotice}
                        </div>";
                }
            } else {
                $message = json_encode($response->get_data(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            }
        } else {
            $oldMetadata = SageService::getInstance()->get_post_meta_single($articlePostId);
            $allMetadataNames = array_map(static fn(array $meta) => $meta['key'], $article["meta_data"]);
            foreach ($oldMetadata as $key => $value) {
                if (!in_array($key, $allMetadataNames, true) && str_starts_with((string)$key, '_' . Sage::TOKEN)) {
                    delete_post_meta($articlePostId, $key);
                }
            }
            foreach ($article["meta_data"] as $meta) {
                update_post_meta($articlePostId, $meta['key'], $meta['value']);
            }
            $wprestResponse = new WP_REST_Response(['id' => $articlePostId], 200);
            $responseError = null;
            $urlArticle = str_replace('%id%', (string)$articlePostId, $urlArticle);
            if ($showSuccessMessage) {
                $message = "<div class='notice notice-success is-dismissible'>
                <p>" . __('Article mis à jour: ', 'egas-data-sync-for-sage') . $article["name"] . "</p>" . $urlArticle . "
                {$dismissNotice}
                        </div>";
            }
        }
        if (!empty($articlePostId)) {
            /** @var WC_Product $wcProduct */
            $wcProduct = wc_get_product($articlePostId);
            $wcProduct->set_category_ids($article["categories"]);
            $wcProduct->set_sku($arRef); // for woocommerce to able to search the product
            $wcProduct->save();
        }
        return [$wprestResponse, $responseError, $message, $articlePostId];
    }

    public function getWooCommerceIdArticle(string $arRef): ?int
    {
        global $wpdb;
        $id = $wpdb->get_var(
            $wpdb->prepare(
                "
            SELECT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID
            WHERE p.post_type = 'product'
              AND p.post_status != 'trash'
              AND pm.meta_key = %s
              AND pm.meta_value = %s
            ",
                FArticleResource::META_KEY,
                $arRef
            )
        );
        return $id ? (int)$id : null;
    }

    public function convertSageArticleToWoocommerce(StdClass $stdClass, Resource $resource, ?int $postId): array
    {
        $fCatalogues = $this->createCategories(array_map(
            fn(int $i) => $stdClass->{'clNo' . $i . 'Navigation'},
            range(1, 4)
        ));
        // https://woocommerce.github.io/woocommerce-rest-api-docs/#product-properties
        $result = [
            'name' => $stdClass->arDesign,
            'categories' => array_map(fn(stdClass $fCatalogue) => $fCatalogue->websiteId, $fCatalogues),
            'meta_data' => [],
        ];
        foreach ($resource->getMetadata()($stdClass) as $metadata) {
            $value = $metadata->getValue();
            $optionName = '_' . Sage::TOKEN . $metadata->getField();
            if (!is_null($value)) {
                $v = $value($stdClass);
                if (is_bool($v)) {
                    $v = (int)$v;
                }
            } else {
                $v = get_post_meta($postId, $optionName, true);
                if (empty($v)) {
                    continue;
                }
            }
            $result['meta_data'][] = [
                'key' => $optionName,
                'value' => $v,
            ];
        }
        return $result;
    }

    private function createCategories(array $fCatalogues): array
    {
        $fCatalogues = array_filter($fCatalogues);
        $prevFCatalogue = null;
        foreach ($fCatalogues as $fCatalogue) {
            $terms = get_terms([
                'taxonomy' => 'product_cat', // taxonomie WooCommerce
                'hide_empty' => false,         // inclure les catégories sans produits
                'meta_query' => [
                    [
                        'key' => '_' . Sage::TOKEN . '_clNo',
                        'value' => $fCatalogue->clNo,
                        'compare' => '=',
                        'type' => 'NUMERIC',
                    ]
                ]
            ]);
            if (empty($terms)) {
                $args = [];
                if (!is_null($prevFCatalogue)) {
                    $args['parent'] = $prevFCatalogue->websiteId;
                }
                $termId = wp_insert_term($fCatalogue->clIntitule, 'product_cat', $args)['term_id'];
                add_term_meta($termId, '_' . Sage::TOKEN . '_clNo', $fCatalogue->clNo, true);
            } else {
                /** @var WP_Term $wpTerm */
                $wpTerm = $terms[0];
                $termId = $wpTerm->term_id;
                if ($wpTerm->name !== $fCatalogue->clIntitule) {
                    $args = ['name' => $fCatalogue->clIntitule];
                    if ($wpTerm->count === 0) {
                        $args['slug'] = sanitize_title($fCatalogue->clIntitule);
                    }
                    wp_update_term($termId, 'product_cat', $args);
                }
            }
            $fCatalogue->websiteId = $termId;
            $prevFCatalogue = $fCatalogue;
        }

        return $fCatalogues;
    }

    private function addProductToOrder(WC_Order $wcOrder, ?int $productId, int $quantity, stdClass $new, array &$alreadyAddedTaxes): string
    {
        $message = '';
        if (empty($productId)) {
            return $message;
        }
        $qty = wc_stock_amount($quantity);
        if (is_null($new->postId)) {
            [$response, $responseError, $message2, $postId] = $this->importFArticleFromSage($new->arRef);
            if ($response->get_status() !== 201 && $response->get_status() !== 200) {
                return $message2;
            }
            $productId = $response->get_data()['id'];
        }

        $product = wc_get_product($productId);
        $itemId = $wcOrder->add_product($product, $qty);
        return $message . $this->updateProductOrder($wcOrder, $itemId, $new, $alreadyAddedTaxes);
    }

    private function updateProductOrder(WC_Order $wcOrder, int $itemId, stdClass $new, array &$alreadyAddedTaxes): string
    {
        $message = $this->changeQuantityProductOrder($wcOrder, $itemId, $new->quantity, false);
        $message .= $this->changePriceProductOrder($wcOrder, $itemId, $new->linePriceHt, false);
        $message .= $this->changeTaxesProductOrder($wcOrder, $itemId, $new->taxes, $alreadyAddedTaxes);
        return $message . $this->changeSerialOutProductOrder($wcOrder, $itemId, $new->fLotseriesOut);
    }

    private function changeQuantityProductOrder(WC_Order $wcOrder, int $itemId, int $quantity, bool $save = true): string
    {
        $message = '';
        $lineItems = array_values($wcOrder->get_items());

        foreach ($lineItems as $lineItem) {
            if ($lineItem->get_id() === $itemId) {
                $lineItem->set_quantity($quantity);
                if ($save) {
                    $lineItem->save();
                }
                break;
            }
        }
        return $message;
    }

    private function changePriceProductOrder(WC_Order $wcOrder, int $itemId, float $linePriceHt, bool $save = true): string
    {
        $lineItems = array_values($wcOrder->get_items());
        foreach ($lineItems as $lineItem) {
            if ($lineItem->get_id() === $itemId) {
                $lineItem->set_props([
                    'subtotal' => (string)$linePriceHt, // subtotal is what the price should be, if higher than total difference will be display as discount (Before discount)
                    'total' => (string)$linePriceHt,
                ]);
                if ($save) {
                    $lineItem->save();
                }
                break;
            }
        }
        return '';
    }

    private function changeTaxesProductOrder(WC_Order $wcOrder, int $itemId, array $taxes, array &$alreadyAddedTaxes, bool $save = true): string
    {
        $message = '';
        $lineItems = array_values($wcOrder->get_items());

        foreach ($lineItems as $lineItem) {
            if ($lineItem->get_id() === $itemId) {
                foreach ($taxes as $tax) {
                    $alreadyAddedTaxes[] = $tax['code'];
                }
                $lineItem->set_taxes($this->formatTaxes($wcOrder, $taxes, $message));
                if ($save) {
                    $lineItem->save();
                }
                break;
            }
        }
        return $message;
    }

    private function formatTaxes(WC_Order $wcOrder, array $taxes, string &$message, int $errorMissingTax = 0): array
    {
        $orderId = $wcOrder->get_id();
        $orderItemTaxes = $wcOrder->get_taxes();
        $orderItemTaxesRateId = array_map(static fn(WC_Order_Item_Tax $wcOrderItemTax) => $wcOrderItemTax->get_rate_id(), $orderItemTaxes);
        [$taxe, $rates] = $this->getWordpressTaxes();
        $result = ['total' => [], 'subtotal' => []];
        foreach ($taxes as $tax) {
            $rate = current(array_filter($rates, static fn(stdClass $rate): bool => $rate->tax_rate_name === $tax['code']));
            if ($rate === false) {
                if ($errorMissingTax === 0) {
                    $errorMissingTax++;
                    $this->updateTaxes();
                    return $this->formatTaxes($wcOrder, $taxes, $message, $errorMissingTax);
                }
                $message .= "<div class='notice notice-error is-dismissible'>
                    <p>" . __('Il semblerait que la taxe soit manquante.', 'egas-data-sync-for-sage') . "[" . $tax['code'] . "]</p>
                    </div>";
                continue;
            }
            $result['total'][$rate->tax_rate_id] = (string)$tax['amount'];
            $result['subtotal'][$rate->tax_rate_id] = (string)$tax['amount'];

            if (!in_array((int)$rate->tax_rate_id, $orderItemTaxesRateId, true)) {
                // woocommerce/includes/class-wc-ajax.php public static function add_order_tax
                $orderItemTax = new WC_Order_Item_Tax();
                $orderItemTax->set_rate($rate->tax_rate_id);
                $orderItemTax->set_order_id($orderId);
                $orderItemTax->save();
            }
        }
        return $result;
    }

    public function getWordpressTaxes(): array
    {
        $taxes = WC_Tax::get_tax_rate_classes();
        $taxe = current(array_filter($taxes, static fn(stdClass $taxe): bool => $taxe->slug === Sage::TOKEN));
        if ($taxe === false) {
            WC_Tax::create_tax_class(__('Egas', 'egas-data-sync-for-sage'), Sage::TOKEN);
            $taxes = WC_Tax::get_tax_rate_classes();
            $taxe = current(array_filter($taxes, static fn(stdClass $taxe): bool => $taxe->slug === Sage::TOKEN));
        }
        $rates = WC_Tax::get_rates_for_tax_class($taxe->slug);
        return [$taxe, $rates];
    }

    public function updateTaxes(bool $showMessage = true): void
    {
        [$taxe, $rates] = $this->getWordpressTaxes();
        $fTaxes = GraphqlService::getInstance()->getFTaxes(useCache: false, getFromSage: true);
        if (!AdminController::showErrors($fTaxes)) {
            $taxeChanges = $this->getTaxesChanges($fTaxes, $rates);
            $this->applyTaxesChanges($taxeChanges);
            if ($showMessage && $taxeChanges !== []) {
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php echo esc_html__("Les taxes Sage ont été mises à jour.", 'egas-data-sync-for-sage') ?></strong></p>
                </div>
                <?php
            }
        }
    }

    private function getTaxesChanges(array $fTaxes, array $rates): array
    {
        $taxeChanges = [];
        $compareFunction = function (stdClass $fTaxe, stdClass $rate): bool {
            $taTaux = (float)($fTaxe->taNp === 1 ? 0 : $fTaxe->taTaux);
            return
                $fTaxe->taCode === $rate->tax_rate_name &&
                $taTaux === (float)$rate->tax_rate &&
                $rate->tax_rate_country === '' &&
                $rate->postcode_count === 0 &&
                $rate->city_count === 0;
        };
        foreach ($fTaxes as $fTaxe) {
            $rate = current(array_filter($rates, static fn(stdClass $rate): bool => $compareFunction($fTaxe, $rate)));
            if ($rate === false) {
                $taxeChanges[] = [
                    'old' => null,
                    'new' => $fTaxe,
                    'change' => TaxeUtils::ADD_TAXE_ACTION,
                ];
            }
        }
        foreach ($rates as $rate) {
            $fTaxe = current(array_filter($fTaxes, static fn(stdClass $fTaxe): bool => $compareFunction($fTaxe, $rate)));
            if ($fTaxe === false) {
                $taxeChanges[] = [
                    'old' => $rate,
                    'new' => null,
                    'change' => TaxeUtils::REMOVE_TAXE_ACTION,
                ];
            }
        }
        return $taxeChanges;
    }

    public function applyTaxesChanges(array $taxeChanges): void
    {
        foreach ($taxeChanges as $taxeChange) {
            if ($taxeChange["change"] === TaxeUtils::ADD_TAXE_ACTION) {
                WC_Tax::_insert_tax_rate([
                    "tax_rate_country" => "",
                    "tax_rate_state" => "",
                    "tax_rate" => $taxeChange["new"]->taNp === 1 ? 0 : (string)$taxeChange["new"]->taTaux,
                    "tax_rate_name" => $taxeChange["new"]->taCode,
                    "tax_rate_priority" => "1",
                    "tax_rate_compound" => "0",
                    "tax_rate_shipping" => "1",
                    "tax_rate_class" => Sage::TOKEN
                ]);
            } elseif ($taxeChange["change"] === TaxeUtils::REMOVE_TAXE_ACTION) {
                WC_Tax::_delete_tax_rate($taxeChange["old"]->tax_rate_id);
            }
        }
    }

    private function changeSerialOutProductOrder(WC_Order $wcOrder, int $itemId, array|null $fLotseriesOut): string
    {
        $lineItems = array_values($wcOrder->get_items());
        $key = '_' . Sage::TOKEN . '_fLotseriesOut';
        foreach ($lineItems as $lineItem) {
            if ($lineItem->get_id() === $itemId) {
                /** @var WC_Meta_Data[] $wcMetaDatas */
                $wcMetaDatas = $lineItem->get_meta_data();
                $found = false;
                foreach ($wcMetaDatas as $wcMetumData) {
                    $value = $wcMetumData->get_data();
                    if ($value['key'] === $key) {
                        $found = true;
                        $value["value"] = json_encode($fLotseriesOut, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                        $lineItem->set_meta_data($value);
                        break;
                    }
                }
                if (!$found) {
                    $lineItem->add_meta_data($key, json_encode($fLotseriesOut, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), true);
                }
                $lineItem->save_meta_data();
                break;
            }
        }
        return '';
    }

    private function replaceProductToOrder(WC_Order $wcOrder, int $itemId, int $productId, stdClass $new, array &$alreadyAddedTaxes): string
    {
        $message = '';
        $lineItems = array_values($wcOrder->get_items());
        foreach ($lineItems as $lineItem) {
            if ($lineItem->get_id() === $itemId) {
                foreach ($new->taxes as $taxe) {
                    $alreadyAddedTaxes[] = $taxe['code'];
                }
                $lineItem->set_product(new WC_Product($productId));
                $message .= $this->updateProductOrder($wcOrder, $itemId, $new, $alreadyAddedTaxes);
                break;
            }
        }

        return $message;
    }

    private function addShippingToOrder(WC_Order $wcOrder, stdClass $new, array &$alreadyAddedTaxes): string
    {
        $message = '';
        foreach ($new->taxes as $taxe) {
            $alreadyAddedTaxes[] = $taxe['code'];
        }
        $wcOrderItemShipping = new WC_Order_Item_Shipping();
        $wcOrderItemShipping->set_props(['method_title' => $new->name, 'method_id' => $new->method_id, 'total' => wc_format_decimal($new->priceHt), 'taxes' => $this->formatTaxes($wcOrder, $new->taxes, $message)]);
        $wcOrder->add_item($wcOrderItemShipping);
        $wcOrder->save();
        return $message;
    }

    private function removeShippingToOrder(WC_Order $wcOrder, int $id): string
    {
        $message = '';
        $lineItemsShipping = array_values($wcOrder->get_items('shipping'));
        foreach ($lineItemsShipping as $lineItemShipping) {
            if ($lineItemShipping->get_id() === $id) {
                $wcOrder->remove_item($id);
                $wcOrder->save();
                break;
            }
        }
        return $message;
    }

    private function updateWcOrderItemTaxToOrder(WC_Order $wcOrder, array $new, array $alreadyAddedTaxes): string
    {
        $message = '';
        $orderId = $wcOrder->get_id();
        [$toRemove, $toAdd] = $this->getToRemoveToAddTaxes($wcOrder, $new);
        $toAdd = array_diff($toAdd, $alreadyAddedTaxes);
        [$taxe, $rates] = $this->getWordpressTaxes();
        foreach ($toAdd as $codeToAdd) {
            $rate = current(array_filter($rates, static fn(stdClass $rate): bool => $rate->tax_rate_name === $codeToAdd));
            $orderItemTax = new WC_Order_Item_Tax();
            $orderItemTax->set_rate($rate->tax_rate_id);
            $orderItemTax->set_order_id($orderId);
            $orderItemTax->save();
        }
        if (!empty($toRemove)) {
            $wcOrderItemTaxs = $wcOrder->get_taxes();
            $wcShippingItemTaxs = $wcOrder->get_shipping_methods();
            foreach ($toRemove as $codeRemove) {
                foreach ($wcOrderItemTaxs as $wcOrderItemTax) {
                    if ($wcOrderItemTax->get_label() === $codeRemove) {
                        $wcOrderItemTax->delete();
                        // no break because can have multiple same label
                    }
                }
                foreach ($wcShippingItemTaxs as $wcShippingItemTax) {
                    $taxes = $wcShippingItemTax->get_taxes();
                    if (empty($taxes)) {
                        continue;
                    }
                    $keys = array_keys($taxes["total"]);
                    foreach ($keys as $key) {
                        if (in_array($rates[$key]->tax_rate_name, $toRemove, true)) {
                            unset($taxes["total"][$key]);
                        }
                    }
                    $wcShippingItemTax->set_taxes($taxes);
                }
            }
        }
        return $message;
    }

    public function getToRemoveToAddTaxes(WC_Order $wcOrder, array $new): array
    {
        $current = array_values(array_map(static fn(WC_Order_Item_Tax $wcOrderItemTax) => $wcOrderItemTax->get_label(), $wcOrder->get_taxes()));
        $toRemove = array_diff($current, $new);
        $toAdd = array_diff($new, $current);
        return [$toRemove, $toAdd];
    }

    private function removeProductOrder(WC_Order $wcOrder, int $itemId): string
    {
        $message = '';
        $lineItems = $wcOrder->get_items();

        $wcOrder->remove_item($itemId);
        foreach ($lineItems as $lineItem) {
            if ($lineItem->get_id() === $itemId) {
                $lineItem->delete();
                break;
            }
        }
        $wcOrder->save();
        return $message;
    }

    private function removeFeeOrder(WC_Order $wcOrder, int $id): string
    {
        $message = '';
        $lineItemsFee = array_values($wcOrder->get_items('fee'));
        foreach ($lineItemsFee as $lineItemFee) {
            if ($lineItemFee->get_id() === $id) {
                $lineItemFee->delete();
            }
        }
        return $message;
    }

    private function removeCouponOrder(WC_Order $wcOrder, int $id): string
    {
        $message = '';
        $coupons = $wcOrder->get_coupons();
        foreach ($coupons as $coupon) {
            if ($coupon->get_id() === $id) {
                $coupon->delete();
            }
        }
        return $message;
    }

    private function changeCustomerOrder(WC_Order $wcOrder, stdClass $new): string
    {
        $message = '';
        $userId = $new->userId;
        if (is_null($userId)) {
            [$response, $responseError, $message, $userId] = SageService::getInstance()->importFComptetFromSage($new->ctNum);
            if (!is_numeric($userId)) {
                return $message;
            }
        }
        $wcOrder->set_customer_id($userId);
        $wcOrder->save();

        $fDocenteteIdentifier = $this->getFDocenteteIdentifierFromOrder($wcOrder);
        $extendedFDocentetes = GraphqlService::getInstance()->getFDocentetes(
            $fDocenteteIdentifier["doPiece"],
            [$fDocenteteIdentifier["doType"]],
            doDomaine: DomaineTypeEnum::DomaineTypeVente->value,
            doProvenance: DocumentProvenanceTypeEnum::DocProvenanceNormale->value,
            getError: true,
            getFDoclignes: true,
            getExpedition: true,
            addWordpressProductId: true,
            getUser: true,
            getLivraison: true,
            getLotSerie: true,
            extended: true,
        );

        if (!is_string($extendedFDocentetes)) {
            $this->applyTasksSynchronizeOrder($wcOrder, $this->getTasksSynchronizeOrder(
                $wcOrder,
                $extendedFDocentetes,
                allChanges: false,
                getUserChanges: true,
            ));
        } else {
            $message .= $extendedFDocentetes;
        }

        return $message;
    }

    public function getFDocenteteIdentifierFromOrder(WC_Order $wcOrder): array|null
    {
        $result = $wcOrder->get_meta(FDocenteteResource::META_KEY);
        if (!empty($result)) {
            return json_decode($result, true, 512, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        return null;
    }

    private function updateUserMetas(WC_Order $wcOrder, stdClass $new): string
    {
        $message = '';
        $userId = $wcOrder->get_user_id();
        foreach ((array)$new as $key => $value) {
            update_user_meta($userId, $key, $value);
        }
        return $message;
    }

    private function updateOrderMetas(WC_Order $wcOrder, stdClass $new, string $addressType): string
    {
        $message = '';
        foreach ((array)$new as $key => $value) {
            if ($key === 'email') {
                $value = WordpressService::getInstance()->getValidWordpressMail($value);
            }
            $wcOrder->{'set_' . $addressType . '_' . $key}($value ?? ''); // doesn't accept null value
        }
        $wcOrder->save();
        return $message;
    }

    private function changePaymentsOrder(WC_Order $wcOrder, array $new): array
    {
        $message = "";
        $isPaid = !is_null($wcOrder->get_date_paid());
        $refunds = $wcOrder->get_refunds();
        $currentRefunds = array_map(fn(OrderRefund $orderRefund): array => [
            'id' => $orderRefund->get_id(),
            'amount' => round((float)$orderRefund->get_total(), 2),
        ], $refunds);
        $currentRefundIds = array_map(static fn(array $refund): int => $refund['id'], $currentRefunds);
        $newRefundIds = array_values(array_filter(array_map(static fn(array $refund): ?int => $refund['id'], $new["refunds"])));

        $refundToRemove = array_values(array_diff($newRefundIds, $currentRefundIds));
        foreach ($currentRefunds as $refundArray) {
            if (in_array($refundArray["id"], $refundToRemove)) {
                /** @var OrderRefund $refund */
                $refund = current(array_filter($refunds, fn(OrderRefund $orderRefund): bool => $orderRefund->get_id() === $refundArray["id"]));
                $refund->delete();
            }
        }
        foreach ($new["refunds"] as $refundArray) {
            if (!is_null($refundArray['id'])) {
                continue;
            }
            $refund = wc_create_refund([
                'amount' => (string)abs($refundArray['amount']),
                'reason' => '',
                'order_id' => $wcOrder->get_id(),
                'line_items' => [],
                'refund_payment' => false,
                'restock_items' => false,
            ]);
            if ($refund instanceof WP_Error) {
                $message .= "<div class='notice notice-error is-dismissible'>
                    <p>" . $refund->get_error_message() . "</p>
                    </div>";
            }
        }
        if ($new["isPaid"] && $new["isPaid"] !== $isPaid) {
            $wcOrder->payment_complete();
        }
        return [wc_get_order($wcOrder->get_id()), $message];
    }

    private function removeDuplicateWcOrderItemTaxToOrder(WC_Order $wcOrder): string
    {
        $message = '';
        $wcOrderItemTaxs = array_values($wcOrder->get_taxes());
        foreach ($wcOrderItemTaxs as $i => $wcOrderItemTax) {
            $hasDuplicate = false;
            for ($y = $i + 1, $yMax = count($wcOrderItemTaxs); $y < $yMax; $y++) {
                if ($wcOrderItemTax->get_label() === $wcOrderItemTaxs[$y]->get_label()) {
                    $hasDuplicate = true;
                    break;
                }
            }
            if ($hasDuplicate) {
                $wcOrderItemTax->delete();
            }
        }
        return $message;
    }

    public function getShippingRateCosts(WC_Cart $wcCart, WC_Shipping_Rate $wcShippingRate): float|null
    {
        $pExpeditions = GraphqlService::getInstance()->getPExpeditions();
        if (!is_array($pExpeditions)) {
            return null;
        }
        $methodId = $wcShippingRate->get_method_id();
        $pExpedition = current(array_filter($pExpeditions, static fn($pExpedition): bool => $pExpedition->slug === $methodId));
        if ($pExpedition === false) {
            return null;
        }
        $customer = $wcCart->get_customer();
        $userMetaWordpress = get_user_meta($customer->get_id(), single: true);
        $userNCatTarif = null;
        $userNCatCompta = null;
        if (isset($userMetaWordpress["_" . Sage::TOKEN . "_nCatTarif"][0])) {
            $userNCatTarif = (int)$userMetaWordpress["_" . Sage::TOKEN . "_nCatTarif"][0];
        }
        if (isset($userMetaWordpress["_" . Sage::TOKEN . "_nCatCompta"][0])) {
            $userNCatCompta = (int)$userMetaWordpress["_" . Sage::TOKEN . "_nCatCompta"][0];
        }
        $price = false;
        if (!is_null($pExpedition->arRefNavigation)) {
            $price = current(array_filter($pExpedition->arRefNavigation->prices, static fn(stdClass $price): bool => $price->nCatTarif->cbIndice === $userNCatTarif && $price->nCatCompta->cbIndice === $userNCatCompta));
        }
        $result = null;
        $woocommerceShowTax = get_option('woocommerce_tax_display_cart') !== "excl"; // excl || incl
        if ($pExpedition->eTypeCalcul === ETypeCalculEnum::Valeur->value) {
            $result = $pExpedition->eValFrais;
        } elseif ($pExpedition->eTypeFrais === DocumentFraisTypeEnum::DocFraisTypeQuantite->value) {
            // grille, in this case (DocFraisTypeForfait && DocFraisTypeColisage) cannot be selected in sage
            $quantity = 0;
            foreach ($wcCart->get_cart_contents() as $cartContent) {
                $quantity += $cartContent["quantity"];
            }
            $result = $this->findFraisExpeditionGrille($pExpedition, $quantity);
        } else {
            $prop = '';
            if ($pExpedition->eTypeFrais === DocumentFraisTypeEnum::DocFraisTypePoidsNet->value) {
                $prop = '_poids_net';
            } elseif ($pExpedition->eTypeFrais === DocumentFraisTypeEnum::DocFraisTypePoidsBrut->value) {
                $prop = '_poids_brut';
            }
            foreach ($wcCart->get_cart_contents() as $cartContent) {
                /** @var WC_Product_Simple $product */
                $product = $cartContent['data'];
                $value = SageService::getInstance()->get_post_meta_single($product->get_id(), '_' . Sage::TOKEN . $prop, true);
                if ($value !== [] && ($value !== '' && $value !== '0')) {
                    $result = $this->findFraisExpeditionGrille($pExpedition, (float)$value);
                }
            }
        }
        $isTtc = (bool)$pExpedition->eTypeLigneFrais;
        if ($price !== false) {
            if ($woocommerceShowTax && !$isTtc) {
                $result = $this->applyTaxes($result, $price, true);
            } elseif (!$woocommerceShowTax && $isTtc) {
                $result = $this->applyTaxes($result, $price, false);
            }
        }
        return $result;
    }

    private function findFraisExpeditionGrille(stdClass $pExpedition, float $borne): float
    {
        $frais = 0;
        $lastBorne = 0;
        foreach ($pExpedition->fExpeditiongrilles as $fExpeditiongrille) {
            if ($fExpeditiongrille->egBorne > $lastBorne && $borne <= $fExpeditiongrille->egBorne) {
                $lastBorne = $fExpeditiongrille->egBorne;
                $frais = $fExpeditiongrille->egFrais;
            }
        }
        return $frais;
    }

    /**
     * Copy paste of applyTaxes of the sage api
     */
    private function applyTaxes(float $value, stdClass $price, bool $addOrRemove): float
    {
        $initPrice = $value;
        foreach ($price->taxes as $taxe) {
            if ($taxe->fTaxe->taNp !== 0) {
                continue;
            }
            if ($taxe->fTaxe->taTtaux === TaxeTauxType::TaxeTauxTypePourcent->value) {
                if ($addOrRemove) {
                    $amount = round(($initPrice * $taxe->fTaxe->taTaux)) / 100;
                } else {
                    $amount = (round($initPrice / (100 + $taxe->fTaxe->taTaux)) * 100) - $initPrice;
                }
            } else {
                $amount = $taxe->fTaxe->taTaux;
                if (!$addOrRemove) {
                    $amount = -$amount;
                }
            }
            $value += $amount;
        }
        return $value;
    }

    public function desynchronizeOrder(WC_Order $wcOrder): WC_Order
    {
        $fDocenteteIdentifier = $this->getFDocenteteIdentifierFromOrder($wcOrder);
        if (!empty($fDocenteteIdentifier)) {
            $wcOrder->add_order_note(__('Le document de vente Sage a été désynchronisé de la commande.', 'egas-data-sync-for-sage') . ' [' . $fDocenteteIdentifier["doPiece"] . ']');
            $wcOrder->delete_meta_data(FDocenteteResource::META_KEY);
            $wcOrder->delete_meta_data('_' . Sage::TOKEN . '_doPiece');
            $wcOrder->delete_meta_data('_' . Sage::TOKEN . '_doType');
            $wcOrder->save();
        }
        return $wcOrder;
    }

    public function custom_price(string|float|int $price, WC_Product $wcProduct, int $userId = 0, ?bool $withTaxes = null): float|string
    {
        $field = 'priceHt';
        if (
            $withTaxes === true ||
            (is_null($withTaxes) && get_option('woocommerce_tax_display_shop') !== 'excl') // excl || incl
        ) {
            $field = 'priceTtc';
        }
        $identifier = $wcProduct->get_id() . '_' . $userId . '_' . $field;
        if (array_key_exists($identifier, $this->prices)) { // performance
            return $this->prices[$identifier];
        }
        $arRef = $wcProduct->get_meta(FArticleResource::META_KEY);
        if (empty($arRef)) {
            $this->prices[$identifier] = $price;
            return $this->prices[$identifier];
        }
        $prices = $this->getPricesProduct($wcProduct);
        if (empty($prices)) {
            return $price;
        }
        $flattenPrices = [];
        foreach ($prices as $price) {
            foreach ($price as $price2) {
                $flattenPrices[] = $price2;
            }
        }
        $maxPrice = $this->getMaxPrice($flattenPrices);
        if ($userId === 0 || is_admin()) {
            $this->prices[$identifier] = $maxPrice->{$field};
            return $this->prices[$identifier];
        }
        $metadata = get_user_meta($userId);
        if (!isset(
            $metadata["_" . Sage::TOKEN . "_nCatTarif"][0],
            $metadata["_" . Sage::TOKEN . "_nCatCompta"][0]
        )) {
            $this->prices[$identifier] = $maxPrice->{$field};
            return $this->prices[$identifier];
        }
        $this->prices[$identifier] = $prices
        [$metadata["_" . Sage::TOKEN . "_nCatTarif"][0]]
        [$metadata["_" . Sage::TOKEN . "_nCatCompta"][0]]->{$field} ?? $maxPrice->{$field};
        return $this->prices[$identifier];
    }

    public function getPricesProduct(WC_Product $wcProduct, bool $flat = false): array
    {
        $r = [];
        $prices = SageService::getInstance()->get_post_meta_single($wcProduct->get_id(), '_' . Sage::TOKEN . '_prices', true);
        if (empty($prices)) {
            return $r;
        }
        $prices = json_decode($prices, false, 512, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        foreach ($prices as $price) {
            // Catégorie comptable (nCatCompta): [Locale, Export, Métropole]
            // Catégorie tarifaire (nCatTarif): [Tarif GC, Tarif Remise, Prix public, Tarif Partenaire]
            if ($flat) {
                $r[] = $price;
            } else {
                $r[$price->nCatTarif->cbIndice][$price->nCatCompta->cbIndice] = $price;
            }
        }
        return $r;
    }

    public function getMaxPrice(array $prices): stdClass|null
    {
        if ($prices === []) {
            return null;
        }
        usort($prices, static fn(StdClass $a, StdClass $b): int => $b->priceTtc <=> $a->priceTtc);
        return $prices[0];
    }
}
