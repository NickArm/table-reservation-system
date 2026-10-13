# TableReserved — Κατάσταση υλοποίησης

> **Τελευταία ενημέρωση:** 2026-10-12 (M6)
> **Spec:** `SPEC.md` v2.4 · **Ανταγωνισμός:** `COMPETITORS.md`
>
> 🔒 **Κανόνας:** αυτό το αρχείο ενημερώνεται **στο ίδιο commit** με τη δουλειά που περιγράφει. Ένα feature δεν θεωρείται τελειωμένο αν δεν έχει (α) tests, (β) εγγραφή στον demo seeder, (γ) ενημερωμένη γραμμή εδώ.

**Υπόμνημα:** ☑ έτοιμο · ◐ σε εξέλιξη · ☐ δεν ξεκίνησε · ⊘ μπλοκαρισμένο

---

## Τώρα

**Στάδιο:** `M6 · Αποδεικτικό & ειδοποιήσεις` ολοκληρώθηκε — **ο πελάτης παίρνει email, το μαγαζί ειδοποιείται**. Αποδεικτικό με QR, PDF και `.ics`, σελίδα που ανοίγει στην πόρτα, ειδοποίηση καταστήματος με ώρες ησυχίας, ημερήσια σύνοψη, υπενθυμίσεις, και bounce webhook που λέει στον εστιάτορα πότε να πάρει τηλέφωνο.

**Επόμενο:** `M7 · Dashboard` — οθόνη Today με ημερολόγιο, χειροκίνητη κράτηση, πελατολόγιο ανά venue (§28), CSV export.

**Μπλοκάρει:** τίποτα. Τα concurrency tests των 50 ταυτόχρονων requests θέλουν MariaDB — σε SQLite δεν υπάρχει `SELECT … FOR UPDATE`, οπότε τρέχουν όταν στηθεί το staging.

**Χρειάζεται ένα composer require:** το PDF του αποδεικτικού θέλει `dompdf/dompdf`. Χωρίς αυτό όλα δουλεύουν — το email φεύγει με QR και `.ics`, απλώς χωρίς το συνημμένο PDF, και γράφεται μια γραμμή στο log. Το QR **δεν** θέλει πακέτο: ο κωδικοποιητής είναι δικός μας, γιατί τα email clients πετάνε SVG και data URI και τα έτοιμα πακέτα βγάζουν PNG μόνο μέσω GD ή Imagick.

**Τρέξε μετά το pull:**

```bash
composer require dompdf/dompdf   # για το PDF του αποδεικτικού
php artisan migrate:fresh --seed
php artisan storage:link         # μία φορά, για να φανούν οι φωτογραφίες
npm run build
php artisan test
```

Για να φύγουν αληθινά email: `MAIL_MAILER=smtp` με τα SMTP στοιχεία του Brevo και `MAIL_WEBHOOK_SECRET` στο `.env`. Χωρίς αυτά όλα γράφονται στο log — `php artisan pail` για να τα δεις.

Demo: `/reservation/demo` · dashboard `owner@demo.test` / `password`

---

## Σύνοψη

| Milestone | | Περιεχόμενο |
|---|---|---|
| **Σχεδιασμός** | ☑ | Spec 33 ενοτήτων, ανάλυση ανταγωνισμού, prototype 4 οθονών |
| **M0 · Θεμέλια** | ☑ | Auth, tenants, resolver, roles, tenant isolation tests |
| **M1 · Δομή** | ◐ | Booking types, areas, resources, open/close — μένει το upload branding |
| **M2 · Διαθεσιμότητα** | ☑ | Ωράρια, 4 modes, occupancy, closures, holds, **tests** |
| **M3 · Τιμές** | ☑ | Base price + price rules με priority, server-side |
| **M4 · Σελίδα κράτησης** | ☑ | Δημόσια ροή χωρίς πληρωμή, 3 οθόνες |
| **M5 · Πληρωμές** | ☑ | Stripe, PayPal, cash· webhooks, reconciliation, refunds |
| **M6 · Αποδεικτικό** | ☑ | Email + PDF + QR + .ics, ειδοποιήσεις, υπενθυμίσεις, bounces |
| **M7 · Dashboard** | ☐ | Οθόνη Today, ημερολόγιο, πελατολόγιο, cancel/refund |
| **M8 · Widget & API** | ☐ | embed.js, builder, Public API v1, Developer page |
| **M9 · Συνδρομές** | ☐ | Register, trial, πακέτα, billing, super admin |
| **M10 · Τελείωμα** | ☐ | Νομικά, διαγραφή λογαριασμού, onboarding, γυάλισμα |
| **Demo seeder** | ◐ | Μεγαλώνει σε **κάθε** milestone (§22.3) |

