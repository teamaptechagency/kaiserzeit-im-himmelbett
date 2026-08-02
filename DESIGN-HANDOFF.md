# Handoff: KAISERZEIT im Himmelbett — Website Design

## Overview
Boutique holiday-apartment website for KAISERZEIT im Himmelbett (Leipzig, Germany). Covers 7 screens: landing (Home), Apartments listing, single Apartment detail, Booking flow, Profile/account, About Us, and Contact. Dark-slate/antique-gold heritage aesthetic, DE/EN language toggle, and a full booking → auth → payment → confirmation flow.

## About the Design Files
The `.dc.html` files in this bundle are **design references built as interactive HTML prototypes** — they demonstrate exact look, copy, layout, and interaction/state behavior, but are **not production code to copy verbatim**. The task is to **recreate these designs inside the real target environment**, which per the client's original brief is **WordPress** (Elementor page builder OR a fully custom WordPress theme — this decision must be confirmed with the client before starting, see "Open Decision" below). Do not ship the HTML/JS files as-is; rebuild each screen's markup/styling/behavior using WordPress-native patterns (Elementor widgets + custom CSS, or PHP templates + enqueued assets for a custom theme).

## Fidelity
**High-fidelity (hifi).** Every screen has final copy (German primary, English secondary), a locked color palette, defined typography pairing, and specified spacing/interaction behavior. Recreate pixel-precisely; do not "reinterpret" the visual design.

## Open Decision (confirm before building)
The client has not yet confirmed: **Elementor-based development vs. a fully custom WordPress theme.** This changes implementation approach substantially (visual builder + widgets vs. hand-coded PHP templates) — get this decision before starting WordPress work.

## Screens / Views

### 1. Home.dc.html (Landing Page)
- **Purpose**: Primary entry point — hero, brand story, property groups, location map, amenities, testimonials, footer.
- **Header (all pages)**: Fixed nav, two rows — centered logo (76px height) on top, then a row with: nav links (Home/Apartments/Booking/About Us/Contact) on the left; on the right, a DE/EN language dropdown (button showing current lang code + chevron, opens a small popover with "Deutsch"/"English"), a gold gradient "Book Now"/"Jetzt buchen" pill button, a vertical divider, and a profile control (signed-out: outlined "Sign In" pill button; signed-in: circular avatar opening a dropdown with avatar/name/email + Bookings link + Logout). Below 880px width: nav links + Book Now hide, a hamburger icon appears (three horizontal bars) that toggles a full-width mobile menu with stacked links.
- **Hero section**: Full-bleed dark background (`#201d1a`/`#1a1815` with subtle radial gold glow + optional user-uploaded texture image), centered content: eyebrow label (small caps, gold `#d9a868`, letter-spacing 0.24-0.28em), two-line gradient title ("KAISERZEIT" bold + "im Himmelbett!" italic, gradient `linear-gradient(180deg,#f0d9ae 0%,#d9a868 45%,#8a5a2f 100%)` clipped to text), subtitle paragraph, then an embedded **booking bar card**: dark rounded card (`#29241f`, border `rgba(185,128,63,0.3)`, radius 14px) containing a single horizontal row of Name / Dates / Guests inputs plus a "View Apartments"/"Check Availability" button, three trust-line checkmarks below, and — when clicked — an expanding dropdown (or optional popup mode, both were built as a demo toggle) listing all 4 apartments with thumbnail + brief + a "Book Now" or "Not Available" status pill (randomized demo availability).
- **Intro/brand story section**: Light background (`#f2ecdf`), two-column image + copy, CTA button.
- **Property groups section** (dark bg): Two cards — "City, Messe & Zoo" (Residenz, Cozy, Belle Étage; Blumenstraße 1, 04105 Leipzig) and "Kanal & Künstlerviertel" (Nest am Kanal; Helmholtzstraße 12, 04177 Leipzig). Each card: 16:10 photo, name, address, description, apartment name chips (linking to detail page anchors), "Learn more" link pinned to card bottom via flex (equal-height cards).
- **Location/map section** (light bg): Two tabs ("Blumenstraße" / "Nest am Kanal") switching between a stylized vintage map image per property and a landmark list (bullet + label) — Blumenstraße: Leipzig Zentrum, Zoo, Messe; Kanal: Alte Baumwollspinnerei, Kunstkraftwerk, RB Arena, Quarterback Arena.
- **Amenities section** ("Mein Leipzig lob ich mir" / "My Little Paris" — Goethe reference, dark bg): heading + italic subtitle, then 4 columns each with a circular gold-outlined icon (connectivity/wifi, location pin, home/arrival, booking/checkmark) + title + descriptive paragraph.
- **Testimonials section** (light bg): 3 cards, large quotation mark glyph, italic quote, name in caps.
- **Footer** (all pages, dark `#1a1815`/`#161310`): 4 columns — logo + tagline; Addresses (both properties); Contact (phone/WhatsApp +49 15565 726486, email); Legal links (Datenschutz/Privacy, AGB/Terms, Cookie-Einstellungen/Cookie Preferences, Impressum/Imprint). Bottom row: copyright + DE/EN toggle repeated.
- **Floating elements**: WhatsApp circular button bottom-right (links to `https://wa.me/4915565726486`), an "Edit" guide button bottom-left (demo-only helper explaining double-click-to-edit-text / drag-to-replace-image — omit in production).

