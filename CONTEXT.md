# Custom Webform Handlers

This context owns the server-authoritative calculations and reservation workflow attached to a Webform submission.

## Language

**Slot**:
A server-canonical pickup-arrival option for a Webform, Resource, and UTC start time. Its Dispatch Window is derived server-side; the browser may display a Slot but cannot define or reserve one.
_Avoid_: Time, appointment

**Hold**:
An internal, short-lived claim on one Slot used to make a payment attempt idempotent. A Hold is never shown to customers, has no public countdown, expires automatically, and is not a completed booking.
_Avoid_: Temporary reservation, pending booking, public timer

**Reservation**:
A confirmed claim on a Slot linked to a paid order. It remains unavailable until an explicit administrative release or successful Replan.
_Avoid_: Booking, appointment

**Dispatch Request**:
The server-normalized operational facts supplied for one potential job: service type, pickup and delivery locations, handling requirements, and applicable add-ons. It can be quoted, offered Slots, manually reviewed, held, or confirmed, but it is not itself a Reservation.
_Avoid_: Submission, quote

**Manual Review**:
An outcome in which a Dispatch Request is not self-bookable and requires staff approval. It creates neither a Hold nor a Reservation and consumes no Resource capacity.
_Avoid_: Pending booking, soft reservation

**Review Request**:
A customer-visible record for a Dispatch Request requiring Manual Review. Its lifecycle is Submitted, Under Review, Approved, then Scheduled when payment creates a Reservation.
_Avoid_: Pending booking, support ticket

**Availability**:
The current server-derived set of Slots that a visitor may select. It is a read model for the form, not the authority that resolves contention.
_Avoid_: Calendar state

**Resource**:
An optional canonical identifier that partitions Slot capacity, such as a crew or vehicle. The initial reservation model has capacity one per Resource and Slot.
_Avoid_: Staff member, asset

**Dispatch Window**:
The Resource's server-derived occupied interval from a customer's pickup arrival through pickup work, the route, delivery work, and required operational buffer.
_Avoid_: Job length, booking duration

**Repositioning**:
The route and buffer needed for a Resource to travel from the end of one Dispatch Window to the pickup of the next Reservation. It determines feasibility but is not part of the customer-requested service.
_Avoid_: Return trip, dead time

**Replan**:
A staff-initiated proposed change to a Reservation's Slot. It becomes effective only when the planner validates the entire affected Resource schedule and atomically replaces the confirmed Reservation.
_Avoid_: Drag and drop, manual move

**Delivery Mode**:
A small-item delivery offering that determines its pickup rules and required information: Send, Receive, or Store Pickup.
_Avoid_: Courier type, delivery option

**Express Courier**:
A top-priority, self-bookable local Service Type for one pickup and one delivery on the same day, subject to configured item, capacity, and route limits. Its preserved canonical machine ID is `courier`; its public label is Express Courier. Its Delivery Mode is Send, Receive, or Store Pickup.
_Avoid_: Uber Connect, instant guarantee

**Service Type**:
A customer-facing logistics offering whose rules determine eligibility, pricing, handling duration, and Resource requirements.
_Avoid_: Product, vehicle option

**Checkout Attempt**:
The private association between a Dispatch Request, selected Slot, and Commerce order while payment is pending. It may own an internal Hold but is not a Reservation and is never presented as scheduled work.
_Avoid_: Pending booking, provisional reservation