**Phase 1: 6 / 11 milestones ολοκληρωμένα.**

---

## Phase 1 — αναλυτικά

### M0 · Θεμέλια ☑
- ☑ Laravel 13 skeleton, PHP 8.4, strict types, Pint, Larastan (level 6), phpunit
- ☑ Auth: register, login, password reset, email verification
- ☑ `tenants`, `venues` (ένα auto-created ανά tenant, §1.3)
- ☑ Path-based tenant resolver `/reservation/{slug}` + resolver από τον χρήστη για το dashboard (§3.2)
- ☑ `BelongsToTenant` trait + `TenantScope` που **πετάει** αντί να επιστρέφει τα πάντα
- ☑ Roles & permissions (owner / staff / super admin)
- ☑ **Tests tenant isolation** — διαβάζει κάθε model από τον δίσκο (§25.1)
- ☑ 🔒 `reservations.resource_id` **nullable** — αγκίστρι για events (§32.3)
- ☑ Design tokens, dashboard shell, δημόσια σελίδα με schema.org + `ReserveAction`

### M1 · Δομή ◐
- ☑ Booking types: ωράρια, mode, occupancy, lead times, deposit label
- ☑ Presets «Tables» / «Sunbeds» — ένα tap και το μαγαζί είναι στημένο (§1.1)
- ☑ Areas + sort order + override occupancy/max stay/buffer ανά area (§6.2.1)
- ☑ Resources: μεμονωμένα & σετ (`is_composite` + components)
- ☑ Resource templates + bulk create «A1 έως A20»
- ☑ Open/close ανά resource (ένα tap), ανά area, και «άνοιξε τα πρώτα N»
- ☑ Η δημόσια σελίδα δείχνει **μόνο** ό,τι έχει στήσει το μαγαζί, με τιμή «from»
- ☑ Demo venue «Demo Beach Bar»: 2 booking types, 5 areas, 44 resources, hero + logo
- ☐ Οθόνη ρυθμίσεων επιχείρησης: upload logo & hero, επεξεργασία εικόνων, accent (§5.5)
  — πάει μαζί με τα πεδία προφίλ του M4

### M2 · Διαθεσιμότητα ☑
- ☑ Opening hours ανά ημέρα, πολλαπλά διαστήματα (μεσημέρι + βράδυ), **μετά τα μεσάνυχτα**
- ☑ 4 booking modes (slot, arrival_window, half_day, full_day)
- ☑ Occupancy whole_day / fixed_duration + override ανά area
- ☑ Closures σε 3 επίπεδα (booking type / area / resource), ολόκληρη μέρα ή διάστημα ωρών
- ☑ «Stop bookings for today» με ένα tap από την οθόνη Today, και αναίρεση
- ☑ Holds με TTL 15' — row lock + unique index, lazy φιλτράρισμα ληγμένων
- ☑ `holds:release` command + scheduler κάθε λεπτό
- ☑ Buffer ανάμεσα σε κρατήσεις, εφαρμοσμένο και από τις δύο πλευρές
- ☑ min_lead_time, max_advance_days, άρνηση ώρας που δεν προσφέρθηκε ποτέ
- ☑ Μήνας με ένα πέρασμα για το date picker (available / limited / full / closed)
- ☑ **Tests αλλαγής ώρας** (DST) — η ανύπαρκτη ώρα της άνοιξης, η διπλή του φθινοπώρου
- ◐ **Concurrency tests** — 50 ταυτόχρονα requests (§25.1): θέλουν MariaDB, η άμυνα
  (row lock + unique index) είναι στη θέση της και καλυμμένη με σειριακά tests

### M3 · Τιμές ☑
- ☑ `base_price` + `price_basis` (ανά resource ή ανά άτομο)
- ☑ Price rules: σταθερή τιμή ή ποσοστό (+/−), ανά booking type / area / resource
- ☑ Φίλτρα κανόνα: ημέρες εβδομάδας, σεζόν (από–έως), ώρες — και συνδυασμοί τους
- ☑ **Priority**: κερδίζει ο μεγαλύτερος· ισοπαλία πάει στον πιο ειδικό κανόνα· **δεν στοιβάζονται**
- ☑ Server-side resolution — η τιμή από τον client αγνοείται πάντα, με test που το αποδεικνύει
- ☑ Κρυμμένα πίσω από «Advanced» — μαγαζί με μία τιμή δεν τα συναντά ποτέ (§1.1)
- ◐ Το «from €X» της δημόσιας σελίδας δείχνει βασική τιμή· με επιλεγμένη ημερομηνία (M4)
  θα περνάει από τους κανόνες

