# Webform 6.3 native layout and conditional-element findings

Scope: read-only research for the Express Courier Webform cleanup. The installed
contrib module is Drupal Webform **6.3.0** (Drupal.org packaged 2026-07-07). This
note uses that installed source and the Webform project's official documentation and
source; it does not change application code or configuration.

## Findings

### Use the native Flexbox container for responsive field rows

`webform_flexbox` is a native Webform container whose declared purpose is to lay out
elements in multiple columns. Its render element attaches
`webform/webform.element.flexbox`. The supplied CSS switches to `display: flex` only
at 768px and above, so the same form stacks on smaller screens. Give **direct** row
children `#flex` weights (for example, `1`/`1` for two equal columns); do not add a
separate layout module or a CSS-only duplicate grid.

- Installed source: `web/modules/contrib/webform/src/Plugin/WebformElement/WebformFlexbox.php:11-30`,
  `web/modules/contrib/webform/src/Element/WebformFlexbox.php:18-32`, and
  `web/modules/contrib/webform/css/webform.element.flexbox.css:9-20,59-156`.
- Official source: [WebformFlexbox plugin](https://git.drupalcode.org/project/webform/-/blob/6.3.x/src/Plugin/WebformElement/WebformFlexbox.php),
  [Flexbox render element](https://git.drupalcode.org/project/webform/-/blob/6.3.x/src/Element/WebformFlexbox.php),
  and [Flexbox CSS](https://git.drupalcode.org/project/webform/-/blob/6.3.x/css/webform.element.flexbox.css).
- Official feature guide: [Webform features](https://www.drupal.org/docs/contributed-modules/webform/webform-features)
  documents native multi-column layout management and conditional logic.

### Use a native Details container for the legal notice

Webform supplies the core `details` element as a container. It is interactive,
defaults to closed (`#open: FALSE`), and attaches its own details library. Put a
static `webform_markup` (Basic HTML) or `processed_text` child inside it rather than
embedding a raw `<details>` tree in a markup string. This preserves accessible native
summary/expand behavior while removing the long notice from the initial vertical
layout.

- Installed source: `web/modules/contrib/webform/src/Plugin/WebformElement/Details.php:8-53`
  and `web/modules/contrib/webform/src/Plugin/WebformElement/WebformMarkup.php:10-31`.
- Official source: [Details element](https://git.drupalcode.org/project/webform/-/blob/6.3.x/src/Plugin/WebformElement/Details.php)
  and [Basic HTML element](https://git.drupalcode.org/project/webform/-/blob/6.3.x/src/Plugin/WebformElement/WebformMarkup.php).

### Preserve element keys and conditional-state behavior when rearranging

Webform initializes each element from its array key, assigns it `#webform_key`, and
records its parent and complete parent path. It separately detects whether a parent is
a Flexbox container. Moving an existing leaf under a new Flexbox or Details container
is therefore safe only when its original key remains unchanged and all code/configured
selectors that reference it still resolve. New layout containers, markup, and a
same-as-sender checkbox need new unique keys; the current address, quote, hidden
dispatch, contact, service, and checkout keys must be retained unless a complete
consumer and historical-data migration is deliberately supplied.

Deleting a Webform element is not a presentation-only operation: Webform's
`deleteElement()` removes stored submission data for the element and its descendants.
Audit handler, Commerce, audit-log, quote, JavaScript, ECA, and historical submission
consumers before removing any field. Prefer removing only obsolete UI elements after
that audit; never rename a value field merely to improve labels or layout.

- Installed source: `web/modules/contrib/webform/src/Entity/Webform.php:1679-1706,
  1750-1757, 1875-1897, 1946-1967`.
- Official source: [Webform entity element initialization](https://git.drupalcode.org/project/webform/-/blob/6.3.x/src/Entity/Webform.php)
  and [Webform element base properties](https://git.drupalcode.org/project/webform/-/blob/6.3.x/src/Plugin/WebformElementBase.php).

### Treat “Same as sender” as UI intent, not payment authority

Webform tracks `#states`; containers default `#states_clear` to `TRUE`. Thus a
checkbox-driven hidden pickup-contact block can clear its values when toggled. If the
values must survive a hide/show round-trip, set `#states_clear: FALSE` deliberately;
otherwise expect them to be cleared. In either case, the handler/checkout path must
server-side copy and validate sender values when the checkbox is selected. Browser
autofill and submitted state alone must not become a quote, customer, payment, or
dispatch authority.

- Installed source: `web/modules/contrib/webform/src/Entity/Webform.php:1750-1757`
  and `web/modules/contrib/webform/src/Plugin/WebformElementBase.php:249-261`.
- Official source: [Webform entity](https://git.drupalcode.org/project/webform/-/blob/6.3.x/src/Entity/Webform.php)
  and [WebformElementBase](https://git.drupalcode.org/project/webform/-/blob/6.3.x/src/Plugin/WebformElementBase.php).

## Implementation constraints for the courier cleanup

1. Use `webform_flexbox` and native `details`; do not introduce the unsupported
   `webform_layout_container` project. Reparent only visual fields within their
   existing wizard page.
2. Keep the existing machine keys, weights where ordering is behaviorally significant,
   `#states` selectors, required-state conditions, hidden quote fields, and dispatch
   Slot fields stable unless every consumer is changed in the same task.
3. Add server-side same-as-sender normalization before validation/checkout. It must
   happen before any data is used to build an order, notification, or review request.
4. Treat removal of legacy/freight elements as a data-contract change, not a CSS
   change: inventory all consumers and preserve legacy stored submission values.
5. Verify the mobile stack and desktop multi-column rendering after cache rebuild;
   avoid CSS that overrides Webform's 768px responsive fallback.
