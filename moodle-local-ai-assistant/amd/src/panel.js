// AMD module: local_ai_assistant/panel
// Handles the AI assistant side panel on the new course creation page.

import Ajax from 'core/ajax';
import Notification from 'core/notification';

export const init = () => {
    const panel     = document.getElementById('ai-assistant-panel');
    const fab       = document.getElementById('ai-assistant-fab');
    const body      = document.getElementById('ai-assistant-body');
    const form      = document.getElementById('ai-assistant-form');
    const textarea  = document.getElementById('ai-assistant-prompt');
    const submitBtn = document.getElementById('ai-assistant-submit');
    const clearBtn  = document.getElementById('ai-assistant-clear');
    const copyBtn   = document.getElementById('ai-assistant-copy');
    const toggleBtn = document.getElementById('ai-assistant-toggle');
    const outputWrap = document.getElementById('ai-assistant-output-wrap');
    const output    = document.getElementById('ai-assistant-output');
    const errorEl   = document.getElementById('ai-assistant-error');

    if (!panel) {
        return;
    }

    // ── Collapse / expand ────────────────────────────────────────────────────

    const collapse = () => {
        panel.style.display = 'none';
        fab.hidden = false;
    };

    const expand = () => {
        panel.style.display = 'flex';
        fab.hidden = true;
        textarea.focus();
    };

    toggleBtn.addEventListener('click', collapse);
    fab.addEventListener('click', expand);

    // ── Helpers ──────────────────────────────────────────────────────────────

    const setThinking = (thinking) => {
        submitBtn.disabled = thinking;
        if (thinking) {
            submitBtn.textContent = submitBtn.dataset.thinkingLabel;
            submitBtn.classList.add('ai-assistant-btn--thinking');
        } else {
            submitBtn.textContent = submitBtn.dataset.submitLabel;
            submitBtn.classList.remove('ai-assistant-btn--thinking');
        }
    };

    const showOutput = (text) => {
        output.textContent = text;
        outputWrap.hidden = false;
        errorEl.hidden = true;
    };

    const showError = () => {
        errorEl.hidden = false;
        outputWrap.hidden = true;
    };

    // ── Submit ───────────────────────────────────────────────────────────────

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const prompt = textarea.value.trim();
        if (!prompt) {
            textarea.focus();
            return;
        }

        setThinking(true);
        errorEl.hidden = true;

        try {
            const [result] = Ajax.call([{
                methodname: 'local_ai_assistant_ask',
                args: {prompt},
            }]);

            const data = await result;
            showOutput(data.response);
        } catch (err) {
            showError();
            // Log to console for debugging but don't surface raw error to user.
            // eslint-disable-next-line no-console
            console.error('[AI Assistant]', err);
        } finally {
            setThinking(false);
        }
    });

    // ── Clear ────────────────────────────────────────────────────────────────

    clearBtn.addEventListener('click', () => {
        textarea.value = '';
        outputWrap.hidden = true;
        errorEl.hidden = true;
        textarea.focus();
    });

    // ── Copy to clipboard ────────────────────────────────────────────────────

    copyBtn.addEventListener('click', async () => {
        const text = output.textContent;
        if (!text) {
            return;
        }
        try {
            await navigator.clipboard.writeText(text);
            copyBtn.textContent = copyBtn.dataset.copiedLabel;
            setTimeout(() => {
                copyBtn.textContent = copyBtn.dataset.copyLabel;
            }, 2000);
        } catch {
            // Clipboard API not available — silently ignore.
        }
    });
};
