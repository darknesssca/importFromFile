<?php

use Bitrix\Iblock\PropertyIndex\Manager;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use InformUnity\Entity\ColorSetsTable;
use InformUnity\Entity\RemainRostovkiTable;
use InformUnity\Entity\ShoeSizesTable;
use InformUnity\Factory\Import;

class ImportFreeStockProducts extends CBitrixComponent
{
    private $data = [];

    /** @var array Список свойств для формирования уникального кода товара или ТП */
    private $propsForProductCode = [
        'PROIZVODITEL',
        'NOMERKOLODKI',
        'NOMERMODELI',
        'MATERIALVERHA',
        'SEZON',
        'MATERIALPODKLADKI',
        'VYSOTAKABLUKA',
        'MATERIALPODOSHVY',
        'VIDIZDELIA',
        'RODIZDELIA',
        'STRANA',
    ];

    /** @var array Список свойств для формирования уникального кода товара или ТП из файла выгрузки */
    private $propsForProductCodeFromFile = [
        'Proizvoditel',
        'NomerKolodki',
        'NomerModeli',
        'MaterialVerha',
        'Sezon',
        'MaterialPodkladki',
        'VysotaKabluka',
        'MaterialPodoshvy',
        'VidIzdelia',
        'RodIzdelia',
        'Strana',
    ];

    private $offer_id;
    private $product_id;

    private $offers_termination;
    private $product_termination;


    /** @var $logger Logger */
    private $logger;

    /** @var $user $USER */
    private $user;

    /** @var $element CIBlockElement */
    private $element;

    /** @var $section CIBlockSection */
    private $section;

    /** @var $productPropertyEnum CIBlockPropertyEnum */
    private $productPropertyEnum;

    /** @var $productProperty CIBlockProperty */
    private $productProperty;

    public function executeComponent()
    {
        $this->beforeStartImport();
        try {
            $this->includeModules();
            $this->getDataFromFile();
            if (!empty($this->data)) {
                $this->prepareDataFromDB();
                $this->logger->WriteInfoMessageToLogFile(Loc::getMessage("START_IMPORT_PRODUCTS_FROM_FILE"), true);
                $this->logger->WriteInfoMessageToLogFile(Loc::getMessage(
                    "COUNT_ITEMS_FROM_FILE",
                    ['#COUNT#' => count($this->data)]
                ), true);
                while ($arProduct = array_shift($this->data)) {
                    $this->changePropSale($arProduct);

                    if (empty($arProduct['Tsena'])) {
                        throw new Exception(Loc::getMessage("NO_PRICE", ['#ARTICUL#' => $arProduct['Artikul']]));
                    }

                    $this->checkProductColor($arProduct);

                    if (!empty($this->arResult['OFFERS']['STRUCT'][$arProduct['Artikul']])) {
                        $this->updateOffer($arProduct);
                    } else {
                        $codeProduct = $this->getFreeStockProductCodeFromFile($arProduct);
                        if (in_array($codeProduct, array_keys($this->arResult['PRODUCT_IMPORT']))) {
                            $this->AddOffer($arProduct);
                        } elseif (empty($arStructProducts[$arProduct['Artikul']])) {
                            $this->AddProduct($arProduct);
                            $this->AddOffer($arProduct);
                        }
                    }
                }

                if ($this->logger->getCounterValue('UPDATED_OFFERS')) {
                    $this->logger->WriteInfoMessageToLogFile(
                        Loc::getMessage(
                            "COUNT_UPDATED_OFFERS",
                            [
                                '#COUNT#' => $this->logger->getCounterValue('UPDATED_OFFERS'),
                                '#WORD#' => $this->logger->getInclinationByNumber(
                                    $this->logger->getCounterValue('UPDATED_OFFERS'),
                                    $this->offers_termination
                                )
                            ]
                        ),
                        true
                    );
                }

                if ($this->logger->getCounterValue('ADDED_OFFERS')) {
                    $this->logger->WriteInfoMessageToLogFile(
                        Loc::getMessage(
                            "COUNT_ADDED_OFFERS",
                            [
                                '#COUNT#' => $this->logger->getCounterValue('ADDED_OFFERS'),
                                '#WORD#' => $this->logger->getInclinationByNumber(
                                    $this->logger->getCounterValue('ADDED_OFFERS'),
                                    $this->offers_termination
                                )
                            ]
                        ),
                        true
                    );
                }

                if ($this->logger->getCounterValue('ADDED_PRODUCTS')) {
                    $this->logger->WriteInfoMessageToLogFile(
                        Loc::getMessage(
                            "COUNT_ADDED_PRODUCTS",
                            [
                                '#COUNT#' => $this->logger->getCounterValue('ADDED_PRODUCTS'),
                                '#WORD#' => $this->logger->getInclinationByNumber(
                                    $this->logger->getCounterValue('ADDED_PRODUCTS'),
                                    $this->offers_termination
                                )
                            ]
                        ),
                        true
                    );
                }

                $this->logger->WriteInfoMessageToLogFile(Loc::getMessage(
                    "STOP_IMPORT_WITHOUT_MISS",
                    ['#DATE#' => date('d.m.Y H:i:s')]
                ), true);
            } else {
                throw new Exception(Loc::getMessage("CANNOT_LOAD_FILE"));
            }
        } catch (Exception $exception) {
            $this->logger->WriteExceptionToLogFile($exception);
            $this->logger->ShowException($exception);
            $this->logger->WriteInfoMessageToLogFile(Loc::getMessage(
                "STOP_IMPORT_WITH_MISS",
                ['#DATE#' => date('d.m.Y H:i:s')]
            ), true);
        }
    }

    private function getDataFromFile()
    {
        $path = $_SERVER['DOCUMENT_ROOT'] . '/upload/ftp/';
        $fileName = $path . "SvobodniySklad.json";
        $import = Import::factory('Import\JSON');
        $import->open($fileName);

        $this->data = $import->extract();
    }

    private function beforeStartImport()
    {
        global $USER;
        $this->logger = new Logger();
        $this->user = $USER;
        $this->logger->WriteInfoMessageToLogFile(
            Loc::getMessage('START_IMPORT', ['#DATE#' => date('d.m.Y H:i:s')]),
            true
        );
        $this->logger->WriteInfoMessageToLogFile(Loc::getMessage(
            'USER_IMPORT',
            ['#ID_USER#' => $this->user->GetID(), '#IP_USER#' => $_SERVER['REMOTE_ADDR']]
        ), true);

        $this->offers_termination = [
            Loc::getMessage("OFFERS_1"),
            Loc::getMessage("OFFERS_2_3_4"),
            Loc::getMessage("OFFERS_MORE")
        ];

        $this->product_termination = [
            Loc::getMessage("PRODUCTS_1"),
            Loc::getMessage("PRODUCTS_2_3_4"),
            Loc::getMessage("PRODUCTS_MORE")
        ];

        $this->productProperty = new CIBlockProperty;
        $this->productPropertyEnum = new CIBlockPropertyEnum;
        $this->element = new CIBlockElement();
        $this->section = new CIBlockSection();
    }