### 2. Apartments.dc.html
- **Purpose**: Browse all apartments, grouped by property.
- **Layout**: Hero band (label + title + subtitle), then a layout toggle (2 grouped cards vs. 4 individual apartment cards — this was a demo A/B toggle; pick one final layout with the client), each card same visual language as Home's property cards, each apartment name/CTA linking to `Apartment.dc.html#<key>` (key: `residenz`, `cozy`, `belle-etage`, `nest`).

### 3. Apartment.dc.html (Single Apartment Detail)
- **Purpose**: Deep-dive on one apartment, selected via `#<key>` hash or a pill-button switcher at the top.
- **Hero**: Full-width photo (21:9), apartment-switcher pills above it.
- **Overview row**: Two columns — left: group label, apartment name, address, description, feature bullet list; right: sticky pricing card (rate/night, "Check Availability" CTA linking to `Booking.dc.html#<key>`, "Ask a Question" outline button linking to Contact).
- **360° + Gallery section** — **two distinct layouts, chosen per apartment**:
  - **Default layout** (Residenz, Cozy, Belle Étage): 360° panorama viewer (drag/touch pans a wide image left-right within a fixed-height frame, "360°" badge, hint text) stacked ABOVE a 3×2 photo gallery grid.
  - **Nest am Kanal layout**: side-by-side split — 360° panorama on the left (taller, flexible width), a 2×3 (originally 3×2, later changed to 2×2/2×3 per iteration — confirm final with client) photo gallery grid on the right, both matching a fixed row height (640px). This side-by-side treatment is UNIQUE to Nest am Kanal; do not apply it to the other three.
  - 360° drag mechanics: pointer/touch down starts a pan; horizontal drag translates the image (clamped range); release settles with an eased transition.
  - Responsive: below 900px, the Nest split collapses to stacked (360° then gallery), each capped at ~320px height.

### 4. Booking.dc.html
- **Purpose**: Full reservation flow.
- **Booking form card** (dark card on dark hero bg): apartment picker, date-range pickers (native `<input type="date">`, dark-themed via `color-scheme:dark`) with a live nights count, guest-count `<select>` (dropdown of preset combos, not free text), name field, optional WhatsApp field, a live price summary (rate × nights, cleaning fee, VAT 7%, city tax 5%, total) that appears once dates are valid, and a primary CTA whose label changes: idle → "Book Now"/"Jetzt anfragen"; validates name + valid date range first (inline hint if invalid).
- **Auth modal** (opens on Book Now click): two variants — **Sign In** (Google/Facebook buttons, "OR" divider, email+password fields, "Forgot password?", link to switch to Sign Up) and **Sign Up** (full name, email, password, confirm password, terms checkbox, link to switch to Sign In). Any completion path sets the user "signed in" (drives the nav avatar/profile state) and proceeds to Payment.
- **Payment modal**: order summary line (total + date range), two selectable payment-method options ("Cash on Arrival" / "Digital Payment" — radio-style rows with a filled dot on the active choice), "Confirm Booking" CTA, small security note.
- **Confirmed modal**: checkmark icon, "Booking Confirmed!" title, subtitle, a summary box (booking ref e.g. `KZ-XXXXX`, total, date range), a WhatsApp follow-up note, close CTA.
- Same header/footer/WhatsApp-button pattern as other pages.