### M4 · Σελίδα κράτησης ☑
- ☑ Landing με φόρμα αναζήτησης· booking type selector που παρακάμπτεται με ένα type
- ☑ Αναζήτηση → ώρες με «πόσα έμειναν» → spots ανά σειρά, με τιμή και τι περιλαμβάνουν
- ☑ **Καμία νεκρή επιλογή** — ώρα χωρίς ελεύθερο spot δεν εμφανίζεται καθόλου (§8.1)
- ☑ Hold 15' με ορατή ώρα λήξης· αλλαγή γνώμης επιστρέφει το προηγούμενο
- ☑ Φόρμα στοιχείων: τηλέφωνο **E.164** + country picker με default +30
- ☑ 🔒 `party_size` ως ποσότητα, ανεξάρτητο από τη χωρητικότητα (§32.3)
- ☑ Σημειώσεις προς το κατάστημα
- ☑ Αποδοχή όρων + consent logging (πότε, από ποια IP) + ξεχωριστό marketing opt-in
- ☑ Όριο 2 κρατήσεων ανά email / 24ώρες, ρυθμίσιμο ανά booking type (§24.6)
- ☑ Honeypot + rate limit στα endpoints κράτησης
- ☑ Σελίδα επιβεβαίωσης με κωδικό, τι κλείστηκε, και «πάρε τηλέφωνο για αλλαγή»
- ☑ Mobile-first, **3 οθόνες** μέχρι την επιβεβαίωση
- ◐ ⭐ **Προφίλ επιχείρησης** (§33.1) — τα πεδία υπάρχουν στη βάση, λείπει η οθόνη
- ☑ ⭐ Δείκτης πληρότητας προφίλ + διακόπτης ευρετηρίασης
- ◐ ⭐ **SEO** (§33.2) — schema.org `Restaurant` + `ReserveAction`, canonical, Open Graph ☑· μένει sitemap.xml
- ☑ Χάρτης ως link σε νέα καρτέλα — ποτέ iframe τρίτου (§24.7)
- ☑ QR + .ics + email αποδεικτικού → έγινε στο M6

### M5 · Πληρωμές ☑
- ☑ `PaymentGateway` interface — πέντε κλήσεις, καμία λέξη του ενός gateway στο άλλο
- ☑ Stripe Checkout με τα κλειδιά του tenant (encrypted at rest, ποτέ πίσω στον browser)
- ☑ PayPal Orders v2, με δική τους επαλήθευση υπογραφής webhook
- ☑ `cash_on_arrival` — πλήρης υλοποίηση, όχι placeholder
- ☑ Webhook endpoint **ανά venue** (`/webhooks/{gateway}/{uuid}`) με έλεγχο υπογραφής
  και παράθυρο 5' κατά replay
- ☑ Test connection + test mode με **banner σε όλη τη δημόσια σελίδα** (§24.3)
- ☑ Λήξη hold → ακύρωση checkout session στο gateway + επανέλεγχος πριν το confirm (§24.1)
- ☑ **Αν η θέση πιάστηκε όσο πλήρωνε: αυτόματο πλήρες refund**, ποτέ δεν κρατάμε λεφτά
  για κράτηση που δεν υπάρχει
- ☑ **Reconciliation job** ανά ώρα — ρωτάει το gateway για ό,τι έμεινε pending (§24.2)
- ☑ Refunds: full / partial / none, idempotency key ανά απόπειρα, ARN, σωρευτικό σύνολο,
  αποτυχίες που **αναφέρονται** αντί να καταπίνονται (§8.3)
- ☑ Staff ακυρώνει, μόνο ο owner κινεί χρήματα

### M6 · Αποδεικτικό & ειδοποιήσεις ☑
- ☑ Αποδεικτικό κράτησης: HTML email (table-based, δουλεύει και σε Outlook) + PDF + QR + `.ics`
- ☑ 🔒 Το **QR περιέχει υπογεγραμμένο token**, ποτέ ωμό id — αγκίστρι για events (§32.3)
- ☑ Σελίδα αποδεικτικού σε σύντομο signed URL `/v/{code}/{token}` — αυτό που σκανάρεται στην πόρτα
- ☑ **Δικός μας κωδικοποιητής QR** (`App\Support\QrCode`) — byte mode, level M, versions 1–10,
  έξοδος σε PNG χωρίς GD/Imagick και σε SVG· 151 payloads επαληθεύτηκαν module-προς-module
  απέναντι σε ανεξάρτητο encoder
