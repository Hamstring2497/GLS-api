from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from .domain import Delivery, validate_delivery


@dataclass(frozen=True, slots=True)
class GlsRequestSettings:
    username: str
    password: str
    customer_no: str = ""
    label_type: str = "pdf"
    shipping_system_name: str = "Exact GLS Werkplek"
    shipping_system_version: str = "0.1.0"


def _unit_id(position: int) -> str:
    """Maak A..Z, AA..AZ enzovoort, zodat iedere doos een stabiele UnitID krijgt."""
    if position < 1:
        raise ValueError("position moet minimaal 1 zijn")
    result = ""
    value = position
    while value:
        value, remainder = divmod(value - 1, 26)
        result = chr(65 + remainder) + result
    return result


def build_create_label_payload(
    delivery: Delivery,
    settings: GlsRequestSettings,
) -> dict[str, Any]:
    validate_delivery(delivery)

    address = delivery.address
    payload: dict[str, Any] = {
        "username": settings.username,
        "password": settings.password,
        "shippingSystemName": settings.shipping_system_name,
        "shippingSystemVersion": settings.shipping_system_version,
        "shiptype": "p",
        "reference": delivery.delivery_number,
        "labelType": settings.label_type,
        "trackingLinkType": "u",
        "addresses": {
            "deliveryAddress": {
                "name1": address.name,
                "street": address.street,
                "houseNo": address.house_no,
                "countryCode": address.country_code.upper(),
                "zipCode": address.zip_code.replace(" ", "").upper(),
                "city": address.city,
                "addresseeType": address.addressee_type.lower(),
            }
        },
        "units": [
            {
                "unitId": _unit_id(index),
                "weight": float(parcel.weight_kg),
                "additionalInfo1": f"Order: {delivery.order_number}",
            }
            for index, parcel in enumerate(delivery.parcels, start=1)
        ],
    }

    delivery_address = payload["addresses"]["deliveryAddress"]
    if address.contact:
        delivery_address["contact"] = address.contact
    if address.phone:
        delivery_address["phone"] = address.phone
    if address.email:
        delivery_address["email"] = address.email
    if delivery.shipping_date:
        payload["shippingDate"] = delivery.shipping_date.isoformat()
    if settings.customer_no:
        payload["customerNo"] = settings.customer_no

    return payload


def redact_gls_payload(payload: dict[str, Any]) -> dict[str, Any]:
    """Maak een logbare kopie zonder MyGLS-inloggegevens."""
    redacted = dict(payload)
    redacted["username"] = "***"
    redacted["password"] = "***"
    return redacted