    /**
     * @throws ArgumentException
     * @throws Exception
     */
    private function getColorSets()
    {
        $this->arResult['colorsHex'] = [];
        $colorsHex = ColorSetsTable::getList();
        while ($arColor = $colorsHex->fetch()) {
            $this->arResult['colorsHex'][$arColor['UF_CODE']] = $arColor;
        }

        if (empty($this->arResult['colorsHex'])) {
            throw new Exception(Loc::getMessage('NO_COLOR_SETS'));
        }
    }

    /**
     * @throws LoaderException
     */
    private function includeModules()
    {
        Loader::IncludeModule("highloadblock");
        Loader::includeModule('iblock');
        Loader::includeModule('sale');
        Loader::includeModule("catalog");
        Loader::includeModule("currency");
    }

    /**
     * @throws ArgumentException
     * @throws Exception
     */
    private function getSections()
    {
        $sections = SectionTable::getList(array(
            'order' => array('LEFT_MARGIN' => 'ASC'),
            'filter' => array('IBLOCK_ID' => $this->arParams['IBLOCK_ID'])
        ));
        while ($arSection = $sections->fetch()) {
            $arLincs[$arSection['IBLOCK_SECTION_ID']]['CHILDS'][$arSection['ID']] = $arSection;
            $arLincs[$arSection['ID']] = &$arLincs[$arSection['IBLOCK_SECTION_ID']]['CHILDS'][$arSection['ID']];
        }

        if (empty($arLincs)) {
            throw new Exception(Loc::getMessage(Loc::getMessage("NO_SECTIONS")));
        }

        $this->arResult['SECTIONS_LINKS'] = $arLincs;

        $arS = array_shift($arLincs);
        $arStructSections = array();
        foreach ($arS['CHILDS'] as $arSect) {
            $arStructSections[$arSect['CODE']] = $arSect;
            if (!empty($arSect['CHILDS'])) {
                foreach ($arSect['CHILDS'] as $arSectSecond) {
                    $arStructSections[$arSect['CODE']][$arSectSecond['CODE']] = $arSectSecond;
                    if (!empty($arSectSecond['CHILDS'])) {
                        foreach ($arSectSecond['CHILDS'] as $arSectThird) {
                            $thre = $arSectThird['CODE'];
                            $arStructSections[$arSect['CODE']][$arSectSecond['CODE']][$thre] = $arSectThird;
                        }
                    }
                }
            }
        }

        if (empty($arStructSections)) {
            throw new Exception(Loc::getMessage(Loc::getMessage("NO_SECTIONS")));
        }

        return $arStructSections;
    }

    /**
     * @param $iblock_id
     * @return array
     * @throws Exception
     */
    private function getListPropertiesIblock($iblock_id)
    {
        $arEnumXmlId = array();
        $arProductPropertiesList = array();
        $productProperties = CIBlockProperty::GetList(array(), array("IBLOCK_ID" => $iblock_id));
        while ($arProperty = $productProperties->fetch()) {
            $arProductPropertiesList[$arProperty['CODE']] = $arProperty;
        }

        if (!empty($arProductPropertiesList)) {
            $propertyEnums = CIBlockPropertyEnum::GetList(
                array(),
                array("IBLOCK_ID" => $iblock_id, "CODE" => array_keys($arProductPropertiesList))
            );
            while ($arPropEnum = $propertyEnums->GetNext()) {
                $arEnumXmlId[$arPropEnum['ID']] = $arPropEnum['XML_ID'];
                $propCode = $arPropEnum['PROPERTY_CODE'];
                $enumCode = $arPropEnum['XML_ID'];
                $arProductPropertiesList[$propCode]['ENUM'][$enumCode] = $arPropEnum;
            }

            unset($arProductPropertiesList['CML2_LINK']);

            return [$arProductPropertiesList, $arEnumXmlId];
        } else {
            throw new Exception(Loc::getMessage(Loc::getMessage(
                "NO_PROPERTIES_IBLOCK",
                ['#IBLOCK_ID#' => $this->arParams['IBLOCK_ID']]
            )));
        }
    }