- ☑ `.ics` γραμμένο με το χέρι (CRLF, folding στα 75 octets, escaping) — περνά από πραγματικό parser
- ☑ Brevo μέσω σκέτου SMTP — καμία αναφορά στο Brevo στον κώδικα πλην του webhook· queued αποστολή
- ☑ From/Reply-To split: `"Το Μαγαζί" <noreply@…>` με reply στο μαγαζί (§10.3)
- ☑ Ειδοποίηση καταστήματος: email σε **όλες** τις διευθύνσεις + καμπανάκι in-app
- ☑ **Ώρες ησυχίας** — η ειδοποίηση καθυστερεί ως το πέρας τους, δεν χάνεται
- ☑ Ημερήσια σύνοψη στην **τοπική ώρα** του κάθε venue, ομαδοποιημένη ανά booking type
- ☑ Υπενθυμίσεις στον πελάτη X ώρες πριν, μία και μόνη φορά· καμία για κράτηση των τελευταίων 2 ωρών
- ☑ Ειδοποίηση ακύρωσης με το ποσό που **όντως** επιστράφηκε, όχι το ζητούμενο
- ☑ Bounce/complaint webhook → `voucher_delivery` στην κράτηση + κόκκινη προειδοποίηση
  στο dashboard: «δεν έφτασε, πάρε τηλέφωνο»
- ☑ Οθόνη ρυθμίσεων «Alerts»: παραλήπτες, ώρες ησυχίας, ώρα σύνοψης, παράθυρο υπενθύμισης
- ☑ 🔒 Κανόνας ουράς: **κανένα model στην ουρά** — τα jobs κουβαλούν ids και δένουν μόνα τους
  τον tenant (`RunsAsTenant`), γιατί ένα queued job ξεκινά χωρίς tenant και κάθε scoped query
  σωστά αρνείται
- ☑ Listeners **δηλωμένα** στον `AppServiceProvider`, όχι auto-discovered (δεν ήταν καν ενεργό)
- ◐ Browser push → Phase 2· θέλει service worker και VAPID keys, δηλαδή PWA που δεν υπάρχει ακόμα.
  Το email είναι το κανάλι που φτάνει στον εστιάτορα· το καμπανάκι τον πιάνει όταν είναι μέσα

### M7 · Dashboard
- ◐ **Οθόνη Today** (§13.3) — λίστα κρατήσεων με ώρα/όνομα/άτομα/τραπέζι/ποσό/κατάσταση,
  μετρητές, φίλτρα Today/Tomorrow/This week/All, «stop bookings today». Μένουν check-in,
  καρτέλα κράτησης, auto-refresh
- ☐ Ημερολόγιο day/week/month + drag
- ☐ Λίστα κρατήσεων, φίλτρα, export CSV
- ☐ Χειροκίνητη/τηλεφωνική κράτηση + autocomplete από πελατολόγιο
- ☐ Check-in, no-show — 🔒 **idempotent** (αγκίστρι για events & offline, §32.3)
- ☐ Cancel + refund με προειδοποίηση προμήθειας
- ☐ **Πελατολόγιο** (§28): guests ανά venue, dedup, ιστορικό, no-shows
- ☑ Λίστα τραπεζιών/ξαπλωστρών με open/close σε ένα tap
- ☑ Οθόνη «Closed dates» ανά booking type — προσθήκη, λίστα, reopen
- ☑ Οθόνη «Prices» ανά booking type — κανόνες, priority, αφαίρεση

### M8 · Widget & API
- ☐ `embed.js` — Web Component + Shadow DOM, <10KB
- ☐ Iframe fallback + postMessage resize
- ☐ Widget builder με live preview + snippet + οδηγίες
- ☐ Channel attribution (`channel`, `referrer_domain`)
- ☐ **Public API v1** (§15.2) — bearer token ανά account, test/live
- ☐ Rate limiting ανά token + ανίχνευση διαρροής (Origin header)
- ☐ **Σελίδα Developer** — token, endpoints με slug, curl, OpenAPI, χρήση

### M9 · Συνδρομές
- ☑ Register + 15ήμερο trial χωρίς κάρτα
- ☐ Πακέτα: seed €70/μήνα, €500/έτος + custom ανά tenant
- ☐ Stripe billing + PayPal + lifecycle (trial → active → past_due → grace → expired)
- ◐ Lockout στη λήξη — `isReadOnly()` και το banner υπάρχουν, μένει η επιβολή
- ☐ Enforcement ορίων πακέτου
- ☐ Χειροκίνητη παράταση από super admin
- ☐ Super admin: tenants, impersonate, audit log
- ☐ Trial emails (ημέρα 1, 7, 12, 14, 15)

