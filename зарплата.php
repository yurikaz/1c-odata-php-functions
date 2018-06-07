<?php
/***********************************************************
 * Расчет зарплаты менеджеров
 * Заказ считается закрытым, если ПОЛНОСТЬЮ отгружен и оплачен.
 * Для расчета в конкретном месяце берутся заказы:
 * ОПЛАЧЕННЫЕ в этом месяце, если отгружены ранее, 
 * и
 * ОТГРУЖЕННЫЕ в этом месяце, если оплачены ранее
 * 
 * Пример: 
 * заказ создан 01.01.2018, 
 * оплачен 1/2 часть 02.02.2018,
 * отгружен 1/2 часть 03.03.2018
 * отгружен 1/2 часть 07.03.2018
 * оплачен 1/2 часть 04.04.2018
 * Заказ засчитан в зарплату за 04 месяц.
 * 
 * Платежи: могут быть наличные и безналичные.
 * В одном платеже может быть сразу несколько заказов, 
 * как частично так и полностью оплаченных.
 * Отгрузки аналогично платежам.
 * 
 * Документы платежей: Document_ПоступлениеБезналичныхДенежныхСредств,
 *                     Document_ПриходныйКассовыйОрдер
 * Документы отгрузок: Document_РеализацияТоваровУслуг
 * 
 **********************************************************/
$installpath = '/home/user/proj';
require $installpath.'/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

include_once $installpath.'/php/00-conf.php';
include_once $installpath.'/php/50-functions.php';

//***************************************************************************
// mongo connect
$mongo = new MongoDB\Client("mongodb://$mongo_user:$mongo_pass@$mongo_serv");
// 1c connect
$client = new Client([
	'base_uri' => "$server1c/$base1c/odata/standard.odata/",
	'timeout'  => 600.0,
]);
//***************************************************************************

//менеджеры
$Менеджеры = getMongoData($mongo,'ut','Catalog_Пользователи');
//контрагенты
$Контрагенты = getMongoData($mongo,'ut','Catalog_Контрагенты');
//Организации
$Организации = getMongoData($mongo,'ut','Catalog_Организации');
//Партнеры
$raw = get1cData($client,$userName, $userAccessKey,'Catalog_Партнеры',
			'Ref_Key,Description,ОсновнойМенеджер_Key',
			''
			);
foreach($raw as $tmp) {
	$Партнеры[$tmp['Ref_Key']]['Description'] = $tmp['Description'];
	$Партнеры[$tmp['Ref_Key']]['ОсновнойМенеджер_Key'] = $tmp['ОсновнойМенеджер_Key'];
}

//прием начальной и конечной даты
// при вызове из консоли
if (php_sapi_name() == "cli") {
	$startdate = @$argv[1];
	$stopdate = @$argv[2];
}
else { // при вызове через GET/POST запрос
	$startdate = @$_REQUEST['startdate'];
	$stopdate = @$_REQUEST['stopdate'];
}
// если даты не заданы, то берем начало текущего месяца и текущую дату
if ((!isset($startdate)) or ($startdate == '')) {
    $startdate = date('d.m.Y',mktime(0, 0, 0, date("m"), date("1"), date("Y")));
}
if ((!isset($stopdate)) or ($stopdate == '')) {
    $stopdate = date('d.m.Y',mktime(23, 59, 59, date("m"), date("d"), date("Y")));
}

$time1 = strtotime($startdate.' 00:00:00');
$time2 = strtotime($stopdate.' 23:59:59');

// список наших контрагентов. заказы на них не идут в зарплату
$nouse_Контрагенты = array( '969a3140-0a1f-11e7-6983-001e62748e39' => 'ИП Иванов Иван Иванович',
                            '4986ea16-2436-11e7-208c-001e62748e39' => 'Рога и копыта ООО',
                            '58353082-c18b-11e4-7299-002590d86530' => 'Сотрудники'
                            );
// готовим массив для сбора инфы по заказам
$Заказы = array();
//******************************************************************************
$raw = get1cData($client,$userName, $userAccessKey,'Document_РеализацияТоваровУслуг',
            'ЗаказКлиента,Date,СуммаДокумента',
            'ЗаказКлиента_Type eq \'StandardODATA.Document_ЗаказКлиента\''
				);
