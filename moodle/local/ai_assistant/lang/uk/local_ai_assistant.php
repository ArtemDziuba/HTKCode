<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']   = 'ШІ-асистент курсу';

// Settings
$string['setting_geminikey']      = 'API-ключ Gemini';
$string['setting_geminikey_desc'] = 'Ваш API-ключ Google Gemini. Отримайте його на https://aistudio.google.com/app/apikey';

// Page
$string['createcourse']          = 'Створити новий курс';
$string['choosecreationmethod']  = 'Як ви хочете налаштувати курс?';

// Cards
$string['manualcreation']       = 'Створити вручну';
$string['manualcreation_desc']  = 'Використовуйте стандартний редактор курсів Moodle.';
$string['aicreation']           = 'За допомогою ШІ';
$string['aicreation_desc']      = 'Згенеруйте структуру курсу, тести або завдання за допомогою ШІ.';

// Form
$string['generatecontent']      = 'Генерація навчального контенту';
$string['task']                 = 'Що потрібно згенерувати?';
$string['task_outline']         = 'Структура курсу (план на 4 тижні)';
$string['task_quiz']            = 'Тестові питання (формат Moodle GIFT)';
$string['task_assignment']      = 'Завдання з критеріями оцінювання';
$string['prompt']               = 'Опишіть курс або тему';
$string['prompt_placeholder']   = 'Напр., Вступ до машинного навчання для студентів без попереднього досвіду…';
$string['generate']             = 'Згенерувати';
$string['generating']           = 'Генерується…';
$string['result']               = 'Згенерований контент';
$string['copy']                 = 'Копіювати';
$string['copied']               = 'Скопійовано!';

// Errors
$string['noapikey'] = 'API-ключ Gemini не налаштовано. Зверніться до адміністратора Moodle — налаштування знаходяться в Адміністрування сайту → Плаґіни → ШІ-асистент курсу.';