### M10 · Τελείωμα
- ☐ Όροι & πολιτική ακυρώσεων καταστήματος + versioning
- ☐ **Νομικές σελίδες πλατφόρμας** (§12.2) — υποδομή, views, placeholder
- ☐ **Διαγραφή λογαριασμού** (§29) — μπλοκάρισμα, export, 30 ημέρες
- ☐ Onboarding wizard
- ☐ Sentry με scrubbing + `send_default_pii=false` (§30.2)
- ☐ Staging environment (§30.3)
- ◐ Object storage R2 (§30.1) — `config/media.php`, ένα env var μακριά

### Συνεχώς
- ◐ **Demo seeder** — μεγαλώνει σε κάθε milestone (§22)· τώρα ~200 κρατήσεις σε 5 εβδομάδες,
  3 closures, 3 price rules, ένα ζωντανό hold, cash on arrival ενεργό + Stripe σε test mode,
  ρυθμίσεις ειδοποιήσεων, καμπανάκι με 3 αναγνώστα, και ~4% bounced vouchers ώστε να
  φαίνεται η προειδοποίηση
- ◐ Tests κατά §25 — 30 αρχεία· tenant isolation, σειρά middleware, δομή, διαθεσιμότητα,
  closures, holds, τιμές, dashboard, ροή κράτησης, πληρωμές & refunds,
  **QR, .ics, αποδεικτικό, ειδοποιήσεις, υπενθυμίσεις, σύνοψη, bounce webhook**
- ◐ Αυτό το αρχείο

---

## Μετά το Phase 1

**Phase 2** — floor plan editor, widget modal, Viva & Cardlink, Reserve with Google, waitlist, merge guests, SMS, **browser push (θέλει PWA)**, QR check-in με ρόλο πορτιέρη, per-tenant verified sending domain, Tenant API writes, multi-venue UI, staff permissions, reports.

**Phase 3 / Backlog** — Ελληνικά, WordPress plugin, custom domains, λογαριασμοί πελατών, καλάθι, ένωση τραπεζιών, προπαραγγελία με μενού, **events & εισιτήρια με scanner (§32)**, Stripe Connect, PWA, offline επίπεδο 1, outgoing webhooks.

---

## Ανοιχτές αποφάσεις

| | Μπλοκάρει; |
|---|---|
| Όνομα & domain πλατφόρμας (working name: TableReserved) | Όχι — env var |
| Brevo: ημερήσιο όριο & branding δωρεάν πακέτου | Όχι — **πριν σταλεί το πρώτο αληθινό email** |
| Μόνιμο δωρεάν πακέτο 20–30 κρατήσεων; (§4.1) | Όχι — ένα `plan` row |
| Υποστήριξη: κανάλι & ώρες | Όχι — πριν τους Όρους |
| Επιστροφή δικής μας συνδρομής (αναλογική;) | Όχι — πριν τον δικηγόρο |
| Ποιος στήνει το μαγαζί: wizard ή εσύ | Όχι — επηρεάζει το M10 |

---

## Ημερολόγιο

### 2026-10-12 — M6 · Αποδεικτικό & ειδοποιήσεις
- **Ο κωδικοποιητής QR γράφτηκε εδώ**, και όχι γιατί ήταν διασκεδαστικό: το QR πρέπει να
  φτάσει σε κινητό μέσα από email client, και κάθε client που μετράει πετάει SVG και data URI.
  Μένει αληθινό PNG — που τα έτοιμα πακέτα βγάζουν μόνο μέσω GD ή Imagick, κανένα από τα δύο
  εξασφαλισμένο στο shared hosting. Το εύρος είναι σκόπιμα στενό: byte mode, level M,
  versions 1–10. Επαληθεύτηκε με 151 payloads απέναντι σε ανεξάρτητο encoder, module προς
  module, και με πραγματικό decoder — ένα QR που δεν διαβάζεται είναι πελάτης στην πόρτα
- Το PNG γράφεται με το χέρι, 1 bit ανά pixel: ένα QR αποδεικτικού βγαίνει ~550 bytes
- **Το QR δείχνει σε σύντομο signed URL** `/v/{code}/{token}` — 50 χαρακτήρες, άρα symbol
  version 4, άρα σκανάρεται γρήγορα σε μισοσκόταδο. Υπογραφή HMAC 64 bit, ποτέ ωμό id (§32.3).
  Λάθος υπογραφή και ανύπαρκτος κωδικός δίνουν **το ίδιο** 404, αλλιώς η σελίδα γίνεται
  μηχανή ελέγχου αν ένας κωδικός υπάρχει
