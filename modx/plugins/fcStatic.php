<?php
/**
 * fcStatic — держит js/points.js и js/config.js в соответствии с админкой (их читают карта и приложение /app/).
 * @events OnDocFormSave, OnDocFormDelete, OnResourceUndelete, OnResourceSort, OnEmptyTrash, OnSiteRefresh, OnLoadWebDocument
 *
 * Сохранили, удалили или передвинули страницу, очистили кэш — пересоздаём оба файла (сниппет fcWriteStatic);
 * если это напиток или точка — сбрасываем кэш страниц, чтобы главная сразу показала новое.
 * Открыли страницу сайта — сверяем контакты с прошлым разом (без запросов к базе): поменяли их в ClientConfig — пересоздаём config.js.
 */
switch ($modx->event->name) {
    case 'OnLoadWebDocument':
        $keys = ['fc_phone', 'fc_phone_raw', 'fc_telegram_url', 'fc_max_url', 'fc_email', 'fc_map_center'];
        $sign = md5(implode('|', array_map(function ($k) use ($modx) { return (string)$modx->getOption($k); }, $keys)));
        if ($modx->cacheManager->get('fc_config_sign') === $sign) break;
        $modx->runSnippet('fcWriteStatic', ['what' => 'config']);
        $modx->cacheManager->set('fc_config_sign', $sign, 0);
        break;
    default:
        $modx->runSnippet('fcWriteStatic', ['what' => 'all']);
        $modx->cacheManager->delete('fc_config_sign');
        // напиток или точка изменились — главная показывает их из кэша: сбрасываем кэш страниц, иначе новое не появится на сайте
        $res = $modx->event->params['resource'] ?? null;
        $parent = $res ? (int)$res->get('parent') : 0;
        $ours = [(int)$modx->getOption('fc_menu_id'), (int)$modx->getOption('fc_points_id')];
        if ($modx->event->name !== 'OnSiteRefresh' && (!$res || in_array($parent, $ours, true) || in_array((int)$res->get('id'), $ours, true))) {
            $modx->cacheManager->refresh(['resource' => []]);
        }
}
