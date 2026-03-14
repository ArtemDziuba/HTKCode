<?php
namespace local_ai_assistant\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

class ask extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'prompt' => new external_value(PARAM_TEXT, 'The lecturer prompt', VALUE_REQUIRED),
        ]);
    }

    public static function execute(string $prompt): array {
        // Validate parameters and context.
        ['prompt' => $prompt] = self::validate_parameters(
            self::execute_parameters(),
            ['prompt' => $prompt]
        );

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/ai_assistant:use', $context);

        // Check API key is configured.
        $apikey = get_config('local_ai_assistant', 'apikey');
        if (empty($apikey)) {
            throw new \moodle_exception('noapikey', 'local_ai_assistant');
        }

        $model = get_config('local_ai_assistant', 'model') ?: 'claude-sonnet-4-20250514';

        $response = self::call_claude($apikey, $model, trim($prompt));

        return ['response' => $response];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'response' => new external_value(PARAM_RAW, 'Generated text from Claude'),
        ]);
    }

    // -------------------------------------------------------------------------

    private static function call_claude(string $apikey, string $model, string $prompt): string {
        $url = 'https://api.anthropic.com/v1/messages';

        $system = <<<SYSTEM
Ти — академічний ШІ-асистент, який допомагає університетським викладачам створювати навчальні курси в Moodle.
Твоя відповідь має бути:
- Написана українською мовою
- Чіткою та структурованою
- Практичною та готовою до використання
- Без зайвих пояснень — одразу до суті

Коли викладач описує курс, запропонуй:
1. Назву курсу
2. Короткий опис (2–3 речення)
3. Перелік модулів/тем (5–8 штук) з коротким описом кожного
4. Очікувані результати навчання (3–5 пунктів)
SYSTEM;

        $payload = json_encode([
            'model'      => $model,
            'max_tokens' => 1500,
            'system'     => $system,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apikey,
                'anthropic-version: 2023-06-01',
            ],
        ]);

        $result   = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlerr  = curl_error($ch);
        curl_close($ch);

        if ($curlerr) {
            throw new \moodle_exception('apierror', 'local_ai_assistant', '', 0);
        }

        if ($httpcode !== 200) {
            throw new \moodle_exception('apierror', 'local_ai_assistant', '', $httpcode);
        }

        $decoded = json_decode($result, true);

        return $decoded['content'][0]['text'] ?? '';
    }
}
