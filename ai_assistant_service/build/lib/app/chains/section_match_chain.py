"""Chain for matching an uploaded file to its best course week/section."""
import logging
import re

from langchain_core.prompts import ChatPromptTemplate

from app.dependencies import get_llm
from app.prompts.section_match import SECTION_MATCH_TEMPLATE

logger = logging.getLogger(__name__)


def run_section_match(
    filename: str,
    excerpt: str,
    week_titles: list[str],
    api_key: str,
) -> int:
    """Return 1-based section number, or 0 for General section.

    Clamps result to valid range [0, len(week_titles)].
    """
    if not week_titles:
        return 0

    weeks_list = "\n".join(f"{i + 1}. {title}" for i, title in enumerate(week_titles))
    excerpt_block = f"File excerpt:\n{excerpt[:800]}" if excerpt else ""

    prompt_text = SECTION_MATCH_TEMPLATE.format(
        filename=filename,
        excerpt_block=excerpt_block,
        weeks_list=weeks_list,
    )

    try:
        llm = get_llm(api_key, temperature=0.1, max_tokens=5)
        chain = ChatPromptTemplate.from_messages([("human", "{prompt}")]) | llm
        raw = chain.invoke({"prompt": prompt_text})
        reply = raw.content if hasattr(raw, "content") else str(raw)

        match = re.search(r"\d+", reply.strip())
        section = int(match.group()) if match else 0
        return section if 1 <= section <= len(week_titles) else 0
    except Exception as exc:
        logger.exception("section_match_chain error: %s", exc)
        return 0
