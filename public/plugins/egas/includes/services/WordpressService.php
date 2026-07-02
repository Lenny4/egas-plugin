<?php

declare(strict_types=1);

namespace Egas\services;

use Automattic\WooCommerce\Utilities\OrderUtil;
use DateTime;
use Egas\class\SageEntityMetadata;
use Egas\controllers\AdminController;
use Egas\resources\FArticleResource;
use Egas\resources\FComptetResource;
use Egas\Sage;
use Egas\utils\PathUtils;
use Egas\utils\TaxeUtils;
use stdClass;
use Symfony\Component\Filesystem\Filesystem;
use WC_Shipping_Zone;
use WC_Shipping_Zones;
use WP_Application_Passwords;

class WordpressService
{
    private static ?WordpressService $wordpressService = null;

    public function install(): void
    {
        $sage = Sage::getInstance();
        $cacheService = CacheService::getInstance();
        $plugin_data = get_plugin_data($sage->file);
        $version = $plugin_data['Version'];
        update_option(Sage::TOKEN . '_version', $version);
        // region delete FilesystemAdapter cache
        $cacheService->clear();
        // endregion
        // region delete twig cache
        $dir = str_replace(Sage::TOKEN . '.php', 'templates/cache', $sage->file);
        if (is_dir($dir)) {
            $filesystem = new Filesystem();
            $filesystem->remove([$dir]);
        }
        // endregion
        $this->applyDefaultResourceOptions();
        $this->addWebsiteSageApi(true);
        if (!term_exists(Sage::TOKEN, 'product_type')) {
            wp_insert_term(Sage::TOKEN, 'product_type', ['slug' => Sage::TOKEN]);
        }

        // $this->init() is called during activation and add_action init because sometimes add_action init could fail when plugin is installed
        $this->init();
        flush_rewrite_rules();
    }

    public static function getInstance(): self
    {
        if (self::$wordpressService === null) {
            self::$wordpressService = new self();
        }
        return self::$wordpressService;
    }

    /**
     * We specifically set the default value in bdd in case between an upgrade we change the default value.
     * This way the user we keep the previous value if he never changed it.
     */
    private function applyDefaultResourceOptions(bool $force = false): void
    {
        $optionNames = [];
        foreach (SageService::getInstance()->getResources() as $resource) {
            foreach ($resource->getOptions()() as $option) {
                $optionNames[Sage::TOKEN . '_' . $option['id']] = $option['default'];
            }
        }
        $options = get_options(array_keys($optionNames));
        foreach ($options as $option => $value) {
            if ($force || $value === false) {
                update_option($option, $optionNames[$option]);
            }
        }
    }

    public function addWebsiteSageApi(bool $force = false): bool|string
    {
        // woocommerce/includes/admin/class-wc-admin-meta-boxes.php:134 add_meta_box( 'woocommerce-product-data
        if (
            !$force && (
            !($this->isOptionFormSubmitted() && current_user_can('manage_options'))
            )
        ) {
            return false;
        }
        $userId = get_current_user_id();
        $password = $this->getCreateApplicationPassword($userId, $force);
        return $this->createUpdateWebsite($userId, $password);
    }

    public function isOptionFormSubmitted(): bool
    {
        return
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            array_key_exists('settings-updated', $_GET) &&
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            array_key_exists('page', $_GET) &&
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $_GET["settings-updated"] === 'true' &&
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $_GET["page"] === Sage::TOKEN . '_settings';
    }

