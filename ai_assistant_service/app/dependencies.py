from fastapi import HTTPException
from langchain_openai import ChatOpenAI

from app.config import settings


def get_llm(api_key: str, temperature: float = 0.4, max_tokens: int | None = None) -> ChatOpenAI:
    """Build a ChatOpenAI instance.

    Uses the per-request api_key if provided, otherwise falls back to the
    OPENAI_API_KEY environment variable.
    """
    key = api_key or settings.openai_api_key
    if not key:
        raise HTTPException(
            status_code=500,
            detail="OpenAI API key not configured. Set OPENAI_API_KEY env var or pass api_key in the request.",
        )
    kwargs: dict = {
        "model": settings.openai_model,
        "openai_api_key": key,
        "temperature": temperature,
    }
    if max_tokens is not None:
        kwargs["max_tokens"] = max_tokens
    return ChatOpenAI(**kwargs)
