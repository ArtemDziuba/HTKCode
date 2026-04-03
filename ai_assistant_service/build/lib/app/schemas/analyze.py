from pydantic import BaseModel


class WeekStructure(BaseModel):
    title: str
    topics: list[str] = []


class SyllabusRequest(BaseModel):
    text: str
    api_key: str = ""


class SyllabusResponse(BaseModel):
    is_syllabus: bool
    course_name: str = ""
    weeks: list[WeekStructure] = []


class MatchSectionRequest(BaseModel):
    filename: str
    excerpt: str = ""
    week_titles: list[str]
    api_key: str = ""


class MatchSectionResponse(BaseModel):
    section: int  # 1-based, or 0 for General


class CourseEditorSection(BaseModel):
    section: int
    name: str


class CourseEditorAssignment(BaseModel):
    id: int
    name: str
    allowsubmissionsfromdate: str
    duedate: str
    cutoffdate: str
    gradingduedate: str


class CourseEditorRequest(BaseModel):
    message: str
    sections: list[CourseEditorSection] = []
    assignments: list[CourseEditorAssignment] = []
    api_key: str = ""


class CourseEditorResponse(BaseModel):
    reply: str
    actions: list[dict] = []
