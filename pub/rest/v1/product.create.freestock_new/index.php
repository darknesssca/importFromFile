<?php
define("BX_UTF", true);
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", false);
define("BX_BUFFER_USED", true);

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
set_time_limit(0);
ini_set('memory_limit', '512M');
$APPLICATION->IncludeComponent("iu:import.freestock", "", array(
    'IBLOCK_ID' => '3',
    'OFFERS_IBLOCK_ID' => '4'
));

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
