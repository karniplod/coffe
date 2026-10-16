<?php
/**
 * fcCount — сколько опубликованных страниц в разделе, со словом в нужной форме: «14 напитков», «21 напиток».
 * &parent — ID раздела, &words — три формы через «|»: «напиток|напитка|напитков».
 */
use MODX\Revolution\modResource;

$parent = (int)$modx->getOption('parent', $scriptProperties, 0);
$words = explode('|', $modx->getOption('words', $scriptProperties, 'напиток|напитка|напитков'));
$n = $parent ? $modx->getCount(modResource::class, ['parent' => $parent, 'published' => 1, 'deleted' => 0]) : 0;

$m10 = $n % 10; $m100 = $n % 100;
$k = ($m10 === 1 && $m100 !== 11) ? 0 : (($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) ? 1 : 2);
return $n . ' ' . ($words[$k] ?? end($words));