    /**
     * @throws Exception
     */
    private function getProducts()
    {
        $products = CIBlockElement::GetList(
            array(),
            array(
                'IBLOCK_ID' => $this->arParams['IBLOCK_ID'],
                'INCLUDE_SUBSECTIONS' => 'Y'
            ),
            false,
            false,
            array(
                'ID',
                'CODE',
                'IBLOCK_ID',
                'PROPERTY_Proizvoditel',
                'PROPERTY_NomerKolodki',
                'PROPERTY_NomerModeli',
                'PROPERTY_MaterialVerha',
                'PROPERTY_Sezon',
                'PROPERTY_MaterialPodkladki',
                'PROPERTY_VysotaKabluka',
                'PROPERTY_MaterialPodoshvy',
                'PROPERTY_VidIzdelia',
                'PROPERTY_RodIzdelia',
                'PROPERTY_Strana',
                'PROPERTY_Collection',
            )
        );
        $arStructProducts = array();
        $arProductsCodes = array();
        while ($arProduct = $products->fetch()) {
            $arStructProducts[$arProduct['CODE']] = [
                'ID' => $arProduct['ID'],
                'PROPERTY_PROIZVODITEL_ENUM_ID' => $arProduct['PROPERTY_PROIZVODITEL_ENUM_ID'],
                'PROPERTY_NOMERKOLODKI_ENUM_ID' => $arProduct['PROPERTY_NOMERKOLODKI_ENUM_ID'],
                'PROPERTY_NOMERMODELI_ENUM_ID' => $arProduct['PROPERTY_NOMERMODELI_ENUM_ID'],
                'PROPERTY_MATERIALVERHA_ENUM_ID' => $arProduct['PROPERTY_MATERIALVERHA_ENUM_ID'],
                'PROPERTY_SEZON_ENUM_ID' => $arProduct['PROPERTY_SEZON_ENUM_ID'],
                'PROPERTY_MATERIALPODKLADKI_ENUM_ID' => $arProduct['PROPERTY_MATERIALPODKLADKI_ENUM_ID'],
                'PROPERTY_VYSOTAKABLUKA_ENUM_ID' => $arProduct['PROPERTY_VYSOTAKABLUKA_ENUM_ID'],
                'PROPERTY_MATERIALPODOSHVY_ENUM_ID' => $arProduct['PROPERTY_MATERIALPODOSHVY_ENUM_ID'],
                'PROPERTY_VIDIZDELIA_ENUM_ID' => $arProduct['PROPERTY_VIDIZDELIA_ENUM_ID'],
                'PROPERTY_RODIZDELIA_ENUM_ID' => $arProduct['PROPERTY_RODIZDELIA_ENUM_ID'],
                'PROPERTY_STRANA_ENUM_ID' => $arProduct['PROPERTY_STRANA_ENUM_ID'],
                'IBLOCK_ID' => $arProduct['IBLOCK_ID'],
                'CODE' => $arProduct['CODE'],
                'PROPERTY_COLLECTION' => $arProduct['PROPERTY_COLLECTION_VALUE'],
            ];
            $arStructCollections[$arProduct['ID']] = [
                'CODE' => $arProduct['CODE'],
                'IBLOCK_ID' => $arProduct['IBLOCK_ID'],
                'PROPERTY_COLLECTION' => $arProduct['PROPERTY_COLLECTION_VALUE'],
            ];
            $arProductsCodes[$this->getFreeStockProductCode(
                $arProduct,
                $this->arResult['PRODUCT_IBLOCK_PROPERTIES_LIST_ENUM']
            )][] = $arProduct['CODE'];
        }

        if (empty($arStructProducts)) {
            throw new Exception(Loc::getMessage("NO_PRODUCT_STRUCT"));
        }

        if (empty($arStructCollections)) {
            throw new Exception(Loc::getMessage("NO_PRODUCT_STRUCT_COLLECTION"));
        }

        if (empty($arProductsCodes)) {
            throw new Exception(Loc::getMessage("NO_PRODUCT_CODES"));
        }

        $arDoubles = array();
        $count_doubles = 0;
        foreach ($arProductsCodes as $item) {
            if (count($item) > 1) {
                $count_doubles++;
                $this->logger->WriteInfoMessageToLogFile(Loc::getMessage("DOUBLES") . implode(' - ', $item), true);
            }
        }
        $this->logger->WriteInfoMessageToLogFile(Loc::getMessage("COUNT_DOUBLES") . $count_doubles, true);

        unset($arDoubles);

        return [$arStructProducts, $arStructCollections, $arProductsCodes];
    }

    /**
     * @throws Exception
     */
    private function getOffers()
    {
        $offers = CIBlockElement::GetList(
            array(),
            array(
                'IBLOCK_ID' => $this->arParams['OFFERS_IBLOCK_ID'],
                'INCLUDE_SUBSECTIONS' => 'Y'
            ),
            false,
            false,
            array(
                'ID',
                'IBLOCK_ID',
                'NAME',
                'CODE',
                'PROPERTY_CML2_LINK',
                'PROPERTY_Proizvoditel',
                'PROPERTY_NomerKolodki',
                'PROPERTY_NomerModeli',
                'PROPERTY_MaterialVerha',
                'PROPERTY_Sezon',
                'PROPERTY_MaterialPodkladki',
                'PROPERTY_VysotaKabluka',
                'PROPERTY_MaterialPodoshvy',
                'PROPERTY_VidIzdelia',
                'PROPERTY_RodIzdelia',
                'PROPERTY_Strana',
                'PROPERTY_CML2_LINK',
            )
        );
        $arStructOffers = array();
        while ($arProduct = $offers->fetch()) {
            $arStructOffers[$arProduct['CODE']] = [
                'ID' => $arProduct['ID'],
                'PROPERTY_CML2_LINK_VALUE' => $arProduct['PROPERTY_CML2_LINK_VALUE'],
                'PROPERTY_PROIZVODITEL_ENUM_ID' => $arProduct['PROPERTY_PROIZVODITEL_ENUM_ID'],
                'PROPERTY_NOMERKOLODKI_ENUM_ID' => $arProduct['PROPERTY_NOMERKOLODKI_ENUM_ID'],
                'PROPERTY_NOMERMODELI_ENUM_ID' => $arProduct['PROPERTY_NOMERMODELI_ENUM_ID'],
                'PROPERTY_MATERIALVERHA_ENUM_ID' => $arProduct['PROPERTY_MATERIALVERHA_ENUM_ID'],
                'PROPERTY_SEZON_ENUM_ID' => $arProduct['PROPERTY_SEZON_ENUM_ID'],
                'PROPERTY_MATERIALPODKLADKI_ENUM_ID' => $arProduct['PROPERTY_MATERIALPODKLADKI_ENUM_ID'],
                'PROPERTY_VYSOTAKABLUKA_ENUM_ID' => $arProduct['PROPERTY_VYSOTAKABLUKA_ENUM_ID'],
                'PROPERTY_MATERIALPODOSHVY_ENUM_ID' => $arProduct['PROPERTY_MATERIALPODOSHVY_ENUM_ID'],
                'PROPERTY_VIDIZDELIA_ENUM_ID' => $arProduct['PROPERTY_VIDIZDELIA_ENUM_ID'],
                'PROPERTY_RODIZDELIA_ENUM_ID' => $arProduct['PROPERTY_RODIZDELIA_ENUM_ID'],
                'PROPERTY_STRANA_ENUM_ID' => $arProduct['PROPERTY_STRANA_ENUM_ID'],
                'IBLOCK_ID' => $arProduct['IBLOCK_ID'],
                'CODE' => $arProduct['CODE'],
                'PROPERTY_CML2_LINK' => $arProduct['PROPERTY_CML2_LINK_VALUE'],
            ];
            $this->arResult['PRODUCTS']['CODES'][$this->getFreestockProductCode(
                $arProduct,
                $this->arResult['OFFERS_IBLOCK_PROPERTIES_LIST_ENUM']
            )]['OFFERS'] = $arProduct['PROPERTY_CML2_LINK_VALUE'];
        }

        if (empty($arStructOffers)) {
            throw new Exception(Loc::getMessage("NO_OFFERS_STRUCT"));
        }

        return [$arStructOffers];
    }

    /**
     * @param $arProduct
     * @param $arEnumXmlId
     * @return string
     * @throws Exception
     */
    private function getFreeStockProductCode($arProduct, $arEnumXmlId)
    {
        $arCodes = [];

        foreach ($this->propsForProductCode as $prop) {
            if (!empty($arProduct['PROPERTY_' . $prop . '_ENUM_ID'])) {
                $arCodes[] = $arEnumXmlId[$arProduct['PROPERTY_' . $prop . '_ENUM_ID']];
            } else {
                throw new Exception(Loc::getMessage(
                    "PROP_EMPTY",
                    ['#PROP#' => $prop, '#PRODUCT_ID#' => $arProduct['ID']]
                ));
            }
        }

        $strCode = implode('-', $arCodes);
        return $strCode;
    }

