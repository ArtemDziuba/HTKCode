"""Deterministic validators for course creation inputs.

Run these BEFORE calling the LLM so that clearly invalid values get
an instant Ukrainian error message without burning an API call.
"""
import re

# Shortname: letters (Latin + Cyrillic), digits, hyphen, underscore — no spaces
_SHORTNAME_RE = re.compile(r"^[a-zA-Z0-9\-_а-яА-ЯіІїЇєЄ']+$")


def validate_shortname(value: str) -> list[str]:
    """Return a list of Ukrainian error strings, or [] if valid / not yet provided."""
    if not value:
        return []  # not provided yet — LLM will ask; validator stays silent
    errors: list[str] = []
    if " " in value:
        errors.append(
            "Коротка назва не може містити пробіли. Використовуйте '-' або '_' замість пробілу."
        )
    if not _SHORTNAME_RE.match(value):
        errors.append(
            "Коротка назва може містити лише літери, цифри, '-' та '_'."
        )
    if len(value) > 100:
        errors.append("Коротка назва занадто довга (максимум 100 символів).")
    return errors


def validate_week_count(value: int | str | None) -> list[str]:
    """Return a list of Ukrainian error strings, or [] if valid / not yet provided."""
    if value is None:
        return []
    try:
        n = int(value)
    except (TypeError, ValueError):
        return ["Кількість тижнів має бути цілим числом від 1 до 52."]
    if n < 1 or n > 52:
        return ["Кількість тижнів має бути від 1 до 52."]
    return []


def validate_course_name(value: str) -> list[str]:
    """Return a list of Ukrainian error strings, or [] if valid / not yet provided."""
    if not value:
        return []
    if len(value.strip()) == 0:
        return ["Назва курсу не може бути порожньою або складатися лише з пробілів."]
    if len(value) > 255:
        return ["Назва курсу занадто довга (максимум 255 символів)."]
    return []


def validate_all(
    course_name: str,
    shortname: str,
    week_count: int | str | None,
) -> list[str]:
    """Run all validators and return combined error list."""
    errors: list[str] = []
    errors.extend(validate_course_name(course_name))
    errors.extend(validate_shortname(shortname))
    errors.extend(validate_week_count(week_count))
    return errors
