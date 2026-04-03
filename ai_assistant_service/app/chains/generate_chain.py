"""Simple LCEL chain for content generation tasks (outline, quiz, assignment, rewrite, course_name)."""
import logging

from langchain_core.output_parsers import StrOutputParser
from langchain_core.prompts import ChatPromptTemplate

from app.dependencies import get_llm
from app.prompts.generation import GENERATION_PROMPTS, GENERATION_TEMPERATURES
from app.schemas.generate import GenerateTask

logger = logging.getLogger(__name__)


def run_generate(task: GenerateTask, prompt: str, api_key: str) -> tuple[str, str]:
    """Run a generation task. Returns (result_text, error_string).

    error_string is empty on success.
    """
    system_prompt = GENERATION_PROMPTS[task]
    temperature = GENERATION_TEMPERATURES[task]
    max_tokens = 30 if task == GenerateTask.course_name else None

    try:
        llm = get_llm(api_key, temperature=temperature, max_tokens=max_tokens)
        chain = (
            ChatPromptTemplate.from_messages([
                ("system", system_prompt),
                ("human", "{input}"),
            ])
            | llm
            | StrOutputParser()
        )
        result = chain.invoke({"input": prompt})
        return result.strip(), ""
    except Exception as exc:
        logger.exception("generate_chain error for task %s: %s", task, exc)
        return "", str(exc)
