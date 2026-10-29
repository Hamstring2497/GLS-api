from __future__ import annotations

import json
import sqlite3
from datetime import date
from decimal import Decimal
from pathlib import Path
from typing import Any

from .domain import Delivery, DeliveryAddress, Parcel


class DeliveryRepository:
    def __init__(self, database_path: str | Path) -> None:
        self.database_path = str(database_path)
        self._initialize()

    def _connect(self) -> sqlite3.Connection:
        connection = sqlite3.connect(self.database_path)
        connection.row_factory = sqlite3.Row
        connection.execute("PRAGMA foreign_keys = ON")
        return connection

    def _initialize(self) -> None:
        with self._connect() as connection:
            connection.executescript(
                """
                CREATE TABLE IF NOT EXISTS deliveries (
                    exact_entry_id TEXT PRIMARY KEY,
                    delivery_number TEXT NOT NULL,
                    order_number TEXT NOT NULL,
                    address_json TEXT NOT NULL,
                    shipping_date TEXT,
                    status TEXT NOT NULL DEFAULT 'new',
                    tracking_numbers_json TEXT NOT NULL DEFAULT '[]',
                    gls_request_json TEXT,
                    gls_response_json TEXT,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS parcels (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    exact_entry_id TEXT NOT NULL REFERENCES deliveries(exact_entry_id)
                        ON DELETE CASCADE,
                    position INTEGER NOT NULL,
                    weight_kg TEXT NOT NULL,
                    UNIQUE(exact_entry_id, position)
                );
                """
            )

    def save_delivery(self, delivery: Delivery) -> None:
        address_json = json.dumps(
            {
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
            ensure_ascii=False,
        )
        with self._connect() as connection:
            connection.execute(
                """
                INSERT INTO deliveries (
                    exact_entry_id, delivery_number, order_number, address_json,
                    shipping_date, status, tracking_numbers_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(exact_entry_id) DO UPDATE SET
                    delivery_number = excluded.delivery_number,
                    order_number = excluded.order_number,
                    address_json = excluded.address_json,
                    shipping_date = excluded.shipping_date,
                    updated_at = CURRENT_TIMESTAMP
                """,
                (
                    delivery.exact_entry_id,
                    delivery.delivery_number,
                    delivery.order_number,
                    address_json,
                    delivery.shipping_date.isoformat() if delivery.shipping_date else None,
                    delivery.status,
                    json.dumps(delivery.tracking_numbers),
                ),
            )

    def replace_parcels(self, exact_entry_id: str, parcels: list[Parcel]) -> None:
        with self._connect() as connection:
            connection.execute("DELETE FROM parcels WHERE exact_entry_id = ?", (exact_entry_id,))
            connection.executemany(
                "INSERT INTO parcels (exact_entry_id, position, weight_kg) VALUES (?, ?, ?)",
                [
                    (exact_entry_id, position, str(parcel.weight_kg))
                    for position, parcel in enumerate(parcels, start=1)
                ],
            )
            connection.execute(
                "UPDATE deliveries SET status = 'ready', updated_at = CURRENT_TIMESTAMP "
                "WHERE exact_entry_id = ?",
                (exact_entry_id,),
            )

    def get(self, exact_entry_id: str) -> Delivery | None:
        with self._connect() as connection:
            row = connection.execute(
                "SELECT * FROM deliveries WHERE exact_entry_id = ?", (exact_entry_id,)
            ).fetchone()
            if row is None:
                return None
            parcel_rows = connection.execute(
                "SELECT weight_kg FROM parcels WHERE exact_entry_id = ? ORDER BY position",
                (exact_entry_id,),
            ).fetchall()
        return self._row_to_delivery(row, parcel_rows)

    def list_all(self) -> list[Delivery]:
        with self._connect() as connection:
            ids = [
                row[0]
                for row in connection.execute(
                    "SELECT exact_entry_id FROM deliveries ORDER BY created_at DESC"
                ).fetchall()
            ]
        return [delivery for entry_id in ids if (delivery := self.get(entry_id)) is not None]

    def save_gls_result(
        self,
        exact_entry_id: str,
        request_payload: dict[str, Any],
        response_payload: dict[str, Any],
        tracking_numbers: list[str],
    ) -> None:
        with self._connect() as connection:
            connection.execute(
                """
                UPDATE deliveries
                SET status = 'labels_created', tracking_numbers_json = ?,
                    gls_request_json = ?, gls_response_json = ?, updated_at = CURRENT_TIMESTAMP
                WHERE exact_entry_id = ?
                """,
                (
                    json.dumps(tracking_numbers),
                    json.dumps(request_payload, ensure_ascii=False),
                    json.dumps(response_payload, ensure_ascii=False),
                    exact_entry_id,
                ),
            )

    @staticmethod
    def _row_to_delivery(row: sqlite3.Row, parcel_rows: list[sqlite3.Row]) -> Delivery:
        address = DeliveryAddress(**json.loads(row["address_json"]))
        return Delivery(
            exact_entry_id=row["exact_entry_id"],
            delivery_number=row["delivery_number"],
            order_number=row["order_number"],
            address=address,
            shipping_date=date.fromisoformat(row["shipping_date"])
            if row["shipping_date"]
            else None,
            parcels=[Parcel(Decimal(item["weight_kg"])) for item in parcel_rows],
            status=row["status"],
            tracking_numbers=json.loads(row["tracking_numbers_json"]),
        )