### 5. Profile.dc.html (My Account)
- **Purpose**: Signed-in user's account hub.
- **Hero header band** (dark bg): circular avatar (image slot), account label, name, email, an "Edit Profile" button that jumps to the Edit tab.
- **Sidebar submenu + content panel layout** (grid: 220px sidebar + flexible content, sidebar sticky, becomes a horizontal scrolling row below 760px): three tabs —
  - **Overview**: two stat cards (Total Bookings count, Upcoming count).
  - **Booking History**: filter pills (All/Upcoming/Past), list of booking rows (thumbnail, apartment name + address, dates, reference, total, status pill [Upcoming=gold, Completed=green, Cancelled=red-tinted], and an "Invoice" button per row).
  - **Edit Profile**: form (full name, email, WhatsApp, language `<select>`), Save button with saving→saved states.
- **Invoice modal** (on-screen preview): dark premium card — gold-tinted header ("Welcome to KAISERZEIT" + invoice title + thank-you line), ref badge, apartment/dates, dashed divider, large gold-gradient total amount, "Download as PDF" button.
- **Print-only invoice layout** (separate from the on-screen modal, shown ONLY via print/PDF export — see Assets/Print notes below): a **landscape, single-page (297mm × 210mm) royal letter/card design** — cream/parchment background (`#faf6ec`), thick dark outer border + thin gold inner border line, two-column split (left: letterhead "KAISERZEIT im Himmelbett", thank-you note, a short closing verse in gold italic; right: reference/dates, apartment name + amount row between gold rules, large total, footer contact line). This is what must actually render when the client (or a future real invoice system) generates a PDF — do not print the on-screen page chrome.