- Το `.ics` επίσης με το χέρι: CRLF, folding στα 75 **octets** (όχι χαρακτήρες — αλλιώς ένα
  ελληνικό όνομα κόβεται στη μέση), escaping για κόμμα/άνω τελεία/backslash/newline
- Το PDF είναι **προαιρετικό**: αν λείπει το dompdf ή σκάσει το rendering, το email φεύγει
  με QR και `.ics` και γράφεται γραμμή στο log. Συνημμένο αξίζει να υπάρχει· δεν αξίζει να
  χάνεται μια επιβεβαίωση γι' αυτό. Το font cache πάει κάτω από `storage`, γιατί στο shared
  hosting το `vendor` σπάνια γράφεται
- **Ένα `Voucher` object** τροφοδοτεί email, PDF και σελίδα. Το χειρότερο από ένα άσχημο
  αποδεικτικό είναι τρία που διαφωνούν
- Τα emails είναι table-based με inline styles και δηλωμένο background σε κάθε κελί, ώστε
  client που επιβάλλει dark mode να μη βγάλει σκούρο κείμενο σε σκούρο πλαίσιο
- **Ώρες ησυχίας: η ειδοποίηση καθυστερεί, δεν χάνεται.** Κράτηση στις 03:30 είναι κράτηση·
  το να ξυπνήσει κάποιον είναι αυτό που μας κόβει τον λογαριασμό. Το καμπανάκι γράφεται
  αμέσως — δεν κάνει θόρυβο
- Η ημερήσια σύνοψη τρέχει **κάθε ώρα** και διαλέγει τα venues των οποίων είναι η ώρα
  *τοπικά*. Έτσι ένα schedule εξυπηρετεί Κέρκυρα και Κανάρια και επιβιώνει της αλλαγής ώρας
- Στέλνεται **και σε κενή μέρα**: η σιωπή μοιάζει ακριβώς με σύστημα που σταμάτησε
- Οι υπενθυμίσεις σημειώνονται **πριν** σταλούν: mail server που δέχεται και μετά κάνει
  timeout θα έδινε δεύτερο αντίγραφο στον πελάτη. Και κανείς δεν υπενθυμίζεται για κράτηση
  που έκανε πριν δύο ώρες — δεν την έχει ξεχάσει ακόμα
- Η ειδοποίηση ακύρωσης λέει το ποσό που **όντως** επιστράφηκε, όχι το ζητούμενο. «Επεστράφη»
  για λεφτά που δεν κινήθηκαν είναι ο συντομότερος δρόμος σε chargeback
- Το bounce webhook λύνει ένα και μόνο σενάριο: ο πελάτης έγραψε λάθος το email του, δεν έχει
  επιβεβαίωση, και εμφανίζεται στην πόρτα για τραπέζι που κανείς δεν ξέρει. Το `message-id`
  γράφεται πάνω στην κράτηση τη στιγμή που φεύγει το μήνυμα — είναι το μόνο νήμα που επιτρέπει
  σε μια ειδοποίηση χωρίς tenant και χωρίς session να βρει τον δρόμο της πίσω
- Το «request» του παρόχου σημαίνει «δεκτό προς αποστολή», όχι «παραδόθηκε»· καταγράφεται ως
  επιτυχία και κρύβει το bounce που ακολουθεί. Αγνοείται
- 🔒 **Κανόνας ουράς:** κανένα Eloquent model δεν μπαίνει στην ουρά. Τα jobs κουβαλούν ids και
  δένουν τον tenant μόνα τους (`RunsAsTenant`) — ένα queued job ξεκινά σε καθαρή διεργασία
  χωρίς tenant, και το `TenantScope` σωστά αρνείται. Για τον ίδιο λόγο τα Mailables **δεν**
  είναι queued: η ουρά είναι το listener, το μήνυμα φτιάχνεται μέσα στο tenant context
- Βρέθηκε ότι το **auto-discovery των listeners ήταν κλειστό** (το `bootstrap/app.php` δεν
  καλεί `withEvents`), άρα τίποτα δεν θα είχε τρέξει. Τα listeners δηλώνονται τώρα ρητά σε
  έναν πίνακα στον `AppServiceProvider` — και διαβάζεται: τι γίνεται μετά από μια επιβεβαίωση
  απαντιέται χωρίς `ls`
