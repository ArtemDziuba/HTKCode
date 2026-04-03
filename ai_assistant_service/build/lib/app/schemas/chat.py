from pydantic import BaseModel


class HistoryTurn(BaseModel):
    role: str  # "user" | "assistant"
    content: str


class ChatRequest(BaseModel):
    history: list[HistoryTurn]
    api_key: str = ""


class WeekStructure(BaseModel):
    title: str
    topics: list[str] = []


class ChatResponse(BaseModel):
    message: str
    ready: bool = False
    course_name: str = ""
    course_shortname: str = ""
    weeks: list[WeekStructure] = []
    description: str = ""
    validation_errors: list[str] = []
