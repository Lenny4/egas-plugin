<?php

namespace App\resources;

use App\enum\Sage\TiersTypeEnum;
use App\Sage;
use App\services\GraphqlService;
use App\services\SageService;
use App\utils\SageTranslationUtils;
use stdClass;

class FComptetResource extends Resource
{
    public const ENTITY_NAME = 'fComptets';
    public const TYPE_MODEL = 'FComptet';
    public const DEFAULT_SORT = 'ctNum';
    public const FILTER_TYPE = 'FComptetFilterInput';
    public final const META_KEY = '_' . Sage::TOKEN . '_ctNum';
    private static ?FComptetResource $instance = null;

    private function __construct()
    {
        parent::__construct();
        global $wpdb;
        $this->title = __("Clients", 'egas');
        $this->description = __("Gestion des clients.", 'egas');
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
                'label' => __("Créer le compte dans Sage.", 'egas'),
                'description' => __("Créer le compte dans Sage lorsqu'un nouveau utilisateur Wordpress est crée.", 'egas'),
                'type' => 'checkbox',
                'default' => 'off',
            ],
            [
                'id' => 'sage_create_old_' . self::ENTITY_NAME,
                'label' => __("Importe les anciens utilisateurs.", 'egas'),
                'description' => __("Importe les anciens utilisateurs Woocommerce dans Sage.", 'egas'),
                'type' => 'checkbox',
                'default' => 'off',
            ],
            [
                'id' => 'sage_update_' . self::ENTITY_NAME,
                'label' => __("Met à jour le compte Sage.", 'egas'),
                'description' => __("Met à jour le compte Sage lorsque l'utilisateur WooCommerce qui lui est lié est modifié.", 'egas'),
                'type' => 'checkbox',
                'default' => 'off',
            ],
            [
                'id' => 'website_create_new_' . self::ENTITY_NAME,
                'label' => __("Créer l'utilisateur dans Woocommerce.", 'egas'),
                'description' => __("Créer l'utilisateur dans Woocommerce lorsqu'un nouveau compte Sage est crée.", 'egas'),
                'type' => 'resource',
                'default' => '',
            ],
            [
                'id' => 'website_create_old_' . self::ENTITY_NAME,
                'label' => __("Importe les anciens comptes Sage.", 'egas'),
                'description' => __("Importe les anciens comptes Sage dans Woocommerce.", 'egas'),
                'type' => 'resource',
                'default' => '',
            ],
            [
                'id' => 'website_update_' . self::ENTITY_NAME,
                'label' => __("Met à jour l'utilisateur Woocommerce.", 'egas'),
                'description' => __("Met à jour l'utilisateur Woocommerce lorsque le compte Sage qui lui est lié est modifié.", 'egas'),
                'type' => 'checkbox',
                'default' => 'off',
            ],
            [
                'id' => 'mail_website_create_new_' . self::ENTITY_NAME,
                'label' => __('Envoyer automatiquement le mail pour définir le mot de passe', 'fComptets'),
                'description' => __("Lorsqu'un compte Wordpress est créé à partir d'un compte Sage, un mail pour définir le mot de passe du compte Wordpress est automatiquement envoyé à l'utilisateur.", 'egas'),
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
        $this->importFromSage = fn(?string $ctNum, stdClass|string|null $fComptet = null, $showSuccessMessage = true): array|string => SageService::getInstance()->importFComptetFromSage($ctNum, $fComptet, $showSuccessMessage);
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
                message: fn(array $fComptet): string => __("Le compte n'est pas un compte client.", 'egas') . ' [' . $fComptet["ctNum"] . ']'),
        ];
        $this->import = static function (string $identifier) {
            [$response, $responseError, $message, $userId] = SageService::getInstance()->importFComptetFromSage($identifier);
            return $userId;
        };
        $this->selectionSet = fn(): array => GraphqlService::getInstance()->_getFComptetSelectionSet();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function supports(): bool
    {
        return true;
    }
}
