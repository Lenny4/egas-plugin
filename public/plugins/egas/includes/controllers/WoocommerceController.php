<?php

declare(strict_types=1);

namespace Egas\controllers;

use Egas\enum\Sage\DocumentProvenanceTypeEnum;
use Egas\enum\Sage\DomaineTypeEnum;
use Egas\resources\FDocenteteResource;
use Egas\Sage;
use Egas\services\GraphqlService;
use Egas\services\TwigService;
use Egas\services\WoocommerceService;
use Egas\utils\FDocenteteUtils;
use Egas\utils\PCatComptaUtils;
use Egas\utils\SageTranslationUtils;
use Symfony\Component\DomCrawler\Crawler;
use WC_Meta_Box_Order_Data;
use WC_Order;
use WP_Post;

class WoocommerceController
{
    public static function addColumn(array $columns): array
    {
        $columns[Sage::TOKEN] = __('Sage', 'egas');
        return $columns;
    }

    public static function displayColumn(string $column_name, WC_Order $wcOrder): string
    {
        $trans = SageTranslationUtils::getTranslations();
        if (Sage::TOKEN !== $column_name) {
            return '';
        }
        $identifier = WoocommerceService::getInstance()->getFDocenteteIdentifierFromOrder($wcOrder);
        if (empty($identifier)) {
            return '<span class="dashicons dashicons-no" style="color: red"></span>';
        }
        return $trans["fDocentetes"]["doType"]["values"][$identifier['doType']]
            . ': n° '
            . $identifier["doPiece"];
    }

    public static function getMetaboxFDocentete(WC_Order $wcOrder, string $message = ''): string
    {
        $woocommerceService = WoocommerceService::getInstance();
        $graphqlService = GraphqlService::getInstance();
        $fDocenteteIdentifier = $woocommerceService->getFDocenteteIdentifierFromOrder($wcOrder);
        $hasFDocentete = !is_null($fDocenteteIdentifier);
        $extendedFDocentetes = null;
        $tasksSynchronizeOrder = [];
        if ($hasFDocentete) {
            $extendedFDocentetes = $graphqlService->getFDocentetes(
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
                addWordpressUserId: true,
                getLotSerie: true,
                extended: true,
                getFDocregls: true,
            );
            if (is_string($extendedFDocentetes)) {
                $message .= $extendedFDocentetes;
            }
            // pour l'instant on appelle manuellement applyTasksSynchronizeOrder
            // $sageService = SageService::getInstance();
            // $sageService->importFromSageIfUpdateApi($sageService->getResource(FDocenteteResource::ENTITY_NAME), $order->get_id());
            $tasksSynchronizeOrder = $woocommerceService->getTasksSynchronizeOrder($wcOrder, $extendedFDocentetes);
            if (filter_var(get_option(Sage::TOKEN . '_website_update_' . FDocenteteResource::ENTITY_NAME, false), FILTER_VALIDATE_BOOLEAN)) {
                [$var1, $var2, $message2, $rOrder] = $woocommerceService->applyTasksSynchronizeOrder($wcOrder, $tasksSynchronizeOrder);
                $message .= $message2;
                $tasksSynchronizeOrder = $woocommerceService->getTasksSynchronizeOrder($wcOrder, $extendedFDocentetes);
            }
        }
        // original WC_Meta_Box_Order_Data::output
        return TwigService::getInstance()->render('woocommerce/metaBoxes/main.html.twig', [
            'message' => $message,
            'doPieceIdentifier' => $fDocenteteIdentifier ? $fDocenteteIdentifier["doPiece"] : null,
            'doTypeIdentifier' => $fDocenteteIdentifier ? $fDocenteteIdentifier["doType"] : null,
            'order' => $wcOrder,
            'hasFDocentete' => $hasFDocentete,
            'extendedFDocentetes' => $extendedFDocentetes,
            'currency' => get_woocommerce_currency(),
            'fdocligneMappingDoType' => FDocenteteUtils::FDOCLIGNE_MAPPING_DO_TYPE,
            'tasksSynchronizeOrder' => $tasksSynchronizeOrder,
            'pCattarifs' => $graphqlService->getPCattarifs(),
            'pCatComptas' => $graphqlService->getPCatComptas()[PCatComptaUtils::TIERS_TYPE_VEN],
        ]);
    }

