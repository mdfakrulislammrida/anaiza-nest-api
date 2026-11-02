# Delivery fee: "inside Dhaka" means Dhaka city (Batch FEE1-B)

The fee rule is unchanged: inside Dhaka ৳80 (1-3 business days), outside ৳150 (3-5 business days), free delivery over ৳10,000 only inside Dhaka.
What changed is what "inside Dhaka" means: **Dhaka city only**, no longer the whole Dhaka division. The amounts and days are still edited in Site settings
(Delivery & returns); only the boundary is in code.

## The rule

An address is inside Dhaka city when the division is `Dhaka`, the district is `Dhaka`, and the thana is in the city list. Everything else is outside:
Gazipur, Narayanganj, Munshiganj, Tangail, Faridpur, Kishoreganj and the other districts of the Dhaka division, the five upazilas of Dhaka district
below, and every other division. Names are compared exactly as stored (the storefront sends them unchanged); only case and extra spaces are ignored.
Nothing is renamed.

A Dhaka-district address with no thana, or a thana in neither list, is charged the outside fee and a warning is written to the log (division, district,
thana, reason). It never stops an order.

## Where it lives (one place)

`config/delivery.php`: two lists, `dhaka_city.thanas` (50) and `dhaka_district_outside` (5). To move a thana, take it out of one list and put it in the
other (spelled as the storefront sends it), then `php artisan optimize:clear`. The class `App\Support\DhakaCity` reads the lists; `App\Support\DeliveryFeeCalculator`
turns the answer into a fee.

* City thanas: Adabor, Airport, Badda, Banani, Bangshal, Bhashantek, Cantonment, Chackbazar, Dakshin Khan, Darus-Salam, Demra, Dhanmondi, Gandaria, Gulshan,
  Hatirjheel, Hazaribagh, Jatrabari, Kadamtoli, Kafrul, Kalabagan, Kamrangirchar, Khilgaon, Khilkhet, Kotwali, Lalbagh, Mirpur, Mohammadpur, Motijheel, Mugda,
  New Market, Pallabi, Paltan, Ramna, Rampura, Rupnagar, Sabujbag, Shah Ali, Shahbag, Shahjahanpur, Sher-e-Bangla Nagar, Shyampur, Sutrapur, Tejgaon,
  Tejgaon Industrial Area, Turag, Uttar Khan, Uttara East, Uttara West, Vatara, Wari.
* Outside, in Dhaka district: Dhamrai, Dohar, Keraniganj, Nawabganj, Savar.

## What the storefront reads

* `GET /api/delivery-quote?division=&district=&thana=&subtotal=` returns `data`: `zone` (`dhaka_city` or `outside_dhaka`), `inside_dhaka_city`, `reason`, `fee`
  (what the order will charge), `free_delivery`, `free_delivery_over`, `standard_fee` (the fee before free delivery), `delivery_days {min,max}`. It uses the same
  code as the order, stores nothing and logs nothing.
* `GET /api/site-settings` → `data.policy.dhaka_city` has `division`, `district`, `thanas` and `outside_upazilas`, next to the existing `free_delivery_threshold`,
  `delivery_fee_dhaka`, `delivery_fee_outside_dhaka` and `delivery_days_*`, so the storefront can decide client-side from the same list.

The server decides the fee again when the order is created, whatever the browser showed. Orders already stored keep the fee they were created with
(nothing is recalculated and there is no data migration).

## Not changed

Courier booking, tracking events and the purchase value (which excludes the delivery fee) are untouched. Shipping zones (`/api/shipping-zones`, the order
form's zone picker) keep their data; only the help text changed. The editable Shipping page and any text an admin saved keep what they typed: a saved promo
line equal to the old generated default ("Free delivery inside Dhaka on orders over ৳10,000") now follows the new "Dhaka city" default.
