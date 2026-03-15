<?php
defined('MOODLE_INTERNAL') || die();

class block_ai_assistant extends block_base {

    public function init() {
        $this->title = get_string('pluginname', 'block_ai_assistant');
    }

    public function applicable_formats() {
        return ['course-view' => true];
    }

    public function get_required_by_theme() {
        return false;
    }

    public function instance_can_be_hidden() {
        return false;
    }
    
    public function hide_header() {
        return false;
    }

    public function get_content() {
        global $COURSE, $CFG;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();

        $apikey  = get_config('local_ai_assistant', 'geminikey');
        $courseid = $COURSE->id;
        $sesskey  = sesskey();

        // Handle AJAX action — called from JS fetch
        if (isset($_POST['ai_chat_action'])) {
            require_sesskey();
            $message  = optional_param('ai_message', '', PARAM_TEXT);
            $response = $this->handle_chat($message, $courseid, $apikey);
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }

        $this->content->text = $this->render_chat_block($courseid, $sesskey);
        return $this->content;
    }

    private function handle_chat(string $message, int $courseid, string $apikey): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        if (empty($apikey)) {
            return ['reply' => 'API key not configured.', 'action' => null];
        }

        // Get current course sections for context
        $sections = $DB->get_records('course_sections',
            ['course' => $courseid], 'section ASC', 'id,section,name', 1, 20);
        $sections_list = '';
        foreach ($sections as $s) {
            $name = $s->name ?: 'Тиждень ' . $s->section;
            $sections_list .= "Тиждень {$s->section}: {$name}\n";
        }

