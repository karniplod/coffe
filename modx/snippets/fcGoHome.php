<?php
/**
 * fcGoHome — служебные страницы (разделы «Меню» и «Точки», отдельные напитки и точки) нужны только админке:
 * на сайте они выводятся на главной. Кто откроет их адрес — перенаправляем на главную (301).
 */
$url = $modx->makeUrl((int)$modx->getOption('site_start'), '', '', 'full');
$modx->sendRedirect($url ?: $modx->getOption('site_url'), ['responseCode' => 'HTTP/1.1 301 Moved Permanently']);
return '';
