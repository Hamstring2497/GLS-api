from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date
from decimal import Decimal


class ShipmentValidationError(ValueError):
    """De levering kan nog niet naar GLS worden gestuurd."""


@dataclass(frozen=True, slots=True)
class DeliveryAddress:
    name: str
    street: str
    house_no: str
    zip_code: str
    city: str
    country_code: str
    contact: str = ""
    phone: str = ""
    email: str = ""
    addressee_type: str = "P"


@dataclass(frozen=True, slots=True)
class Parcel:
    weight_kg: Decimal


@dataclass(slots=True)
class Delivery:
    exact_entry_id: str
    delivery_number: str
    order_number: str
    address: DeliveryAddress
    shipping_date: date | None = None
    parcels: list[Parcel] = field(default_factory=list)
    status: str = "new"
    tracking_numbers: list[str] = field(default_factory=list)


def validate_delivery(delivery: Delivery) -> None:
    required = {
        "naam": delivery.address.name,
        "straat": delivery.address.street,
        "huisnummer": delivery.address.house_no,
        "postcode": delivery.address.zip_code,
        "plaats": delivery.address.city,
        "landcode": delivery.address.country_code,
    }
    missing = [name for name, value in required.items() if not value.strip()]
    if missing:
        raise ShipmentValidationError(f"Ontbrekende adresvelden: {', '.join(missing)}")

    if len(delivery.address.country_code.strip()) != 2:
        raise ShipmentValidationError("Landcode moet een ISO-code van twee letters zijn")
    if not delivery.parcels:
        raise ShipmentValidationError("Voeg minimaal één doos toe")

    for index, parcel in enumerate(delivery.parcels, start=1):
        if parcel.weight_kg <= 0:
            raise ShipmentValidationError(f"Gewicht van doos {index} moet groter zijn dan 0 kg")
        if parcel.weight_kg > Decimal(32):
            raise ShipmentValidationError(
                f"Doos {index} weegt {parcel.weight_kg} kg; GLS Parcel staat maximaal 32 kg toe"
            )
