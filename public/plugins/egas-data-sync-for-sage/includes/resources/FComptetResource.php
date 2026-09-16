<?php

declare(strict_types=1);

namespace Egas\resources;

use Egas\class\dto\ArgumentSelectionSetDto;
use Egas\enum\Sage\TiersTypeEnum;
use Egas\Sage;
use Egas\services\GraphqlService;
use Egas\services\SageService;
use Egas\services\WoocommerceService;
use Egas\services\WordpressService;
use Egas\utils\SageTranslationUtils;
use stdClass;
use WP_Error;
use WP_User;

class FComptetResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'fComptets';
    public const TYPE_MODEL = 'FComptet';
    public const DEFAULT_SORT = 'ctNum';
    public const FILTER_TYPE = 'FComptetFilterInput';
    public final const META_KEY = '_' . Sage::TOKEN . '_ctNum';

    private function __construct()
    {
        global $wpdb;
        $this->title = __("Clients", 'egas-data-sync-for-sage');
        $this->description = __("Gestion des clients.", 'egas-data-sync-for-sage');
        $this->entityName = self::ENTITY_NAME;
        $this->typeModel = self::TYPE_MODEL;
        $this->defaultSortField = self::DEFAULT_SORT;
        $this->defaultFields = [
            'ctNum',
            'ctIntitule',
            'ctContact',
            'ctEmail',
            Sage::META_DATA_PREFIX . '_last_update',
            Sage::META_DATA_PREFIX . '_postId',
        ];
        $this->mandatoryFields = [
            'ctNum',
        ];
        $this->filterType = self::FILTER_TYPE;
        $this->transDomain = SageTranslationUtils::TRANS_FCOMPTETS;
        $this->metaKeyIdentifier = self::META_KEY;
        $this->table = $wpdb->users;
        $this->metaTable = $wpdb->usermeta;
        $this->metaColumnIdentifier = 'user_id';
        $this->postType = null;
        $this->importCondition = [
            new ImportConditionDto(
                field: 'ctType',
                value: TiersTypeEnum::TiersTypeClient->value,
                condition: 'eq',
                message: fn(array $fComptet): string => __("Le compte n'est pas un compte client.", 'egas-data-sync-for-sage') . ' [' . $fComptet["ctNum"] . ']'),
        ];
    }

    public static function supports(): bool
    {
        return true;
    }

    public function options(): array
    {
        return [
            [
                'id' => 'sage_create_new_' . self::ENTITY_NAME,
                'label' => __("Créer le compte dans Sage.", 'egas-data-sync-for-sage'),
                'description' => __("Créer le compte dans Sage lorsqu'un nouveau utilisateur Wordpress est crée.", 'egas-data-sync-for-sage'),
                'type' => 'checkbox',
                'default' => 'off',
            ],
            [
                'id' => 'sage_create_old_' . self::ENTITY_NAME,
                'label' => __("Importe les anciens utilisateurs.", 'egas-data-sync-for-sage'),
                'description' => __("Importe les anciens utilisateurs Woocommerce dans Sage.", 'egas-data-sync-for-sage'),
                'type' => 'checkbox',
                'default' => 'off',
            ],
            [
                'id' => 'sage_update_' . self::ENTITY_NAME,
                'label' => __("Met à jour le compte Sage.", 'egas-data-sync-for-sage'),
                'description' => __("Met à jour le compte Sage lorsque l'utilisateur WooCommerce qui lui est lié est modifié.", 'egas-data-sync-for-sage'),
                'type' => 'checkbox',
                'default' => 'off',
            ],
            [
                'id' => 'website_create_new_' . self::ENTITY_NAME,
                'label' => __("Créer l'utilisateur dans Woocommerce.", 'egas-data-sync-for-sage'),
                'description' => __("Créer l'utilisateur dans Woocommerce lorsqu'un nouveau compte Sage est crée.", 'egas-data-sync-for-sage'),
                'type' => 'resource',
                'default' => '',
            ],
            [
                'id' => 'website_create_old_' . self::ENTITY_NAME,
                'label' => __("Importe les anciens comptes Sage.", 'egas-data-sync-for-sage'),
                'description' => __("Importe les anciens comptes Sage dans Woocommerce.", 'egas-data-sync-for-sage'),
                'type' => 'resource',
                'default' => '',
            ],
            [
                'id' => 'website_update_' . self::ENTITY_NAME,
                'label' => __("Met à jour l'utilisateur Woocommerce.", 'egas-data-sync-for-sage'),
                'description' => __("Met à jour l'utilisateur Woocommerce lorsque le compte Sage qui lui est lié est modifié.", 'egas-data-sync-for-sage'),
                'type' => 'resource',
                'default' => '',
            ],
            [
                'id' => 'mail_website_create_new_' . self::ENTITY_NAME,
                'label' => __('Envoyer automatiquement le mail pour définir le mot de passe', 'egas-data-sync-for-sage'),
                'description' => __("Lorsqu'un compte Wordpress est créé à partir d'un compte Sage, un mail pour définir le mot de passe du compte Wordpress est automatiquement envoyé à l'utilisateur.", 'egas-data-sync-for-sage'),
                'type' => 'checkbox',
                'default' => 'off',
            ],
        ];
    }

    public function metadata(?stdClass $sageEntity = null): array
    {
        $result = [
            ...$this->getMandatoryMetadata(),
        ];
        return SageService::getInstance()->addSelectionSetAsMetadata($this->selectionSet(), $result, $sageEntity);
    }

    public function bddMetadata(?int $id, bool $clearCache = false): array
    {
        if (empty($id)) {
            return [];
        }
        if ($clearCache) {
            clean_user_cache($id);
        }
        return SageService::getInstance()->get_user_meta_single($id);
    }

    public function sageEntity(?string $identifier): ?stdClass
    {
        return GraphqlService::getInstance()->getFComptet($identifier);
    }

    public function selectionSet(array $options = []): array
    {
        return [
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'ctNum',
                'ctIntitule',
                'ctEmail',
                'ctContact',
                'ctAdresse',
                'ctComplement',
                'ctVille',
                'ctCodePostal',
                'ctPays',
                'ctPaysCode',
                'ctTelephone',
                'ctCodeRegion',
                'nCatTarif',
                'nCatCompta',
            ]),
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'ctType',
            ]),
            'fLivraisons' => new ArgumentSelectionSetDto($this->livraisonSelectionSet(), 'liNo'),
        ];
    }

    /**
     * Shared "livraison" fragment, also nested inside FDocentete's selection set.
     */
    public function livraisonSelectionSet(): array
    {
        return [
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'liNo',
            ]),
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'liIntitule',
                'liAdresse',
                'liComplement',
                'liCodePostal',
                'liPrincipal',
                'liVille',
                'liPays',
                'liPaysCode',
                'liContact',
                'liTelephone',
                'liEmail',
                'liAdresseFact',
                'liCodeRegion',
            ]),
        ];
    }

    /**
     * If fComptet is more up to date than user -> update user in wordpress
     * If user is more up to date than fComptet -> update fComptet in sage
     */
    public function import(?string $identifier, ?stdClass $resource = null): ImportResourceResult
    {
        if (is_null($identifier)) {
            return ImportResourceResult::failure(
                "<div class='error'>" . __("Vous devez spécifier le numéro de compte Sage", 'egas-data-sync-for-sage') . "</div>"
            );
        }
        $fComptet = $resource ?? $this->sageEntity($identifier);
        if (is_null($fComptet)) {
            return ImportResourceResult::failure(
                "<div class='error'>" . __("Le compte Sage n'a pas pu être importé", 'egas-data-sync-for-sage') . "</div>"
            );
        }
        $canImportFComptet = $this->canImport($fComptet);
        if (!empty($canImportFComptet)) {
            return ImportResourceResult::failure(
                "<div class='error'>" . implode(' ', $canImportFComptet) . "</div>"
            );
        }
        $ctNum = $fComptet->ctNum;
        $userId = WordpressService::getInstance()->getUserIdWithCtNum($ctNum);
        [$userId, $wpUser, $metadata] = WoocommerceService::getInstance()->convertFComptetToUser(
            $fComptet,
            $userId,
        );
        if (is_string($wpUser)) {
            return ImportResourceResult::failure($wpUser);
        }
        $newUser = is_null($userId);
        if ($newUser) {
            $userId = wp_create_user($wpUser->user_login, $wpUser->user_pass, $wpUser->user_email);
        }
        if ($userId instanceof WP_Error) {
            return ImportResourceResult::failure(
                "<div class='notice notice-error is-dismissible'>
                    <pre>" . $userId->get_error_code() . "</pre>
                    <pre>" . $userId->get_error_message() . "</pre>
                    </div>",
                $userId
            );
        }
        $wpUser = new WP_User($userId);
        $wpUser->user_email = SageService::getInstance()->getEmailFromFComptet($fComptet);
        wp_update_user($wpUser);
        foreach ($metadata as $key => $value) {
            update_user_meta($userId, $key, $value);
        }
        $url = "<strong><span style='display: block; clear: both;'><a href='" . get_admin_url() . "user-edit.php?user_id=" . $userId . "'>" . __("Voir l'utilisateur", 'egas-data-sync-for-sage') . "</a></span></strong>";
        $message = $newUser
            ? "<div class='notice notice-success is-dismissible'>" . __('L\'utilisateur a été créé', 'egas-data-sync-for-sage') . $url . "</div>"
            : "<div class='notice notice-success is-dismissible'>" . __('L\'utilisateur a été modifié', 'egas-data-sync-for-sage') . $url . "</div>";
        return ImportResourceResult::success($userId, $message);
    }
}
