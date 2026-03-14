<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Calls the Gemini API and returns [string $result, string $error].
 *
 * @param  string $task   One of: outline | quiz | assignment
 * @param  string $prompt The user-supplied prompt text
 * @param  string $apikey Gemini API key from plugin settings
 * @return array{0: string, 1: string}  [$result_text, $error_message]
 */
function local_ai_assistant_call_gemini(string $task, string $prompt, string $apikey): array {
    $instructions = [
        'outline'    => 'Ти — досвідчений методист. Створи структуру курсу на 4 тижні на основі наданої теми. Відповідай українською мовою.',
        'quiz'       => 'Створи 3 тестові питання. Виводь їх ТІЛЬКИ у форматі Moodle GIFT українською мовою. Без Markdown, без пояснень.',
        'assignment' => 'Створи детальне практичне завдання з критеріями оцінювання на основі запиту. Відповідай українською мовою.',
    ];

    if (!isset($instructions[$task])) {
        return ['', 'Unknown task: ' . s($task)];
    }

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key='
        . urlencode($apikey);

    $body = json_encode([
        'system_instruction' => [
            'parts' => [['text' => $instructions[$task]]],
        ],
        'contents' => [
            ['parts' => [['text' => $prompt]]],
        ],
        'generationConfig' => [
            'temperature' => $task === 'quiz' ? 0.2 : 0.7,
        ],
    ]);

    // Use Moodle's curl wrapper so proxy settings are respected.
    $curl = new curl();
    $curl->setHeader(['Content-Type: application/json']);
    $raw = $curl->post($endpoint, $body);

    if ($curl->get_errno()) {
        return ['', 'cURL error: ' . $curl->error];
    }

    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['', 'Invalid JSON response from Gemini.'];
    }

    if (!empty($data['error'])) {
        $msg = $data['error']['message'] ?? 'Unknown API error';
        return ['', 'Gemini API error: ' . $msg];
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if ($text === '') {
        return ['', 'Gemini returned an empty response.'];
    }

    return [$text, ''];
}