    /**
     * @param $arProduct
     * @return string
     * @throws Exception
     */
    private function getFreeStockProductCodeFromFile($arProduct)
    {
        $arCodes = [];

        foreach ($this->propsForProductCodeFromFile as $prop) {
            if (!empty($arProduct['Property'][$prop])) {
                $arCodes[] = Cutil::translit(
                    $arProduct['Property'][$prop],
                    "ru",
                    array("replace_space" => "-", "replace_other" => "-")
                );
            } else {
                throw new Exception(Loc::getMessage(
                    "PROP_EMPTY",
                    ['#PROP#' => $prop, '#PRODUCT_ID#' => $arProduct['Artikul']]
                ));
            }
        }

        $strCode = implode('-', $arCodes);
        return $strCode;
    }

    /**
     * @return array
     * @throws Exception
     */
    private function defineOffersByProduct()
    {
        $arProductImport = array();
        foreach ($this->arResult['PRODUCTS']['CODES'] as $prodCode => $fields) {
            if ($fields['OFFERS']) { //if cm2 link exist
                $arProductImport[$prodCode] = $fields['OFFERS']; //Code => cm2link (id of product)
            } else {
                $prodId = $this->arResult['PRODUCTS']['CODES'][$fields[0]]['ID']; //fields[0] - code. arStructProducts = [code => product fields]
                $arProductImport[$prodCode] = $prodId;
            }
        }

        if (empty($arProductImport)) {
            throw new Exception(Loc::getMessage("PRODUCT_IMPORT"));
        }

        return $arProductImport;
    }

    /**
     * @throws ArgumentException
     * @throws Exception
     */
    private function getRostovki()
    {
        $sizes = ShoeSizesTable::getList();
        $this->arResult['SIZES'] = array();
        while ($arSize = $sizes->fetch()) {
            $this->arResult['SIZES'][$arSize['UF_PRODUCT_ID']][$arSize['UF_ROSTOVKA']][$arSize['UF_SIZE']] = $arSize['ID'];
        }

        if (empty($this->arResult['SIZES'])) {
            throw new Exception(Loc::getMessage("NO_ROSTOVKI"));
        }
    }

    /**
     * @throws ArgumentException
     * @throws Exception
     */
    private function getRemains()
    {
        $remain = RemainRostovkiTable::getList();
        $this->arResult['QUANTITY_FS'] = array();
        while ($arQuantity = $remain->fetch()) {
            $this->arResult['QUANTITY_FS'][$arQuantity['UF_PRODUCT_ID']][$arQuantity['UF_ROSTOVKA']] = $arQuantity['ID'];
            $this->arResult['QUANTITY'][$arQuantity['ID']] = $arQuantity['UF_QUANTITY'];
            if ($arQuantity['UF_QUANTITY'] > 0) {
                $result = RemainRostovkiTable::update(
                    $arQuantity['ID'],
                    array(
                        'UF_QUANTITY' => 0
                    )
                );

                if (!$result->isSuccess()) {
                    throw new Exception(Loc::getMessage("REMAIN_NOT_ZERO", ['#ID#' => $arQuantity['ID']]));
                }

                if (empty($this->arResult['QUANTITY_FS'])) {
                    throw new Exception(Loc::getMessage("NO_REMAIN"));
                }

                if (empty($this->arResult['QUANTITY_FS'])) {
                    throw new Exception(Loc::getMessage("NO_REMAIN"));
                }
            }
        }
    }

    /**
     * @throws Exception
     */
    private function getCollection()
    {
        $property_enums = CIBlockPropertyEnum::GetList(
            array("DEF" => "DESC", "SORT" => "ASC"),
            array("IBLOCK_ID" => $this->arParams['IBLOCK_ID'], "CODE" => "Collection")
        );
        while ($enum_fields = $property_enums->GetNext()) {
            $collectionList[$enum_fields["VALUE"]] = $enum_fields["ID"];
        }

        if (empty($collectionList)) {
            throw new Exception(Loc::getMessage("NO_COLLECTIONS"));
        }

        $this->arResult['COLLECTION_LIST'] = $collectionList;
    }

    /**
     * @throws Exception
     */
    private function prepareDataFromDB()
    {
        $this->logger->WriteInfoMessageToLogFile(Loc::getMessage("START_LOAD_DB"), true);
        $this->logger->WriteInfoMessageToLogFile(Loc::getMessage("GET_COLOR_SETS"), true);
        $this->getColorSets();
        $this->logger->WriteInfoMessageToLogFile(Loc::getMessage("GET_SECTIONS"), true);
        $this->arResult['SECTIONS_STRUCT'] = $this->getSections();
        $this->logger->WriteInfoMessageToLogFile(Loc::getMessage("GET_ROSTOVKI"), true);
        $this->getRostovki();
        $this->logger->WriteInfoMessageToLogFile(Loc::getMessage("GET_REMAINS"), true);
        $this->getRemains();
        $this->logger->WriteInfoMessageToLogFile(Loc::getMessage("GET_COLLECTIONS"), true);
        $this->getCollection();
        $this->logger->WriteInfoMessageToLogFile(Loc::getMessage("GET_PROPS_PRODUCTS"), true);
        list($this->arResult['PRODUCT_IBLOCK_PROPERTIES_LIST'],
            $this->arResult['PRODUCT_IBLOCK_PROPERTIES_LIST_ENUM']) = $this->getListPropertiesIblock($this->arParams['IBLOCK_ID']);
        $this->logger->WriteInfoMessageToLogFile(Loc::getMessage("GET_PROPS_OFFERS"), true);
        list($this->arResult['OFFERS_IBLOCK_PROPERTIES_LIST'],
            $this->arResult['OFFERS_IBLOCK_PROPERTIES_LIST_ENUM']) = $this->getListPropertiesIblock($this->arParams['OFFERS_IBLOCK_ID']);
        $this->logger->WriteInfoMessageToLogFile(Loc::getMessage("GET_PRODUCTS_STRUCT"), true);
        list($this->arResult['PRODUCTS']['STRUCT'],
            $this->arResult['PRODUCTS']['STRUCT_COLLECTION'],
            $this->arResult['PRODUCTS']['CODES']) = $this->getProducts();
        $this->logger->WriteInfoMessageToLogFile(Loc::getMessage("GET_STRUCT_OFFERS"), true);
        list($this->arResult['OFFERS']['STRUCT'],
            $this->arResult['OFFERS']['CODES']) = $this->getOffers();
        $this->logger->WriteInfoMessageToLogFile(Loc::getMessage("DEFINE_OFFERS_BY_PRODUCT"), true);
        $this->arResult['PRODUCT_IMPORT'] = $this->defineOffersByProduct();
        $this->logger->WriteInfoMessageToLogFile(Loc::getMessage("GET_CATALOG"), true);
        $this->arResult['CATALOG'] = CCatalog::GetByID($this->arParams['OFFERS_IBLOCK_ID']);//инфоблок предложений
        $this->arResult['IBLOCK_PRODUCTS_ID'] = $this->arResult['CATALOG']['PRODUCT_IBLOCK_ID']; // ID инфоблока товаров
        $this->arResult['SKU_PROP_ID'] = $this->arResult['CATALOG']['SKU_PROPERTY_ID']; // ID свойства в инфоблоке предложений типа "Привязка к товарам (SKU)"
        $this->logger->WriteInfoMessageToLogFile(Loc::getMessage("SUCCESS_LOAD_FROM_DB"), true);
    }

