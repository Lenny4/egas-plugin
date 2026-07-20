<?php

declare(strict_types=1);

namespace Egas\resources;

use Egas\enum\Sage\TiersTypeEnum;
use Egas\Sage;
use Egas\services\GraphqlService;
use Egas\services\SageService;
use Egas\utils\SageTranslationUtils;
use stdClass;

class FComptetResource extends Resource
{
    public const ENTITY_NAME = 'fComptets';
    public const TYPE_MODEL = 'FComptet';
    public const DEFAULT_SORT = 'ctNum';
    public const FILTER_TYPE = 'FComptetFilterInput';
    public final const META_KEY = '_' . Sage::TOKEN . '_ctNum';
    private static ?FComptetResource $fComptetResource = null;

    private function __construct()
    {
        parent::__construct();
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
        $this->options = fn(): array => [
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
        $this->metadata = function (?stdClass $obj = null): array {
            $result = [
                ...$this->getMandatoryMetadata(),
            ];
            return SageService::getInstance()->addSelectionSetAsMetadata(GraphqlService::getInstance()->_getFComptetSelectionSet(), $result, $obj);
        };
        $this->bddMetadata = function (?int $userId, bool $clearCache = false): array {
            if (empty($userId)) {
                return [];
            }
            if ($clearCache) {
                clean_user_cache($userId);
            }
            return SageService::getInstance()->get_user_meta_single($userId);
        };
        $this->sageEntity = fn(?string $ctNum): StdClass|null => GraphqlService::getInstance()->getFComptet($ctNum);
        $this->importFromSage = fn(?string $ctNum, stdClass|string|null $fComptet = null, bool $showSuccessMessage = true): array => SageService::getInstance()->importFComptetFromSage($ctNum, $fComptet, $showSuccessMessage);
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
        $this->import = static function (string $identifier) {
            [$response, $responseError, $message, $userId] = SageService::getInstance()->importFComptetFromSage($identifier);
            return $userId;
        };
        $this->selectionSet = fn(): array => GraphqlService::getInstance()->_getFComptetSelectionSet();
    }

    public static function getInstance(): self
    {
        if (self::$fComptetResource === null) {
            self::$fComptetResource = new self();
        }
        return self::$fComptetResource;
    }

    public static function supports(): bool
    {
        return true;
    }
}
