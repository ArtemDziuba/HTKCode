<?php
/**
 * Course creation choice page.
 * Shown instead of /course/edit.php?action=createcourse so lecturers can
 * pick between the standard Moodle editor or AI-assisted content generation.
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

$category = optional_param('category', 0, PARAM_INT);

// Resolve context: course category if supplied, otherwise system.
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
$ai_result   = '';
$ai_error    = '';
$show_ai     = optional_param('show_ai', 0, PARAM_INT);
$submitted_task   = optional_param('ai_task', 'outline', PARAM_ALPHA);
$submitted_prompt = optional_param('ai_prompt', '', PARAM_TEXT);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_generate'])) {
    require_sesskey();
    $show_ai = 1; // Keep AI panel open after submit.

    $apikey = get_config('local_ai_assistant', 'geminikey');

    if (empty($apikey)) {
        $ai_error = get_string('noapikey', 'local_ai_assistant');
    } else {
        [$ai_result, $ai_error] = local_ai_assistant_call_gemini(
            $submitted_task,
            $submitted_prompt,
            $apikey
        );
    }
}

// ── Build URLs ───────────────────────────────────────────────────────────────
$manual_params = ['action' => 'createcourse', 'direct' => 1];
if ($category) {
    $manual_params['category'] = $category;
}
$manual_url = new moodle_url('/course/edit.php', $manual_params);

$sesskey = sesskey();

// ── Output ───────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
?>

<style>
/* ── Choice cards ── */
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
.aia-card:hover {
    border-color: #0f6cbf;
    box-shadow: 0 4px 16px rgba(15,108,191,.12);
}
.aia-card.aia-card--active {
    border-color: #0f6cbf;
    background: #f0f6ff;
}
.aia-card .aia-icon {
    font-size: 2.8rem;
    line-height: 1;
}
.aia-card h3 { margin: 0; font-size: 1.25rem; }
.aia-card p  { color: #6c757d; margin: 0; font-size: .9rem; }

/* ── AI panel ── */
#aia-panel {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: .75rem;
    padding: 1.75rem;
    margin-top: .5rem;
}
#aia-panel select,
#aia-panel textarea {
    border-radius: .5rem;
}
#aia-panel textarea { resize: vertical; min-height: 100px; }

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
.aia-spinner {
    display: none;
    align-items: center;
    gap: .5rem;
    color: #0f6cbf;
    margin-top: .75rem;
}
</style>

<p class="text-muted"><?= get_string('choosecreationmethod', 'local_ai_assistant') ?></p>

<!-- ── Choice cards ── -->
<div class="aia-choice-wrapper">

    <!-- Manual -->
    <a href="<?= $manual_url->out(false) ?>"
       class="aia-card<?= $show_ai ? '' : ' aia-card--active' ?>">
        <span class="aia-icon">📋</span>
        <h3><?= get_string('manualcreation', 'local_ai_assistant') ?></h3>
        <p><?= get_string('manualcreation_desc', 'local_ai_assistant') ?></p>
    </a>

    <!-- AI -->
    <div class="aia-card<?= $show_ai ? ' aia-card--active' : '' ?>"
         id="aia-toggle-btn"
         role="button" tabindex="0"
         aria-expanded="<?= $show_ai ? 'true' : 'false' ?>"
         aria-controls="aia-panel">
        <span class="aia-icon">✨</span>
        <h3><?= get_string('aicreation', 'local_ai_assistant') ?></h3>
        <p><?= get_string('aicreation_desc', 'local_ai_assistant') ?></p>
    </div>

</div>

<!-- ── AI panel ── -->
<div id="aia-panel" <?= $show_ai ? '' : 'style="display:none"' ?>>

    <h4 class="mb-3"><?= get_string('generatecontent', 'local_ai_assistant') ?></h4>

    <form method="POST" action="" id="aia-form">
        <input type="hidden" name="sesskey"  value="<?= $sesskey ?>">
        <input type="hidden" name="show_ai"  value="1">
        <?php if ($category): ?>
            <input type="hidden" name="category" value="<?= $category ?>">
        <?php endif; ?>

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
                ];
                foreach ($tasks as $val => $label):
                    $sel = ($submitted_task === $val) ? ' selected' : '';
                ?>
                    <option value="<?= $val ?>"<?= $sel ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label for="ai_prompt" class="form-label fw-semibold">
                <?= get_string('prompt', 'local_ai_assistant') ?>
            </label>
            <textarea name="ai_prompt" id="ai_prompt"
                      class="form-control"
                      placeholder="<?= get_string('prompt_placeholder', 'local_ai_assistant') ?>"
                      required><?= s($submitted_prompt) ?></textarea>
        </div>

        <button type="submit" name="ai_generate" class="btn btn-primary" id="aia-submit-btn">
            <?= get_string('generate', 'local_ai_assistant') ?>
        </button>

        <div class="aia-spinner" id="aia-spinner">
            <div class="spinner-border spinner-border-sm" role="status"></div>
            <?= get_string('generating', 'local_ai_assistant') ?>
        </div>
    </form>

    <?php if ($ai_error): ?>
        <div class="aia-error"><?= s($ai_error) ?></div>
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

    // ── Toggle AI panel ──
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

    // ── Spinner on submit ──
    document.getElementById('aia-form').addEventListener('submit', function () {
        document.getElementById('aia-submit-btn').disabled = true;
        document.getElementById('aia-spinner').style.display = 'flex';
    });

    // ── Copy button ──
    const copyBtn    = document.getElementById('aia-copy-btn');
    const resultBox  = document.getElementById('aia-result-box');
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
