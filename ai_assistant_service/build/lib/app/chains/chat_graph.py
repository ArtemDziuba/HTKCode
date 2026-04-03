"""LangGraph pipeline for the course-creation chatbot.

Flow per request
────────────────
  parse_state
      │
  validate_inputs  ──── errors? ──► inject_error_reply
      │ no errors                          │
      ▼                                    │
  llm_chat_node                            │
      │                                    │
      └──────────────► format_response ◄──┘
                              │
                           return

PHP owns session history; this graph is stateless per request.
"""
from __future__ import annotations

import json
import logging
import re
from typing import Any, TypedDict

from langchain_core.messages import AIMessage, HumanMessage, SystemMessage
from langgraph.graph import END, StateGraph

from app.prompts.chat_system import CHAT_SYSTEM
from app.validators.course_inputs import validate_all

logger = logging.getLogger(__name__)


# ── State ─────────────────────────────────────────────────────────────────────

class ChatState(TypedDict):
    # inputs
    history: list[dict]   # [{role, content}, ...]
    llm: Any              # ChatGoogleGenerativeAI instance

    # derived
    extracted_name: str
    extracted_shortname: str
    extracted_week_count: int | None

    # outputs
    validation_errors: list[str]
    llm_response: dict   # raw parsed JSON from LLM
    final_response: dict


# ── Helpers ───────────────────────────────────────────────────────────────────

def _extract_fields_from_history(history: list[dict]) -> tuple[str, str, int | None]:
    """Scan history for the most recent assistant JSON turn and pull out
    course_name, course_shortname, and week_count (derived from weeks length).
    """
    for turn in reversed(history):
        if turn.get("role") != "assistant":
            continue
        try:
            data = json.loads(turn["content"])
            name = data.get("course_name", "")
            shortname = data.get("course_shortname", "")
            weeks = data.get("weeks", [])
            count = len(weeks) if weeks else None
            if name or shortname or count:
                return name, shortname, count
        except (json.JSONDecodeError, TypeError):
            continue
    return "", "", None


def _history_to_messages(history: list[dict], system: str) -> list:
    messages = [SystemMessage(content=system)]
    for turn in history:
        role = turn.get("role", "user")
        content = turn.get("content", "")
        if role == "assistant":
            messages.append(AIMessage(content=content))
        else:
            messages.append(HumanMessage(content=content))
    return messages


# ── Graph nodes ───────────────────────────────────────────────────────────────

def parse_state(state: ChatState) -> ChatState:
    name, shortname, count = _extract_fields_from_history(state["history"])
    state["extracted_name"] = name
    state["extracted_shortname"] = shortname
    state["extracted_week_count"] = count
    return state


def validate_inputs(state: ChatState) -> ChatState:
    errors = validate_all(
        state["extracted_name"],
        state["extracted_shortname"],
        state["extracted_week_count"],
    )
    state["validation_errors"] = errors
    return state


def inject_error_reply(state: ChatState) -> ChatState:
    """Build a reply from validation errors without calling the LLM."""
    errors = state["validation_errors"]
    message = "Виявлено помилки у введених даних:\n" + "\n".join(f"• {e}" for e in errors)
    state["llm_response"] = {
        "message": message,
        "ready": False,
        "course_name": state["extracted_name"],
        "course_shortname": state["extracted_shortname"],
        "weeks": [],
        "description": "",
    }
    return state


def llm_chat_node(state: ChatState) -> ChatState:
    llm = state["llm"]
    messages = _history_to_messages(state["history"], CHAT_SYSTEM)

    try:
        raw = llm.invoke(messages)
        text = raw.content if hasattr(raw, "content") else str(raw)
        # Strip accidental markdown fences
        text = re.sub(r"^```(?:json)?\s*", "", text.strip(), flags=re.IGNORECASE)
        text = re.sub(r"\s*```$", "", text, flags=re.IGNORECASE)
        parsed = json.loads(text)
        state["llm_response"] = parsed if isinstance(parsed, dict) else {}
    except Exception as exc:
        logger.exception("LLM chat node error: %s", exc)
        state["llm_response"] = {
            "message": "Не вдалося отримати відповідь від AI. Спробуйте ще раз.",
            "ready": False,
            "course_name": "",
            "course_shortname": "",
            "weeks": [],
            "description": "",
        }
    return state


def format_response(state: ChatState) -> ChatState:
    resp = state.get("llm_response", {})
    state["final_response"] = {
        "message": resp.get("message", "Щось пішло не так."),
        "ready": bool(resp.get("ready", False)),
        "course_name": resp.get("course_name", ""),
        "course_shortname": resp.get("course_shortname", ""),
        "weeks": resp.get("weeks", []),
        "description": resp.get("description", ""),
        "validation_errors": state.get("validation_errors", []),
    }
    return state


# ── Routing ───────────────────────────────────────────────────────────────────

def route_after_validation(state: ChatState) -> str:
    if state["validation_errors"]:
        return "inject_error_reply"
    return "llm_chat_node"


# ── Build graph ───────────────────────────────────────────────────────────────

def build_chat_graph():
    g = StateGraph(ChatState)

    g.add_node("parse_state", parse_state)
    g.add_node("validate_inputs", validate_inputs)
    g.add_node("inject_error_reply", inject_error_reply)
    g.add_node("llm_chat_node", llm_chat_node)
    g.add_node("format_response", format_response)

    g.set_entry_point("parse_state")
    g.add_edge("parse_state", "validate_inputs")
    g.add_conditional_edges(
        "validate_inputs",
        route_after_validation,
        {
            "inject_error_reply": "inject_error_reply",
            "llm_chat_node": "llm_chat_node",
        },
    )
    g.add_edge("inject_error_reply", "format_response")
    g.add_edge("llm_chat_node", "format_response")
    g.add_edge("format_response", END)

    return g.compile()


chat_graph = build_chat_graph()
