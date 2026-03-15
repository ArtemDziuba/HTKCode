<?php
/**
 * Course creation choice page.
 * Shown instead of /course/edit.php so lecturers can pick between
 * the standard Moodle editor or AI-assisted content generation.
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

$category = optional_param('category', 0, PARAM_INT);

$context = $category
    ? context_coursecat::instance($category)
    : context_system::instance();

require_capability('moodle/course:create', $context);

$PAGE->set_url('/local/ai_assistant/create_course.php', $category ? ['category' => $category] : []);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('createcourse', 'local_ai_assistant'));
$PAGE->set_heading(get_string('createcourse', 'local_ai_assistant'));

// ── State variables ──────────────────────────────────────────────────────────
$ai_result          = '';
$ai_error           = '';
$extracted_info     = '';   // "File X uploaded — N chars"
$detection_message  = '';   // Feedback about syllabus detection
$detection_badge    = '';   // 'found' | 'generated' | ''
$show_ai            = optional_param('show_ai', 0, PARAM_INT);
$submitted_task     = optional_param('ai_task', 'outline', PARAM_ALPHA);
$submitted_prompt   = optional_param('ai_prompt', '', PARAM_TEXT);
$course_id          = 0;

// ── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_task'])) {

    // --- Session key check ---
    try {
        require_sesskey();
    } catch (Exception $e) {
        $ai_error = 'Sesskey error: ' . $e->getMessage();
    }

    if (empty($ai_error)) {
        $show_ai = 1;

        $apikey = get_config('local_ai_assistant', 'claudekey')
            ?: get_config('local_ai_assistant', 'geminikey')
            ?: get_config('local_ai_assistant', 'apikey')
            ?: get_config('block_ai_assistant',  'claudekey')
            ?: get_config('block_ai_assistant',  'geminikey')
            ?: get_config('block_ai_assistant',  'apikey');

        if (empty($apikey)) {
            $stored = $DB->get_records_menu('config_plugins',
                ['plugin' => 'local_ai_assistant'], 'name', 'name, value');
            $stored2 = $DB->get_records_menu('config_plugins',
                ['plugin' => 'block_ai_assistant'], 'name', 'name, value');
            $debug_keys = array_keys($stored + $stored2);
            $hint = empty($debug_keys)
                ? 'No config found for local_ai_assistant or block_ai_assistant.'
                : 'Found config keys: ' . implode(', ', $debug_keys);
            $ai_error = get_string('noapikey', 'local_ai_assistant') . ' [Debug: ' . $hint . ']';
        }
    }

    if (empty($ai_error)) {

        // Normalise $_FILES['ai_file'] (single or multiple) into a flat list.
        $uploaded_files = [];
        if (!empty($_FILES['ai_file']['name'])) {
            $names  = (array) $_FILES['ai_file']['name'];
            $tmps   = (array) $_FILES['ai_file']['tmp_name'];
            $errors = (array) $_FILES['ai_file']['error'];
            $sizes  = (array) $_FILES['ai_file']['size'];
            foreach ($names as $i => $name) {
                if (!empty($name) && ($errors[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $uploaded_files[] = [
                        'name'     => $name,
                        'tmp_name' => $tmps[$i],
                        'error'    => $errors[$i],
                        'size'     => $sizes[$i],
                    ];
                }
            }
        }
        $file_present = count($uploaded_files) > 0;

        // Tasks that benefit from syllabus detection (not quiz / assignment)
        $is_structural_task = in_array($submitted_task, ['outline', 'rewrite'], true);

        // ====================================================================
        // BRANCH A – File(s) uploaded
        // ====================================================================
        if ($file_present) {

            // 1. Extract text from every uploaded file; concatenate into one block.
            $file_prefix    = '';
            $filenames_ok   = [];
            $filenames_fail = [];

            foreach ($uploaded_files as $ufile) {
                [$file_text, $file_error] = local_ai_assistant_extract_text($ufile);
                $fname = clean_filename($ufile['name']);
                if (!empty($file_error)) {
                    $filenames_fail[] = $fname . ' (' . $file_error . ')';
                } else {
                    $filenames_ok[] = $fname . ' — ' . number_format(mb_strlen($file_text)) . ' chars';
                    $file_prefix   .= "=== Uploaded document: {$fname} ===\n{$file_text}\n=== End of document ===\n\n";
                }
            }

            if (!empty($filenames_fail) && empty($filenames_ok)) {
                // Every file failed — surface the errors
                $ai_error = 'Could not read uploaded file(s): ' . implode('; ', $filenames_fail);
            }

            if (empty($ai_error)) {
                $extracted_info = count($filenames_ok) . ' file(s) uploaded: ' . implode(', ', $filenames_ok);
                if (!empty($filenames_fail)) {
                    $extracted_info .= '. Skipped: ' . implode(', ', $filenames_fail);
                }

                // $file_text used for syllabus detection = concatenation of all files
                $file_text = $file_prefix;

                // ── A1: Structural task → try syllabus detection first ─────
                if ($is_structural_task) {

                    $detection = local_ai_assistant_detect_syllabus_json($file_text, $apikey);

                    // ── A1a: Syllabus found in the document ────────────────
                    if (!empty($detection['is_syllabus']) && !empty($detection['weeks'])) {

                        $detection_badge   = 'found';
                        $detection_message = '✅ Силабус виявлено у документі — структуру курсу взято безпосередньо з нього.';

                        // Extract week titles (topics are shown in the result text but
                        // stored as section names in the course)
                        $week_titles = [];
                        foreach ($detection['weeks'] as $w) {
                            $title = trim($w['title'] ?? '');
                            if ($title !== '') {
                                $week_titles[] = $title;
                            }
                        }

                        // Determine course name: document > prompt > fallback
                        $cname = '';
                        if (!empty(trim($detection['course_name'] ?? ''))) {
                            $cname = trim($detection['course_name']);
                        } elseif (!empty(trim($submitted_prompt))) {
                            $cname = trim($submitted_prompt);
                        } else {
                            $cname = pathinfo(clean_filename($uploaded_files[0]['name']), PATHINFO_FILENAME);
                        }

                        // Create the Moodle course
                        $course_id = local_ai_assistant_create_moodle_course($cname, $week_titles);

                        // Attach every uploaded file to section 0
                        if ($course_id > 0) {
                            foreach ($uploaded_files as $ufile) {
                                local_ai_assistant_attach_file_to_course($course_id, $ufile, 0);
                            }
                            // Attach as formatted DOCX
                            local_ai_assistant_attach_syllabus_to_course(
                                $course_id,
                                local_ai_assistant_clean_syllabus_text($ai_result)
                            );
                        }

                        // Build a human-readable result to display
                        $ai_result  = "📋 Назва курсу: {$cname}\n\n";
                        foreach ($detection['weeks'] as $i => $w) {
                            $week_num = $i + 1;
                            $title    = $w['title'] ?? '';
                            $ai_result .= "Тиждень {$week_num}: {$title}\n";
                            if (!empty($w['topics']) && is_array($w['topics'])) {
                                foreach ($w['topics'] as $topic) {
                                    $ai_result .= "  • {$topic}\n";
                                }
                            }
                            $ai_result .= "\n";
                        }

                    // ── A1b: No syllabus found → generate one from document ─
                    } else {

                        $detection_badge   = 'generated';
                        $detection_message = '⚠️ Силабус не знайдено у документі — генерую структуру курсу на основі його вмісту.';

                        // Build a rich prompt: document text + optional extra instruction
                        $generate_prompt = $file_prefix;
                        if (!empty(trim($submitted_prompt))) {
                            $generate_prompt .= "Додаткові інструкції: " . trim($submitted_prompt);
                        } else {
                            $generate_prompt .= "На основі цього документа створи структуру курсу на 4 тижні.";
                        }

                        [$ai_result, $ai_error] = local_ai_assistant_call_gemini('outline', $generate_prompt, $apikey);

                        if (!empty($ai_result) && empty($ai_error)) {
                            $ai_result   = local_ai_assistant_clean_syllabus_text($ai_result);
                            $week_titles = local_ai_assistant_parse_weeks($ai_result);
                            $raw_name    = !empty(trim($submitted_prompt))
                                ? trim($submitted_prompt)
                                : pathinfo(clean_filename($uploaded_files[0]['name']), PATHINFO_FILENAME);
                            $cname       = local_ai_assistant_extract_course_name($raw_name, $apikey);
                            $course_id   = local_ai_assistant_create_moodle_course($cname, $week_titles);

                            if ($course_id > 0) {
                                foreach ($uploaded_files as $ufile) {
                                    local_ai_assistant_attach_file_to_course($course_id, $ufile, 0);
                                }
                                local_ai_assistant_attach_syllabus_to_course($course_id, $ai_result);
                            }
                        }
                    }

                // ── A2: Non-structural task (quiz / assignment) + file ─────────
                } else {
                    $final_prompt = $file_prefix;
                    if (!empty(trim($submitted_prompt))) {
                        $final_prompt .= "Додаткові інструкції: " . trim($submitted_prompt);
                    } else {
                        $final_prompt .= "Обробіть наведений вище документ згідно з обраним завданням.";
                    }

                    [$ai_result, $ai_error] = local_ai_assistant_call_gemini($submitted_task, $final_prompt, $apikey);
                }
            }

        // ====================================================================
        // BRANCH B – Prompt only (no file)
        // ====================================================================
        } else {
            $final_prompt = trim($submitted_prompt);

            if (empty($final_prompt)) {
                $ai_error = 'Будь ласка, введіть промпт або завантажте файл.';
            } else {
                [$ai_result, $ai_error] = local_ai_assistant_call_gemini($submitted_task, $final_prompt, $apikey);

                if (!empty($ai_result) && empty($ai_error) && $submitted_task === 'outline') {
                    $week_titles = local_ai_assistant_parse_weeks($ai_result);
                    $cname       = local_ai_assistant_extract_course_name(trim($submitted_prompt), $apikey);
                    $course_id   = local_ai_assistant_create_moodle_course($cname, $week_titles);
                    if ($course_id > 0) {
                        $ai_result = local_ai_assistant_clean_syllabus_text($ai_result);
                        local_ai_assistant_attach_syllabus_to_course($course_id, $ai_result);
                    }
                }
            }
        }
    }
}

// ── Build URLs ───────────────────────────────────────────────────────────────
$manual_params = ['direct' => 1];
if ($category) {
    $manual_params['category'] = $category;
}
$manual_url = new moodle_url('/course/edit.php', $manual_params);
$sesskey    = sesskey();
$action_url = (new moodle_url('/local/ai_assistant/create_course.php',
    $category ? ['category' => $category] : []))->out(false);

// ── Output ───────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
?>

<style>
.aia-choice-wrapper {
    display: flex;
    gap: 1.5rem;
    margin: 2rem 0;
    flex-wrap: wrap;
}
.aia-card {
    flex: 1 1 260px;
    border: 2px solid #dee2e6;
    border-radius: .75rem;
    padding: 2.5rem 2rem;
    text-align: center;
    cursor: pointer;
    transition: border-color .2s, box-shadow .2s;
    background: #fff;
    text-decoration: none !important;
    color: inherit !important;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .75rem;
}
.aia-card:hover { border-color: #0f6cbf; box-shadow: 0 4px 16px rgba(15,108,191,.12); }
.aia-card.aia-card--active { border-color: #0f6cbf; background: #f0f6ff; }
.aia-card .aia-icon { font-size: 2.8rem; line-height: 1; }
.aia-card h3 { margin: 0; font-size: 1.25rem; }
.aia-card p  { color: #6c757d; margin: 0; font-size: .9rem; }

#aia-panel {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: .75rem;
    padding: 1.75rem;
    margin-top: .5rem;
}
#aia-panel select,
#aia-panel textarea { border-radius: .5rem; }
#aia-panel textarea { resize: vertical; min-height: 100px; }

.aia-file-zone {
    border: 2px dashed #adb5bd;
    border-radius: .5rem;
    padding: 1.5rem;
    text-align: center;
    background: #fff;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    position: relative;
}
.aia-file-zone:hover,
.aia-file-zone.dragover { border-color: #0f6cbf; background: #f0f6ff; }
.aia-file-zone input[type=file] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.aia-file-zone .aia-file-icon { font-size: 2rem; display: block; margin-bottom: .5rem; }
.aia-file-name {
    margin-top: .5rem;
    font-size: .85rem;
    color: #0f6cbf;
    font-weight: 500;
    display: none;
}

.aia-divider {
    display: flex;
    align-items: center;
    gap: .75rem;
    margin: 1.25rem 0;
    color: #adb5bd;
    font-size: .85rem;
}
.aia-divider::before,
.aia-divider::after { content: ''; flex: 1; height: 1px; background: #dee2e6; }

/* Detection badge strip */
.aia-detection {
    border-radius: .5rem;
    padding: .7rem 1rem;
    font-size: .9rem;
    margin-top: .75rem;
    font-weight: 500;
}
.aia-detection--found     { color: #0a3622; background: #d1e7dd; border: 1px solid #a3cfbb; }
.aia-detection--generated { color: #664d03; background: #fff3cd; border: 1px solid #ffecb5; }

.aia-result {
    margin-top: 1.25rem;
    background: #fff;
    border: 1px solid #c3d9f7;
    border-radius: .5rem;
    padding: 1.25rem;
    white-space: pre-wrap;
    font-family: 'SFMono-Regular', Consolas, monospace;
    font-size: .85rem;
    line-height: 1.6;
    max-height: 480px;
    overflow-y: auto;
}
.aia-error {
    margin-top: 1rem;
    color: #842029;
    background: #f8d7da;
    border: 1px solid #f5c2c7;
    border-radius: .5rem;
    padding: .75rem 1rem;
}
.aia-info {
    margin-top: .75rem;
    color: #0a3622;
    background: #d1e7dd;
    border: 1px solid #a3cfbb;
    border-radius: .5rem;
    padding: .6rem 1rem;
    font-size: .85rem;
}
.aia-spinner {
    display: none;
    align-items: center;
    gap: .5rem;
    color: #0f6cbf;
    margin-top: .75rem;
}
</style>

<p class="text-muted"><?= get_string('choosecreationmethod', 'local_ai_assistant') ?></p>

<div class="aia-choice-wrapper">
    <a href="<?= $manual_url->out(false) ?>"
       class="aia-card<?= $show_ai ? '' : ' aia-card--active' ?>">
        <span class="aia-icon">📋</span>
        <h3><?= get_string('manualcreation', 'local_ai_assistant') ?></h3>
        <p><?= get_string('manualcreation_desc', 'local_ai_assistant') ?></p>
    </a>
    <div class="aia-card<?= $show_ai ? ' aia-card--active' : '' ?>"
         id="aia-toggle-btn" role="button" tabindex="0"
         aria-expanded="<?= $show_ai ? 'true' : 'false' ?>"
         aria-controls="aia-panel">
        <span class="aia-icon">✨</span>
        <h3><?= get_string('aicreation', 'local_ai_assistant') ?></h3>
        <p><?= get_string('aicreation_desc', 'local_ai_assistant') ?></p>
    </div>
</div>

<div id="aia-panel" <?= $show_ai ? '' : 'style="display:none"' ?>>

    <h4 class="mb-3"><?= get_string('generatecontent', 'local_ai_assistant') ?></h4>

    <form method="POST"
          action="<?= $action_url ?>"
          enctype="multipart/form-data"
          id="aia-form">

        <input type="hidden" name="sesskey" value="<?= $sesskey ?>">
        <input type="hidden" name="show_ai" value="1">
        <?php if ($category): ?>
            <input type="hidden" name="category" value="<?= $category ?>">
        <?php endif; ?>

        <!-- Task selector -->
        <div class="mb-3">
            <label for="ai_task" class="form-label fw-semibold">
                <?= get_string('task', 'local_ai_assistant') ?>
            </label>
            <select name="ai_task" id="ai_task" class="form-select">
                <?php
                $tasks = [
                    'outline'    => get_string('task_outline',    'local_ai_assistant'),
                    'quiz'       => get_string('task_quiz',       'local_ai_assistant'),
                    'assignment' => get_string('task_assignment',  'local_ai_assistant'),
                    'rewrite'    => get_string('task_rewrite',     'local_ai_assistant'),
                ];
                foreach ($tasks as $val => $label):
                    $sel = ($submitted_task === $val) ? ' selected' : '';
                ?>
                    <option value="<?= $val ?>"<?= $sel ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <!-- Hint shown when outline/rewrite is selected -->
            <div id="aia-detection-hint" class="form-text text-muted mt-1"
                 style="<?= in_array($submitted_task, ['outline','rewrite']) ? '' : 'display:none' ?>">
                💡 Якщо ви завантажите файл, ШІ спочатку перевірить, чи містить він силабус, і використає його напряму.
            </div>
        </div>

        <!-- File upload zone -->
        <div class="mb-3">
            <label class="form-label fw-semibold">
                <?= get_string('upload_file', 'local_ai_assistant') ?>
                <span class="text-muted fw-normal"><?= get_string('upload_file_optional', 'local_ai_assistant') ?></span>
            </label>
            <div class="aia-file-zone" id="aia-file-zone">
                <input type="file" name="ai_file[]" id="ai_file" accept=".docx,.pdf,.txt" multiple>
                <span class="aia-file-icon">📎</span>
                <div><?= get_string('upload_hint', 'local_ai_assistant') ?></div>
                <div class="text-muted" style="font-size:.8rem">DOCX · PDF · TXT</div>
            </div>
            <div class="aia-file-name" id="aia-file-name"></div>
        </div>

        <div class="aia-divider"><?= get_string('or', 'local_ai_assistant') ?></div>

        <!-- Prompt -->
        <div class="mb-3">
            <label for="ai_prompt" class="form-label fw-semibold">
                <?= get_string('prompt', 'local_ai_assistant') ?>
                <span class="text-muted fw-normal"><?= get_string('prompt_optional', 'local_ai_assistant') ?></span>
            </label>
            <textarea name="ai_prompt" id="ai_prompt"
                      class="form-control"
                      placeholder="<?= get_string('prompt_placeholder', 'local_ai_assistant') ?>"><?= s($submitted_prompt) ?></textarea>
        </div>

        <button type="submit" name="ai_generate" class="btn btn-primary" id="aia-submit-btn">
            <?= get_string('generate', 'local_ai_assistant') ?>
        </button>

        <div class="aia-spinner" id="aia-spinner">
            <div class="spinner-border spinner-border-sm" role="status"></div>
            <?= get_string('generating', 'local_ai_assistant') ?>
        </div>
    </form>

    <!-- ── Post-submit feedback ─────────────────────────────────────────── -->

    <?php if ($extracted_info): ?>
        <div class="aia-info">📁 <?= s($extracted_info) ?></div>
    <?php endif; ?>

    <?php if ($detection_message): ?>
        <?php $badge_class = $detection_badge === 'found' ? 'aia-detection--found' : 'aia-detection--generated'; ?>
        <div class="aia-detection <?= $badge_class ?>"><?= s($detection_message) ?></div>
    <?php endif; ?>

    <?php if (!empty($course_id) && $course_id > 0): ?>
        <div class="aia-info" style="background:#d1e7dd;border-color:#a3cfbb;color:#0a3622;padding:1rem;border-radius:.5rem;margin-top:1rem;font-size:1rem;">
            🎉 <strong>Курс створено!</strong>
            <?php if (!empty($uploaded_files)): ?>
                <?php
                $attached = array_map(
                    fn($f) => s(clean_filename($f['name'])),
                    $uploaded_files
                );
                ?>
                Файл(и) «<?= implode('», «', $attached) ?>» додано до розділу «Загальне».
            <?php endif; ?>
            <a href="<?= (new moodle_url('/course/view.php', ['id' => $course_id]))->out(false) ?>"
               style="font-weight:700;color:#0a3622;margin-left:.5rem;">
                Відкрити курс →
            </a>
        </div>
    <?php endif; ?>

    <?php if ($ai_error): ?>
        <div class="aia-error">⚠️ <?= s($ai_error) ?></div>
    <?php endif; ?>

    <?php if ($ai_result): ?>
        <div>
            <div class="d-flex justify-content-between align-items-center mt-4 mb-1">
                <strong><?= get_string('result', 'local_ai_assistant') ?></strong>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="aia-copy-btn">
                    <?= get_string('copy', 'local_ai_assistant') ?>
                </button>
            </div>
            <div class="aia-result" id="aia-result-box"><?= s($ai_result) ?></div>
        </div>
    <?php endif; ?>

</div><!-- /#aia-panel -->

<script>
(function () {
    'use strict';

    // ── Toggle AI panel ──────────────────────────────────────────────────────
    const toggleBtn = document.getElementById('aia-toggle-btn');
    const panel     = document.getElementById('aia-panel');
    const cards     = document.querySelectorAll('.aia-card');

    function openPanel() {
        panel.style.display = '';
        toggleBtn.setAttribute('aria-expanded', 'true');
        toggleBtn.classList.add('aia-card--active');
        cards.forEach(c => { if (c !== toggleBtn) c.classList.remove('aia-card--active'); });
    }
    toggleBtn.addEventListener('click', openPanel);
    toggleBtn.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openPanel(); }
    });

    // ── Task selector: show/hide detection hint ──────────────────────────────
    const taskSelect   = document.getElementById('ai_task');
    const detectionHint = document.getElementById('aia-detection-hint');
    taskSelect.addEventListener('change', function () {
        const structural = ['outline', 'rewrite'].includes(this.value);
        detectionHint.style.display = structural ? '' : 'none';
    });

    // ── File upload zone ─────────────────────────────────────────────────────
    const fileInput  = document.getElementById('ai_file');
    const fileZone   = document.getElementById('aia-file-zone');
    const fileNameEl = document.getElementById('aia-file-name');

    function updateFileZone(filename) {
        fileNameEl.textContent      = '📄 ' + filename;
        fileNameEl.style.display    = 'block';
        fileZone.style.borderColor  = '#0f6cbf';
        fileZone.style.background   = '#f0f6ff';
    }

    fileInput.addEventListener('change', function () {
        if (this.files.length > 0) {
            const names = Array.from(this.files).map(f => f.name).join(', ');
            updateFileZone(this.files.length === 1 ? names : this.files.length + ' files: ' + names);
        } else {
            fileNameEl.style.display   = 'none';
            fileZone.style.borderColor = '';
            fileZone.style.background  = '';
        }
    });

    // Drag-and-drop
    fileZone.addEventListener('dragover', e => { e.preventDefault(); fileZone.classList.add('dragover'); });
    fileZone.addEventListener('dragleave', ()  => fileZone.classList.remove('dragover'));
    fileZone.addEventListener('drop', e => {
        e.preventDefault();
        fileZone.classList.remove('dragover');
        if (e.dataTransfer.files.length > 0) {
            fileInput.files = e.dataTransfer.files;
            fileInput.dispatchEvent(new Event('change'));
        }
    });

    // ── Spinner on submit ────────────────────────────────────────────────────
    document.getElementById('aia-form').addEventListener('submit', function () {
        document.getElementById('aia-submit-btn').disabled = true;
        document.getElementById('aia-spinner').style.display = 'flex';
    });

    // ── Copy button ──────────────────────────────────────────────────────────
    const copyBtn   = document.getElementById('aia-copy-btn');
    const resultBox = document.getElementById('aia-result-box');
    if (copyBtn && resultBox) {
        copyBtn.addEventListener('click', function () {
            navigator.clipboard.writeText(resultBox.textContent).then(() => {
                copyBtn.textContent = '✓ <?= get_string('copied', 'local_ai_assistant') ?>';
                setTimeout(() => { copyBtn.textContent = '<?= get_string('copy', 'local_ai_assistant') ?>'; }, 2000);
            });
        });
    }
})();
</script>

<?php
echo $OUTPUT->footer();