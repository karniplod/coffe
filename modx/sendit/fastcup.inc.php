<?php
/**
 * Пресеты SendIt для сайта Fastcup. Файл кладёт установщик в core/components/sendit/presets/fastcup.inc.php
 * и прописывает его в системной настройке si_path_to_presets.
 *
 * Стандартные пресеты SendIt (защита от спама в 'default') подключаются из sendit.inc.php, наш — 'fastcup'.
 * Почта для заявок берётся из настройки fc_email («Конфигурация» → «Контакты») в момент отправки — поменяли там, и заявки идут на новую.
 * Адрес ниже (%%EMAIL_TO%% при установке) — запасной, если настройку не прочитать.
 * Параметры FormIt: https://docs.modx.com/3.x/en/extras/formit · SendIt: https://docs.modx.pro/components/sendit/
 */
// почта для заявок — из ClientConfig / системной настройки fc_email прямо сейчас, а не та, что была при установке
$mx = $GLOBALS['modx'] ?? null;
$emailTo = ($mx && trim((string)$mx->getOption('fc_email'))) ? trim((string)$mx->getOption('fc_email')) : '%%EMAIL_TO%%';

$base = [];
$std = __DIR__ . '/sendit.inc.php';
if (is_file($std)) $base = (array)include $std;

return array_merge($base, [
    'fastcup' => [
        'extends'      => 'default',                     // защита от спама из стандартного пресета SendIt
        'hooks'        => 'FormItSaveForm,email',        // сначала сохранить заявку («Приложения → FormIt»), потом письмо: не ушло письмо — заявка не потеряется
        'emailTo'      => $emailTo,
        'emailFrom'    => '%%EMAIL_FROM%%',              // ящик на домене сайта, иначе письма уходят в спам
        'emailFromName'=> 'Сайт Fastcup',
        'emailSubject' => 'Заявка с сайта Fastcup',
        'emailTpl'     => 'fc.emailTpl',
        'formName'     => 'Заявка с сайта',
        'formFields'   => 'name,phone,topic,message,page',
        'fieldNames'   => 'name==Имя,phone==Телефон,topic==Что интересует,message==Комментарий,page==Страница',
        'validate'     => 'name:required,phone:required:minLength=^10^,agree:checkbox:required',   // agree — согласие на обработку ПД (152-ФЗ); :checkbox — неотмеченную галочку браузер не отправляет вовсе
        'agree.vTextRequired'  => 'Отметьте согласие на обработку персональных данных',
        'name.vTextRequired'   => 'Укажите имя, чтобы мы знали, как к вам обращаться',
        'phone.vTextRequired'  => 'Укажите телефон, чтобы мы могли перезвонить',
        'phone.vTextMinLength' => 'Проверьте телефон: нужно минимум 10 цифр',
        'clearFieldsOnSuccess'   => 1,
        'successMessage'         => 'Заявка отправлена. Перезвоним в течение рабочего дня',
        'validationErrorMessage' => 'Проверьте поля формы',
    ],
]);