    /**
     * https://developer.wordpress.org/rest-api/reference/application-passwords/#create-a-application-password
     */
    private function getCreateApplicationPassword(string|int $userId, bool $force = false): string
    {
        $optionName = Sage::TOKEN . '_application-passwords';
        $password = get_option($optionName, null);
        $passwords = WP_Application_Passwords::get_user_application_passwords($userId);
        $currentPassword = current(array_filter($passwords, static fn(array $password): bool => $password['name'] === $optionName));
        if ($force || empty($password) || $currentPassword === false) {
            if ($currentPassword !== false) {
                foreach ($passwords as $password) {
                    if ($password['name'] === $optionName) {
                        WP_Application_Passwords::delete_application_password($userId, $password['uuid']);
                    }
                }
            }
            $newApplicationPassword = WP_Application_Passwords::create_new_application_password($userId, [
                'name' => $optionName
            ]);
            $password = $newApplicationPassword[0];
            update_option($optionName, $password);
        }
        return $password;
    }

    private function createUpdateWebsite(string|int $userId, string $password): bool|string
    {
        $graphqlService = GraphqlService::getInstance();
        $user = get_user_by('id', $userId);
        $stdClass = $graphqlService->createUpdateWebsite(
            username: $user->data->user_login,
            password: $password,
            getError: true,
        );
        if (is_string($stdClass)) {
            return $stdClass;
        }
        if (is_null($stdClass)) {
            return false;
        }
        update_option(Sage::TOKEN . '_authorization', $stdClass->data->createUpdateWebsite->authorization);
        update_option(Sage::TOKEN . '_website_id', $stdClass->data->createUpdateWebsite->id);

        $graphqlService->updateAllSageEntitiesInOption(ignores: ['getFTaxes']);
        $this->updateTaxes(showMessage: false);
        $this->updateShippingMethodsWithSage();
        AdminController::adminNotices("
<div class='notice notice-success is-dismissible'>
    <p>" . __('Connexion réussie à l\'API. Les paramètres ont été mis à jour.', 'egas') . "</p>
</div>
");
        return true;
    }

    public function updateTaxes(bool $showMessage = true): void
    {
        $woocommerceService = WoocommerceService::getInstance();
        [$taxe, $rates] = $woocommerceService->getWordpressTaxes();
        $fTaxes = GraphqlService::getInstance()->getFTaxes(useCache: false, getFromSage: true);
        if (!AdminController::showErrors($fTaxes)) {
            $taxeChanges = $this->getTaxesChanges($fTaxes, $rates);
            $woocommerceService->applyTaxesChanges($taxeChanges);
            if ($showMessage && $taxeChanges !== []) {
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php echo esc_html__("Les taxes Sage ont été mises à jour.", 'egas') ?></strong></p>
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

    private function updateShippingMethodsWithSage(): void
    {
        $graphqlService = GraphqlService::getInstance();
        // woocommerce/includes/class-wc-ajax.php : shipping_zone_add_method
        $pExpeditions = $graphqlService->getPExpeditions();
        $newSlugs = array_map(static fn(stdClass $pExpedition) => $pExpedition->slug, $pExpeditions);
        $zones = WC_Shipping_Zones::get_zones();
        $zoneIds = [0, ...array_map(static fn(array $zone) => $zone['id'], $zones)];
        foreach ($zoneIds as $zoneId) {
            $zone = new WC_Shipping_Zone($zoneId);
            $oldSlugs = [];
            foreach ($zone->get_shipping_methods() as $shippingMethod) {
                if (!str_starts_with($shippingMethod->id, Sage::TOKEN . '-')) {
                    continue;
                }
                $oldSlugs[] = $shippingMethod->id;
                if (!in_array($shippingMethod->id, $newSlugs, true)) {
                    $zone->delete_shipping_method($shippingMethod->get_instance_id());
                }
            }
            foreach ($pExpeditions as $pExpedition) {
                if (!in_array($pExpedition->slug, $oldSlugs, true)) {
                    $zone->add_shipping_method($pExpedition->slug);
                }
            }
        }
        update_option(Sage::TOKEN . '_shipping_methods_updated', new DateTime());
    }

    public function init(): void
    {
        // Handle localisation.
        $this->load_plugin_textdomain();
    }

    private function load_plugin_textdomain(): void
    {
        $domain = Sage::TOKEN;
        $locale = apply_filters('egas_plugin_locale', get_locale(), $domain);
        load_textdomain($domain, WP_LANG_DIR . '/' . $domain . '/' . $domain . '-' . $locale . '.mo');
    }

    public function removeUpdateApi(): void
    {
        // todo tester si ça marche
        global $wpdb;
        foreach (SageService::getInstance()->getResources() as $resource) {
            $fields = [];
            if (!filter_var(get_option(Sage::TOKEN . '_sage_update_' . $resource->getEntityName(), false), FILTER_VALIDATE_BOOLEAN)) {
                $fields[] = 'updateApi';
            }
            foreach ($fields as $field) {
                $args = ['_' . Sage::TOKEN . '_' . $field];
                $where = "";
                $join = "";
                if (!is_null($postType = $resource->getPostType())) {
                    $args[] = $postType;
                    $where .= sprintf('AND %s.post_type = %%s', $resource->getTable());
                    $join .= "INNER JOIN {$resource->getTable()}
                        ON {$resource->getTable()}.ID = {$resource->getMetaTable()}.{$resource->getMetaColumnIdentifier()}";
                }
                $sql = "
                    DELETE {$resource->getMetaTable()}
                    FROM {$resource->getMetaTable()}
                    {$join}
                    WHERE {$resource->getMetaTable()}.meta_key = %s {$where}
";
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query($wpdb->prepare($sql, ...$args));
            }
        }
    }

    public function onSavePost(int $postId): void
    {
        if ($postId === 0) {
            return;
        }
        if (
            !empty($_POST) &&
            (
                !isset($_POST['_wpnonce']) ||
                !wp_verify_nonce(
                    sanitize_text_field(wp_unslash($_POST['_wpnonce'])),
                    'update-post_' . $postId
                )
            )
        ) {
            return;
        }
        $arRef = null;
        $flatPost = PathUtils::flatternPostSageData($_POST);
        if (array_key_exists(FArticleResource::META_KEY, $flatPost)) {
            $arRef = $flatPost[FArticleResource::META_KEY];
        }
        if (!empty($arRef)) {
            $resource = SageService::getInstance()->getResource(FArticleResource::ENTITY_NAME);
            $metadataToKeep = [
                FArticleResource::META_KEY,
                ...array_map(fn(SageEntityMetadata $sageEntityMetadata): string => '_' . Sage::TOKEN . $sageEntityMetadata->getField(), $resource->getMetadata()()),
            ];
            $meta = get_post_meta($postId);
            foreach ($meta as $key => $values) {
                if (
                    !array_key_exists($key, $flatPost) &&
                    str_starts_with($key, '_' . Sage::TOKEN) &&
                    !in_array($key, $metadataToKeep)
                ) {
                    delete_post_meta($postId, $key);
                }
            }
            foreach ($flatPost as $key => $value) {
                if (str_starts_with($key, '_' . Sage::TOKEN)) {
                    update_post_meta($postId, $key, $value);
                }
            }
            if (filter_var(get_option(Sage::TOKEN . '_sage_update_' . FArticleResource::ENTITY_NAME, false), FILTER_VALIDATE_BOOLEAN)) {
                update_post_meta($postId, '_' . Sage::TOKEN . '_updateApi', (new DateTime())->format('Y-m-d H:i:s'));
            }
        }
    }

    public function get_order_screen_id(): string
    {
        // copy of register_order_origin_column in woocommerce/src/Internal/Orders/OrderAttributionController.php
        return OrderUtil::custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id('shop-order') : 'shop_order';
    }

    public function saveCustomerUserMetaFields(int $userId, bool $isNew): void
    {
        $post = wp_unslash($_POST);
        $create_nonce = $post['_wpnonce_create-user'] ?? '';
        $update_nonce = $post['_wpnonce'] ?? '';
        if (
            !wp_verify_nonce($create_nonce, 'create-user') &&
            !wp_verify_nonce($update_nonce, 'update-user_' . $userId)
        ) {
            return;
        }
        $nbUpdatedMeta = 0;
        $oldCreationType = get_user_meta($userId, '_' . Sage::TOKEN . '_creationType', true);
        if (empty($oldCreationType) || $oldCreationType === 'none') {
            $isNew = true;
        }
        $sageUpdateFcomptet = null;
        if (array_key_exists('_' . Sage::TOKEN . '_creationType', $post)) {
            $sageUpdateFcomptet = $post['_' . Sage::TOKEN . '_creationType'] !== 'none';
            if ($sageUpdateFcomptet) {
                $metaKey = FComptetResource::META_KEY;
                $fComptet = GraphqlService::getInstance()->getFComptet($post[$metaKey] ?? null);
                if ($post['_' . Sage::TOKEN . '_creationType'] === 'link') {
                    if (
                        !array_key_exists($metaKey, $post) ||
                        !($fComptet instanceof stdClass)
                    ) {
                        return;
                    }
                    $users = get_users([
                        'meta_key' => $metaKey,
                        'meta_value' => strtoupper((string)$post[$metaKey])
                    ]);
                    if (!empty($users) && $users[0]->ID !== $userId) {
                        return;
                    }
                }
                if ($post['_' . Sage::TOKEN . '_creationType'] === 'new' && $fComptet instanceof stdClass) {
                    return;
                }
            }
        }
        foreach ($post as $key => $value) {
            if (str_starts_with($key, '_' . Sage::TOKEN)) {
                $value = trim((string)preg_replace('/\s{2,}/', ' ', (string)$value));
                if ($key === FComptetResource::META_KEY) {
                    $value = strtoupper($value);
                }
                update_user_meta($userId, $key, $value);
                $nbUpdatedMeta++;
            }
        }
        if (!$isNew && $nbUpdatedMeta > 0) {
            if (is_null($sageUpdateFcomptet)) {
                $sageUpdateFcomptet = filter_var(get_option(Sage::TOKEN . '_sage_update_' . FComptetResource::ENTITY_NAME, false), FILTER_VALIDATE_BOOLEAN);
            }
            if ($sageUpdateFcomptet) {
                update_user_meta($userId, '_' . Sage::TOKEN . '_updateApi', (new DateTime())->format('Y-m-d H:i:s'));
            }
        }
    }

    public function getUserIdWithCtNum(string $ctNum): int|null
    {
        global $wpdb;
        $r = $wpdb->get_results(
            $wpdb->prepare("
SELECT user_id
FROM {$wpdb->usermeta}
WHERE meta_key = %s
  AND meta_value = %s
", [FComptetResource::META_KEY, $ctNum]));
        if (!empty($r)) {
            return (int)$r[0]->user_id;
        }
        return null;
    }

    public function getUserWordpressIdForSage(int $userId)
    {
        return get_user_meta($userId, FComptetResource::META_KEY, true);
    }

    public function getValidWordpressMail(?string $value): string|null
    {
        if (is_null($value)) {
            return null;
        }
        if (empty($value = trim($value))) {
            return null;
        }
        $emails = explode(';', $value);
        if (!filter_var(($email = trim($emails[0])), FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return $email;
    }

    /**
     * Exemple: si on créer un nouvel article et que y'a un article dans la poubelle avec le même arRef ça va enlever
     * la meta key arRef
     */
    public function deleteMetaTrashResource(string $key, string $value): void
    {
        global $wpdb;
        $like = '_' . Sage::TOKEN . '_%';
        $sql = "
        DELETE pm
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p
            ON p.ID = pm.post_id
            AND p.post_status = 'trash'
        INNER JOIN {$wpdb->postmeta} pm_filter
            ON pm_filter.post_id = pm.post_id
            AND pm_filter.meta_key = %s
            AND pm_filter.meta_value = %s
        WHERE pm.meta_key LIKE %s
    ";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare($sql, $key, $value, $like));
    }
}
