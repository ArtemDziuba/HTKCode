<?php
/**
 * Course creation choice page — chatbot version.
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

$category = optional_param('category', 0, PARAM_INT);
$context  = $category
    ? context_coursecat::instance($category)
    : context_system::instance();

require_capability('moodle/course:create', $context);

// Clear chat history if requested
if (optional_param('reset', 0, PARAM_INT)) {
    $session_key = 'local_ai_assistant_chat_' . $USER->id;
    $SESSION->{$session_key} = [];
    redirect(new moodle_url('/local/ai_assistant/create_course.php',
        $category ? ['category' => $category] : []));
}

$PAGE->set_url('/local/ai_assistant/create_course.php',
    $category ? ['category' => $category] : []);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('createcourse', 'local_ai_assistant'));
$PAGE->set_heading(get_string('createcourse', 'local_ai_assistant'));

$manual_url = new moodle_url('/course/edit.php', array_merge(
    ['direct' => 1], $category ? ['category' => $category] : []
));
$ajax_url  = (new moodle_url('/local/ai_assistant/chat_ajax.php'))->out(false);
$reset_url = (new moodle_url('/local/ai_assistant/create_course.php',
    array_merge(['reset' => 1], $category ? ['category' => $category] : [])))->out(false);

echo $OUTPUT->header();
?>
<style>
.aia-choice-wrapper{display:flex;gap:1.5rem;margin:2rem 0 1.5rem;flex-wrap:wrap}
.aia-card{flex:1 1 240px;border:2px solid #dee2e6;border-radius:.75rem;padding:2rem 1.5rem;text-align:center;cursor:pointer;transition:border-color .2s,box-shadow .2s;background:#fff;text-decoration:none!important;color:inherit!important;display:flex;flex-direction:column;align-items:center;gap:.6rem}
.aia-card:hover{border-color:#0f6cbf;box-shadow:0 4px 16px rgba(15,108,191,.12)}
.aia-card--active{border-color:#0f6cbf;background:#f0f6ff}
.aia-card .aia-icon{font-size:2.4rem;line-height:1}
.aia-card h3{margin:0;font-size:1.15rem}
.aia-card p{color:#6c757d;margin:0;font-size:.875rem}

#aia-chat-panel{background:#f8f9fa;border:1px solid #dee2e6;border-radius:.75rem;overflow:hidden;margin-top:.5rem}
#aia-messages{height:420px;overflow-y:auto;padding:1.25rem 1.25rem .5rem;display:flex;flex-direction:column;gap:.75rem;scroll-behavior:smooth}
.aia-msg{display:flex;gap:.6rem;max-width:82%}
.aia-msg--bot{align-self:flex-start}
.aia-msg--user{align-self:flex-end;flex-direction:row-reverse}
.aia-bubble{padding:.65rem 1rem;border-radius:1rem;font-size:.9rem;line-height:1.55;white-space:pre-wrap;word-break:break-word}
.aia-msg--bot .aia-bubble{background:#fff;border:1px solid #dee2e6;border-radius:1rem 1rem 1rem .2rem}
.aia-msg--user .aia-bubble{background:#0f6cbf;color:#fff;border-radius:1rem 1rem .2rem 1rem}
.aia-avatar{width:32px;height:32px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1rem;background:#e9ecef;align-self:flex-end}
.aia-msg--bot .aia-avatar{background:#0f6cbf;color:#fff}
.aia-msg--user .aia-avatar{background:#6c757d;color:#fff}

.aia-typing .aia-bubble{display:flex;gap:4px;align-items:center;padding:.75rem 1rem}
.aia-typing .dot{width:7px;height:7px;border-radius:50%;background:#adb5bd;animation:aia-bounce .9s infinite ease-in-out}
.aia-typing .dot:nth-child(2){animation-delay:.15s}
.aia-typing .dot:nth-child(3){animation-delay:.30s}
@keyframes aia-bounce{0%,80%,100%{transform:translateY(0);opacity:.5}40%{transform:translateY(-6px);opacity:1}}

#aia-input-row{display:flex;gap:.5rem;align-items:flex-end;padding:.75rem 1rem;border-top:1px solid #dee2e6;background:#fff}
#aia-input{flex:1;border:1px solid #dee2e6;border-radius:.5rem;padding:.55rem .85rem;font-size:.9rem;resize:none;max-height:120px;overflow-y:auto;line-height:1.5;outline:none;transition:border-color .15s}
#aia-input:focus{border-color:#0f6cbf}
#aia-send-btn{flex-shrink:0;background:#0f6cbf;color:#fff;border:none;border-radius:.5rem;padding:.55rem 1rem;cursor:pointer;font-size:1.1rem;transition:background .15s;align-self:flex-end}
#aia-send-btn:disabled{background:#adb5bd;cursor:not-allowed}
#aia-send-btn:not(:disabled):hover{background:#0d5aa7}

.aia-file-strip{padding:.5rem 1rem .6rem;background:#fff;border-top:1px dashed #dee2e6}
.aia-file-strip-top{display:flex;align-items:center;gap:.5rem;font-size:.8rem;color:#6c757d}
.aia-file-strip label{cursor:pointer;color:#0f6cbf;font-weight:500;display:flex;align-items:center;gap:.3rem;font-size:.8rem}
.aia-file-strip input{display:none}
#aia-file-grid{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.5rem}
.aia-file-card{display:flex;align-items:center;gap:.5rem;background:#f8f9fa;border:1px solid #dee2e6;border-radius:.5rem;padding:.35rem .65rem;font-size:.78rem;color:#495057;max-width:200px;position:relative}
.aia-file-card .fc-icon{font-size:1.1rem;flex-shrink:0}
.aia-file-card .fc-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1}
.aia-file-card .fc-remove{cursor:pointer;color:#adb5bd;font-size:.85rem;flex-shrink:0;line-height:1;margin-left:.25rem}
.aia-file-card .fc-remove:hover{color:#dc3545}
.aia-file-card--sent{opacity:.6;background:#f0f6ff;border-color:#c3d9f7}
.aia-file-card .fc-sent{color:#0f6cbf;font-size:.8rem;flex-shrink:0;margin-left:.25rem}

#aia-success{display:none;margin:1rem 1.25rem;background:#d1e7dd;border:1px solid #a3cfbb;color:#0a3622;border-radius:.5rem;padding:1rem 1.25rem;font-size:1rem}
#aia-success a{color:#0a3622;font-weight:700}
.aia-reset{font-size:.8rem;color:#6c757d;text-align:right;padding:.25rem 1rem .5rem}
.aia-reset a{color:#6c757d}
</style>

<p class="text-muted"><?= get_string('choosecreationmethod', 'local_ai_assistant') ?></p>

<div class="aia-choice-wrapper">
    <a href="<?= $manual_url->out(false) ?>" class="aia-card">
        <span class="aia-icon">📋</span>
        <h3><?= get_string('manualcreation', 'local_ai_assistant') ?></h3>
        <p><?= get_string('manualcreation_desc', 'local_ai_assistant') ?></p>
    </a>
    <div class="aia-card aia-card--active" style="cursor:default;">
        <span class="aia-icon">✨</span>
        <h3><?= get_string('aicreation', 'local_ai_assistant') ?></h3>
        <p><?= get_string('aicreation_desc', 'local_ai_assistant') ?></p>
    </div>
</div>

<div id="aia-chat-panel">
    <div id="aia-messages">
        <div class="aia-msg aia-msg--bot">
            <div class="aia-avatar">✨</div>
            <div class="aia-bubble">Привіт! Я допоможу вам створити курс. Розкажіть, який курс ви хочете створити — назву, тему, кількість тижнів. Можна також завантажити файл із силабусом.</div>
        </div>
    </div>

    <div id="aia-success">
        🎉 <strong>Курс створено!</strong>
        <a id="aia-course-link" href="#">Відкрити курс →</a>
        &nbsp;·&nbsp;
        <a href="<?= $reset_url ?>">Створити ще один</a>
    </div>

    <div class="aia-file-strip">
        <div class="aia-file-strip-top">
            <label>📎 Додати файл(и)<input type="file" id="aia-file-input" accept=".docx,.pdf,.txt" multiple></label>
            <span style="color:#adb5bd">· DOCX, PDF, TXT</span>
        </div>
        <div id="aia-file-grid"></div>
    </div>

    <div id="aia-input-row">
        <textarea id="aia-input" rows="1" placeholder="Напишіть повідомлення…"></textarea>
        <button id="aia-send-btn" title="Надіслати">➤</button>
    </div>

    <div class="aia-reset"><a href="<?= $reset_url ?>">↺ Почати спочатку</a></div>
</div>

<script>
(function(){
'use strict';
const msgs      = document.getElementById('aia-messages');
const inputEl   = document.getElementById('aia-input');
const sendBtn   = document.getElementById('aia-send-btn');
const fileInput = document.getElementById('aia-file-input');
const fileGrid  = document.getElementById('aia-file-grid');
const successEl = document.getElementById('aia-success');
const courseLink= document.getElementById('aia-course-link');
const AJAX_URL  = <?= json_encode($ajax_url) ?>;
const SESSKEY   = <?= json_encode(sesskey()) ?>;
const CATEGORY  = <?= (int)$category ?>;

let fileStore = new DataTransfer(); // files queued to send
let sentFiles = [];                 // files already sent (shown persistently)

function extIcon(name){
    const ext = name.split('.').pop().toLowerCase();
    if(ext==='pdf')  return '\u{1F4D5}';
    if(ext==='docx') return '\u{1F4D8}';
    return '\u{1F4C4}';
}
function renderFileCards(){
    fileGrid.innerHTML = '';

    // Sent files — greyed out, no remove button
    sentFiles.forEach(name => {
        const card = document.createElement('div');
        card.className = 'aia-file-card aia-file-card--sent';
        card.innerHTML = '<span class="fc-icon">'+extIcon(name)+'</span>'
            +'<span class="fc-name" title="'+name.replace(/"/g,'&quot;')+'">'+name+'</span>'
            +'<span class="fc-sent" title="Надіслано">✓</span>';
        fileGrid.appendChild(card);
    });

    // Pending files — removable
    const files = fileStore.files;
    for(let i=0;i<files.length;i++){
        const f = files[i];
        const card = document.createElement('div');
        card.className = 'aia-file-card';
        card.innerHTML = '<span class="fc-icon">'+extIcon(f.name)+'</span>'
            +'<span class="fc-name" title="'+f.name.replace(/"/g,'&quot;')+'">'+f.name+'</span>'
            +'<span class="fc-remove" data-idx="'+i+'" title="Видалити">×</span>';
        fileGrid.appendChild(card);
    }
    fileInput.files = fileStore.files;
}
fileGrid.addEventListener('click',function(e){
    const btn=e.target.closest('.fc-remove');
    if(!btn) return;
    const idx=parseInt(btn.dataset.idx);
    const newDT=new DataTransfer();
    const files=fileStore.files;
    for(let i=0;i<files.length;i++){ if(i!==idx) newDT.items.add(files[i]); }
    fileStore=newDT;
    renderFileCards();
});
fileInput.addEventListener('change',function(){
    for(const f of this.files) fileStore.items.add(f);
    renderFileCards();
    this.value='';
});

inputEl.addEventListener('input', function(){
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
});
inputEl.addEventListener('keydown', function(e){
    if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); send(); }
});
sendBtn.addEventListener('click', send);

function bubble(role, text){
    const isBot = role === 'bot';
    const w = document.createElement('div');
    w.className = 'aia-msg aia-msg--' + (isBot ? 'bot' : 'user');
    w.innerHTML = `<div class="aia-avatar">${isBot ? '✨' : '👤'}</div>`
                + `<div class="aia-bubble">${esc(text)}</div>`;
    msgs.appendChild(w);
    msgs.scrollTop = msgs.scrollHeight;
    return w;
}
function showTyping(){
    const w = document.createElement('div');
    w.id = 'aia-typing';
    w.className = 'aia-msg aia-msg--bot aia-typing';
    w.innerHTML = '<div class="aia-avatar">✨</div>'
        + '<div class="aia-bubble"><span class="dot"></span><span class="dot"></span><span class="dot"></span></div>';
    msgs.appendChild(w);
    msgs.scrollTop = msgs.scrollHeight;
}
function removeTyping(){ const e=document.getElementById('aia-typing'); if(e) e.remove(); }
function esc(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>'); }
function disable(v){ sendBtn.disabled = inputEl.disabled = fileInput.disabled = v; }

async function send(){
    const text = inputEl.value.trim();
    if(!text && fileInput.files.length === 0) return;

    const fileNames = Array.from(fileStore.files).map(f => '📎 ' + f.name);
    const userLabel = [text, ...fileNames].filter(Boolean).join('\n');
    bubble('user', userLabel || '📎 файл(и)');
    inputEl.value = '';
    inputEl.style.height = 'auto';
    disable(true);
    showTyping();

    const fd = new FormData();
    fd.append('sesskey', SESSKEY);
    fd.append('message', text);
    fd.append('category', CATEGORY);
    for(const f of fileStore.files) {
        fd.append('ai_file[]', f);
        sentFiles.push(f.name); // remember as sent
    }
    fileStore = new DataTransfer();
    renderFileCards();

    try{
        const res  = await fetch(AJAX_URL, {method:'POST', body:fd});
        const data = await res.json();
        removeTyping();

        if(data.error){
            bubble('bot', '⚠️ ' + data.error);
        } else {
            bubble('bot', data.reply || '');
            if(data.ready && data.course_url){
                courseLink.href = data.course_url;
                successEl.style.display = 'block';
                msgs.scrollTop = msgs.scrollHeight;
                sentFiles = [];
                renderFileCards();
                return;
            }
        }
    } catch(e){
        removeTyping();
        bubble('bot', '⚠️ Мережева помилка. Спробуйте ще раз.');
    }

    disable(false);
    inputEl.focus();
}
})();
</script>

<?php echo $OUTPUT->footer();