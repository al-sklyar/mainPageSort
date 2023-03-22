<?php
$_SERVER["DOCUMENT_ROOT"] = realpath(dirname(__FILE__) . "/../../..");
$DOCUMENT_ROOT = $_SERVER["DOCUMENT_ROOT"];

const NO_KEEP_STATISTIC = true;
const NOT_CHECK_PERMISSIONS = true;
const BX_NO_ACCELERATOR_RESET = true;
const CHK_EVENT = true;
const BX_WITH_ON_AFTER_EPILOG = true;
const BX_BUFFER_USED = true;

@set_time_limit(300); // По времени работы ограничены 5 минутами
ini_set('memory_limit', '-1'); // Нет лимита на память
@ignore_user_abort(true); // Игнорирует отключение пользователя и позволяет скрипту доработать

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
while (ob_get_level())
    ob_end_flush();
echo "Старт\n";


global $DB;

if ($argv[1] == 18) {
    die();
}

// Если город не задан, выбираем Москву:
if (!isset($argv[1])) {
    $argv[1] = 17;
}

const FIRST_BLOCK_LIMIT = 12;
const NEXT_BLOCKS_LIMIT = 24;

// Выбираем множитель конверсии таким, чтобы при конверсии 0,1% рассчитанное ниже значение свойства сортировки товара равнялось 100
const MULTIPLICATOR = 100000;

if ($argv[1] == 17) {
    $TotalViews = 1000;
} else {
    $TotalViews = 200;
}

// Очищаем свойство "Новая сортировка на главной странице" для выбранного города
$res = \CIBlockElement::GetList(['NAME' => 'ASC'], [
    'IBLOCK_ID' => $argv[1],
    '!PROPERTY_NEW_MAIN_PAGE_SORT' => false
], false, false, ['IBLOCK_ID', 'ID', 'NAME', 'PROPERTY_NEW_MAIN_PAGE_SORT']);
while ($row = $res->Fetch()) {
    \CIBlockElement::SetPropertyValues($row['ID'], $row['IBLOCK_ID'], NULL, 'NEW_MAIN_PAGE_SORT');
}
echo "Очистили свойство \"Новая сортировка на главной странице\"\n";

// Сортировка первого блока
$obFirstBlockDate = new DateTime('-180 days');
$firstBlockDateFrom = $obFirstBlockDate->format('Y-m-d');

$arFirstBlockProducts = [];
// Для сортировки первого блока требуется только 12 товаров с лучшей конверсией, однако умножаем их количество на 4,
// т.к. товары могут уже либо быть удалены, либо сняты с публикации
$firstBlockLimit = FIRST_BLOCK_LIMIT * 4;

$sql = 'select product_id, city_id, sum(add_basket) as sum_basket, sum(main_view) as sum_view, sum(add_basket)/sum(main_view) as conv from stat_report' .
    ' where city_id = ' . CITIES_IDS[$argv[1]] .
    ' and date >= "' . $firstBlockDateFrom . '"' .
    ' and  product_id > 0' .
    ' group by product_id' .
    ' having sum_view >= ' . $TotalViews .
    ' and sum_basket > 0' .
    ' order by conv desc' .
    ' limit ' . $firstBlockLimit;
$res = $DB->Query($sql, true);
while ($row = $res->GetNext(true, false)) {
    $arFirstBlockProducts[$row['product_id']] = $row;
}
echo "В БД найдены товары для первого блока. Товаров: " . count($arFirstBlockProducts) . "\n";

// Ограничиваем количество товаров до 12
// Не должны выводиться товары со свойствами "Скрывать на сайте" и "Нет в наличии" в значении "Да", без цены, без фотографий или без активного партнера
$arAvailableEls = [];
$arFilter = [
    'IBLOCK_ID' => $argv[1],
    'ID' => array_keys($arFirstBlockProducts),
    '!=PROPERTY_SKRYVAT_NA_SAYTE_VALUE' => 'Да',
    '!=PROPERTY_NOT_AVAILABLE_VALUE' => 'Да',
    '!PROPERTY_ID_PARTNERS' => false,
    [
        'LOGIC' => 'OR',
        ['!DETAIL_PICTURE' => false,],
        ['!PREVIEW_PICTURE' => false,]
    ],
    // Решили не фильтровать по данному свойству:
    //'=PROPERTY_INDEX_VALUE' => 'Да'
];
$res = \CIBlockElement::GetList([], $arFilter, false, false, ['ID']);
while ($row = $res->Fetch()) {
    $arAvailableEls[$row['ID']] = $row;
}

echo "Ограничиваем количество товаров до 12. Подходят под условия сортировки: " . count($arAvailableEls) . "\n";
$arIntersect = array_intersect_key($arFirstBlockProducts, $arAvailableEls);
$arFirstBlockProducts = array_slice($arIntersect, 0, FIRST_BLOCK_LIMIT, true);