- Browser push αναβάλλεται στο Phase 2 με λόγο, όχι από αμέλεια: θέλει service worker και
  VAPID keys, δηλαδή PWA που δεν υπάρχει. Το email φτάνει στον εστιάτορα όπου κι αν είναι
- Προστέθηκε «Check in» στη σελίδα αποδεικτικού για συνδεδεμένο προσωπικό — η φθηνή πρώτη
  έκδοση του scanner της §32.4, δεκαπέντε γραμμές πάνω σε check-in που είναι ήδη idempotent

### 2026-10-12 — M5 · Πληρωμές
- Τα gateways μιλούν **REST, χωρίς SDK**: όλη η επιφάνεια είναι πέντε κλήσεις και μια
  υπογραφή· ένα vendor package θα ήταν η βαρύτερη εξάρτηση του project, και το interface
  υπάρχει ακριβώς για να αλλάξει αυτό αργότερα χωρίς να πειραχτεί τίποτα άλλο
- **Τρεις γραμμές άμυνας στο hold** (§24.1): ορατή ώρα λήξης, ακύρωση του checkout session
  όταν λήξει, και επανέλεγχος διαθεσιμότητας πριν το confirm — με αυτόματο refund αν η
  θέση πιάστηκε στο μεταξύ
- **Το reconciliation είναι το πιο σημαντικό job του συστήματος.** Τα webhooks χάνονται·
  πελάτης που πλήρωσε και δεν έχει κράτηση το μαθαίνει στην πόρτα. Κάθε ώρα ρωτάμε το
  gateway κατευθείαν
- Idempotency key ανά απόπειρα refund, δεμένο με το ήδη επιστραμμένο ποσό: διπλό κλικ =
  ένα refund, δεύτερο γνήσιο refund = άλλο κλειδί
- Ποτέ «επεστράφη» πριν το πει το gateway — `refund_pending` μέχρι τότε
- Το webhook endpoint είναι **ανά venue**, γιατί κάθε venue υπογράφει με δικό του secret

### 2026-10-12 — M4 · Σελίδα κράτησης
- Τρεις οθόνες: αναζήτηση → διάλεξε → στοιχεία. Ο τουρίστας δεν διαβάζει τίποτα
- Οι ώρες δείχνουν «πόσα έμειναν» και **μόνο όσες έχουν κάτι ελεύθερο** — το αντίθετο
  από το dropdown του i-host που προσφέρει γεμάτες ώρες
- Η τιμή ξαναϋπολογίζεται server-side στην επιβεβαίωση· ό,τι κι αν λέει η φόρμα
- `resource_snapshot` τη στιγμή της κράτησης, ώστε το αποδεικτικό να μένει σωστό ακόμα
  κι αν ο εστιάτορας αλλάξει αργότερα τη σύνθεση του σετ
- Τηλέφωνο σε E.164 χωρίς βιβλιοθήκη: αυστηρό validation διώχνει τουρίστες, χαλαρό
  αφήνει τον εστιάτορα να διαβάσει έναν αριθμό δυνατά
- 🔒 **Δεύτερο bug σειράς middleware**: το `tenant.path` έλυνε τον tenant από το ήδη
  δεμένο model, οπότε `/reservation/{slug}/book/{hold}` έσκαγε. Πλέον ψάχνει μόνο του
  το slug και τρέχει πριν το `SubstituteBindings`, με δικό του test
- Το marketing consent αποθηκεύεται από τώρα: checkbox που δεν πάει πουθενά είναι
  χειρότερο από καθόλου checkbox

### 2026-10-12 — M3 · Τιμές
- `app/Pricing`: `PriceBook` φορτώνει τους κανόνες της ημέρας μία φορά, `Price` κουβαλάει
  και τον κανόνα που αποφάσισε — ώστε η σελίδα να λέει «Weekend rate», όχι σκέτο ακριβότερα
- **Ένας κανόνας κερδίζει, δεν στοιβάζονται.** Ο εστιάτορας πρέπει να δείχνει μια τιμή και να
  λέει ποιος κανόνας την έκανε· αλυσίδα ποσοστών δεν εξηγείται σε κανέναν
- Δύο στήλες `amount_cents` και `percent` αντί για ένα `value`: λεπτά και ποσοστά είναι
  διαφορετικές μονάδες, και μία στήλη καλεί το bug όπου το 25 σημαίνει άλλο ανά γραμμή
- Το `ClosureScope` έγινε `StructureScope` — closures και τιμές απαντούν την ίδια ερώτηση
  (όλα / μια σειρά / μια θέση), και δύο enums με ίδιες τιμές θα ξέφευγαν στο πρώτο τέταρτο επίπεδο

