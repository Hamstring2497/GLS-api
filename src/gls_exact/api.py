from __future__ import annotations

import os
from decimal import Decimal
from typing import Any
from uuid import uuid4

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

from .domain import Delivery, DeliveryAddress, Parcel, ShipmentValidationError
from .gls_client import GlsApiError, GlsClient, GlsClientConfig
from .gls_contracts import GlsContractError, parse_create_label_response
from .gls_mapping import GlsRequestSettings, build_create_label_payload, redact_gls_payload
from .repository import DeliveryRepository


class AddressInput(BaseModel):
    name: str
    street: str
    house_no: str
    zip_code: str
    city: str
    country_code: str = Field(min_length=2, max_length=2)
    contact: str = ""
    phone: str = ""
    email: str = ""
    addressee_type: str = "P"


class DeliveryInput(BaseModel):
    exact_entry_id: str
    delivery_number: str
    order_number: str
    address: AddressInput
    shipping_date: str | None = None


class ParcelInput(BaseModel):
    weight_kg: Decimal


class ParcelsInput(BaseModel):
    parcels: list[ParcelInput]


def _delivery_dict(delivery: Delivery) -> dict[str, Any]:
    return {
        "exact_entry_id": delivery.exact_entry_id,
        "delivery_number": delivery.delivery_number,
        "order_number": delivery.order_number,
        "address": {
            "name": delivery.address.name,
            "street": delivery.address.street,
            "house_no": delivery.address.house_no,
            "zip_code": delivery.address.zip_code,
            "city": delivery.address.city,
            "country_code": delivery.address.country_code,
            "contact": delivery.address.contact,
            "phone": delivery.address.phone,
            "email": delivery.address.email,
            "addressee_type": delivery.address.addressee_type,
        },
        "shipping_date": delivery.shipping_date.isoformat() if delivery.shipping_date else None,
        "parcels": [{"weight_kg": str(parcel.weight_kg)} for parcel in delivery.parcels],
        "status": delivery.status,
        "tracking_numbers": delivery.tracking_numbers,
    }


def create_app(database_path: str | None = None) -> FastAPI:
    app = FastAPI(title="Exact GLS Werkplek", version="0.1.0")
    repository = DeliveryRepository(
        database_path or os.getenv("APP_DATABASE_PATH", "gls_exact.sqlite3")
    )

    @app.get("/health")
    def health() -> dict[str, str]:
        return {"status": "ok", "gls_mode": os.getenv("GLS_MODE", "mock")}

    @app.get("/api/deliveries")
    def list_deliveries() -> list[dict[str, Any]]:
        return [_delivery_dict(delivery) for delivery in repository.list_all()]

    @app.post("/api/deliveries", status_code=201)
    def create_delivery(data: DeliveryInput) -> dict[str, Any]:
        # Dit endpoint is de eerste testinvoer. De Exact-adapter gaat later exact
        # dezelfde domeinoperatie gebruiken na ontvangst van een webhook.
        from datetime import date

        delivery = Delivery(
            exact_entry_id=data.exact_entry_id,
            delivery_number=data.delivery_number,
            order_number=data.order_number,
            address=DeliveryAddress(**data.address.model_dump()),
            shipping_date=date.fromisoformat(data.shipping_date) if data.shipping_date else None,
        )
        repository.save_delivery(delivery)
        return _delivery_dict(delivery)

    @app.put("/api/deliveries/{exact_entry_id}/parcels")
    def set_parcels(exact_entry_id: str, data: ParcelsInput) -> dict[str, Any]:
        delivery = repository.get(exact_entry_id)
        if delivery is None:
            raise HTTPException(status_code=404, detail="Levering niet gevonden")
        parcels = [Parcel(item.weight_kg) for item in data.parcels]
        repository.replace_parcels(exact_entry_id, parcels)
        return _delivery_dict(repository.get(exact_entry_id))

    @app.post("/api/deliveries/{exact_entry_id}/labels")
    def create_labels(exact_entry_id: str) -> dict[str, Any]:
        delivery = repository.get(exact_entry_id)
        if delivery is None:
            raise HTTPException(status_code=404, detail="Levering niet gevonden")
        if delivery.status == "labels_created":
            raise HTTPException(status_code=409, detail="Voor deze levering zijn al labels gemaakt")

        request_settings = GlsRequestSettings(
            username=os.getenv("GLS_USERNAME", "mock-user"),
            password=os.getenv("GLS_PASSWORD", "mock-password"),
            customer_no=os.getenv("GLS_CUSTOMER_NO", ""),
            label_type=os.getenv("GLS_LABEL_TYPE", "pdf"),
        )
        try:
            payload = build_create_label_payload(delivery, request_settings)
        except ShipmentValidationError as exc:
            raise HTTPException(status_code=422, detail=str(exc)) from exc

        mode = os.getenv("GLS_MODE", "mock").lower()
        if mode == "mock":
            tracking_numbers = [f"MOCK-{uuid4().hex[:12].upper()}" for _ in delivery.parcels]
            response = {
                "mock": True,
                "units": [
                    {
                        "unitId": chr(65 + index),
                        "unitNo": number,
                        "unitTrackingLink": f"https://example.invalid/{number}",
                    }
                    for index, number in enumerate(tracking_numbers)
                ],
            }
        else:
            try:
                response = GlsClient(
                    GlsClientConfig(
                        create_label_url=os.getenv("GLS_CREATE_LABEL_URL", ""),
                        api_key=os.getenv("GLS_API_KEY", ""),
                        api_key_header=os.getenv("GLS_API_KEY_HEADER", "Ocp-Apim-Subscription-Key"),
                    )
                ).create_labels(payload)
                parsed = parse_create_label_response(response)
            except (GlsApiError, GlsContractError) as exc:
                raise HTTPException(status_code=502, detail=str(exc)) from exc
            tracking_numbers = [unit.unit_no for unit in parsed.units]

        repository.save_gls_result(
            exact_entry_id,
            redact_gls_payload(payload),
            response,
            tracking_numbers,
        )
        return {
            "status": "labels_created",
            "tracking_numbers": tracking_numbers,
            "gls_request": redact_gls_payload(payload),
            "gls_response": response,
            "exact_tracking_update": "pending",
        }

    return app


app = create_app()