    /**
     * @param $arProduct
     */
    private function changePropSale(&$arProduct)
    {
        //Переименовал свойство Распродажа и заменил 0 на пустоту
        if ($arProduct['Property']['Rasprodazha'] == "1") {
            $arProduct['Property'][PROPERTY_SALE] = Loc::getMessage("YES");
            unset($arProduct['Property']['Rasprodazha']);
        } else {
            unset($arProduct['Property']['Rasprodazha']);
        }
    }

    /**
     * @param $arProduct
     * @throws Exception
     */
    private function checkProductColor($arProduct)
    {
        $colorCode = Cutil::translit(
            $arProduct['Property']['Tsvet'],
            "ru",
            array("replace_space" => "-", "replace_other" => "-")
        );
        if (empty($this->arResult['colorsHex'][$colorCode])) {
            $arColorHexField = [
                'UF_NAME' => $arProduct['Property']['Tsvet'],
                'UF_CODE' => $colorCode,
                'UF_HEX' => ''
            ];
            $result = ColorSetsTable::add($arColorHexField);

            if (!$result->isSuccess()) {
                throw new Exception(Loc::getMessage("COLOR_NOT_ADDED", ['#COLOR#' => $colorCode]));
            }
            $this->arResult['colorsHex'][$colorCode] = $arColorHexField;
        }
    }

    /**
     * @param $propOfferCodeProduct
     * @param $propOfferValProduct
     * @throws Exception
     */
    private function addNewProperty($propOfferCodeProduct, $propOfferValProduct)
    {
        //создаем
        $arOfferFieldsNewProperty = array(
            "NAME" => $propOfferCodeProduct,
            "ACTIVE" => "Y",
            "SORT" => "500",
            "CODE" => $propOfferCodeProduct,
            "PROPERTY_TYPE" => "L",
            "IBLOCK_ID" => $this->arParams['OFFERS_IBLOCK_ID'],
            "VALUES" => array(
                array(
                    "VALUE" => $propOfferValProduct,
                    "DEF" => "N",
                    "SORT" => "100",
                    "XML_ID" => Cutil::translit(
                        $propOfferValProduct,
                        "ru",
                        array("replace_space" => "-", "replace_other" => "-")
                    )
                )
            )
        );
        //заполняем созданное свойство

        $PropID = $this->productProperty->Add($arOfferFieldsNewProperty);
        if ($PropID > 0) {
            $this->arResult['OFFERS_IBLOCK_PROPERTIES_LIST'][$propOfferCodeProduct] = $arOfferFieldsNewProperty;
        } else {
            throw new Exception(Loc::getMessage(
                "NO_ADD_PROP",
                ['#PROP#' => $propOfferCodeProduct, '#MISS#' => $this->productProperty->LAST_ERROR]
            ));
        }
    }

    /**
     * @param $arProduct
     * @throws Exception
     */
    private function updateOffer($arProduct)
    {
        $propOfferValues = reset($this->fillProperties($arProduct, true));

        $arOfferFields = array(
            'NAME' => $arProduct['Property']['VidIzdelia'],
            "PREVIEW_PICTURE" => CFile::MakeFileArray($_SERVER['DOCUMENT_ROOT'] . "/upload/exportphoto/" . $arProduct['Foto'][0]),
            'PROPERTY_VALUES' => $propOfferValues
        );

        $this->offer_id = $this->arResult['OFFERS']['STRUCT'][$arProduct['Artikul']]['ID'];

        $result = $this->element->Update($this->offer_id, $arOfferFields);

        if (!$result) {
            throw new Exception(Loc::getMessage(
                "OFFER_NOT_UPDATED",
                ['#OFFER#' => $this->offer_id, '#MISS#' => $this->element->LAST_ERROR]
            ));
        } else {
            $this->logger->counterInc('UPDATED_OFFERS');
            $this->logger->WriteInfoMessageToLogFile(
                Loc::getMessage("OFFER_UPDATE", ['#OFFER#' => $this->offer_id]),
                true
            );
        }

        $this->updateOfferRostovki($arProduct);

        $result = CPrice::SetBasePrice($this->offer_id, $arProduct['Tsena'], $arProduct['Valuta']);
        if (!$result) {
            throw new Exception(Loc::getMessage("BASE_PRICE_NOT_SET_OFFER", ['#OFFER#' => $this->offer_id]));
        }
        Manager::updateElementIndex(FREE_STOCK_OFFERS_IBLOCK, $this->offer_id);
    }

