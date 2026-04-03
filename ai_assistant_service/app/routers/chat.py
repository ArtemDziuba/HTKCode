from fastapi import APIRouter, HTTPException

from app.chains.chat_graph import chat_graph
from app.dependencies import get_llm
from app.schemas.chat import ChatRequest, ChatResponse

router = APIRouter()


@router.post("/turn", response_model=ChatResponse)
def chat_turn(request: ChatRequest):
    """One turn of the course-creation chatbot.

    PHP sends the full conversation history on every call; this endpoint is
    completely stateless.
    """
    try:
        llm = get_llm(request.api_key, temperature=0.4)
    except HTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc)) from exc

    history = [{"role": t.role, "content": t.content} for t in request.history]

    result = chat_graph.invoke({
        "history": history,
        "llm": llm,
        "has_syllabus": request.has_syllabus,
        "extracted_name": "",
        "extracted_shortname": "",
        "extracted_week_count": None,
        "validation_errors": [],
        "llm_response": {},
        "final_response": {},
    })

    return ChatResponse(**result["final_response"])
