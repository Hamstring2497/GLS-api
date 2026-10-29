from datetime import date
from decimal import Decimal
from unittest import TestCase

from gls_exact.domain import (
    Delivery,
    DeliveryAddress,
    Parcel,
    ShipmentValidationError,
)
from gls_exact.gls_mapping import GlsRequestSettings, build_create_label_payload


class GlsMappingTests(TestCase):
    def setUp(self) -> None:
        self.delivery = Delivery(
            exact_entry_id="6db186b0-44e8-4b31-b522-a0345c1beaf8",
            delivery_number="202600123",
            order_number="202600456",
            shipping_date=date(2026, 10, 28),
            address=DeliveryAddress(
                name="Voorbeeldbedrijf B.V.",
                street="Dorpsstraat",
                house_no="12A",
                zip_code="1234 AB",
                city="Utrecht",
                country_code="nl",
                contact="R. Voorbeeld",
                email="magazijn@example.nl",
            ),
            parcels=[Parcel(Decimal("4.5")), Parcel(Decimal("7.2"))],
        )

    def test_builds_one_gls_unit_per_box(self) -> None:
        payload = build_create_label_payload(
            self.delivery,
            GlsRequestSettings(username="user", password="secret"),
        )

        self.assertEqual(payload["shiptype"], "p")
        self.assertEqual(payload["reference"], "202600123")
        self.assertEqual(payload["shippingDate"], "2026-10-28")
        self.assertEqual(payload["addresses"]["deliveryAddress"]["zipCode"], "1234AB")
        self.assertEqual(
            payload["units"],
            [
                {"unitId": "A", "weight": 4.5, "additionalInfo1": "Order: 202600456"},
                {"unitId": "B", "weight": 7.2, "additionalInfo1": "Order: 202600456"},
            ],
        )

    def test_rejects_parcel_over_32_kg(self) -> None:
        self.delivery.parcels = [Parcel(Decimal("32.01"))]

        with self.assertRaisesRegex(ShipmentValidationError, "maximaal 32 kg"):
            build_create_label_payload(
                self.delivery,
                GlsRequestSettings(username="user", password="secret"),
            )

    def test_requires_at_least_one_box(self) -> None:
        self.delivery.parcels = []

        with self.assertRaisesRegex(ShipmentValidationError, "minimaal één doos"):
            build_create_label_payload(
                self.delivery,
                GlsRequestSettings(username="user", password="secret"),
            )
