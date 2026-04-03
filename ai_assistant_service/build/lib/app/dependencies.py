from fastapi import HTTPException
from langchain_google_genai import ChatGoogleGenerativeAI

from app.config import settings


def get_llm(api_key: str, temperature: float = 0.4, max_tokens: int | None = None) -> ChatGoogleGenerativeAI:
    """Build a ChatGoogleGenerativeAI instance.

    Uses the per-request api_key if provided, otherwise falls back to the
    GEMINI_API_KEY environment variable.
    """
    key = api_key or settings.gemini_api_key
    if not key:
        raise HTTPException(
            status_code=500,
            detail="Gemini API key not configured. Set GEMINI_API_KEY env var or pass api_key in the request.",
        )
    kwargs: dict = {
        "model": settings.gemini_model,
        "google_api_key": key,
        "temperature": temperature,
    }
    if max_tokens is not None:
        kwargs["max_output_tokens"] = max_tokens
    return ChatGoogleGenerativeAI(**kwargs)