    /**
     * @param $arProduct
     * @throws Exception
     */
    private function AddOffer($arProduct)
    {
        list($propOfferValues, $codeProductSaved) = $this->fillProperties($arProduct, true);

        $propOfferValues[$this->arResult['SKU_PROP_ID']] = $this->arResult['PRODUCT_IMPORT'][$codeProductSaved];

        $arOfferFields = array(
            'NAME' => $arProduct['Property']['VidIzdelia'],
            "PREVIEW_PICTURE" => CFile::MakeFileArray($_SERVER['DOCUMENT_ROOT'] . "/upload/exportphoto/" . $arProduct['Foto'][0]),
            'PROPERTY_VALUES' => $propOfferValues,
            'IBLOCK_ID' => $this->arParams['OFFERS_IBLOCK_ID'],
            'CODE' => $arProduct['Artikul'],
            'ACTIVE' => 'Y',
        );

        $result = $this->element->Add($arOfferFields);

        if (!$result) {
            throw new Exception(Loc::getMessage(
                "OFFER_NOT_ADDED",
                ['#OFFER#' => $arProduct['Artikul'], '#MISS#' => $this->element->LAST_ERROR]
            ));
        } else {
            $this->offer_id = $result;
            $this->logger->counterInc('ADDED_OFFERS');
            $this->logger->WriteInfoMessageToLogFile(
                Loc::getMessage("OFFER_ADD", ['#OFFER#' => $this->offer_id]),
                true
            );
        }

        $this->arResult['OFFERS']['STRUCT'][$arProduct['Artikul']] = array(
            'ID' => $this->offer_id,
            'PROPERTY_CML2_LINK_VALUE' => $this->arResult['PRODUCT_IMPORT'][$codeProductSaved]
        );

        $this->updateOfferRostovki($arProduct);

        $catalogProductAddResult = CCatalogProduct::Add(array(
            "ID" => $this->offer_id,
            "QUANTITY" => $arProduct["Kolichestvo"],
            "VAT_INCLUDED" => "N", //НДС входит в стоимость
        ));
        if ($catalogProductAddResult) {
            $result = CPrice::SetBasePrice($this->offer_id, $arProduct['Tsena'], $arProduct['Valuta']);
            if (!$result) {
                throw new Exception(Loc::getMessage("BASE_PRICE_NOT_SET_OFFER", ['#OFFER#' => $this->offer_id]));
            }
            Manager::updateElementIndex(FREE_STOCK_OFFERS_IBLOCK, $this->offer_id);
        } else {
            throw new Exception(Loc::getMessage("PRODUCT_CATALOG_NOT_ADDED_OFFER", ['#OFFER#' => $this->offer_id]));
        }
    }

    /**
     * @param $arProduct
     * @param $offer
     * @return array|bool
     * @throws Exception
     */
    private function fillProperties($arProduct, $offer)
    {
        foreach ($arProduct['Property'] as $propOfferCodeProduct => $propOfferValProduct) {
            if (!in_array(
                $propOfferCodeProduct,
                array_keys($this->arResult['OFFERS_IBLOCK_PROPERTIES_LIST'])
            )) {
                $this->addNewProperty($propOfferCodeProduct, $propOfferValProduct);


                $newOfferPropertyEnum = CIBlockPropertyEnum::GetList(
                    array(),
                    array(
                        "IBLOCK_ID" => $this->arParams['IBLOCK_ID'],
                        "CODE" => $propOfferCodeProduct
                    )
                );
                while ($arOfferNewPropEnum = $newOfferPropertyEnum->GetNext()) {
                    $propOfferCode = $arOfferNewPropEnum['PROPERTY_CODE'];
                    $enumOfferCode = $arOfferNewPropEnum['XML_ID'];
                    $this->arResult['OFFERS_IBLOCK_PROPERTIES_LIST'][$propOfferCode]['ENUM'][$enumOfferCode] = $arOfferNewPropEnum;
                }
            } elseif (in_array(
                $propOfferCodeProduct,
                array_keys($this->arResult['OFFERS_IBLOCK_PROPERTIES_LIST'])
            ) && $this->arResult['OFFERS_IBLOCK_PROPERTIES_LIST'][$propOfferCodeProduct]['PROPERTY_TYPE'] == 'L') {
                //добавляем значение
                $propOfferValProductCode = Cutil::translit(
                    $propOfferValProduct,
                    "ru",
                    array("replace_space" => "-", "replace_other" => "-")
                );
                $propProductID = $this->arResult['OFFERS_IBLOCK_PROPERTIES_LIST'][$propOfferCodeProduct]['ID'];
                if (!in_array(
                    $propOfferValProductCode,
                    array_keys($this->arResult['OFFERS_IBLOCK_PROPERTIES_LIST'][$propOfferCodeProduct]['ENUM'])
                ) && $propProductID > 0) {
                    $enumOfferNewPropID = $this->productPropertyEnum->Add(array(
                        'PROPERTY_ID' => $propProductID,
                        'VALUE' => $propOfferValProduct,
                        "XML_ID" => $propOfferValProductCode
                    ));
                    if ($enumOfferNewPropID > 0) {
                        $this->arResult['OFFERS_IBLOCK_PROPERTIES_LIST'][$propOfferCodeProduct]['ENUM'][$propOfferValProductCode] = array(
                            'ID' => $enumOfferNewPropID,
                            'PROPERTY_ID' => $propProductID,
                            'VALUE' => $propOfferValProduct,
                            'XML_ID' => $propOfferValProductCode,
                            'PROPERTY_CODE' => $propOfferCodeProduct
                        );
                    } else {
                        throw new Exception(Loc::getMessage(
                            "NO_ADD_PROP_TO_OFFER",
                            ['#PROP#' => $propOfferCodeProduct, '#PRODUCT#' => $arProduct['Artikul']]
                        ));
                    }
                }
            }
        }

        if ($offer) {
            //Инициализируем свойства предложения для его дальнейшего обновления
            $propOfferValues = array();
            $propOfferValProductCodeSaved = '';
            foreach ($this->arResult['OFFERS_IBLOCK_PROPERTIES_LIST'] as $arPropOfferSaved) {
                $propOfferValProductCodeSaved = Cutil::translit(
                    $arProduct['Property'][$arPropOfferSaved['CODE']],
                    "ru",
                    array("replace_space" => "-", "replace_other" => "-")
                );
                $propOfferValues[$arPropOfferSaved['CODE']] = $arPropOfferSaved['ENUM'][$propOfferValProductCodeSaved];
            }

            $propOfferValues[$this->arResult['SKU_PROP_ID']] = $this->arResult['OFFERS']['STRUCT'][$arProduct['Artikul']]['PROPERTY_CML2_LINK_VALUE'];

            if (empty($propOfferValues)) {
                throw new Exception(Loc::getMessage(
                    "NO_CREATED_PROPS_ARRAY_OFFER",
                    ['#OFFER#' => $arProduct['Artikul']]
                ));
            }

            return [$propOfferValues, $propOfferValProductCodeSaved];
        } else {
            $propValues = array();
            $propValProductCodeSaved = '';
            foreach ($this->arResult['PRODUCT_IBLOCK_PROPERTIES_LIST'] as $arPropSaved) {
                $propValProductCodeSaved = Cutil::translit(
                    $arProduct['Property'][$arPropSaved['CODE']],
                    "ru",
                    array("replace_space" => "-", "replace_other" => "-")
                );
                $propValues[$arPropSaved['CODE']] = $arPropSaved['ENUM'][$propValProductCodeSaved];
            }
            return [$propValues, $propValProductCodeSaved];
        }
    }

