from fastapi import APIRouter, HTTPException

from app.chains.generate_chain import run_generate
from app.schemas.generate import (
    CourseNameRequest,
    CourseNameResponse,
    GenerateRequest,
    GenerateResponse,
    GenerateTask,
)

router = APIRouter()


@router.post("", response_model=GenerateResponse)
def generate(request: GenerateRequest):
    """Generate course content: outline, quiz, assignment, or rewrite."""
    result, error = run_generate(request.task, request.prompt, request.api_key)
    if error:
        return GenerateResponse(error=error)
    return GenerateResponse(result=result)


@router.post("/course-name", response_model=CourseNameResponse)
def extract_course_name(request: CourseNameRequest):
    """Extract a clean course title from a raw prompt string."""
    result, error = run_generate(GenerateTask.course_name, request.prompt, request.api_key)
    if error or not result:
        # Fall back to the raw prompt rather than returning an error
        return CourseNameResponse(course_name=request.prompt[:100])
    # Sanity check: too long means the model returned something unexpected
    if len(result) > 100:
        return CourseNameResponse(course_name=request.prompt[:100])
    return CourseNameResponse(course_name=result)
