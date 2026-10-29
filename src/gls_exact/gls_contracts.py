from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime
from typing import Any

from .gls_mapping import GlsRequestSettings


class GlsContractError(ValueError):
    """Een GLS-request of -response voldoet niet aan het gepubliceerde contract."""


@dataclass(frozen=True, slots=True)
class CreatedLabelUnit:
    unit_id: str
    unit_no: str
    unique_no: str = ""
    tracking_link: str = ""
    label_base64: str = ""


@dataclass(frozen=True, slots=True)
class CreateLabelResult:
    transaction_id: str
    shipment_tracking_link: str
    labels_base64: str
    units: list[CreatedLabelUnit]


def parse_create_label_response(payload: dict[str, Any]) -> CreateLabelResult:
    if payload.get("error") is True:
        message = payload.get("message") or payload.get("status") or "Onbekende GLS-fout"
        raise GlsContractError(str(message))

    raw_units = payload.get("units")
    if not isinstance(raw_units, list) or not raw_units:
        raise GlsContractError("GLS-response bevat geen units")

    units: list[CreatedLabelUnit] = []
    for index, item in enumerate(raw_units, start=1):
        if not isinstance(item, dict) or not item.get("unitNo"):
            raise GlsContractError(f"GLS unit {index} bevat geen unitNo")
        units.append(
            CreatedLabelUnit(
                unit_id=str(item.get("unitId", "")),
                unit_no=str(item["unitNo"]),
                unique_no=str(item.get("uniqueNo", "")),
                tracking_link=str(item.get("unitTrackingLink", "")),
                label_base64=str(item.get("label", "")),
            )
        )

    return CreateLabelResult(
        transaction_id=str(payload.get("transactionId", "")),
        shipment_tracking_link=str(payload.get("shipmentTrackingLink", "")),
        labels_base64=str(payload.get("labels", "")),
        units=units,
    )


def build_confirm_label_payload(
    unit_no: str,
    settings: GlsRequestSettings,
    *,
    shipping_date: datetime | None = None,
) -> dict[str, Any]:
    if not unit_no.strip():
        raise GlsContractError("unitNo is verplicht voor ConfirmLabel")

    payload: dict[str, Any] = {
        "username": settings.username,
        "password": settings.password,
        "shippingSystemName": settings.shipping_system_name,
        "shippingSystemVersion": settings.shipping_system_version,
        "shiptype": "p",
        "unitNo": unit_no,
    }
    if shipping_date:
        payload["shippingDate"] = shipping_date.isoformat()
    return payload


def build_track_details_payload(
    parcel_numbers: list[str],
    *,
    username: str,
    password: str = "",
) -> dict[str, Any]:
    cleaned = [number.strip() for number in parcel_numbers if number.strip()]
    if not username.strip():
        raise GlsContractError("userName is verplicht voor Track & Trace")
    if not cleaned:
        raise GlsContractError("Minimaal één parcelNumber is verplicht")
    payload: dict[str, Any] = {"userName": username, "parcelNumbers": cleaned}
    if password:
        payload["password"] = password
    return payload
