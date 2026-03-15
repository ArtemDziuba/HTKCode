<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Calls the Gemini API and returns [string $result, string $error].
 */
function local_ai_assistant_call_gemini(string $task, string $prompt, string $apikey): array {
    $instructions = [
        'outline'    => 'Ти — досвідчений методист. Створи структуру курсу на 4 тижні на основі наданої теми. Відповідай українською мовою.',
        'quiz'       => 'Створи 3 тестові питання. Виводь їх ТІЛЬКИ у форматі Moodle GIFT українською мовою. Без Markdown, без пояснень.',
        'assignment' => 'Створи детальне практичне завдання з критеріями оцінювання на основі запиту. Відповідай українською мовою.',
        'rewrite'    => 'Ти — досвідчений методист. Перероби наданий документ: покращ структуру, чіткість та відповідність сучасним академічним стандартам. Збережи основний зміст, але зроби його більш професійним. Відповідай українською мовою.',
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

/**
 * Extracts plain text from an uploaded file (DOCX, PDF, or TXT).
 * Returns [string $text, string $error].
 */
function local_ai_assistant_extract_text(array $file): array {
    $tmppath  = $file['tmp_name'];
    $origname = $file['name'];
    $ext      = strtolower(pathinfo($origname, PATHINFO_EXTENSION));

    if (!in_array($ext, ['docx', 'pdf', 'txt'], true)) {
        return ['', 'Unsupported file type. Please upload a DOCX, PDF, or TXT file.'];
    }

    if ($ext === 'txt') {
        $text = file_get_contents($tmppath);
        if ($text === false) {
            return ['', 'Could not read the uploaded TXT file.'];
        }
        return [trim($text), ''];
    }

    if ($ext === 'docx') {
        return local_ai_assistant_extract_docx($tmppath);
    }

    if ($ext === 'pdf') {
        return local_ai_assistant_extract_pdf($tmppath);
    }

    return ['', 'Unknown error during file extraction.'];
}

/**
 * Extracts text from a DOCX file using PHP's ZipArchive.
 * DOCX is just a ZIP containing word/document.xml.
 */
function local_ai_assistant_extract_docx(string $path): array {
    if (!class_exists('ZipArchive')) {
        return ['', 'ZipArchive PHP extension is not available on this server.'];
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['', 'Could not open DOCX file. It may be corrupted.'];
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xml === false) {
        return ['', 'Could not read word/document.xml from the DOCX file.'];
    }

    // Strip XML tags and decode entities.
    // Insert spaces between paragraph/run tags so words don't merge.
    $xml  = preg_replace('/<\/w:p>/', "\n", $xml);
    $xml  = preg_replace('/<\/w:r>/', ' ', $xml);
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);          // collapse spaces
    $text = preg_replace('/\n{3,}/', "\n\n", $text);       // collapse blank lines
    $text = trim($text);

    if ($text === '') {
        return ['', 'The DOCX file appears to be empty or contains no readable text.'];
    }

    return [$text, ''];
}

/**
 * Extracts text from a PDF using the pdftotext CLI tool (poppler-utils).
 * Falls back with a helpful message if not available.
 */
function local_ai_assistant_extract_pdf(string $path): array {
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        return ['', 'PDF parsing library not found. Please try DOCX or TXT instead.'];
    }
    require_once($autoload);

    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf    = $parser->parseFile($path);
        $text   = $pdf->getText();
        if (trim($text) === '') {
            return ['', 'Could not extract text from this PDF. It may be a scanned image. Please try DOCX or TXT instead.'];
        }
        return [trim($text), ''];
    } catch (\Exception $e) {
        return ['', 'PDF parsing error: ' . $e->getMessage()];
    }
}