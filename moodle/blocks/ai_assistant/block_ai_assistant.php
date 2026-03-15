<?php
defined('MOODLE_INTERNAL') || die();

class block_ai_assistant extends block_base {

    public function init() {
        $this->title = get_string('pluginname', 'block_ai_assistant');
    }

    public function applicable_formats() {
        return ['course-view' => true];
    }

    public function get_required_by_theme() { return false; }
    public function instance_can_be_hidden() { return false; }
    public function hide_header() { return false; }

    public function get_content() {
        global $COURSE;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content       = new stdClass();
        $this->content->text = $this->render_chat_block((int)$COURSE->id, sesskey());
        return $this->content;
    }

    private function render_chat_block(int $courseid, string $sesskey): string {
        return '
<style>
#ai-chat-wrap{display:flex;flex-direction:column;height:400px;font-family:sans-serif}
#ai-chat-msgs{flex:1;overflow-y:auto;padding:8px;background:#f8f9fa;
  border:1px solid #dee2e6;border-radius:6px;margin-bottom:8px;
  display:flex;flex-direction:column;gap:6px}
.ai-msg{padding:7px 10px;border-radius:8px;font-size:13px;line-height:1.5;max-width:92%}
.ai-msg.user{background:#0f6cbf;color:#fff;align-self:flex-end;border-bottom-right-radius:2px}
.ai-msg.bot{background:#fff;border:1px solid #dee2e6;align-self:flex-start;border-bottom-left-radius:2px}
.ai-msg.bot.typing{color:#aaa;font-style:italic}
#ai-chat-form{display:flex;gap:6px}
#ai-chat-input{flex:1;padding:7px 10px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;outline:none}
#ai-chat-input:focus{border-color:#0f6cbf}
#ai-chat-send{padding:7px 14px;background:#0f6cbf;color:#fff;border:none;
  border-radius:6px;cursor:pointer;font-size:13px;font-weight:600}
#ai-chat-send:hover{background:#0a4e99}
#ai-chat-send:disabled{background:#adb5bd;cursor:not-allowed}
.ai-badges{display:flex;flex-direction:column;gap:3px;margin-top:5px}
.ai-badge{font-size:11px;padding:2px 8px;border-radius:10px;display:inline-block}
.ai-badge.section{background:#d1e7dd;color:#0a3622}
.ai-badge.dates{background:#cfe2ff;color:#052c65}
</style>

<div id="ai-chat-wrap">
  <div id="ai-chat-msgs">
    <div class="ai-msg bot">Привіт! Я можу редагувати курс. Спробуйте:<br>
      &bull; <em>«Перейменуй тижні 1, 2, 3 на Вступ, Основи, Практика»</em><br>
      &bull; <em>«Зсунь дедлайн завдання "Контрольна" на 5 днів»</em><br>
      &bull; <em>«Встанови початок подавання завдання ID 3 на 01.05.2025 09:00»</em>
    </div>
  </div>
  <div id="ai-chat-form">
    <input type="text" id="ai-chat-input" placeholder="Напишіть команду..." />
    <button id="ai-chat-send">→</button>
  </div>
</div>

<script>
(function(){
  const msgs     = document.getElementById("ai-chat-msgs");
  const input    = document.getElementById("ai-chat-input");
  const btn      = document.getElementById("ai-chat-send");
  const courseId = ' . $courseid . ';
  const sesskey  = "' . $sesskey . '";

  function addMsg(html, type, raw) {
    const d = document.createElement("div");
    d.className = "ai-msg " + type;
    if (raw) { d.innerHTML = html; } else { d.textContent = html; }
    msgs.appendChild(d);
    msgs.scrollTop = msgs.scrollHeight;
    return d;
  }

  function buildBadges(actions) {
    if (!actions || !actions.length) return null;
    const wrap = document.createElement("div");
    wrap.className = "ai-badges";
    actions.forEach(a => {
      const b = document.createElement("span");
      if (a.type === "rename_section") {
        b.className = "ai-badge section";
        b.textContent = "✓ Тиждень " + a.section + " → \"" + a.name + "\"";
      } else if (a.type === "add_section") {
        b.className = "ai-badge section";
        b.textContent = "✓ Додано секцію: \"" + a.name + "\"";
      } else if (a.type === "update_assignment_dates") {
        b.className = "ai-badge dates";
        let txt = "✓ Дати завдання \"" + a.name + "\" оновлено";
        if (a.dates) {
          const parts = [];
          if (a.dates.allowsubmissionsfromdate) parts.push("початок: " + a.dates.allowsubmissionsfromdate);
          if (a.dates.duedate)                  parts.push("дедлайн: " + a.dates.duedate);
          if (a.dates.cutoffdate)               parts.push("пізнє здавання: " + a.dates.cutoffdate);
          if (a.dates.gradingduedate)           parts.push("оцінювання: " + a.dates.gradingduedate);
          if (parts.length) txt += " (" + parts.join(", ") + ")";
        }
        b.textContent = txt;
      }
      wrap.appendChild(b);
    });
    return wrap;
  }

  async function send() {
    const text = input.value.trim();
    if (!text) return;
    input.value = "";
    btn.disabled = true;

    addMsg(text, "user", false);
    const typing = addMsg("...", "bot typing", false);

    try {
      const fd = new FormData();
      fd.append("ai_message", text);
      fd.append("sesskey",    sesskey);
      fd.append("courseid",   courseId);

      const res  = await fetch("/blocks/ai_assistant/ajax.php", {method:"POST", body:fd});
      const data = await res.json();

      typing.remove();
      const botEl = addMsg(data.reply || "Готово!", "bot", false);

      const badges = buildBadges(data.actions);
      if (badges) botEl.appendChild(badges);

      const hasRealActions = (data.actions || []).some(
        a => ["rename_section","add_section","update_assignment_dates"].includes(a.type));
      if (hasRealActions) {
        setTimeout(() => window.location.reload(), 1800);
      }
    } catch(e) {
      typing.remove();
      addMsg("Помилка з\'єднання.", "bot", false);
    }
    btn.disabled = false;
  }

  btn.addEventListener("click", send);
  input.addEventListener("keydown", e => { if (e.key === "Enter") send(); });
})();
</script>';
    }
}
