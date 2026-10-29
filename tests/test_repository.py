from decimal import Decimal
from tempfile import TemporaryDirectory
from unittest import TestCase

from gls_exact.domain import Delivery, DeliveryAddress, Parcel
from gls_exact.repository import DeliveryRepository


class DeliveryRepositoryTests(TestCase):
    def test_delivery_and_boxes_survive_roundtrip(self) -> None:
        with TemporaryDirectory() as temp_dir:
            repository = DeliveryRepository(f"{temp_dir}/test.sqlite3")
            delivery = Delivery(
                exact_entry_id="entry-1",
                delivery_number="1001",
                order_number="2002",
                address=DeliveryAddress(
                    name="Test B.V.",
                    street="Teststraat",
                    house_no="1",
                    zip_code="1234AB",
                    city="Utrecht",
                    country_code="NL",
                ),
            )

            repository.save_delivery(delivery)
            repository.replace_parcels(
                delivery.exact_entry_id,
                [Parcel(Decimal("1.25")), Parcel(Decimal("2.50"))],
            )

            stored = repository.get(delivery.exact_entry_id)
            self.assertIsNotNone(stored)
            self.assertEqual(stored.status, "ready")
            self.assertEqual(
                [item.weight_kg for item in stored.parcels],
                [
                    Decimal("1.25"),
                    Decimal("2.50"),
                ],
            )