### 2026-10-11 — M2 · Διαθεσιμότητα
- Μηχανή διαθεσιμότητας σε `app/Availability`: business day, slot generator, closures, busy index
- **Τα slots δεν αποθηκεύονται** — υπολογίζονται· στη βάση μπαίνουν μόνο οι εξαιρέσεις
- Όλη η αριθμητική σε UTC: προσθέτοντας 30' σε instant παίρνεις πάντα 30', σε ρολόι τοίχου όχι
- Η μέρα φορτώνεται **μία φορά** (δύο queries) και απαντά εκατοντάδες ερωτήσεις στη μνήμη —
  20 ξαπλώστρες × 20 ώρες δεν είναι 400 queries
- Holds: row lock → καθάρισμα ληγμένων → επανέλεγχος → γράψιμο, με unique index από κάτω
- Closures: κοιτάζουν **μόνο μπροστά**· ο εστιάτορας μαθαίνει πόσοι έχουν ήδη κλείσει και
  αποφασίζει μόνος του — το σύστημα δεν ακυρώνει ποτέ πληρωμένη κράτηση
- Η οθόνη Today δείχνει πραγματικές κρατήσεις, με «μέρα» του μαγαζιού 06:00 → 06:00

### 2026-10-11 — M1 · Δομή
- Booking types με presets: «Tables» ή «Sunbeds» με ένα tap, τα υπόλοιπα πίσω από «Advanced»
- Areas με override occupancy/max stay/buffer — η μπροστινή σειρά πουλάει μέρα, η πίσω γυρίζει
- Bulk create «A1 έως A20» από template· διπλά labels προσπερνιούνται αντί να σκάσει το batch
- Open/close: ένα tap ανά θέση, όλη η σειρά, ή «άνοιξε τα πρώτα N» για walk-ins
- Η δημόσια σελίδα δείχνει μόνο ό,τι έχει στήσει το μαγαζί, με τιμή «from»
- Demo: μετονομασία σε **Demo Beach Bar**, 44 θέσεις σε 5 areas, δικό του hero + logo
- `App\Support\Money` — ένα σημείο όπου τα cents γίνονται κείμενο
- 🔒 Σειρά middleware: το `tenant.user` τρέχει **πριν** το `SubstituteBindings`, αλλιώς κάθε οθόνη με model στο URL έσκαγε με `TenantNotResolved`. Το test helper δεν κρατάει πια tenant ανάμεσα στα requests, γιατί έκρυβε ακριβώς αυτό
- Η στήλη `attributes` έγινε `meta`: μέσα σε Eloquent model το `$this->attributes` είναι ο ωμός πίνακας, και αντέγραφε σιωπηλά ολόκληρη τη γραμμή
- `phpdoc_types` off στο Pint: το `resource` είναι keyword της PHP και χαλούσε τα generics

### 2026-10-10 — M0 · Θεμέλια
- Laravel 13 skeleton, tenancy, auth, roles, δημόσια σελίδα, design tokens
- `TenantScope` χωρίς «χωρίς tenant σημαίνει χωρίς φίλτρο» — πετάει αντί να διαρρεύσει
- Το `WithoutModelEvents` στον seeder έκλεινε και το stamping του `tenant_id`· έφυγε, με test
- §32 Events & εισιτήρια: **αναβολή υλοποίησης, 4 αγκίστρια υποχρεωτικά στο Phase 1**
- §33 Δημόσιο προφίλ & ευρετηρίαση: schema.org, sitemap, πεδία επιχείρησης στις ρυθμίσεις
- Spec v2.0: API ξαναγραμμένο — first-party vs public με bearer token ανά account (§15)
- §31 Πρότυπα κώδικα: Laravel συμβάσεις, Actions, enums, **καμία αναφορά σε AI**, επεκτασιμότητα
- §30: object storage (R2), Sentry με scrubbing, staging, συντεταγμένες κάτοψης 0–1
- Αποφάσεις: καμία παύση/πακέτο σεζόν· retention 12 μήνες αντί 3

### 2026-10-09 — Σχεδιασμός
- Spec από το μηδέν → v1.9, 31 ενότητες
- Ανάλυση ανταγωνισμού: resOS, Tableo, SevenRooms, i-host → **κανείς δεν κάνει ξαπλώστρες**
- Prototype 4 οθονών (3 τουρίστα + Today εστιάτορα)
- Κλειδώθηκαν: booking types, σετ vs μεμονωμένα, occupancy, path-based URLs, αγγλικά μόνο, Brevo, πελατολόγιο ανά venue