### 6. About Us.dc.html
- Host bio section (photo + pull-quote + two paragraphs in the hosts' voice + WhatsApp CTA "Ask a Question" + reply-time note) and a brand-story section ("Two People, One Standard"/"Zwei Menschen, ein Anspruch").

### 7. Contact.dc.html
- Two-column: contact form (name, email, message, submit) + direct contact details (phone/WhatsApp, email) + both property addresses.

## Interactions & Behavior
- **Language toggle**: All copy exists in parallel `de`/`en` objects per page; switching is instant (no reload), driven by a `lang` state value. Every page must ship BOTH language variants of every string.
- **Mobile menu**: Hamburger (visible <880px) toggles a full-bleed dropdown panel with stacked nav links.
- **Cards/hover**: Standard subtle interactive affordances (pointer cursor, link color shift `#d9a868` → `#f2ecdf` on hover) — no heavy hover animation.
- **Booking date validation**: Nights = date diff; total hidden and CTA blocked with a hint if nights < 1 or name is empty.
- **360° viewer**: horizontal-drag-to-pan, clamped range, eased release; touch-friendly (`touch-action:none` on the frame to prevent page-scroll hijack).
- **Modals** (auth/payment/confirmation/invoice): dark overlay, click-outside or × closes; internal clicks stop propagation.
- **Print/PDF**: invoice print layout uses `@page{size:landscape;margin:0}` and hides all normal page chrome via a dedicated print stylesheet — replicate via a real print stylesheet or (preferred for WordPress) a server-generated PDF (e.g. dompdf) using the same visual design.

## State Management (functional reference — reimplement per your stack)
- `lang`: "de" | "en" — global per page.
- `showMobileMenu`, `langMenuOpen`, `profileMenuOpen`: boolean UI toggles.
- `isSignedIn`: boolean — drives nav avatar vs. Sign In button.
- Booking flow: `flowStep`: "none" → "signin"/"signup" → "payment" → "confirmed"; `paymentChoice`: "cash"|"digital"; generates a `bookingRef` string on confirm.
- Apartment availability check (Home hero + Apartments): simulated async "checking" (~900ms) → random per-apartment available/unavailable (demo only — replace with real Beds24/Smoobu/iCal integration per client's requirements doc).
- Profile: `tab`: "overview"|"history"|"edit"; `filter`: "all"|"upcoming"|"past" for booking history; `invoiceRef` selects which booking's invoice modal is open.

## Design Tokens

### Colors
- Background dark (primary): `#201d1a`, `#1a1815`, `#161310`
- Background light (primary): `#f2ecdf`
- Card dark: `#29241f`
- Text on dark: `#f7f1e6` (headings), `#f2ecdf` (body), `rgba(242,236,224, 0.5–0.85)` (muted)
- Text on light: `#2b241d` (headings), `#4a4038` (body), `#8a5a2f` (accent/label)
- Gold/bronze accent: `#d9a868` (primary gold), `#b9803f` (deep bronze), `#e6c184` (light gold, gradient top), gradient `linear-gradient(135deg,#e6c184,#b9803f)` for buttons/CTAs, `linear-gradient(180deg,#f0d9ae 0%,#d9a868 45%,#8a5a2f 100%)` for the hero title text-clip
- Borders: `rgba(185,128,63, 0.2–0.5)` at various opacities
- Status: Upcoming `#8a5a2f`/gold tint bg, Completed `#4d7a3d`/green tint bg, Cancelled `#a8493f`/red tint bg

### Typography
- Display/headings: **Playfair Display** (weights 500/600/700/800), used bold for most headings, italic for accent/subtitle lines
- Body: **EB Garamond** (400/500/600, italic variants available)
- Google Fonts import: `family=Playfair+Display:wght@500;600;700;800&family=EB+Garamond:ital,wght@0,400;0,500;0,600;1,400;1,500`
- Letter-spacing: labels/eyebrows use 0.2–0.28em uppercase small text (11–13px)

### Spacing / Radius
- Section vertical padding: `clamp(50-70px, 8-10vw, 90-140px)` — generous, airy
- Card radius: 2px (photo/property cards, sharp-edged) vs. 8-14px (form cards, modals) vs. 999px (pills/buttons) vs. 50% (avatars/circular)
- Max content width: 1000–1200px typical

### Shadows
- Modal cards: `0 30px 70px rgba(0,0,0,0.5)` / `0 16px 36px rgba(0,0,0,0.4)`
- Floating buttons (WhatsApp): `0 10px 28px rgba(0,0,0,0.35)`

## Assets
- **Logo**: client-provided, chiseled/metallic gold wordmark PNG (`assets/kaiserzeit-logo.png`) — used at ~62-76px height in nav, ~56px in footer.
- **Hero background texture**: client-provided dark stone/slate texture image (`assets/hero-texture.png`).
- **All other photography (apartment interiors, gallery shots, avatars, vintage maps, 360° panoramas)**: currently drag-and-drop placeholder slots (`<image-slot>` custom element) — the client has NOT yet supplied real photos for these; a real production build needs actual photography sourced from the client for every placeholder.
- **Icons**: hand-drawn inline SVG line icons (WhatsApp, calendar, guests, wifi/location/home/booking amenity icons, checkmark, chevrons) — no icon font/library dependency; recreate as SVG or an icon library of choice.

## Files
- `Home.dc.html` — Landing page
- `Apartments.dc.html` — Apartments listing
- `Apartment.dc.html` — Single apartment detail (360° + gallery, booking CTA)
- `Booking.dc.html` — Booking form + auth/payment/confirmation modals
- `Profile.dc.html` — Account/profile + booking history + invoice
- `About Us.dc.html` — Host bio / brand story
- `Contact.dc.html` — Contact form + details
- `image-slot.js` — drag-and-drop image placeholder component (demo-tooling only, not for production)
- `support.js` — internal prototype runtime (demo-tooling only, not for production)

## Not Yet Implemented (explicitly out of scope for this HTML prototype — required for the real WordPress build)
- Multilingual functionality is currently a simple in-memory JS toggle — production needs a real i18n solution (e.g. WPML/Polylang) with the DE/EN content already drafted here as the source copy.
- Booking/availability is fully simulated (random demo results) — production needs a real Beds24/Smoobu/iCal integration for dynamic pricing and availability, per the client's requirements.
- Payment is a UI-only stub (no real payment processor wired in).
- Cookie preferences / legal pages (Datenschutz, AGB, Impressum) are linked as footer placeholders only — actual legal page content and a real cookie-consent mechanism still need to be built.
- Persistent client image uploads (WordPress Media Library) — the image-slot placeholders here are prototype-only tooling.
