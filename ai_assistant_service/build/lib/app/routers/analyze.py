from fastapi import APIRouter

from app.chains.course_editor_chain import run_course_editor
from app.chains.section_match_chain import run_section_match
from app.chains.syllabus_chain import run_syllabus_detection
from app.schemas.analyze import (
    CourseEditorRequest,
    CourseEditorResponse,
    MatchSectionRequest,
    MatchSectionResponse,
    SyllabusRequest,
    SyllabusResponse,
)

router = APIRouter()


@router.post("/syllabus", response_model=SyllabusResponse)
def detect_syllabus(request: SyllabusRequest):
    """Detect whether the provided document text is a course syllabus."""
    result = run_syllabus_detection(request.text, request.api_key)
    return SyllabusResponse(**result)


@router.post("/match-section", response_model=MatchSectionResponse)
def match_section(request: MatchSectionRequest):
    """Match an uploaded file to the most relevant course week/section."""
    section = run_section_match(
        request.filename,
        request.excerpt,
        request.week_titles,
        request.api_key,
    )
    return MatchSectionResponse(section=section)


@router.post("/course-editor", response_model=CourseEditorResponse)
def course_editor(request: CourseEditorRequest):
    """Handle a course editor command (rename sections, add sections, update dates)."""
    result = run_course_editor(
        request.message,
        request.sections,
        request.assignments,
        request.api_key,
    )
    return CourseEditorResponse(**result)