foreach ($arFirstBlockProducts as $val) {
    $propValue = round($val['conv'] * MULTIPLICATOR);
    $productProperties = [
        'INDEX' => PROPERTY_REGION_INDEX_ID[$argv[1]]['true'],
        'NEW_MAIN_PAGE_SORT' => $propValue
    ];
    \CIBlockElement::SetPropertyValuesEx($val['product_id'], $argv[1], $productProperties);
}
echo "Добавили товары для первого блока сортировки. Товаров: " . count($arFirstBlockProducts) . "\n";

// Для сортировки второго блока выбирается от 24 до 36 случайных товаров, изменявшихся за последние 90 дней, но не попавшие в первый блок
$obSecondBlockDate = new DateTime('-90 days');
$secondBlockDateFrom = $obSecondBlockDate->format('d.m.Y H:i:s');
$secondBlockLimit = NEXT_BLOCKS_LIMIT + FIRST_BLOCK_LIMIT - count($arFirstBlockProducts);

$arSecondBlockProducts = setNewMainPageSortValues($argv[1], $secondBlockDateFrom, $arFirstBlockProducts, $secondBlockLimit, 100);
echo "Добавили товары для второго блока сортировки. Товаров: " . count($arSecondBlockProducts) . "\n";

// Для сортировки третьего блока выбирается от 24 до 60 случайных товаров, изменявшихся за последние 180 дней, но не попавшие в первые два блока
$obThirdBlockDate = new DateTime('-180 days');
$thirdBlockDateFrom = $obThirdBlockDate->format('d.m.Y H:i:s');

$arPreviousProducts = $arFirstBlockProducts + $arSecondBlockProducts;
$thirdBlockLimit = 2 * NEXT_BLOCKS_LIMIT + FIRST_BLOCK_LIMIT - count($arPreviousProducts);

$arThirdBlockProducts = setNewMainPageSortValues($argv[1], $thirdBlockDateFrom, $arPreviousProducts, $thirdBlockLimit, 10);
echo "Добавили товары для третьего блока сортировки. Товаров: " . count($arThirdBlockProducts) . "\n";

echo "Конец\n";

function setNewMainPageSortValues($iBlockId, $timestamp, $arPreviousProducts, $blockLimit, $propValue): array
{
    echo "Вызов setNewMainPageSortValues\n";
    $nextBlockProducts = [];
    $arAddedProducts = [];

    $arFilter = [
        'IBLOCK_ID' => $iBlockId,
        '>=TIMESTAMP_X' => $timestamp,
        '!=PROPERTY_SKRYVAT_NA_SAYTE_VALUE' => 'Да',
        '!=PROPERTY_NOT_AVAILABLE_VALUE' => 'Да',
        '!PROPERTY_ID_PARTNERS' => false,
        [
            'LOGIC' => 'OR',
            ['!DETAIL_PICTURE' => false,],
            ['!PREVIEW_PICTURE' => false,]
        ],
        // Решили не фильтровать по данному свойству:
        //'=PROPERTY_INDEX_VALUE' => 'Да'
    ];

    $res = \CIBlockElement::GetList(['TIMESTAMP_X' => 'asc'], $arFilter, false, false, ['ID']);
    while ($row = $res->Fetch()) {
        if (!array_key_exists($row['ID'], $arPreviousProducts)) {
            $nextBlockProducts[] = $row;
        }
    }
    echo "После GetList. Всего: " . count($nextBlockProducts) . "\n";

    if (!empty($nextBlockProducts)) {
        $productProperties = [
            'INDEX' => PROPERTY_REGION_INDEX_ID[$iBlockId]['true'],
            'NEW_MAIN_PAGE_SORT' => $propValue
        ];

        // Если количество товаров, подходящих под условие сортировки больше лимита блока сортировки, то рандомно выбираем из подходящих товаров,
        // иначе берем все
        if (count($nextBlockProducts) > $blockLimit) {
            $randomValues = [];
            $i = 1;
            while ($i <= $blockLimit) {
                $rand = mt_rand(0, count($nextBlockProducts) - 1);
                if (!in_array($rand, $randomValues)) {
                    $randomValues[] = $rand;
                    $i++;
                }
            }

            foreach ($randomValues as $value) {
                \CIBlockElement::SetPropertyValuesEx($nextBlockProducts[$value]['ID'], $iBlockId, $productProperties);
                $arAddedProducts[$nextBlockProducts[$value]['ID']] = $nextBlockProducts[$value];
            }
        } else {
            foreach ($nextBlockProducts as $prod) {
                \CIBlockElement::SetPropertyValuesEx($prod['ID'], $iBlockId, $productProperties);
                $arAddedProducts[$prod['ID']] = $prod;
            }
        }
    }

    return $arAddedProducts;
}