        $system = "Ти — AI-асистент викладача в Moodle. Поточний курс має такі секції:\n{$sections_list}\n
Коли викладач просить щось змінити — відповідай JSON у форматі:
{\"reply\": \"текст відповіді\", \"action\": {\"type\": \"rename_section\", \"section\": 1, \"name\": \"Нова назва\"}}

Доступні дії:
- rename_section: {\"type\": \"rename_section\", \"section\": N, \"name\": \"Назва\"}
- add_section: {\"type\": \"add_section\", \"name\": \"Назва нової секції\"}
- none: якщо просто відповідаєш на питання без змін

Якщо дія не потрібна — використовуй: \"action\": null
ЗАВЖДИ відповідай валідним JSON. Нічого крім JSON.";

        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key='
            . urlencode($apikey);

        $body = json_encode([
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents'           => [['parts' => [['text' => $message]]]],
            'generationConfig'   => ['temperature' => 0.3],
        ]);

        $curl = new curl();
        $curl->setHeader(['Content-Type: application/json']);
        $raw  = $curl->post($endpoint, $body);
        $data = json_decode($raw, true);

        if (!empty($data['error'])) {
            return ['reply' => 'Gemini error: ' . $data['error']['message'], 'action' => null];
        }

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
        $text = preg_replace('/```json|```/i', '', $text);
        $parsed = json_decode(trim($text), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['reply' => $text, 'action' => null];
        }

        $reply  = $parsed['reply'] ?? 'Готово!';
        $action = $parsed['action'] ?? null;

        // Execute the action
        if ($action && !empty($action['type'])) {
            if ($action['type'] === 'rename_section' && !empty($action['section'])) {
                $section = $DB->get_record('course_sections', [
                    'course'  => $courseid,
                    'section' => (int) $action['section'],
                ]);
                if ($section) {
                    $section->name = clean_param($action['name'] ?? '', PARAM_TEXT);
                    $DB->update_record('course_sections', $section);
                    rebuild_course_cache($courseid, true);
                }
            } elseif ($action['type'] === 'add_section') {
                $maxsection = $DB->get_field_sql(
                    'SELECT MAX(section) FROM {course_sections} WHERE course = ?', [$courseid]);
                $newsection           = new stdClass();
                $newsection->course   = $courseid;
                $newsection->section  = ($maxsection ?? 0) + 1;
                $newsection->name     = clean_param($action['name'] ?? 'Нова тема', PARAM_TEXT);
                $newsection->visible  = 1;
                $newsection->summary  = '';
                $newsection->summaryformat = 1;
                $newsection->sequence = '';
                $DB->insert_record('course_sections', $newsection);

                // Update numsections
                $courseobj = $DB->get_record('course', ['id' => $courseid]);
                course_update_section($courseobj, $newsection, ['name' => $newsection->name]);
                rebuild_course_cache($courseid, true);
            }
        }

        return ['reply' => $reply, 'action' => $action];
    }

    private function render_chat_block(int $courseid, string $sesskey): string {
        $actionurl = (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false);
        return '
<style>
#ai-chat-wrap { display:flex; flex-direction:column; height:380px; font-family:sans-serif; }
#ai-chat-msgs { flex:1; overflow-y:auto; padding:8px; background:#f8f9fa;
    border:1px solid #dee2e6; border-radius:6px; margin-bottom:8px; display:flex; flex-direction:column; gap:6px; }
.ai-msg { padding:7px 10px; border-radius:8px; font-size:13px; line-height:1.5; max-width:90%; }
.ai-msg.user { background:#0f6cbf; color:white; align-self:flex-end; border-bottom-right-radius:2px; }
.ai-msg.bot  { background:#fff; border:1px solid #dee2e6; align-self:flex-start; border-bottom-left-radius:2px; }
.ai-msg.bot.typing { color:#aaa; font-style:italic; }
#ai-chat-form { display:flex; gap:6px; }
#ai-chat-input { flex:1; padding:7px 10px; border:1px solid #dee2e6; border-radius:6px;
    font-size:13px; outline:none; }
#ai-chat-input:focus { border-color:#0f6cbf; }
#ai-chat-send { padding:7px 14px; background:#0f6cbf; color:white; border:none;
    border-radius:6px; cursor:pointer; font-size:13px; font-weight:600; }
#ai-chat-send:hover { background:#0a4e99; }
.ai-action-badge { font-size:11px; background:#d1e7dd; color:#0a3622;
    padding:2px 8px; border-radius:10px; display:inline-block; margin-top:4px; }
</style>

<div id="ai-chat-wrap">
  <div id="ai-chat-msgs">
    <div class="ai-msg bot">Привіт! Я можу змінювати цей курс. Наприклад: <em>"Перейменуй тиждень 1 на Вступ до теми"</em> або <em>"Додай тему про нейронні мережі"</em></div>
  </div>
  <div id="ai-chat-form">
    <input type="text" id="ai-chat-input" placeholder="Напишіть команду..." />
    <button id="ai-chat-send">→</button>
  </div>
</div>

<script>
(function(){
    const msgs  = document.getElementById("ai-chat-msgs");
    const input = document.getElementById("ai-chat-input");
    const btn   = document.getElementById("ai-chat-send");
    const courseId = ' . $courseid . ';
    const sesskey  = "' . $sesskey . '";

    function addMsg(text, type) {
        const d = document.createElement("div");
        d.className = "ai-msg " + type;
        d.textContent = text;
        msgs.appendChild(d);
        msgs.scrollTop = msgs.scrollHeight;
        return d;
    }

    function addActionBadge(action) {
        if (!action) return;
        const badge = document.createElement("div");
        badge.className = "ai-action-badge";
        if (action.type === "rename_section") {
            badge.textContent = "✓ Тиждень " + action.section + " перейменовано";
        } else if (action.type === "add_section") {
            badge.textContent = "✓ Додано нову тему: " + action.name;
        }
        msgs.lastChild.appendChild(document.createElement("br"));
        msgs.lastChild.appendChild(badge);
    }

    async function send() {
        const text = input.value.trim();
        if (!text) return;
        input.value = "";
        btn.disabled = true;

        addMsg(text, "user");
        const typing = addMsg("...", "bot typing");

        try {
            const fd = new FormData();
            fd.append("ai_chat_action", "1");
            fd.append("ai_message", text);
            fd.append("sesskey", sesskey);
            fd.append("courseid", courseId);

            const res  = await fetch("/blocks/ai_assistant/ajax.php", { method: "POST", body: fd });
            const data = await res.json();

            typing.remove();
            const botMsg = addMsg(data.reply || "Готово!", "bot");

            if (data.action && data.action.type !== "none") {
                addActionBadge(data.action);
                // Reload page after 1.5s to show changes
                setTimeout(() => window.location.reload(), 1500);
            }
        } catch(e) {
            typing.remove();
            addMsg("Помилка з/єднання.", "bot");
        }
        btn.disabled = false;
    }

    btn.addEventListener("click", send);
    input.addEventListener("keydown", e => { if (e.key === "Enter") send(); });
})();
</script>';
    }
}