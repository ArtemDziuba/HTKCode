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

// ── Handle AI form submission ────────────────────────────────────────────────
$ai_result        = '';
$ai_error         = '';
$extracted_info   = ''; // Info message about uploaded file
$show_ai          = optional_param('show_ai', 0, PARAM_INT);
$submitted_task   = optional_param('ai_task', 'outline', PARAM_ALPHA);
$submitted_prompt = optional_param('ai_prompt', '', PARAM_TEXT);
$course_id        = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_task'])) {
    try {
        require_sesskey();
    } catch (Exception $e) {
        $ai_error = 'Sesskey error: ' . $e->getMessage();
    }

    if (empty($ai_error)) {
        $show_ai = 1;

        $apikey = get_config('local_ai_assistant', 'geminikey');

        if (empty($apikey)) {
            $ai_error = get_string('noapikey', 'local_ai_assistant');
        } else {
            // ── Build the final prompt ──────────────────────────────────────
            $final_prompt = trim($submitted_prompt);

            // Handle optional file upload.
            if (!empty($_FILES['ai_file']['name'])) {
                $upload_error = $_FILES['ai_file']['error'];

                if ($upload_error !== UPLOAD_ERR_OK) {
                    $ai_error = 'File upload error (code ' . $upload_error . '). Please try again.';
                } else {
                    [$file_text, $file_error] = local_ai_assistant_extract_text($_FILES['ai_file']);

                    if (!empty($file_error)) {
                        $ai_error = $file_error;
                    } else {
                        $filename       = clean_filename($_FILES['ai_file']['name']);
                        $extracted_info = 'File "' . s($filename) . '" uploaded — '
                            . number_format(mb_strlen($file_text)) . ' characters extracted.';

                        // Prepend file content to the prompt.
                        $file_prefix  = "=== Uploaded document: " . $filename . " ===\n";
                        $file_prefix .= $file_text . "\n";
                        $file_prefix .= "=== End of document ===\n\n";

                        if (!empty($final_prompt)) {
                            $final_prompt = $file_prefix . "Additional instructions: " . $final_prompt;
                        } else {
                            $final_prompt = $file_prefix . "Please process the document above according to the selected task.";
                        }

                        // Switch to rewrite task if no task explicitly chosen and file present.
                        if ($submitted_task === 'outline' && empty(trim($submitted_prompt))) {
                            $submitted_task = 'rewrite';
                        }
                    }
                }
            }

            if (empty($ai_error)) {
                if (empty($final_prompt)) {
                    $ai_error = 'Please enter a prompt or upload a file.';
                } else {
                    [$ai_result, $ai_error] = local_ai_assistant_call_gemini(
                        $submitted_task,
                        $final_prompt,
                        $apikey
                    );

                    // If outline — create the actual Moodle course
                    if (!empty($ai_result) && $submitted_task === 'outline') {
                        $weeks     = local_ai_assistant_parse_weeks($ai_result);
                        $cname     = !empty(trim($submitted_prompt)) ? trim($submitted_prompt) : 'AI Generated Course';
                        $course_id = local_ai_assistant_create_moodle_course($cname, $weeks);
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
        </div>

        <!-- File upload -->
        <div class="mb-3">
            <label class="form-label fw-semibold">
                <?= get_string('upload_file', 'local_ai_assistant') ?>
                <span class="text-muted fw-normal"><?= get_string('upload_file_optional', 'local_ai_assistant') ?></span>
            </label>
            <div class="aia-file-zone" id="aia-file-zone">
                <input type="file" name="ai_file" id="ai_file" accept=".docx,.pdf,.txt">
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

    <?php if ($extracted_info): ?>
        <div class="aia-info">✅ <?= s($extracted_info) ?></div>
    <?php endif; ?>

    <?php if (!empty($course_id) && $course_id > 0): ?>
        <div class="aia-info" style="background:#d1e7dd;border-color:#a3cfbb;color:#0a3622;padding:1rem;border-radius:.5rem;margin-top:1rem;font-size:1rem;">
            🎉 <strong>Курс створено!</strong>
            <a href="<?= (new moodle_url('/course/view.php', ['id' => $course_id]))->out(false) ?>" style="font-weight:700;color:#0a3622;">
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

    // Toggle AI panel
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

    // File upload zone
    const fileInput  = document.getElementById('ai_file');
    const fileZone   = document.getElementById('aia-file-zone');
    const fileNameEl = document.getElementById('aia-file-name');

    fileInput.addEventListener('change', function () {
        if (this.files.length > 0) {
            fileNameEl.textContent = '📄 ' + this.files[0].name;
            fileNameEl.style.display = 'block';
            fileZone.style.borderColor = '#0f6cbf';
            fileZone.style.background  = '#f0f6ff';
        } else {
            fileNameEl.style.display = 'none';
            fileZone.style.borderColor = '';
            fileZone.style.background  = '';
        }
    });

    // Drag and drop
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

    // Spinner on submit
    document.getElementById('aia-form').addEventListener('submit', function () {
        document.getElementById('aia-spinner').style.display = 'flex';
    });

    // Copy button
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