foreach ($raw as $Реализация) {
    if ($Реализация['ЗаказКлиента'] == '00000000-0000-0000-0000-000000000000') { continue; }

    $Заказ_Key = trim($Реализация['ЗаказКлиента']);
    $ДатаРеализации = strtotime(trim($Реализация['Date']));
    $СуммаРеализации = trim($Реализация['СуммаДокумента']);
    
    if (array_key_exists($Заказ_Key,$Заказы)) { 
        //дополняем массив необходимыми значениями
        $Заказы[$Заказ_Key]['ДатаРеализации'][] = $ДатаРеализации;
        $Заказы[$Заказ_Key]['СуммаРеализации'][] = $СуммаРеализации;
        $Заказы[$Заказ_Key]['ОбщаяСуммаРеализации'] += $СуммаРеализации;
        $Заказы[$Заказ_Key]['ПоследняяДатаРеализации'] = max($Заказы[$Заказ_Key]['ДатаРеализации']);
    } else {
        // создаем новое значение массива с необходимыми дополнительными значениями
        $Заказы[$Заказ_Key] = array();
        $Заказы[$Заказ_Key]['ДатаРеализации'][] = $ДатаРеализации;
        $Заказы[$Заказ_Key]['СуммаРеализации'][] = $СуммаРеализации;
        $Заказы[$Заказ_Key]['ПоследняяДатаРеализации'] = $ДатаРеализации;
        $Заказы[$Заказ_Key]['ОбщаяСуммаРеализации'] = $СуммаРеализации;
        
        $Заказы[$Заказ_Key]['ДатаПлатежки'] = array();
        $Заказы[$Заказ_Key]['ПоследняяДатаПлатежки'] = 0;
        $Заказы[$Заказ_Key]['СуммаПлатежки'] = array();
        $Заказы[$Заказ_Key]['ОбщаяСуммаПлатежки'] = 0;
        
        $Заказы[$Заказ_Key]['ДатаЧека'] = array();
        $Заказы[$Заказ_Key]['ПоследняяДатаЧека'] = 0;
        $Заказы[$Заказ_Key]['СуммаЧека'] = array();
        $Заказы[$Заказ_Key]['ОбщаяСуммаЧека'] = 0;
    }
}

//******************************************************************************
$raw = get1cData($client,$userName, $userAccessKey,'Document_ПоступлениеБезналичныхДенежныхСредств',
            'РасшифровкаПлатежа,Date',
                    ''
				);
foreach ($raw as $Платежка) {
    $ДатаПлатежки = strtotime(trim($Платежка['Date']));

    foreach ($Платежка['РасшифровкаПлатежа'] as $РасшифровкаПлатежа) {
        if ($РасшифровкаПлатежа['Заказ_Type'] != 'StandardODATA.Document_ЗаказКлиента') { continue; }
        if ($РасшифровкаПлатежа['Заказ'] == '00000000-0000-0000-0000-000000000000') { continue; }

        $Заказ_Key = trim($РасшифровкаПлатежа['Заказ']);
        
        if (!array_key_exists($Заказ_Key,$Заказы)) { continue; }
        
        $Заказы[$Заказ_Key]['ДатаПлатежки'][] = $ДатаПлатежки;
        $Заказы[$Заказ_Key]['ПоследняяДатаПлатежки'] = max($Заказы[$Заказ_Key]['ДатаПлатежки']);
        
        $Заказы[$Заказ_Key]['СуммаПлатежки'][] = trim($РасшифровкаПлатежа['Сумма']);
        $Заказы[$Заказ_Key]['ОбщаяСуммаПлатежки'] += trim($РасшифровкаПлатежа['Сумма']);
    }
}

//******************************************************************************
$raw = get1cData($client,$userName, $userAccessKey,'Document_ПриходныйКассовыйОрдер',
			'РасшифровкаПлатежа,Date',
			''
				);
