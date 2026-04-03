"""Chain for syllabus detection from raw document text."""
import json
import logging
import re

from langchain_core.prompts import ChatPromptTemplate

from app.dependencies import get_llm
from app.prompts.syllabus import SYLLABUS_MAX_CHARS, SYLLABUS_SYSTEM

logger = logging.getLogger(__name__)

_EMPTY = {"is_syllabus": False, "course_name": "", "weeks": []}


def run_syllabus_detection(text: str, api_key: str) -> dict:
    """Detect whether text is a course syllabus.

    Returns a dict with keys: is_syllabus (bool), course_name (str), weeks (list).
    Returns _EMPTY on any error.
    """
    truncated = text[:SYLLABUS_MAX_CHARS]
    try:
        llm = get_llm(api_key, temperature=0.1)
        chain = ChatPromptTemplate.from_messages([
            ("system", SYLLABUS_SYSTEM),
            ("human", "{document}"),
        ]) | llm

        raw = chain.invoke({"document": truncated})
        content = raw.content if hasattr(raw, "content") else str(raw)

        # Strip markdown fences if any
        content = re.sub(r"^```(?:json)?\s*", "", content.strip(), flags=re.IGNORECASE)
        content = re.sub(r"\s*```$", "", content, flags=re.IGNORECASE)

        parsed = json.loads(content)
        if not isinstance(parsed, dict):
            return _EMPTY

        # Validate shape
        return {
            "is_syllabus": bool(parsed.get("is_syllabus", False)),
            "course_name": str(parsed.get("course_name", "")),
            "weeks": parsed.get("weeks", []) if isinstance(parsed.get("weeks"), list) else [],
        }
    except Exception as exc:
        logger.exception("syllabus_chain error: %s", exc)
        return _EMPTY
