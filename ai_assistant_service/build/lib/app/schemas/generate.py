from enum import Enum

from pydantic import BaseModel


class GenerateTask(str, Enum):
    outline = "outline"
    quiz = "quiz"
    assignment = "assignment"
    rewrite = "rewrite"
    course_name = "course_name"


class GenerateRequest(BaseModel):
    task: GenerateTask
    prompt: str
    api_key: str = ""


class GenerateResponse(BaseModel):
    result: str | None = None
    error: str | None = None


class CourseNameRequest(BaseModel):
    prompt: str
    api_key: str = ""


class CourseNameResponse(BaseModel):
    course_name: str
