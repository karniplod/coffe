<?php
/**
 * Пресеты SendIt для сайта Fastcup. Файл кладёт установщик в core/components/sendit/presets/fastcup.inc.php
 * и прописывает его в системной настройке si_path_to_presets.
 *
 * Стандартные пресеты SendIt (защита от спама в 'default') подключаются из sendit.inc.php, наш — 'fastcup'.
 * Почту для заявок меняйте в настройке fc_email и запустите установщик ещё раз — он перепишет адрес здесь.
 * Параметры FormIt: https://docs.modx.com/3.x/en/extras/formit · SendIt: https://docs.modx.pro/components/sendit/
 */
$base = [];
$std = __DIR__ . '/sendit.inc.php';
if (is_file($std)) $base = (array)include $std;

return array_merge($base, [
    'fastcup' => [
        'extends'      => 'default',                     // защита от спама из стандартного пресета SendIt
        'hooks'        => 'FormItSaveForm,email',        // сначала сохранить заявку («Приложения → FormIt»), потом письмо: не ушло письмо — заявка не потеряется
        'emailTo'      => '%%EMAIL_TO%%',
        'emailFrom'    => '%%EMAIL_FROM%%',              // ящик на домене сайта, иначе письма уходят в спам
        'emailFromName'=> 'Сайт Fastcup',
        'emailSubject' => 'Заявка с сайта Fastcup',
        'emailTpl'     => 'fc.emailTpl',
        'formName'     => 'Заявка с сайта',
        'formFields'   => 'name,phone,topic,message,page',
        'fieldNames'   => 'name==Имя,phone==Телефон,topic==Что интересует,message==Комментарий,page==Страница',
        'validate'     => 'name:required,phone:required:minLength=^10^',
        'name.vTextRequired'   => 'Укажите имя, чтобы мы знали, как к вам обращаться',
        'phone.vTextRequired'  => 'Укажите телефон, чтобы мы могли перезвонить',
        'phone.vTextMinLength' => 'Проверьте телефон: нужно минимум 10 цифр',
        'clearFieldsOnSuccess'   => 1,
        'successMessage'         => 'Заявка отправлена. Перезвоним в течение рабочего дня',
        'validationErrorMessage' => 'Проверьте поля формы',
    ],
]);