    public static function getMetaBoxOrderItems(WC_Order $wcOrder): string
    {
        ob_start();
        include __DIR__ . '/../../woocommerce/includes/admin/meta-boxes/views/html-order-items.php';
        return ob_get_clean();
    }

    public static function showMetaBoxProduct(array $wp_meta_boxes, string $screen): void
    {
        $id = 'woocommerce-product-data';
        $context = 'normal';
        remove_meta_box($id, $screen, $context);

        $callback = $wp_meta_boxes[$screen][$context]["high"][$id]["callback"];
        add_meta_box($id, __('Données produit', 'egas'), static function (WP_Post $wpPost) use ($callback): void {
            ob_start();
            $callback($wpPost);
            $html = ob_get_clean();
            if (str_contains($wpPost->post_status, 'auto-draft')) {
                $html = str_replace(
                    ["selected='selected'", "option value=" . Sage::TOKEN],
                    ['', "option value=" . Sage::TOKEN . " selected='selected'"],
                    $html
                );
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $html;
        }, $screen, $context, 'high');
    }

    /**
     * woocommerce/src/Internal/Admin/Orders/Edit.php:78 add_meta_box('woocommerce-order-data'
     */
    public static function showMetaBoxOrder(array $wp_meta_boxes, string $screen): void
    {
        $id = 'woocommerce-order-data';
        $context = 'normal';
        remove_meta_box($id, $screen, $context);

        $callback = $wp_meta_boxes[$screen][$context]["high"][$id]["callback"];
        add_meta_box($id,
            sprintf(
            /* translators: %s: object name (e.g. "Commande"). */
                __('%s data', 'egas'),
                __('Commande', 'egas')
            ), static function (WC_Order $wcOrder) use ($callback): void {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo WoocommerceController::getMetaBoxOrder($wcOrder, $callback);
            }, $screen, $context, 'high');
    }

    public static function getMetaBoxOrder(WC_Order $wcOrder, ?callable $callback = null): string
    {
        ob_start();
        if (is_null($callback)) {
            WC_Meta_Box_Order_Data::output($wcOrder);
        } else {
            $callback($wcOrder);
        }
        $html = ob_get_clean();
        $crawler = new Crawler($html);
        $dom = $crawler->getNode(0)->ownerDocument;
        $fDocenteteIdentifier = WoocommerceService::getInstance()->getFDocenteteIdentifierFromOrder($wcOrder);
        $translations = SageTranslationUtils::getTranslations();
        if (!empty($fDocenteteIdentifier)) {
            // region add Sage document info in the header
            $heading = $crawler->filter('.woocommerce-order-data__heading')->first();
            $headingNode = $heading->getNode(0);
            $newTitle =
                trim($heading->text()) .
                ' [' .
                $translations['fDocentetes']['doType']['values'][$fDocenteteIdentifier['doType']] .
                ': n° ' .
                $fDocenteteIdentifier['doPiece'] .
                ']';
            $headingNode->nodeValue = $newTitle;
            // endregion

            // region disable change customer when fDocentete linked
            $select = $crawler->filter('select#customer_user')->first();
            $selectNode = $select->getNode(0);
            // Remove select2-hidden-accessible
            $classes = explode(' ', $selectNode->getAttribute('class'));
            $classes = array_filter($classes, fn($c): bool => $c !== 'wc-customer-search');
            $selectNode->setAttribute('class', implode(' ', $classes));
            // Hide the select
            $selectNode->setAttribute('style', 'display:none;');
            // Get selected option
            $selectedOption = $select->filter('option[selected]')->count() !== 0
                ? $select->filter('option[selected]')
                : $select->filter('option')->first();
            $selectedOptionText = trim($selectedOption->text());
            $selectedOptionText = html_entity_decode($selectedOptionText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $userId = $selectedOption->attr('value');
            // Create a link to the user profile
            $dom = $selectNode->ownerDocument;
            $link = $dom->createElement('a', $selectedOptionText);
            $link->setAttribute('href', admin_url('user-edit.php?user_id=' . $userId));
            $link->setAttribute('class', 'customer-user-link');
            // Insert link after select
            $selectNode->parentNode->insertBefore($link, $selectNode->nextSibling);
            // endregion
            return $dom->saveHTML();
        }
        return $html;
    }
}