    /**
     * @param $arProduct
     * @throws Exception
     */
    private function updateOfferRostovki($arProduct)
    {
        if (!empty($arProduct['Rostovki'])) {
            foreach ($arProduct['Rostovki'] as $arRostovka) {
                if (!empty($arRostovka['Rostovka']) && !empty($arRostovka['SostavRostovki'])) {
                    //Удаляем старые ростовки
                    $arTmpSizes = $this->arResult['SIZES'][$this->offer_id][$arRostovka['Rostovka']];
                    foreach ($arTmpSizes as $key => $tmpSize) {
                        ShoeSizesTable::delete($tmpSize);
                        unset($this->arResult['SIZES'][$this->offer_id][$arRostovka['Rostovka']][$key]);
                    }

                    foreach ($arRostovka['SostavRostovki'] as $lineUp) {
                        list($size, $count) = explode('-', $lineUp);
                        if (!empty($count) && !empty($size)) {
                            //Добавляем новые
                            $shoeSizeOb = ShoeSizesTable::add(
                                array(
                                    'UF_PRODUCT_ID' => $this->offer_id,
                                    'UF_ROSTOVKA' => $arRostovka['Rostovka'],
                                    'UF_SIZE' => $size,
                                    'UF_COUNT' => $count
                                )
                            );
                            if ($shoeSizeOb->getId()) {
                                $this->arResult['SIZES'][$this->offer_id][$arRostovka['Rostovka']][$size] = $shoeSizeOb->getId();
                            } else {
                                throw new Exception(Loc::getMessage(
                                    "NO_ADD_ROST_OFFER",
                                    ['#ROST#' => $arRostovka['Rostovka'], '#OFFER#' => $this->offer_id]
                                ));
                            }
                        }
                    }
                    if (!empty($this->arResult['QUANTITY_FS'][$this->offer_id][$arRostovka['Rostovka']])) {
                        $result = RemainRostovkiTable::update(
                            $this->arResult['QUANTITY_FS'][$this->offer_id][$arRostovka['Rostovka']],
                            array(
                                'UF_ROSTOVKA' => $arRostovka['Rostovka'],
                                'UF_QUANTITY' => $arRostovka['Kolichestvo']
                            )
                        );
                        if (!$result->isSuccess()) {
                            throw new Exception(Loc::getMessage(
                                "ROST_REMAIN_NOT_UPDATED_OFFER",
                                ['#ROST#' => $arRostovka['Rostovka'], '#OFFER#' => $this->offer_id]
                            ));
                        }
                    } else {
                        $result = RemainRostovkiTable::add(
                            array(
                                'UF_PRODUCT_ID' => $this->offer_id,
                                'UF_ROSTOVKA' => $arRostovka['Rostovka'],
                                'UF_QUANTITY' => $arRostovka['Kolichestvo']
                            )
                        );
                        if (!$result->isSuccess()) {
                            throw new Exception(Loc::getMessage(
                                "ROST_REMAIN_NOT_ADDED_OFFER",
                                ['#ROST#' => $arRostovka['Rostovka'], '#OFFER#' => $this->offer_id]
                            ));
                        }
                    }
                }
            }
        }
    }

    /**
     * @param $arProduct
     * @throws Exception
     */
    private function updateProductRostovki($arProduct)
    {
        if (!empty($arProduct['Rostovki'])) {
            foreach ($arProduct['Rostovki'] as $arRostovka) {
                if (!empty($arRostovka['Rostovka']) && !empty($arRostovka['SostavRostovki'])) {
                    foreach ($arRostovka['SostavRostovki'] as $lineUp) {
                        list($size, $count) = explode('-', $lineUp);
                        $shoeSizeOb = ShoeSizesTable::add(array(
                            'UF_PRODUCT_ID' => $this->product_id,
                            'UF_ROSTOVKA' => $arRostovka['Rostovka'],
                            'UF_SIZE' => $size,
                            'UF_COUNT' => $count
                        ));
                        if ($shoeSizeOb->getId()) {
                            $this->arResult['SIZES'][$this->product_id][$arRostovka['Rostovka']][$size] = $shoeSizeOb->getId();
                        } else {
                            throw new Exception(Loc::getMessage(
                                "NO_ADD_ROST_PRODUCT",
                                ['#ROST#' => $arRostovka['Rostovka'], '#PRODUCT#' => $this->product_id]
                            ));
                        }
                    }
                }

                if (!empty($arRostovka['Rostovka'])) {
                    $result = RemainRostovkiTable::add(
                        array(
                            'UF_PRODUCT_ID' => $this->product_id,
                            'UF_ROSTOVKA' => $arRostovka['Rostovka'],
                            'UF_QUANTITY' => $arRostovka['Kolichestvo']
                        )
                    );

                    if (!$result->isSuccess()) {
                        throw new Exception(Loc::getMessage(
                            "ROST_REMAIN_NOT_ADDED_PRODUCT",
                            ['#ROST#' => $arRostovka['Rostovka'], '#PRODUCT#' => $this->product_id]
                        ));
                    }
                }
            }
        }
    }

    /**
     * @param $arProduct
     * @throws Exception
     */
    private function AddProduct($arProduct)
    {
        list($propValues, $codeProductSaved) = $this->fillProperties($arProduct, false);

        $section_id = $this->getSectionPath($arProduct);

        if ($section_id > 0) {
            $arProdFields = array(
                'NAME' => $arProduct['Property']['VidIzdelia'],
                'ACTIVE' => 'Y',
                'CODE' => $arProduct['Artikul'],
                'IBLOCK_ID' => $this->arParams['IBLOCK_ID'],
                'IBLOCK_SECTION_ID' => $section_id,
                'PROPERTY_VALUES' => $propValues,
                "PREVIEW_PICTURE" => CFile::MakeFileArray($_SERVER['DOCUMENT_ROOT'] . "/upload/exportphoto/" . $arProduct['Foto'][0]),
                'DETAIL_TEXT' => $arProduct['Opisanie']
            );

            $this->product_id = $this->element->Add($arProdFields);

            if (!$this->product_id) {
                throw new Exception(Loc::getMessage(
                    "PRODUCT_NOT_ADDED",
                    ['#PRODUCT#' => $arProduct['Artikul'], '#MISS#' => $this->element->LAST_ERROR]
                ));
            } else {
                $this->logger->counterInc('ADDED_PRODUCTS');
                $this->logger->WriteInfoMessageToLogFile(
                    Loc::getMessage(
                        "PRODUCT_ADD",
                        ['#PRODUCT#' => $this->product_id]
                    ),
                    true
                );
                $this->arResult['PRODUCTS']['STRUCT'][$arProduct['Artikul']] = array('ID' => $this->product_id);
                $this->updateProductRostovki($arProduct);

                $catalogProdAddResult = CCatalogProduct::Add(array(
                    "ID" => $this->product_id,
                    "QUANTITY" => $arProduct["Kolichestvo"],
                    "VAT_INCLUDED" => "N", //НДС входит в стоимость
                ));
                if ($catalogProdAddResult) {
                    $result = CPrice::SetBasePrice($this->product_id, $arProduct['Tsena'], $arProduct['Valuta']);
                    if (!$result) {
                        throw new Exception(Loc::getMessage(
                            "BASE_PRICE_NOT_SET_PRODUCT",
                            ['#PRODUCT#' => $this->product_id]
                        ));
                    }
                    Manager::updateElementIndex(FREE_STOCK_IBLOCK, $this->product_id);
                } else {
                    throw new Exception(Loc::getMessage(
                        "PRODUCT_CATALOG_NOT_ADDED_PRODUCT",
                        ['#PRODUCT#' => $this->offer_id]
                    ));
                }

                $this->arResult['PRODUCT_IMPORT'][$codeProductSaved] = $this->product_id;
            }
        }
    }

