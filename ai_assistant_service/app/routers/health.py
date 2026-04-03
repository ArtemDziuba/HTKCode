from fastapi import APIRouter

from app.config import settings

router = APIRouter()


@router.get("/health")
def health():
    return {"status": "ok", "model": settings.openai_model}
