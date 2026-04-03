import logging

from fastapi import FastAPI

from app.config import settings
from app.routers import analyze, chat, generate, health

logging.basicConfig(level=settings.log_level.upper())

app = FastAPI(title="AI Assistant Service", version="0.1.0")

app.include_router(health.router)
app.include_router(chat.router, prefix="/chat")
app.include_router(generate.router, prefix="/generate")
app.include_router(analyze.router, prefix="/analyze")