    /**
     * @param $arProduct
     * @return bool|int
     * @throws Exception
     */
    private function getSectionPath($arProduct)
    {
        $idSecondSection = 0;
        $idThirdSection = 0;
        $secondCode = '';

        $parentCode = Cutil::translit(
            $arProduct['Property']['RodIzdelia'],
            "ru",
            array("replace_space" => "-", "replace_other" => "-")
        );
        $parentSectionSearch = $this->arResult['SECTIONS_STRUCT'][$parentCode];

        //Определяем родительский раздел
        if (empty($parentSectionSearch)) {
            $arParentSection = array(
                'ACTIVE' => 'Y',
                'NAME' => $arProduct['Property']['RodIzdelia'],
                'CODE' => $parentCode,
                'IBLOCK_ID' => $this->arParams['IBLOCK_ID'],
                'DEPTH_LEVEL' => '1'
            );
            $ID = $this->section->Add($arParentSection);
            if ($ID > 0) {
                $this->arResult['SECTIONS_STRUCT'][$parentCode] = array('ID' => $ID);
            } else {
                throw new Exception(Loc::getMessage(
                    "NO_ADD_SECTION",
                    ['#NAME#' => $arProduct['Property']['RodIzdelia']]
                ));
            }
        } else {
            $ID = $parentSectionSearch['ID'];
        }

        //Определяем второй раздел
        if ($ID > 0 && $arProduct['Kategoria']) {
            $secondCode = Cutil::translit(
                $arProduct['Kategoria'],
                "ru",
                array("replace_space" => "-", "replace_other" => "-")
            );
            $secondSectionSearch = $this->arResult['SECTIONS_STRUCT'][$parentCode][$secondCode];
            if (empty($secondSectionSearch)) {
                $arSecondSection = array(
                    'ACTIVE' => 'Y',
                    'NAME' => $arProduct['Kategoria'],
                    'CODE' => $secondCode,
                    'IBLOCK_ID' => $this->arParams['IBLOCK_ID'],
                    'IBLOCK_SECTION_ID' => $ID,
                );
                $idSecondSection = $this->section->Add($arSecondSection);
                if ($idSecondSection > 0) {
                    $this->arResult['SECTIONS_STRUCT'][$parentCode][$secondCode] = array('ID' => $idSecondSection);
                } else {
                    throw new Exception(Loc::getMessage("NO_ADD_SECTION", ['#NAME#' => $arProduct['Kategoria']]));
                }
            } else {
                $idSecondSection = $secondSectionSearch['ID'];
            }
        }

        //Определяем третий раздел
        if ($idSecondSection > 0 && $arProduct['Property']['VidIzdelia']) {
            $thirdCode = Cutil::translit(
                $arProduct['Property']['VidIzdelia'],
                "ru",
                array("replace_space" => "-", "replace_other" => "-")
            );
            $thirdSectionSearch = $this->arResult['SECTIONS_STRUCT'][$parentCode][$secondCode][$thirdCode];
            if (empty($thirdSectionSearch)) {
                $arThirdSection = array(
                    'ACTIVE' => 'Y',
                    'NAME' => $arProduct['Property']['VidIzdelia'],
                    'CODE' => $thirdCode,
                    'IBLOCK_ID' => $this->arParams['IBLOCK_ID'],
                    'IBLOCK_SECTION_ID' => $idSecondSection,
                );
                $idThirdSection = $this->section->Add($arThirdSection);
                if ($idThirdSection > 0) {
                    $this->arResult['SECTIONS_STRUCT'][$parentCode][$secondCode][$thirdCode] = array('ID' => $idThirdSection);
                } else {
                    throw new Exception(Loc::getMessage(
                        "NO_ADD_SECTION",
                        ['#NAME#' => $arProduct['Property']['VidIzdelia']]
                    ));
                }
            } else {
                $idThirdSection = $thirdSectionSearch['ID'];
            }
        }

        return $idThirdSection;
    }
}

class Logger
{
    private $filePath = '';
    private $dirPath = '';

    private $counter;

    public function __construct()
    {
        $this->dirPath = $_SERVER["DOCUMENT_ROOT"] . '/local/logs/FreeStockImport/';
        mkdir($this->dirPath);
        $this->filePath = $this->dirPath . 'import_' . date('d_m_Y_H:i:s') . '.txt';
    }

    public function WriteInfoMessageToLogFile($message, $show = false)
    {
        file_put_contents($this->filePath, $message . PHP_EOL, FILE_APPEND);

        if ($show) {
            echo $message . '<br>';
        }
    }

    public function WriteExceptionToLogFile(Exception $exception)
    {
        $string = '==================================================' . PHP_EOL
            . $exception->getMessage() . ' '
            . Loc::getMessage("IN_LINE") . $exception->getLine() . PHP_EOL
            . '==================================================' . PHP_EOL;

        file_put_contents($this->filePath, $string . PHP_EOL, FILE_APPEND);
    }

    public function ShowException(Exception $exception)
    {
        $string = '==================================================' . '<br>'
            . $exception->getMessage() . ' '
            . Loc::getMessage("IN_LINE") . $exception->getLine() . '<br>'
            . '==================================================' . '<br>';

        echo $string;
    }

    public function counterInc($key)
    {
        if (!empty($this->counter[$key])) {
            $this->counter[$key] += 1;
        } else {
            $this->counter[$key] = 1;
        }
    }

    public function getCounterValue($key)
    {
        return $this->counter[$key];
    }

    public function getInclinationByNumber($num, $words = array())
    {
        $num = $num % 100;
        if ($num > 19) {
            $num = $num % 10;
        }
        switch ($num) {
            case 1:
                return ($words[0]);
            case 2:
            case 3:
            case 4:
                return ($words[1]);
            default:
                return ($words[2]);
        }
    }
}
