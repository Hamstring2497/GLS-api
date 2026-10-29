from __future__ import annotations

from dataclasses import dataclass
from typing import Any

import httpx


class GlsApiError(RuntimeError):
    pass


@dataclass(frozen=True, slots=True)
class GlsClientConfig:
    create_label_url: str
    api_key: str
    api_key_header: str = "Ocp-Apim-Subscription-Key"
    timeout_seconds: float = 30.0


class GlsClient:
    def __init__(self, config: GlsClientConfig) -> None:
        self.config = config

    def create_labels(self, payload: dict[str, Any]) -> dict[str, Any]:
        if not self.config.create_label_url:
            raise GlsApiError("GLS_CREATE_LABEL_URL is niet ingesteld")
        if not self.config.api_key:
            raise GlsApiError("GLS_API_KEY is niet ingesteld")

        try:
            response = httpx.post(
                self.config.create_label_url,
                headers={self.config.api_key_header: self.config.api_key},
                json=payload,
                timeout=self.config.timeout_seconds,
            )
            response.raise_for_status()
        except httpx.HTTPError as exc:
            # Bij een timeout mag de caller niet blind opnieuw CreateLabel uitvoeren:
            # GLS kan de zending al hebben aangemaakt.
            raise GlsApiError(f"GLS CreateLabel mislukt: {exc}") from exc

        try:
            return response.json()
        except ValueError as exc:
            raise GlsApiError("GLS gaf geen geldige JSON-response terug") from exc
