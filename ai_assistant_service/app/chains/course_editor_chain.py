"""Chain for the course-editor block (rename sections, add sections, update dates)."""
import json
import logging
import re

from langchain_core.prompts import ChatPromptTemplate

from app.dependencies import get_llm
from app.prompts.course_editor import COURSE_EDITOR_SYSTEM_TEMPLATE
from app.schemas.analyze import CourseEditorAssignment, CourseEditorSection

logger = logging.getLogger(__name__)


def _build_sections_list(sections: list[CourseEditorSection]) -> str:
    if not sections:
        return "  (немає секцій)\n"
    return "".join(f"  Тиждень {s.section}: {s.name}\n" for s in sections)


def _build_assignments_list(assignments: list[CourseEditorAssignment]) -> str:
    if not assignments:
        return "  (немає завдань у курсі)\n"
    lines = []
    for a in assignments:
        lines.append(f'  ID {a.id}: "{a.name}"')
        lines.append(f"    allowsubmissionsfromdate: {a.allowsubmissionsfromdate}")
        lines.append(f"    duedate: {a.duedate}")
        lines.append(f"    cutoffdate: {a.cutoffdate}")
        lines.append(f"    gradingduedate: {a.gradingduedate}")
    return "\n".join(lines) + "\n"


def run_course_editor(
    message: str,
    sections: list[CourseEditorSection],
    assignments: list[CourseEditorAssignment],
    api_key: str,
) -> dict:
    """Returns {reply: str, actions: list[dict]}."""
    system = COURSE_EDITOR_SYSTEM_TEMPLATE.format(
        sections_list=_build_sections_list(sections),
        assignments_list=_build_assignments_list(assignments),
    )

    try:
        llm = get_llm(api_key, temperature=0.2).bind(response_format={"type": "json_object"})
        chain = ChatPromptTemplate.from_messages([
            ("system", system),
            ("human", "{message}"),
        ]) | llm

        raw = chain.invoke({"message": message})
        text = raw.content if hasattr(raw, "content") else str(raw)
        parsed = json.loads(text)
        return {
            "reply": parsed.get("reply", "Готово!"),
            "actions": parsed.get("actions", []) if isinstance(parsed.get("actions"), list) else [],
        }
    except Exception as exc:
        logger.exception("course_editor_chain error: %s", exc)
        return {"reply": f"Помилка AI: {exc}", "actions": []}