foreach ($raw as $КассовыйЧек) {
    $ДатаЧека = strtotime(trim($КассовыйЧек['Date']));
    
    foreach ($КассовыйЧек['РасшифровкаПлатежа'] as $РасшифровкаПлатежа) {
        if ($РасшифровкаПлатежа['Заказ_Type'] != 'StandardODATA.Document_ЗаказКлиента') { continue; }
        if ($РасшифровкаПлатежа['Заказ'] == '00000000-0000-0000-0000-000000000000') { continue; }
        
        $Заказ_Key = trim($РасшифровкаПлатежа['Заказ']);
        
        if (!array_key_exists($Заказ_Key,$Заказы)) { continue; }
        
        $Заказы[$Заказ_Key]['ДатаЧека'][] = $ДатаЧека;
        $Заказы[$Заказ_Key]['ПоследняяДатаЧека'] = max($Заказы[$Заказ_Key]['ДатаЧека']);
        
        $Заказы[$Заказ_Key]['СуммаЧека'][] = trim($РасшифровкаПлатежа['Сумма']);
        $Заказы[$Заказ_Key]['ОбщаяСуммаЧека'] += trim($РасшифровкаПлатежа['Сумма']);
    }
}

//******************************************************************************
$begining = date('Y-m-d\TH:i:s',mktime(0, 0, 0, date("m"), date("d"), date("Y")-1)); //год назад полночь сегодня
$tasks = get1cData($client,$userName, $userAccessKey,'Document_ЗаказКлиента',
								'Ref_Key,Number,СуммаДокумента,ДатаОтгрузки,Менеджер_Key,Контрагент_Key,Организация_Key,Партнер_Key,Date',
                                'Date gt datetime\''.$begining.'\''
								);
foreach ($tasks as $single_task) {
    $Заказ_Key = trim($single_task['Ref_Key']);
    if (!array_key_exists($Заказ_Key,$Заказы)) { continue; }
    if (array_key_exists(trim($single_task['Контрагент_Key']),$nouse_Контрагенты)) { continue; }
    
    $Заказы[$Заказ_Key]['Number'] = trim($single_task['Number']);
    $Заказы[$Заказ_Key]['СуммаДокумента'] = trim($single_task['СуммаДокумента']);
    $Заказы[$Заказ_Key]['ДатаЗаказа'] = strtotime(trim($single_task['Date']));
    
    $Заказы[$Заказ_Key]['Менеджер_Key'] = $Менеджеры[$single_task['Менеджер_Key']];
    
    $Партнер_Key = $Партнеры[$single_task['Партнер_Key']]['ОсновнойМенеджер_Key'];
            if (!array_key_exists($Партнер_Key,$Менеджеры)) {
                $Заказы[$Заказ_Key]['Менеджер_клиента'] = 'Нет';
            } else {
                 $Заказы[$Заказ_Key]['Менеджер_клиента'] = $Менеджеры[$Партнер_Key];
            }

    $Заказы[$Заказ_Key]['Контрагент_Key'] = $Контрагенты[$single_task['Контрагент_Key']];
    $Заказы[$Заказ_Key]['Партнер_Key'] = $Партнеры[$single_task['Партнер_Key']];
    $Заказы[$Заказ_Key]['Организация_Key'] = $Организации[trim($single_task['Организация_Key'])];
}
//******************************************************************************
$output = '<th>Номер Заказа</th><th>Дата</th><th>Сумма заказа</th>'.
            '<th style="width:  16%">Клиент</th>'.
            '<th style="width:  16%">Организация</th>'.
            '<th>Реализации</th><th>Оплаты</th><th>Дней прошло</th>'.
            '<th>Менеджер заказа</th><th>Менеджер клиента</th>'.PHP_EOL;
$ordercount = 0;
foreach ($Заказы as $Ref_Key => $Заказ) {
    
    if ( !array_key_exists('СуммаДокумента',$Заказ) ) { continue; } // если нет отгрузки, пропускаем
    if ( $Заказ['ПоследняяДатаРеализации'] == 0 ) { continue; } // если нет отгрузки, пропускаем
    if (( $Заказ['ПоследняяДатаПлатежки'] == 0 )
        and ( $Заказ['ПоследняяДатаЧека'] == 0 )) { continue; } //если нет оплаты нал/безнал, пропускаем

    
    $ВсяСуммаОплаты = $Заказ['ОбщаяСуммаПлатежки'] + $Заказ['ОбщаяСуммаЧека'];
    
    if ($ВсяСуммаОплаты >= $Заказ['СуммаДокумента']) {
        $ПоследняяДата = max($Заказ['ПоследняяДатаРеализации'],$Заказ['ПоследняяДатаПлатежки'],$Заказ['ПоследняяДатаЧека']);
        
        if (($ПоследняяДата < $time2) and ($ПоследняяДата >= $time1)) {

            // собираем отгрузки
            $Реализации = '<ol>';
            foreach ($Заказ['ДатаРеализации'] as $key => $ДатаРеализации) {
                $Реализации .= '<li>'.date('d.m.Y',$ДатаРеализации).'&nbsp;';
                $Реализации .= $Заказ['СуммаРеализации'][$key].'</li>';
            }
            $Реализации .= '</ol>';
            
            // собираем безнал платежки
            $ОплатыБН = '<ol>';
            foreach ($Заказ['ДатаПлатежки'] as $key => $ДатаОплаты) {
                $ОплатыБН .= '<li>'.date('d.m.Y',$ДатаОплаты).'&nbsp;';
                $ОплатыБН .= 'б: '.$Заказ['СуммаПлатежки'][$key].'</li>';
            }
            $ОплатыБН .= '</ol>';
            
            //собираем наличные платежы
            $ОплатыНал = '<ol>';
            foreach ($Заказ['ДатаЧека'] as $key => $ДатаОплаты) {
                $ОплатыНал .= '<li>'.date('d.m.Y',$ДатаОплаты).'&nbsp;';
                $ОплатыНал .= 'н: '.$Заказ['СуммаЧека'][$key].'</li>';
            }
            $ОплатыНал .= '</ol>';
            
	    //менеджер выполнивший заказ
            $managerFIO = explode(' ',$Заказ['Менеджер_Key']);
            if (count($managerFIO)>1) {
                $order_manager = $managerFIO[0].$managerFIO[1][0].$managerFIO[1][1].$managerFIO[2][0].$managerFIO[2][1];
            } else {
                $order_manager = $Заказ['Менеджер_Key'];
            }
            // менеджер клиента
            $managerFIO = explode(' ',$Заказ['Менеджер_клиента']);
            if (count($managerFIO)>1) {
                $client_manager = $managerFIO[0].$managerFIO[1][0].$managerFIO[1][1].$managerFIO[2][0].$managerFIO[2][1];
            } else {
                $client_manager = $Заказ['Менеджер_Key'];
            }
            
            $output .= '<tr>';
            $output .= '<td>'.$Заказ['Number'].'</td>';
            $output .= '<td>'.date('d.m.Y',$Заказ['ДатаЗаказа']).'</td>';
            $output .= '<td>'.str_replace('.',',',$Заказ['СуммаДокумента']).'</td>';
            $output .= '<td>'.$Заказ['Контрагент_Key'].'</td>';
            $output .= '<td>'.$Заказ['Организация_Key'].'</td>';
            $output .= '<td>'.$Реализации.'</td>';
            $output .= '<td>'.$ОплатыБН.$ОплатыНал.'</td>';
            $deltatime = round(($ПоследняяДата - $Заказ['ДатаЗаказа'])/60/60/24);
            $output .= '<td>'.$deltatime.'</td>';
            $output .= '<td>'.$order_manager.'</td>';
            $output .= '<td>'.$client_manager.'</td>'.PHP_EOL;
            $output .= '</tr>';
            
            $ordercount++;
        }
    }
    
}
$htmlout = 'Всего заказов: '.$ordercount.'<br>'.PHP_EOL;
$htmlout .= '<table class="table table-hover">'.PHP_EOL;
$htmlout .= $output;
$htmlout .= '</table>'.PHP_EOL;

//возвращаем страницу с таблицей
echo '<h1>Отчет по заказам с '.$startdate.' по '.$stopdate.' включительно</h1>'.PHP_EOL;
echo 'С 00:00 по 23:59<br>'.PHP_EOL;
echo 'б = безналичная оплата<br>н = наличная оплата<br>'.PHP_EOL;
echo $htmlout;
