# TableReserved — Functional & Technical Spec

> **Status:** Draft v2.5 — 2026-10-15
> **Συνοδευτικά:** `PROGRESS.md` — τι υλοποιήθηκε & τι μένει · `CHANGELOG.md` — τι άλλαξε, ανά ημέρα · `DEPLOY.md` — εγκατάσταση σε νέο server · `ADMIN-SETUP.md` — τι στήνει ο διαχειριστής της πλατφόρμας · `COMPETITORS.md` — ανάλυση resOS / Tableo / SevenRooms / i-host · `CONFLUENCE.md` — ποια σελίδα του wiki βγαίνει από ποιο αρχείο
> **Owner:** Nick
> **Working name:** TableReserved (τελικό όνομα/domain TBD)
> **Stack:** Laravel · Blade · MariaDB · DB queue & cache (§3.3, §21) — **όχι** Inertia/React, **όχι** Next.js
> **Benchmark:** [i-host.gr](https://www.i-host.gr)

---

## 1. Σκοπός

Multi-tenant SaaS πλατφόρμα όπου **εστιατόρια, beach bars και καταστήματα** αποκτούν δικό τους λογαριασμό με σύστημα online κρατήσεων **τραπεζιών** και **ξαπλωστρών**, με προπληρωμή από τον τελικό πελάτη. Η πρόσβαση ελέγχεται από **συνδρομή**.

Κάθε κατάστημα παίρνει (α) δική του **προσωποποιημένη σελίδα κράτησης** και (β) **embeddable widget** για το site του.

Δύο ξεχωριστά οικονομικά επίπεδα:

| Επίπεδο | Ποιος πληρώνει | Ποιον | Gateway |
|---|---|---|---|
| **Platform billing** | Κατάστημα (tenant) | Εμάς | Το **δικό μας** Stripe/PayPal |
| **Reservation payments** | Τελικός πελάτης | Το κατάστημα | Τα **δικά του** Stripe/PayPal keys |

**Νόμισμα: μόνο EUR** στο v1.

> 🚫 **Εκτός scope:** έκδοση φορολογικών παραστατικών και διαβίβαση στην ΑΑΔΕ/myDATA. Ο πελάτης παίρνει **αποδεικτικό κράτησης** (§11), όχι απόδειξη.

### 1.1 Κυρίαρχη αρχή: ΑΠΛΟΤΗΤΑ ⭐

> **«Εδώ είμαστε Κέρκυρα, όχι Νέα Υόρκη.»**

Αυτή η πρόταση υπερισχύει κάθε άλλης απόφασης στο έγγραφο. Αν κάτι παρακάτω συγκρούεται μαζί της, **κερδίζει αυτή**.

**Ποιοι είναι οι δύο χρήστες:**

| | Ποιος είναι | Τι σημαίνει για το UI |
|---|---|---|
| **Τελικός πελάτης** | Ξένος τουρίστας, με κινητό, στην παραλία ή στο ξενοδοχείο, με μέτριο σήμα, που **δεν θα διαβάσει τίποτα** | 3 taps μέχρι την πληρωμή. Μηδέν κείμενο προς ανάγνωση. Τεράστια κουμπιά. Γρήγορο σε 4G |
| **Εστιάτορας** | Ταβερνιάρης, όχι software operator. Θα το ανοίξει 5 φορές τη μέρα από το κινητό, στη βιάση της υπηρεσίας | Μία οθόνη για το 90% της δουλειάς. Καμία ρύθμιση που δεν χρειάζεται |

**Οι πέντε κανόνες:**

1. **Defaults που δουλεύουν χωρίς καμία ρύθμιση.** Ο εστιάτορας πρέπει να μπορεί να δεχτεί κράτηση 10 λεπτά μετά το register, χωρίς να αγγίξει τίποτα πέρα από «πόσα τραπέζια» και «τι ώρες».
2. **Progressive disclosure.** Ό,τι δεν χρειάζεται το 80% πάει πίσω από «Advanced» — price rules, occupancy overrides, buffers, widget theming, attributes. Υπάρχουν, αλλά δεν τα βλέπει κανείς αν δεν τα ψάξει.
3. **Ό,τι μπορεί να προκύψει αυτόματα, προκύπτει αυτόματα.** Δεν ρωτάμε ποτέ κάτι που μπορούμε να συμπεράνουμε.
4. **Αν μια λειτουργία θέλει εξήγηση, είναι λάθος σχεδιασμένη.** Κανένα tooltip-μάθημα, κανένα onboarding video.
5. **Λιγότερες επιλογές, καλύτερες επιλογές.** Προτιμάμε να πούμε «όχι» σε feature παρά να προσθέσουμε ακόμα ένα κουμπί.

**Πρακτικά, τώρα:** τα φίλτρα στο frontend (sea view, shade, smoking…) εμφανίζονται **μόνο** αν ο εστιάτορας έχει πραγματικά ορίσει attributes — αλλιώς δεν υπάρχουν. Οι φωτογραφίες ανά resource είναι προαιρετικές. Το booking-type βήμα παρακάμπτεται αν υπάρχει ένα. Τα price rules είναι κρυμμένα μέχρι να πατήσει «different price for weekends».

### 1.2 Τρεις απλοποιήσεις που μας γλιτώνουν εβδομάδες

- **Καμία αυτο-εξυπηρέτηση σε ακύρωση/αλλαγή.** Ο πελάτης τηλεφωνεί στο μαγαζί· το μαγαζί ενεργεί από το dashboard (§8.3)
- **Καμία ένωση τραπεζιών** για μεγάλα γκρουπ — αυτά τηλεφωνικά
- **Κανένα αυτόματο pacing.** Ο εστιάτορας ανοίγει/κλείνει τραπέζια χειροκίνητα (§5.4)

### 1.3 Ένα venue ανά εστιάτορα

Κάθε tenant έχει **ένα venue** στο v1. Το `venues` table υπάρχει κανονικά από την αρχή και κάθε tenant-scoped εγγραφή κρέμεται από αυτό — απλώς δημιουργείται **αυτόματα στο register** και **δεν εμφανίζεται πουθενά στο UI**.

Έτσι η προσθήκη δεύτερου venue αργότερα είναι ξεκλείδωμα οθονών, όχι migration. Η σύνδεση πολλαπλών venues (κοινή σελίδα με επιλογή, ή ξεχωριστό slug ανά venue) θα αποφασιστεί όταν έρθει ο πρώτος πελάτης που το χρειάζεται.

---

## 2. Ρόλοι & Actors

### 2.1 Super Admin (platform)
- Δημιουργεί/διαχειρίζεται **πακέτα συνδρομών** και τα όριά τους
- Φτιάχνει **custom πακέτα** για συγκεκριμένο εστιάτορα (invite-only, δική του τιμή)
- **Ανανεώνει/παρατείνει χειροκίνητα** οποιαδήποτε συνδρομή, όποτε και όπως θέλει
- Βλέπει όλους τους tenants, status συνδρομής, MRR, churn
- Suspend / extend / comp
- Impersonate tenant για support
- Global settings: email templates, default T&C
- Audit log όλων των κρίσιμων ενεργειών

### 2.2 Tenant Owner (κατάστημα)
- **Self-service register** με 15ήμερο trial (§4.2)
- Διαχείριση προφίλ, λογότυπου, branding
- Ρυθμίσεις payment gateways (τα δικά του keys)
- **Booking types** (τραπέζια σάλας, ξαπλώστρες παραλίας…)
- Καταχώρηση χώρων, τραπεζιών/ξαπλωστρών, σετ, floor plan
- **Άνοιγμα/κλείσιμο resources** όποτε θέλει
- Ωράρια, timeslots, τιμές, πολιτική ακυρώσεων
- **Widget builder**
- Διαχείριση κρατήσεων: check-in, no-show, **ακύρωση & refund** (κατόπιν τηλεφώνου)
- Σελίδες όρων χρήσης & πολιτικής ακυρώσεων
- Προσκλήσεις staff users
- Διαχείριση συνδρομής

### 2.3 Tenant Staff (προσωπικό)
- Ημερολόγιο κρατήσεων, check-in, τηλεφωνικές/walk-in κρατήσεις
- Άνοιγμα/κλείσιμο τραπεζιών
- **Δεν** βλέπει payment settings, συνδρομή, ούτε κάνει refunds

### 2.4 End Customer (τελικός πελάτης)
- Guest booking **χωρίς εγγραφή** (όνομα + email + τηλέφωνο)
- Λαμβάνει **αποδεικτικό κράτησης**
- Για αλλαγή/ακύρωση **τηλεφωνεί στο κατάστημα** — το τηλέφωνο είναι πάνω στο αποδεικτικό

---

## 3. Αρχιτεκτονική

### 3.1 Multi-tenancy
**Single database, shared schema με `tenant_id`** σε κάθε tenant-scoped πίνακα.

- Global scope σε όλα τα tenant models (`BelongsToTenant` trait) — αυτόματο filtering
- Middleware `IdentifyTenant` → θέτει `app()->instance('tenant', $tenant)`
- Super admin panel χωρίς tenant scope
- Το `tenant_id` **ποτέ** δεν έρχεται από request input — μόνο από resolved context

### 3.2 Tenant resolution — path-based, ένα domain ⭐

**Κανένα subdomain.** Ένα domain, ένα πιστοποιητικό, μηδέν DNS φασαρία:

```
tablereserved.com/reservation/crystal
```

Το i-host κάνει το ίδιο με query param (`?restaurant=2884`) — εμείς το κάνουμε με καθαρό path, που διαβάζεται, μοιράζεται και ευρετηριάζεται καλύτερα.

| Σκοπός | URL |
|---|---|
| **Public booking page** | `tablereserved.com/reservation/{slug}` |
| Deep link από widget | `/reservation/{slug}?type=sunbeds&people=4&date=2026-10-16&time=10:30&channel=widget` |
| Tenant dashboard | `tablereserved.com/dashboard` |
| Super admin | `tablereserved.com/admin` |
| Widget script | `tablereserved.com/embed.js` |
| Widget iframe | `/embed/{slug}/search` |
| Webhooks | `/webhooks/{gateway}/{tenant_uuid}` |

**Δύο διαφορετικοί μηχανισμοί, και οι δύο απλοί:**

```php
// 1. Public routes — tenant από το route param
Route::prefix('reservation/{tenant:slug}')
     ->middleware('tenant.public')
     ->group(...);

// 2. Authenticated routes — tenant από τον συνδεδεμένο χρήστη,
//    ΚΑΘΟΛΟΥ slug στο URL
Route::prefix('dashboard')
     ->middleware(['auth', 'tenant.session'])
     ->group(...);
```

Στο dashboard ο tenant **δεν** έρχεται ποτέ από το URL — έρχεται από το `auth()->user()->tenant_id`. Έτσι δεν υπάρχει καν θεωρητικός τρόπος να δει κανείς άλλου tenant τα δεδομένα αλλάζοντας το URL.

**Τι κερδίζουμε:** μηδέν wildcard DNS, μηδέν wildcard SSL, ένα Let's Encrypt cert, δουλεύει σε οποιοδήποτε shared hosting. Είναι και η επιλογή που συμφωνεί με το §1.1.

> 🔜 Custom domain ανά tenant (`reservations.crystalseaside.gr`) παραμένει εφικτό αργότερα: ένα `custom_domains` table που δείχνει σε tenant, και middleware που ελέγχει το `Host` header πριν το route param. Δεν χτίζουμε τίποτα γι' αυτό τώρα.

### 3.3 Stack

```
Laravel 12 (PHP 8.3+)
├── Fortify / Breeze        auth + register
├── Spatie Permission       roles & permissions (tenant-scoped)
├── Laravel Cashier         platform subscriptions (Stripe)
├── PayPal SDK              platform subscriptions (PayPal)
├── Horizon + Redis         queues — ή database driver (§21)
├── Scheduler               expiry checks, reminders, hold cleanup
├── Spatie Media Library    floor plans, logos, φωτογραφίες
├── Spatie Activity Log     audit trail
└── Laravel Pennant         feature flags ανά plan

Frontend
├── Blade                   ⭐ δημόσια σελίδα κράτησης **και dashboard**
├── Λίγη vanilla JS         autocomplete πελάτη· καμία σελίδα δεν τη χρειάζεται
├── Tailwind                UI
├── react-konva / dnd-kit   floor plan editor (Phase 2)
└── Vanilla TS Web Component  embed widget (zero dependencies)
```

> ⭐ **Γιατί όχι Next.js για τη δημόσια σελίδα** (αναθεώρηση, 2026-10-10): δύο frontends σημαίνουν δύο deployments και δύο σημεία που σπάνε. Η σελίδα κράτησης χρειάζεται **server-rendered HTML** για το SEO (§33) και **μηδέν Node στην παραγωγή** για να τρέχει σε shared hosting (§21). Το Blade τα δίνει και τα δύο χωρίς SSR.
>
> ⭐ **Το Inertia + React δεν μπήκε στο M7** (αναθεώρηση, 2026-10-15). Η απόφαση πάρθηκε όταν έφτασε η ώρα του, με το dashboard ήδη χτισμένο σε Blade και να δουλεύει.
>
> Τρεις λόγοι, κατά βαρύτητα. **Δεν υπάρχει αρκετή διαδραστικότητα για να το δικαιολογήσει:** οι οθόνες του εστιάτορα είναι λίστες, φόρμες και ένα tap για check-in — πράγματα που ο server αποδίδει καλύτερα και με λιγότερο κώδικα. **Το shared hosting δεν θέλει build step:** κάθε deploy θα απαιτούσε `npm run build`, και ένα αποτυχημένο build είναι λευκή σελίδα σε παραγωγή αντί για μια σελίδα με παλιό στυλ. **Και θα ήταν ξαναγράψιμο οκτώ οθονών** που ήδη λειτουργούν, για μηδέν κέρδος στον χρήστη — ο ταβερνιάρης δεν μετρά αν η σελίδα έκανε full reload.
>
> Το κόστος είναι πραγματικό και αναγνωρίζεται: **το ημερολόγιο με drag-n-drop μετακίνηση κράτησης δεν γίνεται έτσι** και πάει στο Phase 2, όπου μπορεί να μπει ως **μία** σελίδα με JS αν το ζητήσει πελάτης. Αν ποτέ χρειαστούν τρεις τέτοιες σελίδες, τότε αξίζει το Inertia — όχι πριν.
>
> Ο κανόνας που μένει: **καμία σελίδα του dashboard δεν σπάει χωρίς JavaScript.** Η μόνη υπάρχουσα χρήση είναι το autocomplete πελάτη στην τηλεφωνική κράτηση, και η φόρμα συμπληρώνεται κανονικά με το χέρι χωρίς αυτό.

### 3.4 Γλώσσες

**v1: μόνο Αγγλικά** — super admin, tenant dashboard, public booking page, widget, emails, αποδεικτικό.

> ⚠️ Αφορά **ποιες μεταφράσεις γράφουμε**, όχι **πώς γράφουμε τον κώδικα.** Όλα τα strings περνούν από i18n από την πρώτη γραμμή — `__('booking.confirm')`, `t('booking.confirm')`, `en.json` στο widget. Μηδέν hardcoded κείμενο σε blade/JSX.
>
> Με πελατεία σε Κέρκυρα τα Ελληνικά θα ζητηθούν· σε σωστά δομημένο project είναι ένα αρχείο μετάφρασης, σε hardcoded είναι ξήλωμα κάθε view.

Υποδομή από το v1, χωρίς κόστος:
- `locale` column σε tenants & reservations (default `en`)
- `lang` query param (δέχεται μόνο `en` προς το παρόν)
- Email templates ανά locale με fallback σε `en`
- Ημερομηνίες/ώρες/νομίσματα μέσω `Intl`, όχι χειροκίνητο formatting
- Τα **δεδομένα** του tenant (ονόματα booking types, areas, περιγραφές) είναι ελεύθερο κείμενο — τα γράφει όπως θέλει, δεν τα μεταφράζουμε ποτέ

### 3.5 Ώρες & θερινή ώρα

- **Όλα τα timestamps σε UTC** στη βάση, πάντα
- Κάθε venue έχει `timezone` (default `Europe/Athens`)
- Μετατροπή μόνο στο boundary: display & input
- **Προσοχή στην αλλαγή ώρας:** η νύχτα που γυρνάει το ρολόι, μια ώρα είτε δεν υπάρχει είτε υπάρχει δύο φορές. Ο slot generator πρέπει να δουλεύει σε UTC και να απορρίπτει ανύπαρκτες τοπικές ώρες — όχι `strtotime` με naive strings
- Το αποδεικτικό τυπώνει πάντα τοπική ώρα venue + timezone label

---

## 4. Συνδρομές (Platform Billing)

### 4.1 Μοντέλο πακέτων
```
name, slug, description
billing_period: monthly | yearly
price, currency (EUR)
trial_days
is_public            # false = custom πακέτο για συγκεκριμένο tenant
limits:
  max_venues
  max_booking_types
  max_resources
  max_reservations_month   # null = unlimited
  max_staff_users
features:
  embed_widget           bool
  floor_plan_editor      bool
  composite_sets         bool
  custom_domain          bool
  sms_notifications      bool
  multi_venue            bool
  api_access             bool
  remove_branding        bool
```

**Seed (αρχικά πακέτα):**

| Πακέτο | Τιμή | Περίοδος |
|---|---|---|
| Monthly | **€70 / μήνα** | monthly |
| Annual | **€500 / έτος** | yearly |

Το ετήσιο βγαίνει ~€41.7/μήνα — έκπτωση ~40%, αρκετά δελεαστικό για να σπρώχνει σε ετήσια δέσμευση (καλύτερο cash flow & λιγότερο churn).

> 📊 **Σε σχέση με τον ανταγωνισμό** (λεπτομέρειες: `COMPETITORS.md`): στα unlimited είμαστε **φθηνότεροι απ' όλους** — €70 έναντι €125 (resOS Unlimited) και ~€96 (Tableo Grow). Η τιμή δεν είναι το πρόβλημα.
>
> **Η πόρτα εισόδου είναι.** resOS και Tableo έχουν **μόνιμο δωρεάν πακέτο** (25 bookings / 100 covers τον μήνα). Ο ταβερνιάρης μπαίνει χωρίς ρίσκο και αναβαθμίζει όταν γεμίσει. Εμείς ζητάμε €70 μετά από 15 μέρες — ψηλό σκαλοπάτι για μαγαζί που δουλεύει 5 μήνες τον χρόνο.
>
> **Προς εξέταση:** μόνιμο δωρεάν πακέτο **20–30 κρατήσεις/μήνα**. Κοστίζει μερικές εγγραφές στη βάση και λειτουργεί ως διαρκές demo. Το σχήμα το σηκώνει ήδη — ένα ακόμα `plan` row.

**Custom πακέτα:** ο super admin φτιάχνει πακέτο με `is_public=false` και το αναθέτει σε συγκεκριμένο tenant — δική του τιμή, δικά του όρια, όποια περίοδο θέλει. Δεν εμφανίζεται σε κανέναν άλλο.

**Χειροκίνητη ανανέωση/παράταση:** ο super admin μπορεί ανά πάσα στιγμή να σπρώξει το `current_period_end`, να δώσει δωρεάν μήνες, ή να σημειώσει μια συνδρομή ως πληρωμένη εκτός συστήματος (π.χ. κατάθεση σε τράπεζα). Καταγράφεται στο audit log με λόγο.

### 4.2 Register & Trial ⭐

```
1. Self-service register: όνομα καταστήματος, email, password
2. Email verification
3. → 15 ΗΜΕΡΕΣ TRIAL, ΧΩΡΙΣ ΚΑΡΤΑ
   πλήρης πρόσβαση, ζωντανές κρατήσεις
4. Onboarding wizard: booking type → areas → resources → ωράριο → τιμές
   → payment gateway → widget code
5. Μέρα 15 → επιλογή πακέτου & πληρωμή για συνέχεια
```

**Χωρίς κάρτα στο trial** σημαίνει περισσότερα signups αλλά και περισσότερους που δεν μετατρέπονται. Αντίμετρα, χωρίς να γίνουμε ενοχλητικοί:
- Emails μέρα 1 (καλωσόρισμα + setup), 7 (πώς πάει), 12 (λήγει σε 3 μέρες), 14, 15
- Banner με υπόλοιπο ημερών μέσα στο dashboard
- Το email της μέρας 12 δείχνει **τι θα χάσει**: «έχεις 23 κρατήσεις, €460 τζίρο από το σύστημα»

### 4.3 Κύκλος ζωής

```
trialing → active → past_due → grace_period → expired/suspended
                 ↘ cancelled (στο τέλος της περιόδου)
```

- Auto-renew μέσω Stripe Subscriptions / PayPal Billing Agreements
- Αποτυχία πληρωμής → `past_due`, dunning emails D+1, D+3, D+7
- **Grace period** 3 ημέρες (configurable) μετά τη λήξη
- Η απόδειξη της συνδρομής εκδίδεται **από Stripe/PayPal** — εμείς δείχνουμε ιστορικό + link λήψης

### 4.4 Τι σημαίνει "κλειδώνει"
- ❌ Tenant dashboard → read-only + banner ανανέωσης (όχι hard 403 — πρέπει να μπορεί να πληρώσει)
- ❌ Public booking page → "Online reservations are temporarily unavailable"
- ❌ **Το widget** δείχνει το ίδιο ευγενικό μήνυμα — δεν σπάει, δεν βγάζει error, δεν αφήνει κενό κουτί
- ✅ **Υπάρχουσες μελλοντικές κρατήσεις παραμένουν ορατές** και έγκυρες — δεν τιμωρούμε τους τελικούς πελάτες
- ✅ Export δεδομένων πάντα διαθέσιμο
- **Data retention 12 μήνες** μετά τη λήξη ⭐

> ⭐ **Γιατί 12 μήνες και όχι 3:** δεν υπάρχει pause ούτε πακέτο σεζόν (§19) — άρα ο εποχικός εστιάτορας θα κάνει το φυσικό: **ακύρωση τον Νοέμβριο, επανεγγραφή τον Απρίλιο**. Με 90 ημέρες τα δεδομένα του σβήνονται τον Φεβρουάριο και επιστρέφει σε άδειο λογαριασμό — χαμένα τραπέζια, κατόψεις, τιμές, πελατολόγιο. Θα έπρεπε να ξαναστήσει τα πάντα, και εκεί σκέφτεται τον ανταγωνιστή.
>
> Το 12μηνο καλύπτει κάθε εποχικό κύκλο. Δεν είναι feature — είναι μία τιμή ρύθμισης που δεν τιμωρεί τη φυσιολογική συμπεριφορά της αγοράς. Στους 11 μήνες φεύγει email υπενθύμισης πριν τη διαγραφή.

### 4.5 Enforcement ορίων
- Στο όριο: μπλοκάρεται η δημιουργία νέων, δεν διαγράφεται τίποτα
- Σε downgrade που παραβιάζει όριο → ο tenant επιλέγει τι απενεργοποιεί
- Validation σε API level, όχι μόνο UI

---

## 5. Δομή: Booking Types, Χώροι & Resources

### 5.1 Ιεραρχία

```
Tenant (κατάστημα)
└── Venue (ΕΝΑ στο v1 — auto-created, αόρατο στο UI, §1.3)
    └── Booking Type  ← τι κλείνει ο κόσμος
        │   "Dining Room Tables" · "Beach Sunbeds" · "Roof Garden"
        └── Area / Zone  "1st Row", "2nd Row", "By the window"
            └── Resource  table #12 / set A4 / single sunbed
```

**Γιατί υπάρχει το Booking Type:** το ίδιο μαγαζί έχει τραπέζια στη σάλα **και** ξαπλώστρες στην παραλία — άλλο ωράριο, άλλος τρόπος κράτησης, άλλες τιμές, άλλη σεζόν. Χωρίς αυτό το επίπεδο θα τα στριμώχναμε στο ίδιο ωράριο.

Κάθε Booking Type έχει δικά του:
```
name, slug, description, photos, icon
resource_type: table | sunbed | sunbed_set | cabana | custom
booking_mode: slot | arrival_window | half_day | full_day
occupancy: whole_day | fixed_duration  (+ max_stay_minutes)
opening_hours
slot settings
min_lead_time / max_advance_days
cancellation_policy (κείμενο — η εφαρμογή είναι χειροκίνητη, §8.3)
payment_requirement + deposit_label (§7.3)
default_price
default_view: list | floorplan
is_active, sort_order
```

Ο πελάτης με μόνο τραπέζια βλέπει **ένα** booking type που δημιουργείται αυτόματα — δεν του εμφανίζουμε καν την έννοια. Εμφανίζεται μόλις προσθέσει δεύτερο.

### 5.2 Resources: μεμονωμένα ή σετ

Ο εστιάτορας αποφασίζει ανά resource:

```
resources:
  booking_type_id, area_id
  label: "12" / "A4"
  is_composite: bool
  components: json          # μόνο αν is_composite
    [{"label":"Sunbed","qty":2},
     {"label":"Umbrella","qty":1},
     {"label":"Side table","qty":1}]
  capacity_min, capacity_max
  base_price
  price_basis: per_resource | per_person
  online_status: open | closed      ← §5.4
  is_active
  attributes: json          # sea_view, shade, smoking, accessible, pet_friendly
  photos
  sort_order
  pos_x, pos_y, width, height, rotation, shape   # floor plan geometry
```

| Τι θέλει ο εστιάτορας | Πώς το στήνει |
|---|---|
| Μεμονωμένες ξαπλώστρες, 10€ | `is_composite=false`, 40 resources A1–A40 |
| Σετ: 2 ξαπλώστρες + ομπρέλα + τραπεζάκι, 20€ | `is_composite=true` + components, 20 resources S1–S20 |
| Μικτό | Δύο areas, διαφορετικό `is_composite` ανά resource |

**Set templates** για να μη χτίζει το ίδιο 20 φορές:
```
resource_templates: tenant_id, name, is_composite, components json,
                    capacity_min/max, base_price, attributes
```
→ «Create 20 resources S1–S20 in 1st Row from template *Beach Set*».

**Το σετ είναι αδιαίρετη μονάδα κράτησης.** Όποιος θέλει ευελιξία, καταχωρεί μεμονωμένα.

### 5.3 Δύο τρόποι καταχώρησης & εμφάνισης (απαίτηση του project)

**A. Περιγραφικά (list mode)** — πάντα διαθέσιμο
- Areas με όνομα, bulk creation από template, drag για σειρά
- **Frontend:** accordion ανά area με κάρτες — με τιμή, περιεχόμενο σετ και διαθεσιμότητα ορατά

**B. Κάτοψη (floor plan mode)** — behind `floor_plan_editor` feature flag
- Upload εικόνας κάτοψης ή κενό καμβά με grid
- Drag & drop, resize, rotate, snap-to-grid
- Διαφορετικό σχήμα/εικονίδιο ανά τύπο
- **Frontend:** ο πελάτης βλέπει την ίδια κάτοψη με χρωματικό κώδικα, hover tooltip
- Κάτοψη **ανά booking type**
- Mobile: pan/zoom ή fallback σε list

**Συνυπάρχουν.** Αν υπάρχει κάτοψη → toggle «List ⇄ Floor plan», ο tenant ορίζει το default.

### 5.4 Χειροκίνητο άνοιγμα/κλείσιμο ⭐

Αντί για αυτόματο pacing, **ο εστιάτορας ελέγχει χειροκίνητα τι είναι online**. Από τα 20 τραπέζια βγάζει 10 στο internet και κρατά 10 για walk-in.

Κάθε resource έχει `online_status`:

```
open    → κρατήσιμο online
closed  → δεν εμφανίζεται καθόλου στο frontend
```

Στο dashboard εμφανίζονται **τρεις καταστάσεις** (η τρίτη υπολογίζεται, δεν αποθηκεύεται):

| Κατάσταση | Σημασία | Χρώμα |
|---|---|---|
| **Open** | Διαθέσιμο για online κράτηση | 🟢 |
| **Reserved** | Έχει κράτηση στο επιλεγμένο χρονικό σημείο | 🟡 |
| **Closed** | Το έκλεισε ο εστιάτορας | ⚫ |

**UX που πρέπει να είναι γρήγορο** — γίνεται καθημερινά, συχνά από κινητό:
- Toggle απευθείας πάνω στη λίστα και πάνω στην κάτοψη, ένα tap
- **Bulk:** «close entire 2nd Row», «open all», «open first 10»
- Από την live κάτοψη με ένα κλικ στο τραπέζι

**Το κλείσιμο κοιτάζει μόνο μπροστά** (§24.5): σταματά *νέες* κρατήσεις αμέσως, οι υπάρχουσες παραμένουν έγκυρες. Αν το resource έχει μελλοντικές κρατήσεις, εμφανίζεται ενημερωτικό μήνυμα με τον αριθμό τους και συντόμευση για χειροκίνητη ακύρωση — **ποτέ μπλοκάρισμα, ποτέ αυτόματη ακύρωση**.

Για προσωρινό κλείσιμο ημερών/περιόδων υπάρχουν τα `closures` (§6.2) — το `online_status` είναι για το καθημερινό on/off.

### 5.5 Branding: λογότυπο & hero image ⭐

Κάθε venue ανεβάζει **δύο εικόνες**, όπως το i-host — και αυτό είναι το μόνο branding που του ζητάμε (§1.1).

```
venues:
  logo_path          # PNG/SVG με διαφάνεια
  hero_path          # μεγάλη φωτογραφία για background του home
  accent_color       # hex — προτείνεται αυτόματα από το λογότυπο
  hero_overlay       # 0–80% σκοτείνιασμα, default 40%
```

| | Προδιαγραφές | Πού χρησιμοποιείται |
|---|---|---|
| **Logo** | PNG/SVG, διαφανές φόντο, ≥400px πλάτος, ≤2MB | Header σελίδας κράτησης, email, PDF αποδεικτικού |
| **Hero** | JPG/WebP, συνιστάται 2400×1600, ≤8MB | Background του home της σελίδας κράτησης |

**Αυτόματη επεξεργασία στο upload** — ο εστιάτορας ανεβάζει ό,τι έχει από το κινητό και δουλεύει:
- Resize σε 640 / 1280 / 1920 / 2400 + WebP με JPG fallback, σερβίρισμα με `srcset`
- Αφαίρεση EXIF (περιλαμβάνει GPS — ζήτημα ιδιωτικότητας)
- LQIP/blur placeholder ώστε να μη «χοροπηδάει» η σελίδα
- Το λογότυπο trim σε διαφάνεια και εξαγωγή κυρίαρχου χρώματος → προτεινόμενο `accent_color`

**Αναγνωσιμότητα — εδώ βελτιώνουμε το i-host.** Στα screenshots η φωτογραφία με τις ξαπλώστρες τρώει το περιεχόμενο· η κάρτα κράτησης παλεύει να ξεχωρίσει. Οπότε:
- **Υποχρεωτικό scrim** πάνω από το hero (gradient ή σκούρο layer), με slider στο dashboard και live preview
- Η κάρτα κράτησης έχει πάντα συμπαγές φόντο, ποτέ ημιδιάφανο πάνω σε φωτογραφία
- Αυτόματος έλεγχος contrast: αν τα κείμενα πέφτουν κάτω από WCAG AA, το dashboard το λέει και προτείνει μεγαλύτερο overlay

**Mobile (η πλειονότητα των τουριστών):** το hero γίνεται banner ~30vh στην κορυφή, **όχι** full-bleed background. Καλύτερη αναγνωσιμότητα, λιγότερα bytes, καλύτερο LCP σε 4G στην παραλία. Το hero είναι το LCP element → `preload` + responsive sizes· όλα τα υπόλοιπα lazy.

**Fallback:** αν δεν ανεβάσει τίποτα, η σελίδα δείχνει καθαρό gradient από το `accent_color` με το όνομα του καταστήματος σε μεγάλη γραμματοσειρά. **Ποτέ σπασμένο layout, ποτέ placeholder εικόνα** — ένα μαγαζί που δεν ανέβασε φωτογραφία πρέπει να φαίνεται μινιμαλιστικό, όχι ημιτελές.

Στο widget περνάει **μόνο** το `accent_color` — το embed μένει ελαφρύ και δεν κουβαλάει εικόνες στο site του πελάτη.

---

## 6. Διαθεσιμότητα & Timeslots

### 6.1 Ωράρια λειτουργίας
Ανά **booking type**, ανά ημέρα, με πολλαπλά διαστήματα (μεσημέρι + βράδυ):
```
opening_hours: booking_type_id, day_of_week, opens_at, closes_at
```
Υποστήριξη **μετά τα μεσάνυχτα** (close 02:00 → ανήκει στην προηγούμενη business day).

### 6.2 Booking modes

```
slot            # Τραπέζι: 19:00–20:30, 90' διάρκεια
arrival_window  # Ξαπλώστρα: ώρα άφιξης 10:00–12:30
                #   η διάρκεια κατοχής ορίζεται από το occupancy
half_day        # Πρωί / Απόγευμα
full_day        # Όλη η ημέρα, χωρίς επιλογή ώρας
```

> 📌 Το `arrival_window` προκύπτει από το i-host: δείχνει 10:00/10:30/…/12:30 για ξαπλώστρες. Δεν είναι slots διάρκειας — είναι ώρες άφιξης.

Ρυθμίσεις ανά booking type (override ανά area):
```
booking_mode, slot_duration_minutes, slot_interval_minutes,
turnover_buffer_minutes, arrival_window_start/end,
occupancy, max_stay_minutes
```

Τα slots **δεν αποθηκεύονται** — υπολογίζονται on the fly. Αποθηκεύονται μόνο οι εξαιρέσεις:
```
closures: scope_type (booking_type|area|resource), scope_id,
          date_from, date_to, time_from, time_to, reason
# ιδιωτική εκδήλωση, αργία, συντήρηση, κακοκαιρία, εκτός σεζόν
```
Η παραλία κλείνει Νοέμβριο–Απρίλιο ενώ η σάλα δουλεύει — γι' αυτό τα closures είναι ανά booking type.

### 6.2.1 Διάρκεια κατοχής (`occupancy`)

```
whole_day       # δεσμεύεται μέχρι το κλείσιμο — μία κράτηση/ημέρα
fixed_duration  # δεσμεύεται για max_stay_minutes (π.χ. 240')
                #   → χωράει δεύτερη κράτηση
```

**Και τα δύο ταυτόχρονα.** Ορίζεται στο booking type, **παρακάμπτεται ανά area**:

| Area | occupancy | Αποτέλεσμα |
|---|---|---|
| 1st Row (premium) | `whole_day` | €25, δική σου όλη μέρα |
| 2nd Row | `fixed_duration` 240' | €15 το 4ωρο, δύο βάρδιες |
| Back Row | `fixed_duration` 180' | €10 το 3ωρο |

Αλλάζει μόνο ο υπολογισμός του `ends_at`:
```php
$endsAt = match ($occupancy) {
    'whole_day'      => $businessDayClose,
    'fixed_duration' => $startsAt->addMinutes($maxStay),
};
```
Ο ίδιος overlap check με το buffer κάνει τα υπόλοιπα: κράτηση 10:00–14:00 με 30' buffer → επόμενο arrival 14:30.

**Στο frontend:** όταν `fixed_duration` το slot δείχνει ρητά «10:30 → 14:30 · 4 hours · €15», ώστε να μη νομίζει ο πελάτης ότι το πήρε όλη μέρα. Η διάρκεια τυπώνεται και στο αποδεικτικό.

### 6.3 Χρονικοί κανόνες
```
min_lead_time_minutes      # π.χ. 60 — κόβει κρατήσεις <1h πριν
max_advance_days           # π.χ. 60
hold_duration_minutes      # 15 — όσο κρατάμε το slot στο checkout (§24.1)
```
Ανά booking type: η σάλα δέχεται μέχρι 1 ώρα πριν, η παραλία μέχρι την προηγούμενη βραδιά.

### 6.4 Αποφυγή διπλοκράτησης (κρίσιμο)
1. Επιλογή slot → **`reservation_hold`** με TTL (15') μέσα σε transaction με `SELECT … FOR UPDATE`
2. Overlap check: `(start < existing_end AND end > existing_start)` + buffer, απέναντι σε confirmed reservations **και** ενεργά holds
3. Redirect σε πληρωμή
4. Webhook επιτυχίας → hold γίνεται `confirmed`
5. Scheduled job κάθε λεπτό καθαρίζει ληγμένα holds
6. Index: `(resource_id, starts_at, ends_at, status)`

Σε MySQL δεν υπάρχουν exclusion constraints — ο locking μηχανισμός είναι η άμυνα. **Concurrency tests με 50 ταυτόχρονα requests στο ίδιο slot.**

---

## 7. Τιμολόγηση Κρατήσεων

### 7.1 Βάση
`base_price` ανά resource. Το `price_basis` ορίζει αν χρεώνεται ανά resource ή ανά άτομο.

### 7.2 Κανόνες τιμών (price rules) — προαιρετικοί
Απαίτηση: «Σάββατο άλλη τιμή από Τρίτη απόγευμα». Ο εστιάτορας που θέλει μία τιμή δεν τους αγγίζει ποτέ.

```
price_rules:
  scope_type: booking_type | area | resource
  scope_id
  days_of_week: [6,7]        # null = όλες
  date_from, date_to          # σεζόν
  time_from, time_to
  price_type: fixed | percent_modifier
  value                       # 35.00 ή +25%
  priority                    # μεγαλύτερο κερδίζει
  is_active
```
**Resolution:** `base_price` → ο κανόνας με το μεγαλύτερο priority που ταιριάζει. **Server-side πάντα** — η τιμή από τον client δεν εμπιστεύεται ποτέ.

### 7.3 Τι είναι η πληρωμή — ελεύθερος χαρακτηρισμός ⭐

Στην πράξη το ποσό είναι συνήθως **προκαταβολή που αφαιρείται από τον τελικό λογαριασμό**: πληρώνει €20 online, κάνει λογαριασμό €100, δίνει €80 στο μαγαζί.

**Ο συμψηφισμός γίνεται στο ταμείο του καταστήματος και δεν μας αφορά.** Εμείς εισπράττουμε την προκαταβολή και το δηλώνουμε καθαρά στον πελάτη.

Γι' αυτό, ανά booking type:
```
payment_requirement: required | optional | none
deposit_label: string          # ελεύθερο κείμενο, το γράφει ο εστιάτορας
```

Παραδείγματα που μπορεί να βάλει:
- *"This amount will be deducted from your final bill"*
- *"Reservation fee — not redeemable"*
- *"Minimum spend prepayment"*

Το `deposit_label` εμφανίζεται **σε τρία σημεία**: στη σύνοψη πριν την πληρωμή, στη σελίδα επιτυχίας, και στο **αποδεικτικό κράτησης**. Έτσι δεν υπάρχει παρεξήγηση στο ταμείο — ο πελάτης έχει μαύρο στο άσπρο τι σημαίνει το ποσό που πλήρωσε.

Το προσωπικό βλέπει το ποσό και το label στην καρτέλα της κράτησης κατά το check-in.

### 7.4 Payment Gateways ανά tenant

**Και τα τρία διαθέσιμα από το v1:**
```
stripe            # κάρτες
paypal            # PayPal
cash_on_arrival   # χωρίς online πληρωμή — επιβεβαίωση & πληρωμή στο μαγαζί
```

> 🇬🇷 **Ελληνικά gateways — πιθανό εμπόδιο πώλησης.** Το i-host προσφέρει **Cardlink** δίπλα σε Stripe/PayPal, και όχι τυχαία: τα ελληνικά μικρομάγαζα συχνά έχουν ήδη τερματικό Cardlink/Viva/Alpha και **όχι** λογαριασμό Stripe. Το «άνοιξε πρώτα Stripe» μπορεί να χαλάσει πωλήσεις. Το `cash_on_arrival` καλύπτει προσωρινά όποιον δεν έχει τίποτα, αλλά **Viva Wallet και Cardlink αξίζει να μπουν νωρίς στο Phase 2** — το `PaymentGateway` interface τα δέχεται χωρίς αλλαγές αλλού.

```
payment_gateway_settings:
  tenant_id, venue_id (nullable)
  gateway, mode: test | live
  credentials: encrypted json
  is_active, statement_descriptor
```

**Ασφάλεια (μη διαπραγματεύσιμο):**
- Credentials encrypted at rest με ξεχωριστό key από env
- **Ποτέ** δεν επιστρέφονται στο API — μόνο masked (`sk_live_••••4242`)
- Webhook endpoint **ανά tenant**: `/webhooks/stripe/{tenant_uuid}` → ξέρουμε με ποιο secret να επαληθεύσουμε την υπογραφή
- Δεν αγγίζουμε ποτέ card data → Stripe Elements / PayPal SDK, **SAQ-A** PCI scope
- Test call όταν αποθηκεύονται τα keys

**Hook για το μέλλον:** `PaymentGateway` interface αφαιρετικό, ώστε αν θελήσουμε προμήθεια να μπει `StripeConnectGateway` χωρίς να αλλάξει το booking flow.

> ⚖️ Τα χρήματα πάνε κατευθείαν στον λογαριασμό του καταστήματος → **η φορολογική συμμόρφωση είναι δική του ευθύνη**. Ρητά στους Όρους Χρήσης.

> 💸 **Προμήθεια σε refund:** το Stripe **δεν** επιστρέφει το fee του — σε 100% refund το μαγαζί βάζει από την τσέπη του. **Εξαίρεση:** refund λίγο μετά τη χρέωση περνά ως *reversal*, χωρίς προμήθεια. Λεπτομέρειες & χειρισμός: §8.3.

---

## 8. Booking Flow

```
0. Επιλογή τι κλείνει  ← μόνο αν >1 booking types
   → κάρτες με φωτογραφία· αν υπάρχει ένα, παρακάμπτεται

1. Landing page: branding, hero, περιγραφή, τι περιλαμβάνει, ωράριο, χάρτης

2. Αναζήτηση: people + date + time
   → από widget έρχονται ΗΔΗ συμπληρωμένα → κατευθείαν στο 3

3. Αποτελέσματα
   → accordion ανά area, ή toggle σε FLOOR PLAN
   → κάθε επιλογή: label, τι περιλαμβάνει, χωρητικότητα, ΤΙΜΗ, φωτογραφία
   → φίλτρα ΜΟΝΟ αν ο εστιάτορας έχει ορίσει attributes — αλλιώς δεν υπάρχουν
   → εμφανίζονται ΜΟΝΟ resources με online_status=open

4. Στοιχεία πελάτη
   → όνομα, email, τηλέφωνο με country code (default +30), αποθήκευση E.164
   → ⭐ Σημειώσεις προς το κατάστημα / προπαραγγελία
   → ⭐ Checkbox: αποδοχή Terms + Cancellation Policy
   → GDPR consent για marketing (ξεχωριστό, unchecked)

5. Σύνοψη & Πληρωμή
   → breakdown + ⭐ deposit_label ("deducted from your final bill")
   → redirect σε Stripe/PayPal — ή επιβεβαίωση αν cash_on_arrival
   → hold 15' με ορατό countdown (§24.1)

6. Επιβεβαίωση
   → κωδικός κράτησης + QR
   → email με ΑΠΟΔΕΙΚΤΙΚΟ ΚΡΑΤΗΣΗΣ
   → .ics για το ημερολόγιο
   → ⭐ "To change or cancel, call the venue: +30 …"
```

**Validation server-side σε κάθε βήμα.** Το βήμα 5 επανα-υπολογίζει τιμή και διαθεσιμότητα πριν το payment intent.

**Μία κράτηση = ένα booking type.** Τραπέζι + ξαπλώστρες = δύο κρατήσεις.

> ⭐ **Στόχος απλότητας (§1.1):** για το συνηθισμένο μαγαζί — ένα booking type, χωρίς attributes, χωρίς κάτοψη — η πραγματική ροή για τον τουρίστα είναι **τρεις οθόνες**: αναζήτηση → διάλεξε → στοιχεία & πληρωμή. Τα βήματα 0 και 1 παρακάμπτονται, τα φίλτρα δεν εμφανίζονται. Αν στο τεστ χρειάζονται περισσότερα από 3 taps μέχρι το Stripe, κάτι πήγε στραβά.

### 8.1 Σχεδιαστικές βελτιώσεις έναντι i-host

Ζητήθηκε ρητά «**πολύ πιο όμορφο**»:

| Πρόβλημα στο i-host | Τι κάνουμε |
|---|---|
| Διπλή επιλογή ώρας (dropdown + grid) | Μία επιλογή |
| Το dropdown δείχνει γεμάτες ώρες | **Availability-aware** από την πρώτη οθόνη |
| Καμία τιμή πουθενά | Τιμή σε κάθε slot/resource |
| Δεν ξέρεις πόσα έμειναν | Badge «3 available» ανά area |
| Native selects, μηδέν styling | Custom date picker με heatmap διαθεσιμότητας |
| Μόνο λίστα | **Toggle List ⇄ Floor plan** |
| Καμία φωτογραφία | Φωτογραφίες ανά area/resource/booking type |
| Όλα χωνεμένα σε μία ροή | Καθαρός διαχωρισμός booking types |
| Η φωτογραφία φόντου τρώει το περιεχόμενο | Hero με υποχρεωτικό scrim + συμπαγής κάρτα + έλεγχος contrast (§5.5) |
| Spinners & full reloads | Skeleton loaders, instant transitions |
| Οριακά responsive | Mobile-first, sticky summary bar |

Προσβασιμότητα: keyboard navigation, ARIA, WCAG AA contrast.

### 8.2 Reservation statuses

```
hold              # προσωρινό, TTL
pending_payment   # περιμένει webhook
confirmed         # ✅ ενεργή
checked_in        # ήρθε
completed
cancelled_by_venue      # μόνο από το μαγαζί (§8.3)
no_show
expired           # έληξε το hold
refunded | partially_refunded
```

### 8.3 Ακύρωση & αλλαγή — χειροκίνητα, μέσω τηλεφώνου ⭐

**Ο πελάτης ΔΕΝ ακυρώνει μόνος του στο v1.** Καμία self-service ακύρωση, καμία αλλαγή ώρας/ημέρας.

```
Πελάτης → τηλεφωνεί στο μαγαζί
        → το μαγαζί αποφασίζει βάσει της πολιτικής του
        → ενεργεί από το dashboard: cancel + (προαιρετικά) refund
```

Στο dashboard, πάνω στην κράτηση:
- **Cancel** με λόγο (καταγράφεται)
- **Refund** full / partial / none — ο εστιάτορας βάζει το ποσό
- Αυτόματο email στον πελάτη με την επιβεβαίωση ακύρωσης & το ποσό επιστροφής

#### Πώς γίνεται τεχνικά το refund

**Τα χρήματα δεν περνούν ποτέ από εμάς.** Βρίσκονται στον λογαριασμό Stripe/PayPal του καταστήματος. Εμείς καλούμε το API του gateway **με τα κλειδιά του tenant** — το gateway αφαιρεί το ποσό από το υπόλοιπό του και το επιστρέφει στην κάρτα του πελάτη.

```
Εστιάτορας πατά "Cancel & refund"
   ↓
Διάλογος: Full €25 / Partial [___] / No refund  + λόγος
   ↓
Refund::create(payment_intent, amount)  με τα κλειδιά ΤΟΥ TENANT
   + idempotency key = "refund:{reservation_uuid}:{attempt}"
   ↓
status → refund_pending      (ΠΟΤΕ δεν λέμε "επεστράφη" πριν το επιβεβαιώσει το gateway)
   ↓
webhook refund.created / refund.updated / charge.refunded
   ↓
payments.refunded_amount += ποσό
reservation.status → refunded | partially_refunded
   ↓
Email στον πελάτη: ποσό, "5–10 εργάσιμες", κωδικός αναφοράς
```

**Οκτώ πράγματα που πρέπει να γίνουν σωστά** (πηγή: [Stripe Refunds docs](https://docs.stripe.com/refunds)):

**1. Idempotency key σε κάθε απόπειρα.** Διπλό κλικ σε κουμπί refund = διπλή επιστροφή χρημάτων. Το κλειδί το αποτρέπει στο επίπεδο του gateway, όχι του UI.

**2. Ποτέ αισιόδοξο UI.** Το refund είναι ασύγχρονο. Μέχρι να έρθει το webhook, η κατάσταση είναι `refund_pending` — όχι «επεστράφη».

**3. Η προμήθεια του gateway ΔΕΝ επιστρέφεται.** Σε πλήρες refund €25, το μαγαζί χάνει ~€0.60. Εμφανίζεται στον διάλογο πριν πατήσει:
> *«The payment fee (~€0.60) is not returned by Stripe. A full refund costs you that amount.»*

**4. Εξαίρεση — το γρήγορο refund είναι δωρεάν.** Αν γίνει λίγο μετά τη χρέωση, το Stripe το περνά ως **reversal** αντί refund: η αρχική χρέωση **εξαφανίζεται** από το extrait του πελάτη και **δεν παρακρατείται προμήθεια**. Πρακτικά: *αυθημερόν ακύρωση = μηδενικό κόστος*. Αξίζει να το λέει το UI — ενθαρρύνει τον εστιάτορα να μην αναβάλλει.

**5. Ανεπαρκές υπόλοιπο.** Εκτός σεζόν, το μαγαζί μπορεί να έχει μηδενικό υπόλοιπο στο Stripe. Τότε το refund μένει **pending** μέχρι να μπουν χρήματα (για κάρτες· για άλλες μεθόδους αποτυγχάνει), και το Stripe μπορεί να χρεώσει τον τραπεζικό του λογαριασμό. Το μήνυμα στο dashboard πρέπει να το εξηγεί αντί να δείχνει αόριστο «pending».

**6. Το refund μπορεί να αποτύχει.** Κλειστός λογαριασμός, χαμένη κάρτα. Έρχεται `refund.failed`, τα χρήματα γυρνούν στο Stripe (έως 30 μέρες). Τότε το σύστημα **δεν σιωπά**: ειδοποιεί τον εστιάτορα με τον λόγο και την οδηγία να κανονίσει εμβάσμα.

**7. Κωδικός αναφοράς (ARN).** Όταν ο πελάτης τηλεφωνεί «πού είναι τα λεφτά μου;», ο κωδικός από το `refund.updated` τον στέλνει στην τράπεζά του με κάτι χειροπιαστό. Αποθηκεύεται και φαίνεται στην καρτέλα της κράτησης.

**8. Μερικά & πολλαπλά refunds.** Το gateway επιτρέπει πολλά μερικά refunds, με άθροισμα ≤ αρχικό ποσό. Το `refunded_amount` είναι **σωρευτικό** και ο έλεγχος γίνεται server-side.

**Ανά gateway:**

| Gateway | Refund |
|---|---|
| **Stripe** | API με τα κλειδιά του tenant, όπως παραπάνω |
| **PayPal** | Αντίστοιχο API· οι κανόνες προμηθειών διαφέρουν — εμφανίζουμε **τα νούμερα που επιστρέφει το gateway**, ποτέ δικό μας υπολογισμό |
| **cash_on_arrival** | Καμία κλήση API. Η κράτηση σημειώνεται `cancelled`, και αν είχαν δοθεί μετρητά ο εστιάτορας τα επιστρέφει ο ίδιος — το σύστημα απλώς το καταγράφει |

**Ακύρωση χωρίς refund** είναι εξίσου έγκυρη επιλογή: no-show, ή πολιτική που δεν προβλέπει επιστροφή. Η κράτηση ακυρώνεται, τα χρήματα μένουν στο μαγαζί, ο πελάτης ειδοποιείται με τον λόγο.

Κάθε refund γράφεται στο **audit log**: ποιος, πότε, πόσο, γιατί. Είναι χρήματα — πρέπει να υπάρχει ίχνος.

**Η σελίδα πολιτικής ακυρώσεων παραμένει υποχρεωτική** (ο πελάτης τη συμφωνεί στο checkout) — αλλά η *εφαρμογή* της είναι ανθρώπινη απόφαση, όχι αυτοματισμός. Αυτό είναι σκόπιμο: οι πραγματικές ακυρώσεις έχουν αποχρώσεις που κανένας κανόνας δεν πιάνει.

**Αλλαγή κράτησης:** το μαγαζί ακυρώνει και ξανακλείνει, ή μετακινεί την κράτηση με drag στο ημερολόγιο.

> 🔜 Self-service ακύρωση με signed link → Phase 2, αν το ζητήσουν οι πελάτες.

---

## 9. Embeddable Widget ⭐

**Απαίτηση:** ο εστιάτορας παίρνει κωδικό από το account του, ο προγραμματιστής του τον βάζει στο site, εμφανίζεται **search bar** (people / date / time). Με «Search» πάει στην προσωποποιημένη σελίδα κράτησης.

> Το i-host το κάνει με `?restaurant=2884&lang=eng&channel=website`. Κρατάμε το μοτίβο (venue + **channel** για attribution), το κάνουμε πιο όμορφο.

**Phase 1.** Είναι ο βασικός τρόπος που φτάνουν οι κρατήσεις.

### 9.1 Τρεις μορφές

| Μορφή | Τι δείχνει |
|---|---|
| **A. Search bar** (default) | [booking type] + people + date + time + κουμπί |
| **B. Button / link** | «Book now» — για menu, header, sticky mobile bar |
| **C. Full inline** | ολόκληρη η ροή μέσα στο site του |

Με >1 booking types: διακριτικός selector, ή καρφωμένο `data-booking-type="sunbeds"` — οπότε μπορεί να βάλει **δύο widgets σε διαφορετικές σελίδες**.

### 9.2 Δύο τρόποι παράδοσης

**1. Script tag — προτεινόμενο**
```html
<div id="rsv-widget"></div>
<script
  src="https://tablereserved.com/embed.js"
  data-venue="crystal-seaside"
  data-booking-type="sunbeds"     <!-- προαιρετικό -->
  data-mode="search"
  data-lang="en"
  data-theme="auto"
  data-accent="#C9A227"
  data-layout="horizontal"
  data-on-search="redirect"
  async defer></script>
```

**2. Σκέτο iframe — fallback**
```html
<iframe src="https://tablereserved.com/embed/crystal-seaside/search?theme=light"
        width="100%" height="140" frameborder="0"
        style="border:0;max-width:720px"
        title="Reservations"></iframe>
```

> 🔜 **WordPress plugin — εκτός scope προς το παρόν** (§17). Shortcode, Gutenberg block και Elementor widget θα έχουν νόημα όταν μαζευτούν αρκετοί WP πελάτες· μέχρι τότε το script tag μπαίνει μια χαρά σε Custom HTML block ή Elementor HTML widget. Το `embed.js` δεν χρειάζεται καμία προετοιμασία για να τυλιχθεί σε plugin αργότερα.

### 9.3 Τεχνική υλοποίηση

**Web Component + Shadow DOM** (όχι iframe) για το search bar:
- Απόλυτη απομόνωση styles — το CSS του πελάτη δεν μας σπάει, και εμείς δεν σπάμε το site του
- Χωρίς height-sync, χωρίς scrollbars, χωρίς layout shift
- Το date picker μπορεί να ξεχειλίσει ελεύθερα — σε iframe θα κοβόταν
- `<10KB gzipped`, zero dependencies, async

**Iframe fallback:** `postMessage({type:'resize'})` για ύψος, `postMessage({type:'search'})` για navigation του **top window**. Αυστηρό `targetOrigin`, ποτέ `*`.

**Συμπεριφορά στην αναζήτηση** (`data-on-search`):
```
redirect       → ίδιο tab (όπως i-host)
redirect_blank → νέο tab
modal          → lightbox ΠΑΝΩ στο site του ⭐ (το i-host δεν το έχει)
inline         → αντικαθιστά το widget με όλη τη ροή
```

**Το URL μεταφέρει τα πάντα:**
```
https://tablereserved.com/reservation/crystal-seaside
  ?type=sunbeds&people=4&date=2026-10-16&time=10:30
  &channel=widget&ref=crystalseaside.gr
```

**Απόδοση & ασφάλεια:**
- Edge cache, `preconnect` στο API, prefetch της booking page στο hover
- Public read-only endpoints, rate limit ανά origin
- **Cookieless** (sessionStorage μόνο) — δεν χαλάει το cookie banner του πελάτη
- CSP `frame-ancestors` με προαιρετικό domain allowlist (default ανοιχτό)
- Μηδέν PII, κανένα tracking τρίτων

### 9.4 Widget Builder (tenant dashboard)
Live preview δίπλα στις ρυθμίσεις:
- Μορφή, booking type, layout, θέμα, accent, radius, font
- Προεπιλεγμένος αριθμός ατόμων, κείμενο κουμπιού, συμπεριφορά αναζήτησης
- **Κουμπί αντιγραφής** + «send to my developer» με email
- Οδηγίες επικόλλησης: WordPress (Custom HTML block) / Elementor (HTML widget) / Wix / Squarespace / σκέτο HTML
- Διαγνωστικό: «Widget detected on example.gr 2 hours ago ✅»

### 9.5 Attribution
```
channel: website | widget | direct | qr | google | phone | walkin | social
```
Dashboard: «από πού έρχονται οι κρατήσεις σου». Αυτό το νούμερο κάνει τον πελάτη να ανανεώνει τη συνδρομή.

---

## 10. Ειδοποιήσεις

### 10.1 Στον τελικό πελάτη

| Trigger | Κανάλι | Περιεχόμενο |
|---|---|---|
| Επιβεβαίωση | Email | **Αποδεικτικό κράτησης** (§11) |
| Υπενθύμιση | Email | X ώρες πριν (default 24h) |
| Ακύρωση από το μαγαζί | Email | Επιβεβαίωση + ποσό επιστροφής |
| Αλλαγή από το μαγαζί | Email | Τι άλλαξε |

### 10.2 Στο κατάστημα

| Trigger | Κανάλι |
|---|---|
| Νέα κράτηση | Email σε όλες τις διευθύνσεις + in-app bell + **browser push** |
| Ημερήσια σύνοψη | Email το πρωί, ανά booking type |
| Αποτυχία πληρωμής συνδρομής | Email στον owner |

**Ρυθμίσεις:** multiple recipient emails, ποια events, ώρες ησυχίας.

**Οι ώρες ησυχίας καθυστερούν, δεν ακυρώνουν.** Μια κράτηση στις 03:30 είναι κράτηση· το να μην τη μάθει ποτέ ο εστιάτορας είναι χειρότερο από το να τη μάθει στις 08:00. Η ειδοποίηση μπαίνει στην ουρά με delay μέχρι το πέρας των ωρών ησυχίας. Το καμπανάκι γράφεται αμέσως — δεν κάνει θόρυβο.

**Ο παραλήπτης-ιδιοκτήτης είναι πάντα μέσα**, ό,τι κι αν λένε οι ρυθμίσεις: λογαριασμός χωρίς καμία διεύθυνση που διαβάζεται είναι λογαριασμός του οποίου οι κρατήσεις περνούν απαρατήρητες.

**Η ημερήσια σύνοψη τρέχει κάθε ώρα** και διαλέγει τα venues των οποίων είναι η ώρα *τοπικά*, αντί να προγραμματίζεται μια φορά ανά ζώνη. Ένα schedule εξυπηρετεί Κέρκυρα και Κανάρια, και η αλλαγή ώρας δεν χρειάζεται καμία φροντίδα. Στέλνεται **και σε κενή μέρα**, με μια γραμμή που το λέει: η σιωπή μοιάζει ακριβώς με σύστημα που σταμάτησε να δουλεύει.

> 🔜 **Browser push → Phase 2.** Θέλει service worker, VAPID keys και εγκαταστάσιμη εφαρμογή — δηλαδή το PWA που είναι ήδη backlog. Μέχρι τότε το email είναι το κανάλι που φτάνει στον εστιάτορα όπου κι αν βρίσκεται, και το καμπανάκι τον πιάνει όταν είναι μέσα στο σύστημα.

### 10.3 Αποστολή email ⭐

**Στέλνει το σύστημά μας**, όχι ο server του πελάτη.

```
From:     "Crystal Seaside" <noreply@tablereserved.com>
Reply-To: info@crystalseaside.gr          ← το email του καταστήματος
```

Δεν στέλνουμε από το domain του πελάτη — θα απαιτούσε πρόσβαση στο DNS του (SPF/DKIM). Το display name δείχνει το μαγαζί, το reply πάει στο μαγαζί, και η παράδοση εξασφαλίζεται από **το δικό μας** επαληθευμένο domain.

**Service: Brevo** (υπάρχει ήδη account).

Γιατί ταιριάζει:
- **Ευρωπαϊκή εταιρεία** → τα δεδομένα μένουν σε EU, καθαρότερο GDPR για ελληνική πελατεία
- **SMS στον ίδιο λογαριασμό** → όταν έρθει το Phase 2 με SMS ειδοποιήσεις, δεν χρειάζεται δεύτερος πάροχος (π.χ. Twilio)
- Δουλεύει με σκέτο SMTP στο Laravel — μηδενική ενσωμάτωση για να ξεκινήσουμε

**Δύο πράγματα να τσεκάρεις στο account σου πριν το κλειδώσουμε:**
1. **Ημερήσιο όριο** στο δωρεάν πακέτο — είναι *ανά ημέρα*, όχι μηνιαίο. Μια γεμάτη μέρα (επιβεβαιώσεις + υπενθυμίσεις + ειδοποιήσεις καταστήματος) το φτάνει πιο εύκολα απ' όσο φαίνεται, γιατί κάθε κράτηση παράγει 3–4 emails
2. **Branding Brevo** στα emails του δωρεάν πακέτου — αν υπάρχει, δεν κάνει για παραγωγή· τα αποδεικτικά πρέπει να δείχνουν μόνο το μαγαζί

Αν κάποιο από τα δύο ενοχλεί, η αλλαγή είναι **ένα env var** — γράφουμε πάντα σε Laravel mail abstraction, ποτέ σε Brevo SDK. Εναλλακτικές αν χρειαστεί: Resend (άριστο DX, εύκολο per-tenant domain), Postmark (κορυφαία deliverability), SES (φθηνότερο σε όγκο).

**Απαραίτητα από την αρχή, ανεξάρτητα από πάροχο:** SPF + DKIM + DMARC στο domain μας, bounce/complaint webhooks, και **queued αποστολή** ώστε αργό SMTP να μη μπλοκάρει ποτέ το booking flow — ο τουρίστας δεν περιμένει το email για να δει «confirmed».

**Πώς βρίσκει τον δρόμο της μια ειδοποίηση bounce.** Ο πάροχος δεν ξέρει τίποτα για tenants: στέλνει ένα `message-id` και τίποτε άλλο. Οπότε τη στιγμή που φεύγει κάθε μήνυμα, το id του γράφεται πάνω στην κράτηση (`reservations.voucher_message_id`), και το webhook ταιριάζει πάνω σε αυτό. Άγνωστο id απαντιέται με 200 και αγνοείται — πάροχος που παίρνει σφάλμα ξαναπροσπαθεί για μέρες, και δεν υπάρχει εδώ τίποτα που αξίζει retry.

**Το σενάριο που δικαιολογεί όλο αυτό:** ο πελάτης γράφει λάθος το email του, η επιβεβαίωση γυρίζει πίσω, και εμφανίζεται στην πόρτα για τραπέζι που κανείς δεν ξέρει. Η κράτηση είναι αληθινή και η μόνη λύση είναι ένα τηλέφωνο — που γίνεται μόνο αν το dashboard ξέρει να το ζητήσει. Γι' αυτό το `voucher_delivery` βγαίνει κόκκινο στην καρτέλα της κράτησης και στην ημερήσια σύνοψη.

⚠️ **Προσοχή στο λεξιλόγιο του παρόχου:** το `request` του Brevo σημαίνει «δεκτό προς αποστολή», όχι «παραδόθηκε». Καταγράφεται ως επιτυχία και κρύβει το bounce που ακολουθεί. Αγνοείται, όπως και το `deferred`.

> 🔜 Phase 2: per-tenant verified domain, ώστε να στέλνει `reservations@crystalseaside.gr` όποιος θέλει και μπορεί να πειράξει το DNS του.

---

## 11. Αποδεικτικό Κράτησης

> 🚫 **Δεν εκδίδουμε φορολογικά παραστατικά και δεν διαβιβάζουμε στην ΑΑΔΕ/myDATA.**

### 11.1 Τι περιέχει
```
Κωδικός κράτησης + QR
Κατάστημα: όνομα, λογότυπο, διεύθυνση, ΤΗΛΕΦΩΝΟ, χάρτης
Τι κλείστηκε: booking type, area, label + τι περιλαμβάνει (αν σετ)
Ημερομηνία & ώρα (τοπική ώρα venue), διάρκεια
Αριθμός ατόμων
Σημειώσεις / προπαραγγελία πελάτη
Στοιχεία πελάτη
Ποσό που πληρώθηκε + ⭐ deposit_label
   π.χ. "€20.00 — will be deducted from your final bill"
Πολιτική ακυρώσεων που ίσχυε
⭐ "To change or cancel your reservation, please call us: +30 …"
Αρχείο .ics
⚠️ "This is a booking confirmation and NOT a tax document."
```

### 11.2 Μορφή
- **HTML email** (responsive) + **PDF attachment**
- Σελίδα με signed URL — το ανοίγει από το κινητό στην είσοδο
- QR για check-in από το προσωπικό (Phase 2)
- Branded με λογότυπο & χρώματα του tenant

**Το signed URL είναι σκόπιμα κοντό:** `/{code}/{token}` κάτω από `/v/`, χωρίς slug και χωρίς id — περίπου 50 χαρακτήρες, άρα QR version 4. Λιγότερα modules σημαίνει σύμβολο που διαβάζεται γρήγορα σε μισοσκόταδο, που είναι η πραγματική συνθήκη χρήσης: beach bar, δέκα το βράδυ, κινητό με λερωμένη κάμερα. Ο κωδικός κράτησης είναι ήδη μοναδικός σε όλη την πλατφόρμα (§11.3), οπότε δεν χρειάζεται tenant στη διαδρομή.

**Λάθος υπογραφή και ανύπαρκτος κωδικός δίνουν το ίδιο 404.** Διαφορετική απάντηση θα έκανε τη σελίδα μηχανή ελέγχου του αν ένας κωδικός υπάρχει.

**Ο κωδικοποιητής QR είναι δικός μας** (`App\Support\QrCode`), για τρεις λόγους κατά βαρύτητα:

1. Το QR πρέπει να φτάσει σε κινητό **μέσα από email client**, και κάθε client που μετράει πετάει SVG και data URI. Μένει αληθινό PNG — που τα διαθέσιμα πακέτα παράγουν μόνο μέσω GD ή Imagick, κανένα από τα δύο εξασφαλισμένο στο shared hosting.
2. Αυτό που κωδικοποιούμε είναι πάντα ένα κοντό URL: byte mode, error correction M, κάτω από 100 χαρακτήρες. Μικρή, σταθερή γωνιά της προδιαγραφής, όχι ολόκληρη.
3. Ένα QR που δεν διαβάζεται είναι πελάτης στην πόρτα· ο κώδικας που το φτιάχνει πρέπει να είναι δικός μας να τον διορθώσουμε.

Το εύρος είναι ρητά στενό — versions 1–10, level M — και ό,τι δεν χωρά **πετάει** αντί να υποβαθμιστεί σιωπηλά. Η ορθότητα δεν στηρίζεται σε καλή πίστη: τα tests επαληθεύουν τη δομή που ψάχνει ένας scanner, και ο κωδικοποιητής ελέγχθηκε με 151 payloads απέναντι σε ανεξάρτητη υλοποίηση, module προς module, σε όλες τις versions.

**Το PDF είναι προαιρετικό.** Αν λείπει η βιβλιοθήκη ή αποτύχει το rendering, το email φεύγει κανονικά — με κωδικό, QR και `.ics` — και η αποτυχία γράφεται στο log αντί να πεταχτεί. Συνημμένο αξίζει να υπάρχει· δεν αξίζει να χάνεται μια επιβεβαίωση γι' αυτό. Το font cache πηγαίνει κάτω από `storage`, γιατί στο shared hosting ο φάκελος `vendor` σπάνια είναι εγγράψιμος και μια βιβλιοθήκη PDF που δεν μπορεί να γράψει το cache της αποτυγχάνει στην **πρώτη κράτηση της σεζόν**, όχι στα tests.

**Ένα `Voucher` object τροφοδοτεί και τα τρία** (email, PDF, σελίδα). Το μόνο χειρότερο από ένα άσχημο αποδεικτικό είναι τρία που διαφωνούν μεταξύ τους.

### 11.3 Κωδικός
Μοναδικός **κωδικός κράτησης** (`CS-2026-A4F7K`), όχι λογιστική σειρά. Human-readable για το τηλέφωνο, χωρίς διφορούμενους χαρακτήρες (0/O, 1/I).

---

## 12. Νομικές Σελίδες & Συναίνεση

```
legal_documents: tenant_id, type: terms | cancellation_policy | privacy,
                 version, content, published_at, is_current
```
- Ο super admin δίνει default templates που ο tenant επεξεργάζεται
- Υποχρεωτικό checkbox στο checkout με link σε modal
- Αποθηκεύεται **ποια version** αποδέχτηκε + timestamp + IP
- Το αποδεικτικό ενσωματώνει την πολιτική που ίσχυε

### 12.2 Της πλατφόρμας — υποδομή τώρα, κείμενα από τον δικηγόρο ⭐

```
platform_documents: type: terms | privacy | dpa | cookies,
                    version, content, published_at, is_current
```

**Χτίζεται ολόκληρη η υποδομή στο Phase 1**· τα κείμενα μπαίνουν όταν τα δώσει ο δικηγόρος, χωρίς deploy:

- **Views & routes:** `/terms`, `/privacy`, `/dpa` — δημόσιες, styled, με ημερομηνία τελευταίας ενημέρωσης
- **Επεξεργασία από το super admin panel** με versioning
- **Αποδοχή στο register:** checkbox με links· καταγράφεται `accepted_terms_version` + timestamp + IP στον tenant
- **Επανα-αποδοχή σε ουσιώδη αλλαγή:** banner στο dashboard μέχρι να αποδεχτεί τη νέα έκδοση
- **Placeholder μέχρι τότε:** *«Draft — pending legal review»*, ώστε να μην μπει κατά λάθος σε παραγωγή ημιτελές κείμενο

**Σημειώσεις για το briefing του δικηγόρου** (όχι νομική συμβουλή):
- **Πωλητής προς τον τελικό πελάτη είναι το κατάστημα** — εμείς παρέχουμε λογισμικό, δεν πουλάμε κρατήσεις, δεν κρατάμε χρήματα (§7.4)
- **Η φορολογική συμμόρφωση και η έκδοση παραστατικών είναι ευθύνη του καταστήματος** (§1, §11)
- **DPA:** εμείς data processor, ο εστιάτορας controller για το πελατολόγιό του (§28.4)
- Διαθεσιμότητα υπηρεσίας, ευθύνη, όροι τερματισμού, δικαιοδοσία

---

## 13. Dashboards

### 13.1 Tenant Dashboard
```
⭐ Today         Η ΑΡΧΙΚΗ ΟΘΟΝΗ — όλες οι κρατήσεις της ημέρας (§13.3)
Calendar        day / week / month · timeline ανά resource
                drag για μετακίνηση κράτησης
Reservations    λίστα, φίλτρα, search, export CSV
                manual/τηλεφωνική κράτηση, check-in, no-show
                ⭐ cancel + refund (μετά από τηλέφωνο πελάτη)

🔒 **Η λίστα και το CSV περνούν από το ίδιο query object** (`App\Queries\ReservationSearch`), όχι από δύο αντίγραφα των ίδιων φίλτρων. Export που καλύπτει σιωπηλά διαφορετικό σύνολο από τη λίστα πάνω του είναι bug που κανείς δεν αναφέρει και όλοι χρησιμοποιούν. Για τον ίδιο λόγο **τα σύνολα αφορούν όλο το φιλτραρισμένο σύνολο**, όχι τη σελίδα στην οθόνη: πίσω από ένα φίλτρο ημερομηνιών η ερώτηση είναι σχεδόν πάντα «πόσο έκανε εκείνη η εβδομάδα».
⭐ Guests         πελατολόγιο ανά venue — ιστορικό, no-shows,
                σημειώσεις, tags, export (§28)
⭐ Floor / Tables  live view: open 🟢 / reserved 🟡 / closed ⚫
                ένα tap για open/close · bulk actions
Booking Types   ωράρια, mode, occupancy, τιμές, deposit label
Areas & Resources  resources, set templates, bulk create, floor plan editor
Pricing         base prices, price rules, seasons
⭐ Widget        builder με live preview + embed code
Payments        gateway settings (Stripe/PayPal/cash), συναλλαγές, refunds
Notifications   recipients, events
Legal           terms, cancellation policy
Users           staff invites & roles
Subscription    πακέτο, χρήση vs όρια, ιστορικό πληρωμών, upgrade
⭐ Developer     API keys (regenerate), allowed origins, endpoints με το
                slug συμπληρωμένο, curl, OpenAPI, γράφημα χρήσης (§15.2)
⭐ Business      προφίλ επιχείρησης: περιγραφή, φωτογραφίες, ώρες
                λειτουργίας, τοποθεσία, κατηγορία + δείκτης
                πληρότητας & διακόπτης ευρετηρίασης (§33.1)
Settings        branding (logo + hero + accent, live preview),
                timezone, URL slug, ⭐ delete account (§29)
```

> ⭐ **Progressive disclosure (§1.1):** η καθημερινή δουλειά ζει σε **δύο οθόνες** — *Today* και *Floor / Tables*. Όλα τα υπόλοιπα είναι setup που αγγίζεται μία φορά και μετά ξεχνιέται.
>
> Μέσα σε κάθε οθόνη setup, ό,τι δεν χρειάζεται το 80% μπαίνει πίσω από «Advanced»: price rules, occupancy overrides, turnover buffers, attributes, widget theming, allowed domains.

### 13.2.0 Ημερολόγιο — εφτά κολόνες, όχι πλέγμα ωρών ⭐

Το ημερολόγιο απαντά σε **μία ερώτηση που δεν απαντούν ούτε το Today ούτε η λίστα**: *ποιες μέρες είναι βαριές και ποιες άδειες;* Αυτή αποφασίζει αν θα φωνάξει δεύτερο ζευγάρι χέρια και αν θα ανοίξει καθόλου την πίσω σειρά.

Γι' αυτό είναι **εφτά κολόνες με δείκτη πληρότητας**, όχι πλέγμα ωρών:

- Μια παραλία όπου όλα κλείνονται για **όλη τη μέρα** δεν έχει τίποτα να πει σε πλέγμα ωρών — οι ξαπλώστρες θα ήταν μια γραμμή στις 10:00 και κενό δεκατέσσερις ώρες
- Η εβδομάδα τρέχει **Δευτέρα–Κυριακή στην τοπική ώρα του venue**. Ιδιοκτήτης που κοιτάζει «αυτή την εβδομάδα» Κυριακή βράδυ και βλέπει την επόμενη, σταματά να εμπιστεύεται την οθόνη
- Οι **κενές μέρες εμφανίζονται** — η κενή Τρίτη είναι πληροφορία
- Οι ακυρωμένες δεν μετριούνται στον δείκτη

> 🔜 **Το drag-n-drop μετακίνησης κράτησης πάει στο Phase 2**, μαζί με το Inertia (§3.3). Είναι επιθυμία, όχι καθημερινή ανάγκη: η μετακίνηση κράτησης γίνεται μετά από τηλέφωνο, και τότε ο εστιάτορας είναι ήδη στην καρτέλα της.

---

### 13.2.1 Τηλεφωνική κράτηση — τι επιτρέπεται στο προσωπικό ⭐

Ίδιος χώρος, ίδιο πελατολόγιο, ίδιο αποδεικτικό με μια online κράτηση. Αλλάζει **ποιος πληκτρολογεί και τι του επιτρέπεται να αποφασίσει**:

| | Online | Τηλεφωνικά |
|---|---|---|
| Email | **υποχρεωτικό** — εκεί πάει το αποδεικτικό | **προαιρετικό** (§28.2) |
| Τηλέφωνο | υποχρεωτικό | υποχρεωτικό — είναι ο μόνος τρόπος να τον βρεις |
| Τιμή | από τον τιμοκατάλογο, ποτέ από τη φόρμα | **μπορεί να παρακαμφθεί** |
| Hold & online πληρωμή | ναι | **όχι** — τα λεφτά δίνονται στο μαγαζί |
| Αποδοχή όρων | καταγράφεται με IP | **δεν καταγράφεται** |
| Διαθεσιμότητα | δεσμευτική | **μπορεί να παρακαμφθεί**, με σημείωση |

**Η τιμή παρακάμπτεται** γιατί ένας τακτικός πελάτης τηλεφώνησε και του δόθηκε η γωνία στην περσινή τιμή. Ο ιδιοκτήτης το ξέρει, ο τιμοκατάλογος όχι.

**Η διαθεσιμότητα παρακάμπτεται** γιατί αν ο ιδιοκτήτης λέει ότι ένα τραπέζι είναι ελεύθερο, είναι: στέκεται μέσα στο μαγαζί και εμείς όχι. Η παράκαμψη **γράφεται πάνω στην κράτηση** αντί να γίνεται σιωπηλά, ώστε μια αδύνατη διπλοκράτηση να εξηγείται αργότερα.

**Οι όροι ΔΕΝ καταγράφονται ως αποδεκτοί.** Κανείς δεν τσέκαρε κουτάκι στο τηλέφωνο, και το να γραφτεί το αντίθετο θα έκανε το μόνο αποδεικτικό στοιχείο που μετράει σε μια διαφωνία άχρηστο **παντού αλλού**.

**Το κατάστημα δεν ειδοποιείται** για κράτηση που καταχώρησε μόνο του: ο άνθρωπος που την πληκτρολόγησε το ξέρει ήδη, και ένα email γι' αυτό είναι ο θόρυβος που κάνει τον αποστολέα να καταλήξει σε φίλτρο.

---

### 13.3 «Today» — η οθόνη που βλέπει ο εστιάτορας ⭐

**Η αρχική οθόνη μετά το login, σε desktop και mobile.** Απαντά στη μία ερώτηση που έχει ο εστιάτορας 20 φορές τη μέρα: *«τι κρατήσεις έχω;»*

```
┌──────────────────────────────────────────────┐
│  Today · Friday 9 October                    │
│                                              │
│   12 bookings    38 guests    €240   3 new   │
│                                              │
│  [ Today ]  Tomorrow   This week   All       │
├──────────────────────────────────────────────┤
│ 13:00  Maria Rossi          4 👤   Table 7   │
│        €20 · paid              [Check in]    │
│                                              │
│ 13:30  John Smith           2 👤   Set A4  📝│
│        €20 · paid              [Check in]    │
│                                              │
│ 14:00  Anna Müller          6 👤   Table 12  │
│        €35 · paid         ✅ checked in      │
└──────────────────────────────────────────────┘
```

**Κανόνες σχεδιασμού:**
- **Χρονολογική λίστα**, όχι πίνακας με 12 στήλες. Διαβάζεται με μια ματιά σε κινητό
- Κάθε γραμμή: **ώρα, όνομα, άτομα, τι έκλεισε, ποσό, κατάσταση** — τίποτα άλλο
- 📝 εικονίδιο αν υπάρχουν σημειώσεις/προπαραγγελία· tap για άνοιγμα
- **Check-in με ένα tap**, χωρίς επιβεβαίωση
- Μετρητές στην κορυφή: κρατήσεις, άτομα, έσοδα ημέρας, **νέες από την τελευταία επίσκεψη**
- Χρωματικός κώδικας status, με εικονίδιο δίπλα — ποτέ **μόνο** χρώμα (προσβασιμότητα)
- Γρήγορα φίλτρα: Today / Tomorrow / This week / All upcoming
- Tap σε κράτηση → πλήρης καρτέλα: στοιχεία επικοινωνίας, σημειώσεις, πληρωμή, cancel/refund
- **Auto-refresh** και διακριτικό badge όταν μπαίνει νέα κράτηση ενώ η οθόνη είναι ανοιχτή
- Κουμπί **+ New booking** πάντα ορατό — η τηλεφωνική κράτηση είναι η πιο συχνή ενέργεια
- ⭐ Κουμπί **«Stop bookings for today»** με ένα tap (§24.5) — για αέρα, βλάβη, γεμάτη κουζίνα

**Αντοχή σε κακό σήμα** (νησιωτικά beach bars): η σημερινή λίστα μπαίνει σε cache και εμφανίζεται άμεσα από τοπικό αντίγραφο με ένδειξη «updated 2 min ago», ενώ γίνεται refresh στο παρασκήνιο. Ο εστιάτορας δεν κοιτάζει ποτέ spinner επειδή έπεσε το 4G.

### 13.2 Super Admin Dashboard
```
Overview        MRR, active tenants, trials (+ πόσοι λήγουν), churn
Tenants         λίστα, status, usage, impersonate, suspend
                ⭐ χειροκίνητη παράταση/ανανέωση με λόγο
Plans           CRUD· public πακέτα + ⭐ custom ανά tenant
Subscriptions   active/past_due/expired, failed payments, dunning
Settings        default templates, email templates,
                ⭐ νομικά κείμενα πλατφόρμας με versioning (§12.2)
Audit log       ποιος έκανε τι, πότε
System          queue health, failed jobs, webhook failures
```

---

## 14. Database Schema

```
# Platform
users                     id, name, email, password, is_super_admin, locale
tenants                   id, uuid, name, slug (unique, στο URL· δεσμεύεται
                          μόνιμα μετά από διαγραφή §29.3),
                          status, timezone, locale (en), currency (EUR),
                          trial_ends_at, accepted_terms_version,
                          accepted_terms_at, accepted_terms_ip,
                          deletion_requested_at, purge_at
tenant_user               tenant_id, user_id, role
plans                     name, slug, billing_period, price, trial_days,
                          is_public, limits json, features json
subscriptions             tenant_id, plan_id, gateway, gateway_sub_id,
                          status, current_period_start/end, trial_ends_at,
                          cancel_at_period_end, grace_ends_at,
                          manual_note          # χειροκίνητη παράταση
subscription_payments     amount, status, gateway_payment_id, paid_at,
                          gateway_receipt_url

# Structure
venues                    tenant_id, name, description,            ⭐ §33
                          address, city, postal_code, region, country,
                          lat, lng, phone, public_email, website_url,
                          social json, venue_category, cuisine,
                          price_range, timezone, settings json,
                          logo_path, hero_path, gallery,
                          accent_color, hero_overlay,
                          is_indexable, meta_title, meta_description
booking_types             venue_id, name, slug, description, photos, icon,
                          resource_type, booking_mode, slot_duration,
                          slot_interval, turnover_buffer,
                          arrival_window_start/end,
                          occupancy, max_stay_minutes,
                          min_lead_time_minutes, max_advance_days,
                          cancellation_policy json,
                          payment_requirement, deposit_label,
                          default_price, default_view, is_active, sort_order
opening_hours             scope: venue|booking_type, scope_id,     ⭐ §33.1
                          day_of_week, opens_at, closes_at
areas                     booking_type_id, name, description, photos,
                          occupancy (nullable override), max_stay_minutes,
                          turnover_buffer_minutes (nullable), sort_order
floor_plans               booking_type_id, image_path, width, height, scale
resource_templates        tenant_id, name, is_composite, components json,
                          capacity_min/max, base_price, attributes json
resources                 booking_type_id, area_id, label,
                          is_composite, components json,
                          capacity_min, capacity_max, base_price, price_basis,
                          online_status: open|closed,       ⭐
                          is_active, attributes json, photos,
                          pos_x, pos_y, width, height, rotation, shape,
                          sort_order
closures                  scope_type, scope_id, date_from/to,
                          time_from/to, reason
price_rules               scope_type, scope_id, days_of_week json,
                          date_from/to, time_from/to, price_type, value,
                          priority, is_active

# Widget
widget_settings           tenant_id, venue_id, booking_type_id (nullable),
                          mode, layout, theme, accent, radius, font,
                          default_people, button_text, on_search,
                          locale, allowed_domains json, show_branding
widget_events             tenant_id, type, referrer_domain, occurred_at

# Bookings
reservation_holds         resource_id, starts_at, ends_at, session_token,
                          expires_at
reservations              uuid, code, tenant_id, venue_id, booking_type_id,
                          resource_id (NULLABLE — §32.3),
                          resource_snapshot json,
                          customer_name, customer_email, customer_phone,
                          guest_id,                          ⭐ §28
                          party_size, starts_at, ends_at (UTC), status,
                          notes, amount, deposit_label_snapshot,
                          payment_status, channel, referrer_domain,
                          terms_version_id, terms_accepted_at,
                          terms_accepted_ip, voucher_pdf_path,
                          cancelled_at, cancelled_by, cancellation_reason,
                          checked_in_at, created_by
payments                  reservation_id, gateway, gateway_payment_id,
                          amount, status, refunded_amount (σωρευτικό),
                          refund_status, refund_reference (ARN),
                          refund_failure_reason, payload json
payment_gateway_settings  tenant_id, venue_id, gateway, mode,
                          credentials (encrypted), is_active

# Πελατολόγιο (§28)
guests                    venue_id, email (normalized), name, phone (E.164),
                          locale, first_seen_at, last_seen_at,
                          bookings_count, completed_count, cancelled_count,
                          no_show_count, total_spent,
                          marketing_consent, marketing_consent_at,
                          internal_notes, tags json, anonymized_at
                          ⭐ UNIQUE (venue_id, email)

# API (§15.2.1)
api_tokens                tenant_id, name, mode: test|live, token_hash,
                          prefix, last_used_at, last_origin,
                          created_at, revoked_at
api_requests              tenant_id, token_id, endpoint, status, occurred_at

# Ops
legal_documents           tenant_id, type, version, content, published_at
platform_documents        type, version, content, published_at, is_current
notification_settings     tenant_id, channel, recipients json, events json
activity_log              (spatie)
```

Το `resource_snapshot` και το `deposit_label_snapshot` κρατούν τι ίσχυε **τη στιγμή της κράτησης** — ώστε το αποδεικτικό να παραμένει σωστό ακόμα κι αν ο εστιάτορας αλλάξει τη σύνθεση του σετ ή το κείμενο της προκαταβολής αργότερα.

**Indexes:**
`reservations (resource_id, starts_at, ends_at, status)` · `reservations (tenant_id, starts_at)` · `reservations (booking_type_id, starts_at)` · `reservations (tenant_id, channel, created_at)` · `resources (booking_type_id, area_id, online_status, is_active)` · `reservation_holds (expires_at)` · `tenants (slug)` unique.

---

## 15. API

Δύο ξεχωριστά πράγματα, που δεν πρέπει να μπερδευτούν:

| | **First-party** (§15.1) | **Public API** (§15.2) |
|---|---|---|
| Ποιος καλεί | Η δική μας σελίδα κράτησης & το widget | Ο server του πελάτη |
| Auth | Καμία — slug στο URL | **Bearer token ανά account** |
| Συμβόλαιο | Εσωτερικό, αλλάζει με το frontend | **Σταθερό & versioned** |

### 15.1 First-party endpoints

Τα τροφοδοτεί η δική μας σελίδα κράτησης και το embed widget. Δεν είναι δημόσιο προϊόν — αλλάζουν μαζί με το frontend και δεν τα τεκμηριώνουμε προς τα έξω.

```
GET  /api/internal/{slug}/venue
GET  /api/internal/{slug}/booking-types
GET  /api/internal/{slug}/days?type=&month=
GET  /api/internal/{slug}/times?type=&date=&people=
GET  /api/internal/{slug}/availability?type=&date=&time=&people=
GET  /api/internal/{slug}/floor-plan?type=
POST /api/internal/{slug}/holds
POST /api/internal/{slug}/reservations
GET  /api/internal/reservations/{signed}      # το αποδεικτικό
POST /webhooks/{gateway}/{tenant_uuid}        # υπογραφή επαληθευμένη
```

Χωρίς token: το slug είναι δημόσιο και η πληροφορία είναι η ίδια που βλέπει όποιος ανοίγει τη σελίδα. Rate limit ανά IP και origin, edge cache 60–300s, cookieless. Μηδέν προσωπικά δεδομένα.

### 15.2 Public API — bearer token ανά account ⭐

Για τον πελάτη που θέλει **δική του φόρμα αναζήτησης** στο site του, ή να συνδέσει το σύστημά του. Το μοντέλο είναι το καθιερωμένο: ο **server** του πελάτη κρατά το token και καλεί εμάς.

```
Authorization: Bearer tr_live_8f2a9c4e1b7d…
```

**Ένα token ανά account**, από *Settings → Developer*: ο πελάτης το βλέπει, το αντιγράφει, και το ανανεώνει μόνος του όποτε θέλει. Δύο λειτουργίες, `tr_test_…` και `tr_live_…`, ώστε ο προγραμματιστής του να δουλεύει χωρίς να αγγίζει αληθινά δεδομένα.

**Phase 1 — ανάγνωση διαθεσιμότητας** (αυτό που χρειάζεται μια custom φόρμα):
```
GET /api/v1/venue
GET /api/v1/booking-types
GET /api/v1/days?type=sunbeds&month=2026-10
      → [{ date, status: available|limited|full|closed }]
        ένας μήνας σε μία κλήση, για χρωματισμό ημερολογίου
GET /api/v1/times?type=sunbeds&date=2026-10-18&people=4
      → [{ time, available, from_price }]
GET /api/v1/availability?type=&date=&time=&people=
      → areas με resources, τι περιλαμβάνουν, τιμή, διάρκεια
```

Η φόρμα στέλνει τον επισκέπτη στο
`tablereserved.com/reservation/{slug}?type=…&people=…&date=…&time=…&channel=api`
— **η κράτηση και η πληρωμή γίνονται πάντα σε εμάς**, όπου ζει η μηχανή διαθεσιμότητας και το hold.

**Phase 2 — τα δικά του δεδομένα**, πίσω από το `api_access` feature flag:
```
GET    /api/v1/reservations?from=&to=&status=&booking_type=
GET    /api/v1/reservations/{uuid}
POST   /api/v1/reservations        # κράτηση εκ μέρους του καταστήματος
PATCH  /api/v1/reservations/{uuid} # check-in, no-show, ακύρωση
GET    /api/v1/guests
GET    /api/v1/resources
PATCH  /api/v1/resources/{id}      # open/close
```

### 15.2.1 Κανόνες

| | |
|---|---|
| **Scope** | Το token δένεται σε έναν tenant. Κάθε id εκτός αυτού αγνοείται από τον resolver — δεν υπάρχει τρόπος να δει ξένα δεδομένα |
| **Server-side μόνο** | Το token **δεν μπαίνει ποτέ σε JavaScript του browser** — εκεί το διαβάζει όποιος ανοίξει το DevTools. Η φόρμα στο site του καλεί τον δικό του server, κι αυτός εμάς |
| **Ανίχνευση διαρροής** | Αν δούμε κλήσεις με `Origin` header (δηλαδή από browser), **προειδοποιούμε στο dashboard**: «το token σου φαίνεται εκτεθειμένο στο example.gr». Φθηνός έλεγχος, γλιτώνει περιστατικό |
| **Regenerate** | Άμεσο: το παλιό παύει να ισχύει τη στιγμή που πατάει. Ρητή προειδοποίηση πριν, γιατί σπάει η ενσωμάτωσή του μέχρι να το αντικαταστήσει |
| **Rate limit** | **Ανά token**, με `429` + `Retry-After`· προειδοποίηση στο dashboard πριν φτάσει το όριο |
| **Έκδοση** | `/v1` από την πρώτη μέρα. Breaking change → `/v2`, με το `/v1` ζωντανό ≥12 μήνες |
| **Σχήμα** | Σταθερό: πεδία προστίθενται, δεν αφαιρούνται, δεν αλλάζουν τύπο |
| **Σφάλματα** | `{ error: { code, message } }`, ποτέ stack trace· `401` άκυρο token, `403` εκτός scope ή χωρίς το feature |
| **Audit** | Κάθε κλήση καταγράφεται: endpoint, token, status, χρόνος |

**Σελίδα *Developer* στο dashboard:** το token με κουμπί αποκάλυψης και αντιγραφής, κουμπί regenerate, **τα endpoints με το slug του ήδη συμπληρωμένο**, έτοιμα `curl`, ζωντανή δοκιμή, **OpenAPI schema** για αυτόματη δημιουργία client, και γράφημα χρήσης — πόσες κλήσεις, ποια endpoints, πόσες απορρίφθηκαν.

```
api_tokens
  tenant_id, name, mode: test|live
  token_hash, prefix          # "tr_live_8f2a" για εμφάνιση
  last_used_at, last_origin   # για την ανίχνευση διαρροής
  created_at, revoked_at
api_requests
  tenant_id, token_id, endpoint, status, occurred_at
```

### 15.3 Outgoing webhooks (Phase 3)

Ο tenant δηλώνει URL και λαμβάνει `reservation.created`, `reservation.cancelled`, `reservation.checked_in` — ώστε να συνδέσει POS ή CRM χωρίς polling. Υπογεγραμμένα με HMAC, με retry και dead-letter, ακριβώς όπως τα δεχόμαστε κι εμείς από τα gateways.

---

## 16. Ασφάλεια & Συμμόρφωση

- **Tenant isolation:** global scopes + policies σε **κάθε** model· integration tests ότι tenant A δεν φτάνει δεδομένα tenant B — το #1 ρίσκο σε SaaS
- **Gateway credentials:** encrypted at rest, masked στο API, audit log
- **PCI:** SAQ-A — μηδενικό contact με card data
- **Widget:** cookieless, μηδέν PII, CSP `frame-ancestors`, αυστηρό postMessage origin
- **GDPR:** DPA με tenants, right to erasure (anonymize), export, consent logging
- Signed URLs για αποδεικτικά, με expiry
- Webhook signature verification, idempotency keys, replay protection
- 2FA για tenant owners & super admins
- Rate limiting σε booking endpoints
- Backups: ημερήσια DB + weekly full, **δοκιμασμένο restore**

---

## 17. Phases

### Phase 1 — MVP
1. Auth, **register + 15ήμερο trial χωρίς κάρτα**, tenants, path-based resolver, roles
2. Super admin: plans (seed €70/μήνα, €500/έτος) + custom plans + χειροκίνητη ανανέωση
3. Subscriptions με Stripe + PayPal + lifecycle/lockout
4. Booking types (ωράρια, mode, occupancy, κανόνες, deposit label)
5. Areas, resources — list mode, μεμονωμένα ή σετ + templates + bulk create
6. **Open/close resources** (single + bulk + από κάτοψη)
7. Booking modes: slot + arrival_window· closures
8. Base pricing + price rules
9. Public booking page (mobile-first, booking-type selector) + **branding: logo & hero upload** (§5.5)
10. ⭐ **Δημόσιο προφίλ & SEO** (§33) — πεδία επιχείρησης, schema.org + ReserveAction, sitemap, OG
11. ⭐ **Embed widget** — search bar + builder + channel attribution
12. ⭐ **Public API v1** (§15.2) — bearer token ανά account, rate limits, σελίδα *Developer* με OpenAPI
13. Payments: Stripe + PayPal + cash_on_arrival (tenant keys) + holds + webhooks
14. **Αποδεικτικό κράτησης** (email + PDF + QR + .ics) & ειδοποίηση καταστήματος
15. Tenant dashboard: ⭐ **οθόνη «Today»** (§13.3), ημερολόγιο, manual booking, **cancel + refund**
16. ⭐ **Πελατολόγιο** (§28) — guests ανά venue, dedup, ιστορικό, autocomplete στην τηλεφωνική κράτηση
17. Terms & cancellation policy καταστήματος + consent logging
18. ⭐ **Νομικές σελίδες πλατφόρμας** (§12.2) — υποδομή & views με placeholder κείμενα
19. ⭐ **Διαγραφή λογαριασμού** (§29) — μπλοκάρισμα με ενεργές κρατήσεις, export, 30ήμερη αναμονή
20. Onboarding wizard + trial emails
21. ⭐ **Demo seeder** (§22) — χτίζεται παράλληλα, ενημερώνεται σε κάθε feature
22. 🔒 **Αγκίστρια για events** (§32.3) — υπογεγραμμένο QR, nullable `resource_id`, idempotent check-in, `party_size` ως ποσότητα

### Phase 2
23. **Floor plan editor + public κάτοψη με επιλογή**
24. Widget modal mode + full inline booking
25. Self-service ακύρωση με signed link (αν ζητηθεί)
26. **Viva Wallet + Cardlink** (ελληνικά gateways — §7.4)
27. **Reserve with Google** — το έχουν και οι 4 ανταγωνιστές· το μόνο κανάλι που φέρνει *νέους* πελάτες
28. **Waitlist** — φθηνό, το ζητάνε όταν γεμίζουν
29. **Merge guests** — χειροκίνητη συγχώνευση διπλοεγγραφών όταν κάποιος χρησιμοποίησε δύο emails (§28.2)
30. SMS notifications (μέσω Brevo — ίδιος λογαριασμός)
31. Reminders & ημερήσια σύνοψη
32. QR check-in για το προσωπικό
33. Per-tenant verified email domain
34. **Multi-venue UI** (η υποδομή υπάρχει ήδη, §1.3)
35. Staff users & permissions
36. Reports & analytics (πληρότητα, έσοδα, no-show, channels)

### Phase 3 / Backlog
37. **Ελληνικά** — αρχεία μετάφρασης, η υποδομή υπάρχει ήδη
38. **WordPress plugin + Elementor widget**
39. Custom domain ανά tenant (`custom_domains` table + Host middleware, §3.2)
40. Λογαριασμοί τελικών πελατών + ιστορικό
41. Καλάθι: τραπέζι + ξαπλώστρες σε μία πληρωμή
42. Ένωση τραπεζιών για μεγάλα γκρουπ
43. Προπαραγγελία με μενού & items
44. **Events & εισιτήρια** με scanner (§32) — μετά την πρώτη σεζόν, με συγκεκριμένο πελάτη
45. Stripe Connect + προμήθεια ανά κράτηση
46. PWA για το προσωπικό
47. **Offline επίπεδο 1** — read-only PWA (§23)

---

## 18. Τι ΔΕΝ είναι στο scope
- ❌ **Έκδοση φορολογικών παραστατικών** / **myDATA-ΑΑΔΕ**
- ❌ **Self-service ακύρωση ή αλλαγή κράτησης** (τηλεφωνικά, v1)
- ❌ **Ένωση τραπεζιών** για μεγάλα γκρουπ (τηλεφωνικά)
- ❌ **Αυτόματο pacing / covers limit** (χειροκίνητο open/close)
- ❌ **Overbooking** — ασύμβατο: ο πελάτης κλείνει συγκεκριμένο, προπληρωμένο resource
- ❌ Άλλα νομίσματα πέρα από EUR
- POS / παραγγελίες στο τραπέζι
- Διαχείριση αποθήκης, payroll, loyalty, email marketing
- Dynamic pricing με αλγόριθμο

---

## 19. Αποφάσεις

| Θέμα | Απόφαση |
|---|---|
| **ΑΠΛΟΤΗΤΑ** | **Υπερισχύει κάθε άλλης απόφασης (§1.1).** 3 taps για τον τουρίστα, 2 οθόνες για τον εστιάτορα, τα πάντα άλλα πίσω από «Advanced» |
| **Venues** | **Ένα ανά εστιάτορα** στο v1· το `venues` table υπάρχει από την αρχή, auto-created, αόρατο στο UI (§1.3) |
| Payments κρατήσεων | Tenant's own API keys — Stripe + PayPal + cash_on_arrival. Έσοδο μόνο η συνδρομή |
| Σημασία πληρωμής | Προκαταβολή· **ελεύθερο `deposit_label`** που γράφει ο εστιάτορας. Ο συμψηφισμός δεν μας αφορά |
| Register & trial | **Self-service, 15 ημέρες, χωρίς κάρτα** |
| Πακέτα | Seed **€70/μήνα** & **€500/έτος**· custom πακέτα + χειροκίνητη ανανέωση από super admin |
| Παραστατικά / myDATA | **Εκτός scope.** Μόνο αποδεικτικό κράτησης |
| Ακύρωση & αλλαγή | **Τηλεφωνικά.** Το μαγαζί ενεργεί από το dashboard |
| Pacing | **Χειροκίνητο open/close** ανά resource (+ bulk), όχι αυτόματο covers limit |
| Μεγάλα γκρουπ | Τηλεφωνικά — καμία ένωση τραπεζιών |
| Σετ vs μεμονωμένα | Ο εστιάτορας επιλέγει ανά resource (`is_composite` + templates) |
| Booking types | Ξεχωριστή κράτηση για σάλα και παραλία, με δικά τους ωράρια/τιμές/κανόνες |
| Διάρκεια κατοχής | `whole_day \| fixed_duration` ανά booking type, override ανά area |
| Γλώσσες | **Μόνο Αγγλικά**, κώδικας i18n-ready |
| Νόμισμα | **EUR μόνο** |
| Tenant URL | **Path-based, κανένα subdomain**: `tablereserved.com/reservation/{slug}`. Ένα domain, ένα SSL cert (§3.2) |
| Emails | Στέλνει το σύστημά μας· From=όνομα μαγαζιού, Reply-To=email μαγαζιού. **Brevo** (υπάρχον account, EU, SMS στον ίδιο λογαριασμό για Phase 2) |
| **Overbooking** | **Ασύμβατο με το μοντέλο** — ο πελάτης κλείνει συγκεκριμένο resource και το έχει προπληρώσει. Hard limit πάντα |
| WordPress plugin | Εκτός scope προς το παρόν |
| Όνομα | Working name **TableReserved**· domain TBD |
| Hosting | Verpex shared αρχικά → VPS όταν χρειαστεί (§21) |
| **Demo seeder** | **Υποχρεωτικός** (§22) — και **ενημερώνεται σε κάθε νέο feature, στο ίδιο PR** |
| **PROGRESS.md** | **Υποχρεωτικό** (§22.4) — ενημερώνεται στο ίδιο commit με τη δουλειά· feature = tests + seeder + progress |
| **Ευρετηρίαση** | **Ναι**, με πραγματικό περιεχόμενο, schema.org + ReserveAction, `noindex` στα query params, διακόπτη ανά tenant· αυτόματο `noindex` σε φτωχό προφίλ (§33) |
| **Events & εισιτήρια** | **Όχι τώρα**, αλλά **4 αγκίστρια υποχρεωτικά στο Phase 1** (§32.3): υπογεγραμμένο QR, nullable `resource_id`, idempotent check-in, `party_size` ως ποσότητα |
| **Θέση στην αγορά** | **«Κρατήσεις για beach bars ΚΑΙ εστιατόρια»** — κανένας διεθνής ανταγωνιστής δεν κάνει ξαπλώστρες (`COMPETITORS.md`) |
| **Εποχικότητα** | **Κανένα pause, κανένα πακέτο σεζόν.** Ό,τι λέει το πακέτο — ακύρωση & επανεγγραφή. Αντιστάθμισμα: retention 12 μήνες (§4.4) |
| **Αρχεία** | **Object storage** (Cloudflare R2 — μηδενικό egress) από την αρχή, όχι τοπικός δίσκος (§30.1) |
| **Error tracking** | **Sentry**, με `send_default_pii=false` και υποχρεωτικό scrubbing κλειδιών & PII (§30.2) |
| **Staging** | **Υποχρεωτικό** πριν τις πληρωμές — δική του βάση & test keys (§30.3) |
| **Κάτοψη** | Συντεταγμένες **κανονικοποιημένες 0–1**· παραμένει Phase 2 — το περιγραφικό μοντέλο πουλάει περισσότερο (§30.4) |
| **Offline** | **Επίπεδο 0 τώρα, στόχος το 1** (read-only PWA). Τα επίπεδα 2–3 μόνο αν το προϊόν πουλήσει (§23) |
| **Hold** | **15 λεπτά**, μοντέλο more.com· ακύρωση checkout session μαζί με το hold + επαναέλεγχος στο webhook (§24.1) |
| **Έλεγχος gateway** | Κουμπί «Test connection» + **sandbox mode** με εμφανές banner. Ημερήσιος health check εκτός scope (§24.3) |
| **Τηλέφωνο** | Country picker με **default +30**, αποθήκευση E.164 (§24.4) |
| **Όριο ανά email** | **2 resources / 24ώρες** ανά κατάστημα, ρυθμίσιμο (§24.6) |
| **Cookies** | **Κανένα banner** — μόνο απολύτως απαραίτητα, μηδέν tracking τρίτων (§24.7) |
| **Κλείσιμο** | **Κοιτάζει μόνο μπροστά**: σταματά νέες κρατήσεις, δεν αγγίζει τις υπάρχουσες. Ακυρώσεις & refunds χειροκίνητα. Ίδιο με resOS/Resy (§24.5) |
| **Πελατολόγιο** | **Ανά venue, `UNIQUE(venue_id, email)`** — ίδιο email σε δύο μαγαζιά = δύο εγγραφές· ίδιο μαγαζί ξανά = μία εγγραφή με πλήρες ιστορικό. Ποτέ κοινό μεταξύ tenants (§28) |
| **Νομικά πλατφόρμας** | **Υποδομή & views τώρα** με versioning και placeholder· τα κείμενα από τον δικηγόρο χωρίς deploy (§12.2) |
| **Διαγραφή λογαριασμού** | **Μπλοκάρεται** με ενεργές μελλοντικές κρατήσεις· μετά export + ρητή επιβεβαίωση + οριστική διαγραφή σε 30 ημέρες, slug δεσμευμένο για πάντα (§29) |
| **Frontend** | **Blade για όλα** — δημόσια σελίδα και dashboard (SEO, μηδέν Node, κανένα build step). Το Inertia + React **δεν μπήκε** στο M7· η απόφαση και οι τρεις λόγοι της είναι στο §3.3 |
| **Design** | Ουδέτερο κέλυφος + **ένα accent χρώμα** από τον tenant· minimal, χωρίς σκιές & διακοσμητικά (§27) |
| **Framework** | **Laravel**, PHP 8.3+, κοινοτικές συμβάσεις — όχι ιδιωτικό framework (§31.1) |
| **Ποιότητα κώδικα** | strict types, Pint, Larastan, Form Requests, API Resources, Actions, backed enums, χρήματα σε λεπτά (§31.2) |
| **Attribution** | **Καμία αναφορά σε AI** σε commits, κώδικα ή τεκμηρίωση (§31.3) |
| **Επεκτασιμότητα** | Interfaces σε ό,τι θα αποκτήσει δεύτερη υλοποίηση· events αντί για if· ρύθμιση αντί για κώδικα (§31.4) |
| **Public API** | **Bearer token ανά account**, server-to-server, versioned (§15.2). Ο πελάτης φτιάχνει δική του φόρμα· η κράτηση γίνεται πάντα σε εμάς |
| **API token** | Ένα ανά account, test & live, **regenerate από τον ίδιο** στο *Settings → Developer*. Hashed, scoped στον tenant, ποτέ σε browser |

---

## 20. Ανοιχτά ερωτήματα

1. **Όνομα & domain της πλατφόρμας** — ανοιχτό, working name *TableReserved*. Δεν μπλοκάρει τίποτα: μπαίνει σε env var και στα email templates.
2. **Brevo:** επιβεβαίωση ημερήσιου ορίου & τυχόν branding στο δωρεάν πακέτο (§10.3).
3. **Μόνιμο δωρεάν πακέτο** 20–30 κρατήσεων/μήνα; Το έχουν resOS & Tableo και είναι η πόρτα εισόδου τους (§4.1, `COMPETITORS.md`). Ένα ακόμα `plan` row — μπορεί να αποφασιστεί και μετά το launch.

*Κανένα από τα τρία δεν εμποδίζει να ξεκινήσουν οι migrations.*

---

## 21. Υποδομή & Hosting

### 21.1 Βάση δεδομένων: MariaDB δίπλα στην εφαρμογή — **όχι Supabase**

**Ο λόγος είναι η latency, και είναι αποφασιστικός.**

Η PHP δεν έχει connection pooling. Κάθε HTTP request ανοίγει **νέα** σύνδεση στη βάση και την κλείνει στο τέλος. Με τοπική MariaDB αυτό κοστίζει κλάσματα του χιλιοστού. Με απομακρυσμένο Postgres (Supabase) κοστίζει TCP + TLS handshake, **κάθε φορά**.

Πάνω σε αυτό, το Eloquent είναι φλύαρο — μια σελίδα διαθεσιμότητας κάνει εύκολα 20–30 queries:

| Σενάριο | Ανά query | × 25 queries |
|---|---|---|
| MariaDB localhost | ~0.3ms | **~8ms** |
| Supabase (Frankfurt) από Verpex | ~15–25ms | **375–625ms** |

Μισό δευτερόλεπτο καθαρό δίκτυο, πριν καν τρέξει κώδικας. Σε booking flow όπου ο χρήστης κάνει 5–6 βήματα, είναι η διαφορά ανάμεσα σε «γρήγορο» και «χαλασμένο».

**Το δεύτερο πρόβλημα:** θα χρησιμοποιούσαμε το ~10% του Supabase. Το Auth το κάνει το Laravel, το Realtime δεν το θέλουμε, το Storage το κάνει το Media Library, τα Edge Functions δεν τα χρειαζόμαστε, και το **RLS — που είναι το δυνατό του χαρτί — το αντικαθιστούν τα global scopes** του Laravel. Μένει «απομακρυσμένος Postgres», και πληρώνουμε latency γι' αυτό.

> Το Supabase λάμπει όταν ο **client** μιλάει κατευθείαν στη βάση με RLS — Next.js + `supabase-js`, χωρίς backend. Εδώ έχουμε Laravel στη μέση· η αρχιτεκτονική δεν ταιριάζει. (Διαφορετική περίπτωση από το Risk4Sea, όπου η επιλογή βγάζει νόημα.)

**Απόφαση:** MariaDB/MySQL στον ίδιο host με την εφαρμογή. Αν θέλουμε Postgres κάποια στιγμή, το Laravel το υποστηρίζει — αλλά **τοπικό**, όχι απομακρυσμένο.

### 21.2 Verpex shared hosting — τι να ελεγχθεί ΠΡΙΝ ξεκινήσουμε

✅ **Το wildcard SSL δεν είναι πλέον θέμα.** Με path-based URLs (§3.2) χρειαζόμαστε **ένα** domain και **ένα** Let's Encrypt cert — ό,τι δίνει κάθε shared πακέτο. Ο μόνος dealbreaker εξαφανίστηκε.

Μένουν τρία, όλα με δουλεμένο fallback:

**1. Queue workers.** Θέλουμε μόνιμη διεργασία (`queue:work` με supervisor). Το shared hosting σπάνια επιτρέπει daemons.
→ *Λύση:* `database` queue driver + cron ανά λεπτό που τρέχει `queue:work --stop-when-empty --max-time=55`. Δουλεύει μια χαρά σε αυτή την κλίμακα· τα emails φεύγουν με καθυστέρηση έως 1 λεπτό.

**2. Cron ανά λεπτό** για το `schedule:run` — καθαρισμός ληγμένων holds. Αν το ελάχιστο είναι 5λεπτο, ανεβάζουμε το `hold_duration` σε 15' και καθαρίζουμε **και** lazily μέσα στον έλεγχο διαθεσιμότητας (ένα `WHERE expires_at > NOW()` κάνει τη δουλειά ανεξάρτητα από το cron).

**3. Redis.** Συνήθως απών σε shared.
→ *Λύση:* `database` driver για cache/session/queue. Μέχρι μερικές δεκάδες tenants δεν θα το καταλάβει κανείς.

Κανένα από τα τρία δεν μπλοκάρει· όλα είναι αλλαγή σε `.env` όταν μετακομίσουμε.

### 21.3 Διαδρομή μετάβασης

```
Verpex shared  →  δικός σου VPS (Plesk/AlmaLinux)
   dev + demo         production με πραγματικούς πελάτες
```

Στον VPS παίρνεις supervisor για queue workers, Redis, έλεγχο PHP-FPM pools, σωστά backups. Είναι ακριβώς το περιβάλλον που ήδη διαχειρίζεσαι.

**Τίποτα στον σχεδιασμό δεν μας δένει σε shared hosting** — τα swappable drivers κάνουν τη μετακόμιση αλλαγή `.env`, όχι refactor. Το μόνο που απαιτεί προσοχή είναι να μη γράψουμε κώδικα που *υποθέτει* Redis ή supervisor· χρησιμοποιούμε πάντα τα Laravel abstractions (`Cache::`, `Queue::`), ποτέ απευθείας client.

### 21.4 Deployment checklist (v1)
- `.env` εκτός repo, `APP_DEBUG=false`, ξεχωριστό `GATEWAY_ENCRYPTION_KEY`
- HTTPS παντού, HSTS
- Ημερήσια DB backup **εκτός του ίδιου server** + δοκιμασμένο restore
- `php artisan optimize` στο deploy, OPcache on
- Uptime monitoring στο booking endpoint, όχι μόνο στην αρχική
- Log rotation· alerting σε failed jobs & webhook failures

---

## 22. Demo Seeder ⭐

```bash
php artisan db:seed --class=DemoRestaurantSeeder
```

Φτιάχνει **έναν πλήρη, ρεαλιστικό tenant** με κάθε feature του συστήματος συμπληρωμένο. Δεν είναι βοήθημα ανάπτυξης — είναι υποδομή τριών χρήσεων:

| Χρήση | Γιατί μετράει |
|---|---|
| **Πώληση** | Ο υποψήφιος πελάτης βλέπει γεμάτο σύστημα σε 10 δευτερόλεπτα, όχι άδειες οθόνες |
| **Ανάπτυξη** | Καμία χειροκίνητη συμπλήρωση φορμών για να δοκιμάσεις μια οθόνη |
| **Έλεγχος** | Σταθερό fixture για tests· κάθε νέο feature έχει ήδη δεδομένα να δείξει |

### 22.1 Τι περιέχει

```
Tenant: "Crystal Seaside Demo"          slug: demo
  ├── Συνδρομή σε active πακέτο (όχι trial — να φαίνονται όλα)
  ├── Branding: λογότυπο, hero image, accent color, overlay
  ├── Λογαριασμοί: owner@demo.test / staff@demo.test  (κωδικός στο output)
  │
  ├── Booking Type 1: "Dining Room"        mode: slot, 90'
  │     ├── Area "Main Hall"        10 τραπέζια, 2–6 ατόμων
  │     ├── Area "Terrace"           6 τραπέζια, attributes: sea_view
  │     ├── Ωράριο 19:00–01:00, Δευ κλειστά
  │     ├── Price rule: Παρ/Σάβ +30%
  │     └── Κάτοψη με τοποθετημένα τραπέζια
  │
  ├── Booking Type 2: "Beach Sunbeds"      mode: arrival_window 10:00–13:00
  │     ├── Area "1st Row"    8 ΣΕΤ (2 ξαπλ.+ομπρέλα+τραπεζάκι) €25, whole_day
  │     ├── Area "2nd Row"   10 ΣΕΤ €15, fixed_duration 240'
  │     ├── Area "Back Row"  12 ΜΕΜΟΝΩΜΕΝΕΣ ξαπλώστρες €8
  │     ├── Ωράριο 09:00–19:00, κλειστά Νοέ–Απρ (closure)
  │     ├── deposit_label: "Deducted from your final bill"
  │     └── Κάτοψη με σετ & ομπρέλες
  │
  ├── Resource templates: "Beach Set", "Single Sunbed"
  ├── Κάποια resources με online_status=closed (να φαίνεται η λειτουργία)
  ├── Payment gateways σε test mode (Stripe + PayPal + cash)
  ├── Legal: terms + cancellation policy, δημοσιευμένα
  ├── Widget settings με custom accent
  └── ~60 ΚΡΑΤΗΣΕΙΣ σε ρεαλιστική κατανομή:
        • Παρελθόν: completed, no_show, cancelled, refunded
        • ΣΗΜΕΡΑ: confirmed, checked_in, μερικές με σημειώσεις/προπαραγγελία
        • Μέλλον: confirmed, 1–2 pending_payment
        • Όλα τα channels: widget, website, phone, walkin, qr
        • Ονόματα/emails ρεαλιστικά (ξένα — τουρίστες), τηλέφωνα ψεύτικα
```

### 22.2 Κανόνες υλοποίησης

- **Idempotent:** ξανατρέξιμο σβήνει και ξαναχτίζει τον demo tenant· δεν αγγίζει ποτέ άλλα δεδομένα
- **Σχετικές ημερομηνίες:** όλα υπολογίζονται από το `today()`, ποτέ σταθερές ημερομηνίες — ο seeder του Μαρτίου πρέπει να δείχνει σωστά και τον Αύγουστο
- **Ρεαλιστική κατανομή:** όχι 60 ίδιες κρατήσεις· διαφορετικές ώρες, μεγέθη παρέας, καταστάσεις, κανάλια. Το βράδυ Σαββάτου γεμάτο, η Τρίτη αραιή
- **Εικόνες:** 2–3 ελαφριά δείγματα στο repo (hero, logo, floor plan), όχι εξωτερικά links που σπάνε
- **Ποτέ σε production** χωρίς ρητό flag· το seeder command ελέγχει το `APP_ENV`
- Τυπώνει στο τέλος **URL + credentials**, ώστε να μπαίνεις κατευθείαν

### 22.3 Κανόνας συντήρησης 🔒

> **Κάθε feature που προστίθεται στο σύστημα, προστίθεται ΤΑΥΤΟΧΡΟΝΑ στον demo seeder — στο ίδιο PR.**

Χωρίς αυτόν τον κανόνα, ο seeder σαπίζει μέσα σε έναν μήνα: νέα πεδία μένουν null, νέες οθόνες δείχνουν άδειες, και το demo που υποτίθεται πουλάει αρχίζει να δείχνει ημιτελές προϊόν.

Πρακτικά, στο PR checklist μπαίνει μία γραμμή:

```
[ ] Ο DemoRestaurantSeeder καλύπτει το νέο feature
```

Αν ένα feature δεν μπορεί να αναπαρασταθεί με σταθερά demo δεδομένα, αυτό είναι ένδειξη ότι ή το feature είναι υπερβολικά περίπλοκο, ή λείπει κάτι από το μοντέλο.

### 22.4 Το ίδιο ισχύει για το `PROGRESS.md` 🔒

Το `PROGRESS.md` κρατά τι υλοποιήθηκε και τι μένει, ανά milestone. **Ενημερώνεται στο ίδιο commit με τη δουλειά που περιγράφει** — όχι στο τέλος της εβδομάδας, όχι «όταν θυμηθούμε».

Ένα feature θεωρείται τελειωμένο μόνο όταν έχει και τα τρία:

```
[ ] tests κατά §25
[ ] εγγραφή στον demo seeder (§22.3)
[ ] ενημερωμένη γραμμή στο PROGRESS.md
```

Ο λόγος είναι πρακτικός: όταν γυρίσεις στο project μετά από δύο εβδομάδες άλλης δουλειάς, το `PROGRESS.md` είναι το μόνο που σου λέει πού ήσουν — και αν είναι ξεπερασμένο, είναι χειρότερο από ανύπαρκτο, γιατί το εμπιστεύεσαι και ξεκινάς από λάθος σημείο.

---

## 23. Offline — τέσσερα επίπεδα, πάμε μέχρι το 1

Το i-host διαφημίζει offline mode. Μπορεί, επειδή είναι **εγκατεστημένη εφαρμογή** με τοπική βάση: ο υπολογιστής του μαγαζιού είναι ο master και συγχρονίζει όταν γυρίσει το δίκτυο.

**Εμείς είμαστε web app όπου ο server είναι ο master — και πρέπει να είναι**, γιατί το widget δέχεται κρατήσεις από τουρίστες 24/7. Δεν υπάρχουν δύο masters για το ίδιο φυσικό τραπέζι.

| Επίπεδο | Τι κάνει | Απόφαση |
|---|---|---|
| **0 — Cache σημερινής λίστας** | Άμεση εμφάνιση από τοπικό αντίγραφο με «updated 2 min ago», refresh στο παρασκήνιο. Λύνει το τρεμόπαιγμα του 4G | ✅ **Phase 1** (§13.3) |
| **1 — Read-only offline** | PWA + service worker. Πέφτει το δίκτυο, ο σερβιτόρος **βλέπει** ακόμα ποιος έρχεται στις 21:00, με σημειώσεις. Δεν γράφει | 🎯 **Στόχος** — Phase 3 |
| **2 — Offline εγγραφές με ουρά** | Κρατήσεις/ενέργειες σε ουρά, replay όταν γυρίσει το δίκτυο | ⏸️ Μόνο αν πουλήσει το προϊόν |
| **3 — Πλήρες local-first** | Εγκατεστημένη εφαρμογή + τοπική βάση, όπως i-host | ❌ Ποτέ |

**Γιατί το επίπεδο 2 είναι επικίνδυνο:** η μόνη δουλειά της μηχανής κρατήσεων είναι να αποτρέπει τη διπλοκράτηση, κι αυτό απαιτεί τον server. Κράτηση φτιαγμένη offline μπορεί να συγκρουστεί με μία που έκανε ταυτόχρονα τουρίστας από το widget — και τότε πρέπει να πεις σε πελάτη που ήδη κάθεται «συγγνώμη, πιάστηκε».

> 💡 **Εξαίρεση για αργότερα:** το **check-in** είναι ασφαλές σε ουρά offline, γιατί δεν διεκδικεί πόρο — σημειώνει μόνο ότι κάποιος ήρθε, και είναι idempotent. Οι *νέες κρατήσεις* δεν είναι. Αν ποτέ πάμε σε επίπεδο 2, ξεκινάμε από εκεί.

**Σχεδιαστική συνέπεια από τώρα:** το API για τη σημερινή λίστα επιστρέφει αυτοτελές JSON με όλα όσα χρειάζεται η οθόνη (χωρίς N+1 follow-up κλήσεις), ώστε το επίπεδο 1 να είναι προσθήκη service worker και όχι ξαναγράψιμο του data layer.

---

## 24. Edge cases & λειτουργικά ρίσκα

Περιπτώσεις που **θα** συμβούν, με συγκεκριμένη αντιμετώπιση. Αφορούν χρήματα πελατών και σιωπηλές αποτυχίες — δεν αφήνονται στην τύχη.

### 24.1 Το hold λήγει ενώ ο πελάτης πληρώνει

Μπαίνει στο Stripe, γράφει λάθος CVV, ξαναπροσπαθεί, κλείνει την καρτέλα. Στο λεπτό 16 πληρώνει — αλλά το σετ το πήρε άλλος.

**Μοντέλο: όπως το more.com — 15 λεπτά και μετά ελευθερώνεται.** Είναι το καθιερωμένο πρότυπο στα ticketing και ο χρήστης το καταλαβαίνει: αν άργησες, ξαναπροσπαθείς από την αρχή.

Τρεις γραμμές άμυνας, με αυτή τη σειρά:

1. **Ορατό countdown** στην οθόνη πληρωμής: «Your selection is held for 14:32». Καμία έκπληξη.
2. **Ακύρωση της συνεδρίας πληρωμής μαζί με το hold.** Όταν το cron λήγει ένα hold, καλεί και το gateway να ακυρώσει το ανοιχτό checkout session (το Stripe έχει endpoint γι' αυτό). Έτσι ο πελάτης **δεν μπορεί** να πληρώσει εκπρόθεσμα — βλέπει «this session has expired» και ξαναρχίζει. Κλείνει το 95% των περιπτώσεων στη ρίζα.
3. **Επαναέλεγχος στο webhook** — τελευταία άμυνα. Πριν γίνει `confirmed`, η κράτηση επανελέγχει διαθεσιμότητα μέσα σε transaction:
   - Ελεύθερο → confirm κανονικά
   - Πιασμένο → **αυτόματο πλήρες refund** + email συγγνώμης με link για νέα κράτηση, και **ειδοποίηση στον εστιάτορα** ώστε να μην τον πιάσει αδιάβαστο αν πάρει τηλέφωνο ο πελάτης

Το σενάριο 3 είναι σπάνιο αλλά **ποτέ δεν κρατάμε λεφτά για κράτηση που δεν υπάρχει**.

### 24.2 Το webhook δεν φτάνει ποτέ

Η πληρωμή πέρασε, το webhook χάθηκε — δικό μας downtime, λάθος υπογραφή, timeout, queue worker πεσμένος. Ο πελάτης πλήρωσε και **δεν έχει κράτηση**.

**Reconciliation job, κάθε ώρα:**

```
για κάθε ενεργό tenant με gateway σε live mode:
    πάρε από το gateway τις πληρωμές των τελευταίων 24h
    για κάθε πληρωμή:
        βρες κράτηση με το ίδιο gateway_payment_id
        ├── υπάρχει & confirmed        → ok
        ├── υπάρχει & pending_payment  → επανάληψη του webhook handler
        └── δεν υπάρχει καθόλου        → 🚨 ALERT + λεπτομέρειες στο admin
```

Είναι απλό, σίγουρο, και τρέχει ανεξάρτητα από τα webhooks — που είναι ακριβώς το νόημα. Idempotency keys στον handler ώστε η επανάληψη να μη δημιουργεί διπλή κράτηση.

Το ίδιο job βρίσκει και την αντίστροφη περίπτωση: κράτηση `pending_payment` παλαιότερη των 24h που δεν αντιστοιχεί σε καμία πληρωμή → καθαρίζεται.

### 24.3 Έλεγχος σύνδεσης & sandbox mode

**Sandbox/test mode** σε κάθε gateway (`mode: test | live`):
- Ο εστιάτορας βάζει test keys και κάνει ολόκληρη δοκιμαστική κράτηση με δοκιμαστική κάρτα
- **Εμφανές banner** σε όλη τη σελίδα κράτησης όσο είναι σε test mode: «TEST MODE — no real payments», ώστε να μη μείνει κατά λάθος σε test και χάνει αληθινές κρατήσεις
- Οι test κρατήσεις σημειώνονται και εξαιρούνται από στατιστικά

**Κουμπί «Test connection»** δίπλα στα keys: κάνει πραγματική κλήση στο gateway και επιστρέφει ✅ ή το ακριβές μήνυμα σφάλματος. Ο εστιάτορας δεν φεύγει από την οθόνη χωρίς να ξέρει αν δουλεύει.

> Ημερήσιος αυτόματος έλεγχος υγείας των keys: **εκτός scope προς το παρόν**. Σημειώνεται ως γνωστό ρίσκο — αν ανακληθούν τα keys, οι κρατήσεις αποτυγχάνουν σιωπηλά μέχρι να το παρατηρήσει κάποιος.

### 24.4 Τηλέφωνο πελάτη

Αφού η **ακύρωση γίνεται μόνο τηλεφωνικά** (§8.3), το τηλέφωνο είναι κρίσιμο δεδομένο, όχι πεδίο φόρμας.

- Input με **country code picker, default +30**
- Αποθήκευση σε **E.164** (`+306941234567`) — ένα format, πάντα κλήσιμο
- Validation μορφής ανά χώρα· απόρριψη προφανώς άκυρων
- Στο dashboard εμφανίζεται ως **tap-to-call** link

### 24.5 Closure πάνω σε υπάρχουσες κρατήσεις

Ο εστιάτορας βάζει closure (αργία, ιδιωτική εκδήλωση, κακοκαιρία, τέλος σεζόν) σε περίοδο που **έχει ήδη πληρωμένες κρατήσεις**.

**Κανόνας: το κλείσιμο κοιτάζει μόνο μπροστά.**

```
Closure  →  σταματά ΝΕΕΣ κρατήσεις, αμέσως
         →  ΔΕΝ αγγίζει τις υπάρχουσες
         →  οι ακυρώσεις & τα refunds γίνονται χειροκίνητα από τον εστιάτορα
```

Το κλείσιμο είναι **πάντα διαθέσιμο, πάντα ακαριαίο, ποτέ μπλοκαρισμένο** από υπάρχουσες κρατήσεις. Ο εστιάτορας που βλέπει αέρα 8 μποφόρ πρέπει να κλείνει τις ξαπλώστρες με ένα tap, όχι να απαντά σε διάλογο με τέσσερις επιλογές.

**Τι βλέπει:** ενημερωτικό μήνυμα, όχι ερώτηση.

```
✓  Closed — no new bookings for Saturday 18 Oct

⚠️  12 existing reservations remain active · €240 paid
    [ See them → ]        [ Cancel & refund all → ]
```

Το δεύτερο κουμπί είναι **συντόμευση στη μαζική ενέργεια**, όχι προϋπόθεση. Μπορεί να το αγνοήσει, να πάρει τηλέφωνο τους πελάτες και να αποφασίσει ένας-ένας.

**Τρία επίπεδα κλεισίματος**, όλα με την ίδια λογική:

| Επίπεδο | Πότε | Πού |
|---|---|---|
| **Stop bookings for today** | «Έχει αέρα», «γέμισε η κουζίνα» | Ένα tap από την οθόνη *Today* |
| **Κλείσιμο χρονικού διαστήματος** | «Σήμερα μόνο βράδυ» | Today → ώρες |
| **Κλείσιμο ημέρας ή περιόδου** | Αργία, ιδιωτική εκδήλωση, εκτός σεζόν | Booking type → closures |

Συν το **open/close ανά resource** (§5.4) για μεμονωμένο τραπέζι.

> **Τι κάνουν οι άλλοι:** το ίδιο. Το [resOS](https://resos.com/support/close-for-online-booking-for-the-rest-of-the-day/) έχει ακριβώς αυτά τα τρία επίπεδα («Close bookings today», «Close time interval today», special opening hours για μελλοντικές ημερομηνίες) και το support του περιγράφει **μόνο** τι βλέπει ο επισκέπτης — «guests are no longer offered times on the closed dates». Για τις υπάρχουσες κρατήσεις δεν λέει τίποτα, που από μόνο του είναι ένδειξη: μια καταστροφική ενέργεια θα τεκμηριωνόταν οπωσδήποτε.
>
> Το [Resy](https://helpdesk.resy.com/en_us/how-to-handle-closures-H1fdbwXU_) το λέει ακόμα πιο καθαρά στον ίδιο τον τίτλο του άρθρου: *«Emergency Closures: How to Update Settings **and inform Guests**»* — η ενημέρωση των πελατών είναι **ξεχωριστό βήμα** από την αλλαγή ρυθμίσεων. Αν το κλείσιμο ακύρωνε και ειδοποιούσε αυτόματα, δεν θα υπήρχε δεύτερο σκέλος.
>
> Κανένα σύστημα δεν ακυρώνει αυτόματα πληρωμένες κρατήσεις. Ο λόγος είναι προφανής μόλις το σκεφτείς: η ακύρωση είναι μη αναστρέψιμη, κοστίζει χρήματα, και συχνά ο εστιάτορας θα προτιμήσει να πάρει τηλέφωνο και να προτείνει άλλη μέρα αντί να επιστρέψει τα λεφτά.

Ίδια λογική παντού: **το σύστημα δεν ακυρώνει ποτέ μόνο του κράτηση που πληρώθηκε.**

### 24.6 Όριο κρατήσεων ανά email

Ένα email μπορεί να κλείσει **το πολύ 2 resources ανά 24 ώρες** στο ίδιο κατάστημα.

```
max_reservations_per_email_24h: 2    # ρύθμιση ανά booking type, default 2
```

Αποτρέπει κάποιον να κλείσει 10 σετ στην 1η σειρά και να τα κρατάει. Ο έλεγχος μετρά `confirmed` + `pending_payment` + ενεργά holds, και το μήνυμα είναι ανθρώπινο: *«You've reached the limit of 2 reservations per day. Please call us for a larger booking.»* — που είναι ούτως ή άλλως η σωστή οδός για μεγάλα γκρουπ (§1.2).

Ρυθμίσιμο, γιατί ένα beach bar μπορεί να θέλει 4 ενώ ένα εστιατόριο 1.

Συμπληρωματικά: rate limit ανά IP στα booking endpoints, και honeypot πεδίο στη φόρμα — αρκούν για αυτή την κλίμακα, χωρίς CAPTCHA που χαλάει τη ροή των 3 taps.

### 24.7 Cookies — χωρίς banner

Η σελίδα κράτησης χρησιμοποιεί **μόνο απολύτως απαραίτητα cookies**: session cookie για το hold και CSRF token. Αυτά **εξαιρούνται από την απαίτηση συναίνεσης** (ePrivacy, άρθρο 5(3) — «strictly necessary»), οπότε **δεν μπαίνει cookie banner**.

Προϋπόθεση να παραμείνει έτσι, και είναι δέσμευση:
- ❌ Κανένα Google Analytics, Meta Pixel, ή οποιοδήποτε tracking τρίτων
- ❌ Καμία ενσωματωμένη γραμματοσειρά από Google Fonts (self-hosted αντ' αυτού)
- ❌ Κανένας χάρτης τρίτου που φορτώνει πριν το κλικ — στατική εικόνα με link αντ' αυτού
- ✅ Το widget είναι ήδη cookieless (§9.3), άρα δεν χαλάει το banner του site του πελάτη

Στο Privacy Policy μπαίνει μια παράγραφος που το δηλώνει. Ένα banner λιγότερο είναι και ένα εμπόδιο λιγότερο στα 3 taps.

---

## 25. Στρατηγική ελέγχου

Pest, feature tests πάνω από unit tests. Δεν κυνηγάμε ποσοστό κάλυψης — καλύπτουμε **τα σημεία όπου το λάθος κοστίζει**.

### 25.1 Οι πέντε περιοχές, κατά σειρά σοβαρότητας

**1. Tenant isolation — το #1 ρίσκο κάθε SaaS**
Ένα κενό εδώ σημαίνει ότι ένα μαγαζί βλέπει τις κρατήσεις άλλου. Δεν είναι bug, είναι περιστατικό ασφαλείας.
```
για ΚΑΘΕ tenant-scoped model:
  ✓ ο tenant A δεν διαβάζει εγγραφή του B (index, show, search, export)
  ✓ ο tenant A δεν γράφει/σβήνει εγγραφή του B με χειροκίνητο id στο URL
  ✓ το global scope εφαρμόζεται και σε σχέσεις (π.χ. reservations μέσω resource)
  ✓ τα public endpoints δεν διαρρέουν δεδομένα άλλου tenant
```
Γράφεται **μία φορά ως test που επαναλαμβάνεται αυτόματα για κάθε model** — έτσι ένα καινούργιο model που ξέχασε το trait πέφτει αμέσως.

**2. Διπλοκράτηση (concurrency)**
```
✓ 50 ταυτόχρονα requests στο ίδιο slot → ακριβώς 1 επιτυχία
✓ hold + ταυτόχρονη κράτηση για το ίδιο resource → 1 επιτυχία
✓ επικαλυπτόμενα διαστήματα με buffer απορρίπτονται
✓ ληγμένο hold δεν μπλοκάρει
✓ webhook που φτάνει δύο φορές → 1 κράτηση (idempotency)
✓ webhooks εκτός σειράς (success πριν το created)
```

**3. Μηχανή διαθεσιμότητας** — εδώ κρύβονται τα ύπουλα
```
✓ slot / arrival_window / half_day / full_day
✓ occupancy whole_day vs fixed_duration, + override ανά area
✓ ωράριο μετά τα μεσάνυχτα (close 02:00)
✓ ΑΛΛΑΓΗ ΩΡΑΣ: ανύπαρκτη & διπλή τοπική ώρα (§3.5)
✓ closures σε κάθε επίπεδο (booking type / area / resource)
✓ min_lead_time & max_advance_days στα όριά τους
✓ online_status=closed δεν εμφανίζεται ποτέ
```

**4. Τιμολόγηση**
```
✓ base price χωρίς κανόνες
✓ priority: ποιος κανόνας κερδίζει όταν ταιριάζουν δύο
✓ percent modifier πάνω σε fixed
✓ per_person vs per_resource
✓ η τιμή από τον client ΑΓΝΟΕΙΤΑΙ πάντα — test που στέλνει €1 και περιμένει €25
```

**5. Χρήματα**
```
✓ πληρωμή μετά τη λήξη του hold → refund + ειδοποίηση (§24.1)
✓ reconciliation βρίσκει πληρωμή χωρίς κράτηση (§24.2)
✓ refund: full / partial / none
✓ κύκλος συνδρομής: trial → active → past_due → grace → expired
✓ λήξη συνδρομής κλειδώνει το dashboard αλλά ΟΧΙ τις υπάρχουσες κρατήσεις
✓ όρια πακέτου μπλοκάρουν δημιουργία, όχι ανάγνωση
```

### 25.2 Πρακτικά

- **Factories καθρεφτίζουν τον demo seeder** (§22) — ίδια σενάρια, ώστε ό,τι δοκιμάζεις να είναι ό,τι βλέπεις
- **Gateways mocked** στα tests· χωριστή χειροκίνητη λίστα ελέγχου με test keys πριν κάθε release
- **Χωρίς E2E browser tests στο v1** — ακριβά στη συντήρηση σε αυτή την κλίμακα. Feature tests στα HTTP endpoints καλύπτουν το 90%
- Κάθε bug που φτάνει στην παραγωγή αποκτά **test πριν διορθωθεί**
- Στο CI: `pest`, `pint`, `larastan` σε κάθε push

---

## 26. Σειρά υλοποίησης

Δέκα milestones με εξαρτήσεις. Το κριτήριο σε κάθε βήμα: **τι μπορείς να δείξεις**.

| # | Milestone | Τι υπάρχει στο τέλος |
|---|---|---|
| **M0** | Θεμέλια — Laravel, auth, tenants, path resolver, roles, σκελετός demo seeder | Μπαίνεις σε άδειο dashboard ως tenant |
| **M1** | Δομή — venue, booking types, areas, resources (σετ & μεμονωμένα), templates, bulk create, open/close | **Ο εστιάτορας στήνει το μαγαζί του** |
| **M2** | Μηχανή διαθεσιμότητας — ωράρια, 4 modes, occupancy, closures, holds + **όλα τα tests §25.2–3** | «Τι είναι ελεύθερο το Σάββατο;» απαντιέται σωστά |
| **M3** | Τιμολόγηση — base + price rules | Κάθε slot έχει σωστή τιμή |
| **M4** | Δημόσια σελίδα κράτησης **χωρίς πληρωμή** | **Πλήρης κράτηση από άκρη σε άκρη** — το πρώτο πραγματικό demo |
| **M5** | Πληρωμές — Stripe + PayPal + cash, holds, webhooks, reconciliation, edge cases §24.1–2 | **Μπαίνουν αληθινά λεφτά** |
| **M6** | Αποδεικτικό + ειδοποιήσεις (Brevo) | Ο πελάτης παίρνει email, το μαγαζί ειδοποιείται |
| **M7** | Dashboard — οθόνη **Today**, ημερολόγιο, χειροκίνητη κράτηση, cancel + refund | **Ο εστιάτορας δουλεύει πραγματικά μέσα από το σύστημα** |
| **M8** | Widget — embed.js, builder, attribution | Μπαίνει στο site του πελάτη |
| **M9** | Συνδρομές — register, 15ήμερο trial, πακέτα, Stripe billing, super admin | **Πουλιέται** |
| **M10** | Νομικά, onboarding wizard, trial emails, γυάλισμα | Έτοιμο για πρώτο πελάτη |

**Τρεις αποφάσεις σειράς που αξίζει να εξηγηθούν:**

**Οι συνδρομές (M9) μπαίνουν σχεδόν τελευταίες** — όχι από αμέλεια. Το προϊόν πρέπει να δουλεύει πριν μπει το ταμείο· το billing είναι το πιο ανεξάρτητο κομμάτι και προστίθεται καθαρά στο τέλος. Μέχρι τότε δουλεύουμε με χειροκίνητα δημιουργημένους tenants.

**Η πληρωμή (M5) μετά τη σελίδα κράτησης (M4)** — έτσι όλη η ροή δοκιμάζεται χωρίς τη θορυβώδη πολυπλοκότητα των gateways, και το M4 είναι ήδη δείξιμο demo.

**Τα tests μπαίνουν στο M2, όχι στο τέλος.** Η μηχανή διαθεσιμότητας είναι η καρδιά· αν γραφτεί χωρίς tests, κάθε επόμενο milestone χτίζεται πάνω σε άγνωστο έδαφος.

**Ο demo seeder (§22) μεγαλώνει σε κάθε milestone**, όχι στο τέλος — είναι ο κανόνας συντήρησης του §22.3.

---

## 27. Κατεύθυνση σχεδιασμού

«Minimal και μοντέρνο», χωρίς branding προς το παρόν. Η οπτική ταυτότητα θα οριστικοποιηθεί όταν υπάρχει λογότυπο· μέχρι τότε αυτοί οι κανόνες δίνουν συνεκτικό αποτέλεσμα.

### 27.1 Η κεντρική ιδέα: ουδέτερο κέλυφος, ένα χρώμα

Κάθε tenant φέρνει **το δικό του accent color** (§5.5). Άρα το σύστημα χτίζεται **ουδέτερο** — κλίμακα γκρι — και το accent είναι το **μόνο** χρώμα στη σελίδα. Αποτέλεσμα: δουλεύει με οποιοδήποτε brand, από χρυσό beach bar μέχρι σκούρα ταβέρνα, χωρίς να δείχνει ποτέ ασύμβατο.

```
Ουδέτερα:  λευκό → 9 αποχρώσεις γκρι → σχεδόν μαύρο
Accent:    ΕΝΑ χρώμα, από τον tenant — κουμπιά, επιλογές, focus
Σημασία:   πράσινο/κίτρινο/κόκκινο ΜΟΝΟ για κατάσταση — ποτέ διακοσμητικά
```

Κάθε χρωματική ένδειξη συνοδεύεται από **εικονίδιο ή κείμενο** — ποτέ μόνο χρώμα.

### 27.2 Συγκεκριμένοι κανόνες

**Τυπογραφία** — μία οικογένεια, self-hosted (όχι Google Fonts, §24.7). Μια σύγχρονη geometric sans με καλά ελληνικά για αργότερα. Κλίμακα: 12 / 14 / 16 / 20 / 28 / 40. Σώμα κειμένου 16px — ο τουρίστας διαβάζει στον ήλιο.

**Χώρος** — σύστημα 4px. Γενναιόδωρο padding· το minimal δεν είναι «μικρό», είναι «λίγα πράγματα με αέρα».

**Σχήματα** — radius 12px σε κάρτες, 8px σε κουμπιά, 999px σε badges. Σταθερά παντού.

**Βάθος** — σχεδόν καθόλου σκιές. Ιεραρχία με χώρο και ουδέτερες επιφάνειες, όχι με σκιές. Μία διακριτική σκιά μόνο σε στοιχεία που επιπλέουν (modal, dropdown).

**Κουμπιά** — ένα κύριο ανά οθόνη, γεμάτο με το accent. Όλα τα άλλα ουδέτερα ή απλό κείμενο. Ύψος ≥48px σε κινητό.

**Κίνηση** — 150–200ms, μόνο σε αλλαγές κατάστασης. Κανένα διακοσμητικό animation. Σεβασμός στο `prefers-reduced-motion`.

**Καταστάσεις φόρτωσης** — skeletons που μιμούνται το τελικό σχήμα, ποτέ spinner. Η σελίδα δεν «χοροπηδάει».

**Σκοτεινό θέμα** — από την αρχή στα design tokens, ακόμα κι αν δεν ενεργοποιηθεί στο v1. Προσθήκη μετά = ξαναβάψιμο όλων.

### 27.3 Τι αποφεύγουμε ρητά

Gradient φόντα, glassmorphism, περιττά εικονίδια δίπλα σε κάθε ετικέτα, καρουζέλ, modals που ανοίγουν modals, «fun» μικροκείμενα, εικόνες από stock. Το προϊόν κλείνει τραπέζια — δεν εντυπωσιάζει.

### 27.4 Ο έλεγχος

Μία ερώτηση σε κάθε οθόνη: **«μπορεί ένας 55χρονος ταβερνιάρης να το χρησιμοποιήσει χωρίς να ρωτήσει τίποτα, και ένας τουρίστας να κλείσει τραπέζι στον ήλιο με το ένα χέρι;»** Αν όχι, δεν είναι έτοιμη.

---

## 28. Πελατολόγιο (Guests) ⭐

Κάθε κατάστημα έχει **πλήρη λίστα όλων όσων έκλεισαν ποτέ** τραπέζι ή ξαπλώστρα, με το ιστορικό τους.

### 28.1 Ο κανόνας: ανά venue, ποτέ ανά tenant

```
guests
  UNIQUE (venue_id, email)      ← το κλειδί όλου του σχεδιασμού
```

Το `armenisnick@gmail.com` που κλείνει φέτος στο **Beach Bar A** και του χρόνου στο **Restaurant B** είναι **δύο ξεχωριστές εγγραφές** — ακόμα κι αν τα δύο μαγαζιά ανήκουν στον ίδιο tenant. Κάθε μαγαζί βλέπει μόνο τη δική του σχέση με αυτόν τον άνθρωπο.

Αν ξανακλείσει στο **Beach Bar A**, **δεν δημιουργείται δεύτερη εγγραφή** — βρίσκεται η υπάρχουσα και η νέα κράτηση προστίθεται στο ιστορικό της.

```
armenisnick@gmail.com
├── Beach Bar A  → guest #412   3 κρατήσεις  (2025, 2026 ×2)
└── Restaurant B → guest #891   1 κράτηση    (2027)
```

Το ίδιο ισχύει **διασταυρούμενα μεταξύ tenants**: δύο διαφορετικά μαγαζιά δεν μοιράζονται ποτέ πελατολόγιο, δεν βλέπει το ένα ότι ο πελάτης πήγε στο άλλο, και δεν υπάρχει «κεντρική βάση πελατών» της πλατφόρμας. Το πελατολόγιο ανήκει στο κατάστημα.

### 28.2 Σχήμα

```
guests
  venue_id
  email                    # κανονικοποιημένο: trim + lowercase
  name                     # το πιο πρόσφατο που έδωσε
  phone                    # E.164, το πιο πρόσφατο
  locale
  first_seen_at
  last_seen_at
  bookings_count           # σωρευτικά, derived
  completed_count
  cancelled_count
  no_show_count            ⚠️ το πιο χρήσιμο νούμερο
  total_spent              # άθροισμα κρατήσεων, όχι λογαριασμών
  marketing_consent        bool
  marketing_consent_at
  internal_notes           # ΙΔΙΩΤΙΚΕΣ σημειώσεις του μαγαζιού
  tags json                # "regular", "VIP", "allergy", ό,τι θέλει
  anonymized_at            # GDPR (§28.5)
  UNIQUE (venue_id, email)

reservations.guest_id → guests.id
```

**Ταύτιση κατά την κράτηση:** `firstOrCreate` σε `(venue_id, normalized_email)` μέσα στο ίδιο transaction με την κράτηση. Το όνομα και το τηλέφωνο ενημερώνονται με τα πιο πρόσφατα — ο κόσμος αλλάζει τηλέφωνα.

**Το email είναι το μοναδικό κλειδί ταύτισης** (§1.1 — απλότητα). Όχι fuzzy matching σε ονόματα, όχι τηλέφωνο ως δεύτερο κλειδί· δύο άνθρωποι μοιράζονται συχνά τηλέφωνο, και το fuzzy matching δημιουργεί λάθος συγχωνεύσεις που είναι χειρότερες από διπλοεγγραφές. Αν κάποιος χρησιμοποιήσει δύο emails, είναι δύο εγγραφές — και προστίθεται χειροκίνητο **«Merge guests»** αργότερα αν ζητηθεί.

**Οι μετρητές είναι denormalized αλλά επαληθεύσιμοι:** ενημερώνονται σε transaction μέσω observer, και υπάρχει `php artisan guests:recount` που τους ξαναϋπολογίζει από τις κρατήσεις. Χωρίς αυτό, ένα bug στον observer αφήνει μόνιμα λάθος νούμερα χωρίς τρόπο διόρθωσης.

⭐ **Ο observer ξαναϋπολογίζει, δεν αυξάνει** (υλοποίηση, 2026-10-15). Ένας μετρητής που μόνο προσθέτει αποκλίνει — μια κατάσταση που άλλαξε από console command, μια γραμμή που σβήστηκε, ένα event που δεν έφτασε — και κανείς δεν το προσέχει ώσπου ο εστιάτορας να διαφωνήσει με το νούμερο στη δική του οθόνη. Με επανυπολογισμό, οι κρατήσεις είναι η αλήθεια και οι μετρητές ένα cache τους: το χειρότερο που μπορεί να κάνει ένα χαμένο event είναι να αφήσει το cache στιγμιαία παλιό. Κοστίζει **ένα aggregate query** ανά εγγραφή, αρκετά φθηνό για να τρέχει πάντα.

**Το `total_spent` μετρά μόνο ό,τι όφειλε ο πελάτης:** confirmed, checked-in, completed και no-show. Μια ακυρωμένη ή ληγμένη κράτηση δεν φουσκώνει την αξία του πελάτη για το μαγαζί.

Το πελατολόγιο γεμίζει από **όλα** τα κανάλια: online, widget, τηλεφωνική και walk-in κράτηση που καταχωρεί το προσωπικό.

⚠️ **Με μία εξαίρεση που αξίζει να λέγεται:** στην τηλεφωνική κράτηση το email είναι **προαιρετικό** (§13.1), και κράτηση χωρίς email **δεν μπαίνει στο πελατολόγιο** — δεν υπάρχει τίποτα να ταυτιστεί την επόμενη φορά. Η εναλλακτική θα ήταν να υποχρεώνουμε email, που στην πράξη σημαίνει προσωπικό που γράφει `noemail@x.gr`: κάθε επιβεβαίωση γυρίζει πίσω και το πελατολόγιο γεμίζει σκουπίδια. Η φόρμα το λέει καθαρά στον χρήστη: με email παίρνει αποδεικτικό και υπενθύμιση και μπαίνει στη λίστα, χωρίς email τίποτα από τα δύο.

### 28.3 Πού φαίνεται

**Οθόνη «Guests»** — λίστα με αναζήτηση σε όνομα/email/τηλέφωνο, ταξινόμηση κατά τελευταία επίσκεψη ή αριθμό κρατήσεων, φίλτρα (επαναλαμβανόμενοι, no-shows), export CSV.

**Καρτέλα πελάτη** — στοιχεία επικοινωνίας, μετρητές, **πλήρες ιστορικό κρατήσεων** (ημερομηνία, τι έκλεισε, ποσό, κατάσταση, οι σημειώσεις του), ιδιωτικές σημειώσεις του μαγαζιού, tags.

**Μέσα στη ροή — εκεί είναι η πραγματική αξία:**
- Στην οθόνη *Today*, δίπλα στο όνομα: `3rd visit` ή `⚠️ 2 no-shows`
- Στην τηλεφωνική κράτηση: γράφει email ή τηλέφωνο → **αυτόματη συμπλήρωση** από το πελατολόγιο
- Στην καρτέλα κράτησης: link στο προφίλ του πελάτη

Το `no_show_count` είναι το νούμερο που θα κοιτάει περισσότερο. Όποιος έχει στήσει δύο φορές το μαγαζί, αξίζει μια σκέψη πριν του κρατηθεί η 1η σειρά σε γεμάτο Σάββατο.

### 28.4 Το πελατολόγιο δεν είναι άδεια για marketing

Η λίστα υπάρχει για να **εξυπηρετηθεί** ο πελάτης, όχι για να του σταλούν προσφορές.

- Το `marketing_consent` έρχεται **μόνο** από το ξεχωριστό, μη προεπιλεγμένο checkbox του checkout (§8)
- Τα transactional emails (επιβεβαίωση, υπενθύμιση, ακύρωση) στέλνονται **πάντα** — είναι η εκτέλεση της κράτησης, δεν θέλουν συναίνεση
- Οποιοδήποτε προωθητικό email, όταν και αν υλοποιηθεί, φιλτράρει σε `marketing_consent = true` και φέρει unsubscribe

Ο εστιάτορας είναι **data controller** για το πελατολόγιό του, εμείς processor. Να αναφέρεται ρητά στο DPA.

### 28.5 Διαγραφή πελάτη (GDPR)

Ένας τουρίστας μπορεί να ζητήσει διαγραφή. Η ενέργεια είναι **ανωνυμοποίηση, όχι σβήσιμο**:

```
guest.name   → "Deleted guest"
guest.email  → null        (λύνεται το unique constraint)
guest.phone  → null
guest.internal_notes → null
guest.anonymized_at  → now()

Οι ΚΡΑΤΗΣΕΙΣ παραμένουν, με τα προσωπικά πεδία καθαρισμένα:
ημερομηνίες, ποσά, τι κλείστηκε → μένουν
```

Το μαγαζί κρατά τα λειτουργικά του στοιχεία (πόσες κρατήσεις είχε τον Αύγουστο, τι τζίρο), ο άνθρωπος εξαφανίζεται από τη βάση. Διαθέσιμο από την καρτέλα πελάτη και μέσω αιτήματος στον εστιάτορα.

---

## 29. Διαγραφή λογαριασμού καταστήματος

### 29.1 Ο κανόνας: όχι με ενεργές κρατήσεις

```
Υπάρχουν μελλοντικές confirmed κρατήσεις;
├── ΝΑΙ → η διαγραφή ΜΠΛΟΚΑΡΕΤΑΙ
│         "You have 14 upcoming reservations.
│          Cancel them (and refund your guests) first,
│          or wait until they have passed."
│         [ See the 14 reservations → ]
└── ΟΧΙ → προχωρά στην επιβεβαίωση
```

Δεν αφήνουμε έναν εστιάτορα να εξαφανιστεί αφήνοντας 14 τουρίστες με πληρωμένες κρατήσεις που δεν υπάρχουν πουθενά. Είναι το ίδιο σκεπτικό με το κλείσιμο (§24.5): **το σύστημα δεν ακυρώνει ποτέ μόνο του πληρωμένη κράτηση** — αλλά ούτε επιτρέπει να μείνουν ορφανές.

### 29.2 Η επιβεβαίωση

Ρητή, χωρίς ωραιοποίηση, με πληκτρολόγηση του ονόματος για επιβεβαίωση:

```
⚠️  This permanently deletes everything:

    · 2 booking types, 38 tables and sunbeds, floor plans
    · 1.247 past reservations
    · 892 guests in your guest list
    · Your branding, settings and payment configuration

    This cannot be undone.

    [ Download everything first ]   ← CSV + JSON, πάντα διαθέσιμο

    Type "Crystal Seaside" to confirm:  [__________]
```

### 29.3 Τι γίνεται μετά

- Η συνδρομή ακυρώνεται στο gateway (καμία επόμενη χρέωση)
- Το `slug` **δεσμεύεται μόνιμα** — δεν το παίρνει άλλος, ώστε παλιά links να μη δείχνουν ποτέ σε ξένο μαγαζί
- Τα δεδομένα σβήνονται οριστικά μέσα σε **30 ημέρες** (όχι ακαριαία — προστατεύει από μετανοιωμένο κλικ και επιτρέπει επαναφορά με αίτημα στο support)
- Τα παραστατικά των **δικών μας** συνδρομών διατηρούνται όσο απαιτεί η φορολογική νομοθεσία — δεν είναι δικά του δεδομένα, είναι δικά μας λογιστικά
- Email επιβεβαίωσης με το αρχείο εξαγωγής και την ημερομηνία οριστικής διαγραφής

> Διαφορά από τη **λήξη συνδρομής** (§4.4): εκεί ο λογαριασμός κλειδώνει αλλά τα δεδομένα μένουν **12 μήνες** και επανέρχονται με πληρωμή — ώστε ο εποχικός πελάτης να ξαναβρεί τα πάντα την επόμενη σεζόν. Η διαγραφή είναι ρητή, σκόπιμη και τελική.


---

## 30. Υποδομή παραγωγής & εκκρεμότητες

### 30.1 Αποθήκευση αρχείων

Λογότυπα, hero images, φωτογραφίες resources, κατόψεις. Σε shared hosting ο δίσκος είναι περιορισμένος και τα backups πολλαπλασιάζουν τον όγκο.

**Απόφαση: object storage από την αρχή, όχι τοπικός δίσκος.**

Προτείνεται **Cloudflare R2** για έναν συγκεκριμένο λόγο: οι εικόνες σερβίρονται σε **κάθε** επίσκεψη τουρίστα στη σελίδα κράτησης, άρα το egress είναι το κυρίαρχο κόστος — και το R2 **δεν χρεώνει egress** καθόλου. Το Hetzner Object Storage είναι επίσης καλή επιλογή αν προτιμάς να μείνουν όλα σε έναν πάροχο.

```
config/filesystems.php → disk 'media' (s3-compatible)
Διαδρομές: tenants/{tenant_uuid}/venue/{logo|hero}/...
           tenants/{tenant_uuid}/resources/{id}/...
```

- Όλη η πρόσβαση μέσω `Storage::disk('media')` — ποτέ απευθείας paths
- Τοπικός δίσκος **μόνο** για προσωρινά αρχεία επεξεργασίας εικόνας
- Δημόσιο read για εικόνες (είναι ούτως ή άλλως δημόσιες στη σελίδα κράτησης), υπογεγραμμένα URLs για PDF αποδεικτικών
- Τα backups καλύπτουν **και** το bucket, όχι μόνο τη βάση
- Διαγραφή tenant (§29) σβήνει και τον φάκελό του

### 30.2 Error tracking — Sentry

Για solo developer που διαχειρίζεται πληρωμές, το να μαθαίνεις για ένα σφάλμα από τηλέφωνο πελάτη είναι κακή θέση.

```bash
composer require sentry/sentry-laravel
php artisan sentry:publish --dsn=https://...
```

**Δύο προεπιλογές που ΠΡΕΠΕΙ να αλλάξουν** — αλλιώς το Sentry δουλεύει εναντίον μας:

**1. `send_default_pii` → `false`.** Αν μείνει `true`, το Sentry στέλνει αυτόματα IP, cookies, headers και δεδομένα αιτήματος. Στη δική μας περίπτωση αυτό σημαίνει **emails και τηλέφωνα τουριστών σε τρίτο πάροχο** — ευθεία αντίφαση με το §24.7 και το DPA.

**2. Scrubbing πριν την αποστολή.** Ακόμα και χωρίς PII, ένα σφάλμα στο payment flow μπορεί να μεταφέρει stack trace με **κλειδιά gateway του πελάτη**. Υποχρεωτικό `before_send` που κόβει:

```php
'before_send' => function (Event $event) {
    // ποτέ στο Sentry:
    // secret/api keys, webhook secrets, card data,
    // customer_email, customer_phone, customer_name
    return $scrubbed;
},
```

**Ρυθμίσεις που κάνουν τη διαφορά:**

| Ρύθμιση | Τιμή | Γιατί |
|---|---|---|
| `traces_sample_rate` | `0.0` αρχικά | Το performance monitoring καταναλώνει το δωρεάν όριο πολύ γρήγορα· ανεβάζεις αν χρειαστεί |
| `environment` | `production` / `staging` | Αλλιώς δεν ξεχωρίζεις πού έσπασε |
| `release` | git SHA του deploy | Σου λέει **ποιο deploy** έφερε το σφάλμα — το πιο χρήσιμο πεδίο |
| Tag `tenant_id` | πάντα | «Ποιο μαγαζί επηρεάζεται;» απαντιέται αμέσως. Είναι id, όχι PII |

**Τι ΔΕΝ στέλνουμε** (θόρυβος που καταναλώνει το όριο): 404, validation errors, ληγμένα holds, αποτυχημένα logins. Μπαίνουν στο `ignore_exceptions`.

**Ειδοποιήσεις:** όχι σε κάθε σφάλμα — μόνο σε **νέο τύπο** σφάλματος και σε **απότομη αύξηση**. Αλλιώς τις αγνοείς μέσα σε μια βδομάδα και χάνεις το νόημα.

**Προτεραιότητα παρακολούθησης:** payment flow, webhooks, queue jobs. Εκεί σπάνε χρήματα.

### 30.3 Staging

Υποχρεωτικό πριν αγγίξουμε πληρωμές σε παραγωγή: ξεχωριστό subdomain, **δική του βάση**, gateway σε test mode, δικό του Sentry `environment`.

Χωρίς αυτό, η πρώτη αλλαγή στο payment flow δοκιμάζεται πάνω σε ζωντανούς πελάτες — και τα webhooks είναι ακριβώς το κομμάτι που δεν δοκιμάζεται τοπικά.

Ο demo seeder (§22) γεμίζει το staging με ένα `php artisan db:seed`.

### 30.4 Κάτοψη: συντεταγμένες & προτεραιότητα

**Κανονικοποιημένες 0–1**, ως ποσοστό των διαστάσεων του σχεδίου — ποτέ απόλυτα pixels:

```
pos_x = 0.342    // 34.2% του πλάτους
pos_y = 0.781
width = 0.055
```

Με απόλυτα pixels, μόλις ο εστιάτορας ξανανεβάσει κάτοψη διαφορετικής διάστασης, **όλα τα τραπέζια πετάγονται εκτός θέσης**. Με ποσοστά, η κάτοψη αλλάζει και οι θέσεις παραμένουν σωστές — και το ίδιο rendering δουλεύει σε κάθε μέγεθος οθόνης χωρίς υπολογισμούς.

> **Προτεραιότητα:** η κάτοψη παραμένει **Phase 2**, και αυτό είναι συνειδητή εμπορική εκτίμηση: τα περισσότερα μαγαζιά προτιμούν να κανονίζουν μόνα τους ποιος κάθεται πού, και το **περιγραφικό μοντέλο** («ξαπλώστρες 1η σειρά», «τραπέζια σάλας») καλύπτει το μεγαλύτερο μέρος της αγοράς. Το χτίζουμε ως feature διαφοροποίησης, όχι ως προϋπόθεση πώλησης — και βελτιώνεται αφού δούμε πόσοι πραγματικά το χρησιμοποιούν.

### 30.5 Εμπορικά που λύνονται στην πράξη

Καταγράφονται για να μην ξεχαστούν· **δεν μπλοκάρουν τίποτα** και θα απαντηθούν από τους πρώτους πελάτες:

- **Υποστήριξη:** κανάλι, ώρες, τι υπόσχεται το ΤοS
- **Πιλοτικός πελάτης:** ένα beach bar δωρεάν μια σεζόν με αντάλλαγμα feedback — αξίζει περισσότερο από κάθε ανάλυση
- **Ποιος στήνει το μαγαζί:** self-service wizard ή το στήνεις εσύ ως κλείσιμο πώλησης
- **Επιστροφή δικής μας συνδρομής:** αναλογική ή όχι — πρέπει να μπει στους Όρους, άρα αποφασίζεται πριν τον δικηγόρο
- **Δωρεάν πακέτο:** αν τελικά μπει (§4.1)
- **Τιμή €70:** επιβεβαίωση από πραγματικούς εστιάτορες

---

## 31. Πρότυπα κώδικα

### 31.1 Πλαίσιο

**Laravel**, τελευταία σταθερή έκδοση, PHP 8.3+. Όχι «Laravel με δικές μας συμβάσεις» — Laravel όπως το γράφει η κοινότητα, ώστε οποιοσδήποτε άλλος developer να μπορεί να μπει στο project χωρίς να μάθει πρώτα ένα ιδιωτικό framework.

### 31.2 Υποχρεωτικά

```
declare(strict_types=1);   σε κάθε αρχείο
PSR-12 · Laravel Pint      στο CI, όχι στη διακριτική ευχέρεια
Larastan level 6+          στο CI
Pest                       feature tests πάνω από unit (§25)
```

**Δομή:**
- **Form Requests** για validation — ποτέ μέσα σε controller
- **API Resources** για κάθε απόκριση — ποτέ `return $model`, που διαρρέει κάθε μελλοντική στήλη
- **Actions** (μονοσήμαντες invokable κλάσεις) για κάθε εγγραφή: `CreateReservation`, `CancelReservation`, `RefundReservation`. Τις καλούν και ο controller, και το API, και το Artisan command, και η ουρά — μία υλοποίηση, όχι τέσσερις
- **Controllers λεπτοί**: validate → action → resource
- **Models**: σχέσεις, scopes, casts. Καμία επιχειρηματική λογική

**Τύποι:**
- **Backed enums** για κάθε κατάσταση (`ReservationStatus`, `BookingMode`, `Occupancy`) — ποτέ γυμνά strings διάσπαρτα στον κώδικα
- **Χρήματα σε ακέραια λεπτά**, ποτέ float. `2500`, όχι `25.00`
- **Ημερομηνίες σε UTC**, μετατροπή μόνο στο boundary (§3.5)

**Βάση:**
- Foreign keys με ρητούς κανόνες `onDelete`
- Κάθε index μπαίνει στο migration που τον χρειάζεται
- **Migrations δεν επεξεργάζονται ποτέ μετά το deploy** — πάντα νέο
- Καμία καταστροφική αλλαγή σε ζωντανά δεδομένα: πρώτα προσθήκη, μετά backfill, μετά αφαίρεση σε επόμενο release

**Ουρά — ο κανόνας που κρατά την απομόνωση 🔒**

**Κανένα Eloquent model δεν μπαίνει στην ουρά.** Ένα queued job ξεκινά σε καθαρή διεργασία χωρίς tenant, και κάθε tenant-scoped model αρνείται να ερωτηθεί σε αυτή την κατάσταση — σωστά, γιατί η εναλλακτική είναι η επιβεβαίωση ενός venue να απαριθμεί τα τραπέζια ενός άλλου. Το `SerializesModels` θα προσπαθούσε να τα φορτώσει ξανά κατά το unserialize, πριν προλάβει να τρέξει οτιδήποτε δικό μας.

Οπότε:
- Jobs, listeners και events κουβαλούν **ids**, όχι models
- Δένουν τον tenant μόνα τους μέσα στο `handle()` (`App\Tenancy\Concerns\RunsAsTenant`)
- Τα **Mailables δεν είναι queued**: η ουρά είναι το listener, και το μήνυμα φτιάχνεται και αποστέλλεται μέσα στο tenant context
- Κάθε queued job **idempotent** — θα ξανατρέξει
- `ShouldQueueAfterCommit` όπου το job αφορά γραμμή που μόλις γράφτηκε, αλλιώς σε γρήγορο worker δεν τη βρίσκει

**Listeners δηλώνονται, δεν ανακαλύπτονται.** Το auto-discovery σαρώνει το `app/Listeners` σε κάθε boot όσο δεν έχει ζεσταθεί το cache — στο shared hosting ένα filesystem scan ανά request. Πιο σημαντικό: μια λίστα διαβάζεται. «Τι γίνεται μετά από μια επιβεβαίωση κράτησης» πρέπει να απαντιέται χωρίς `ls`. Ο πίνακας ζει στον `AppServiceProvider`.

**Παγίδες που κοστίζουν:**
- `config()` παντού, **`env()` μόνο μέσα σε αρχεία config** — με `config:cache` η `env()` επιστρέφει `null` και η παραγωγή σπάει σιωπηλά
- **`Config::string()` πετάει σε `null`**, ακόμα και με default: αν το κλειδί υπάρχει με τιμή `null`, το default δεν χρησιμοποιείται. Για ρυθμίσεις που μπορεί να μην έχουν τεθεί, `(string) config(...)`
- Eager loading παντού· το N+1 στη λίστα κρατήσεων φαίνεται αμέσως
- **Νέα στήλη ημερομηνίας θέλει cast**: χωρίς `immutable_datetime` η τιμή γυρίζει ως σκέτο string και η πρώτη `->format()` σκάει

### 31.3 Καμία αναφορά σε AI 🔒

**Ο κώδικας, τα commits και η τεκμηρίωση δεν αναφέρουν πουθενά AI βοήθεια.**

- Μηδέν `Co-Authored-By` ή «Generated with» σε commit messages
- Μηδέν σχόλια τύπου «AI-generated» ή αναφορές σε εργαλεία μέσα στον κώδικα
- Commit messages στη φωνή του project: *«Add arrival window occupancy override per area»*
- README, τεκμηρίωση και κείμενα προς τον χρήστη χωρίς καμία τέτοια αναφορά
- Author metadata: ο κάτοχος του project

### 31.4 Επεκτασιμότητα

Η αρχή: **ό,τι είναι πιθανό να αποκτήσει δεύτερη υλοποίηση, γράφεται πίσω από interface από την πρώτη.**

| Σημείο | Interface | Γιατί |
|---|---|---|
| Πληρωμές | `PaymentGateway` | Stripe σήμερα· Viva, Cardlink, Connect αύριο (§7.4) |
| Ειδοποιήσεις | `NotificationChannel` | Email σήμερα· SMS, Viber, push αύριο |
| Αποθήκευση | Laravel `Storage` | Τοπικό ↔ R2 με αλλαγή config (§30.1) |
| Ουρές & cache | Laravel abstractions | `database` ↔ `redis` με ένα env var (§21.2) |

**Events αντί για if:** ο πυρήνας εκπέμπει `ReservationConfirmed`, `ReservationCancelled`, `SubscriptionExpired`. Κάθε νέα λειτουργία —SMS, outgoing webhook, στατιστικά, loyalty— μπαίνει ως **listener**, χωρίς να αγγίξει τη λογική της κράτησης. Αυτό είναι που επιτρέπει στο §15.4 να προστεθεί αργότερα σχεδόν χωρίς κόστος.

**Feature flags ανά πακέτο** (Laravel Pennant) αντί για σκορπισμένους ελέγχους συνδρομής: `Feature::active('floor_plan_editor')`.

**Ρύθμιση αντί για κώδικα:** ό,τι μπορεί να διαφέρει ανά κατάστημα γίνεται πεδίο στη βάση, όχι `if`. Αν βρεθούμε να γράφουμε `if ($tenant->slug === '…')`, κάτι λείπει από το μοντέλο.

**API versioned από την πρώτη μέρα** (§15.2) — το δημόσιο συμβόλαιο είναι το πιο δύσκολο πράγμα να αλλάξει αργότερα.

**Ο demo seeder συμβαδίζει** (§22.3): feature που δεν μπορεί να αναπαρασταθεί με δεδομένα είναι feature κακώς σχεδιασμένο.

---

## 32. Events & εισιτήρια — υποδομή τώρα, υλοποίηση αργότερα

**Η περίπτωση:** beach bars της Κέρκυρας με βραδιές DJ (Pazuzu και παρόμοια) πουλάνε είσοδο με αντίτιμο — π.χ. 100 εισιτήρια × €20 για συγκεκριμένη μέρα και ώρα, με QR που σκανάρεται στην πόρτα.

**Απόφαση: δεν χτίζεται τώρα. Μπαίνουν όμως τέσσερα αγκίστρια στο Phase 1**, γιατί κοστίζουν σχεδόν μηδέν σήμερα και πολύ αργότερα.

### 32.1 Γιατί ταιριάζει

Ένα εισιτήριο είναι **κράτηση σε μετρήσιμη χωρητικότητα αντί σε συγκεκριμένο αντικείμενο** — δηλαδή *απλούστερη* περίπτωση από ό,τι έχουμε: χωρίς overlap check, μόνο `πουλημένα < capacity`. Τα υπόλοιπα υπάρχουν ήδη: πληρωμές με τα κλειδιά του μαγαζιού, αποδεικτικό με QR και μοναδικό κωδικό, email, ακύρωση, πελατολόγιο, dashboard.

Ίδιος πελάτης, ίδια σεζόν, **δεκαπλάσιος τζίρος ανά συναλλαγή** (100 × €20 ανά βραδιά έναντι €25 μιας ξαπλώστρας). Το resOS το πουλά ως add-on («Experiences», €19,99/μήνα), που επιβεβαιώνει ότι η αγορά το πληρώνει ξεχωριστά.

### 32.2 Γιατί όχι τώρα

Τέσσερα πράγματα είναι πραγματικά καινούργια, και το τελευταίο είναι σοβαρό:

1. **Πολλαπλά εισιτήρια ανά παραγγελία** — μία πληρωμή, πολλές είσοδοι, και η παρέα δεν φτάνει μαζί (μερική σάρωση: 3 μπήκαν, 1 αργότερα)
2. **Oversell protection υπό πίεση** — 400 άνθρωποι ταυτόχρονα μόλις ανακοινωθεί ο DJ· 105 άτομα για 100 θέσεις δεν διορθώνεται
3. **Ρόλος πορτιέρη** — σκανάρει μόνο, δεν βλέπει τζίρο
4. **Η πόρτα.** Μία τη νύχτα, 300 στην ουρά, 4G κορεσμένο από τους ίδιους τους πελάτες. Scanner που θέλει δίκτυο σε κάθε σάρωση **αποτυγχάνει ακριβώς τη στιγμή που μετράει** — και η αποτυχία είναι δημόσια. Σωστός offline scanner είναι **το offline επίπεδο 2** που ρητά μένει εκτός (§23)

Επιπλέον, εδώ ο ανταγωνισμός δεν είναι το i-host αλλά το **more.com και το Eventbrite**, που φέρνουν και κοινό. Κερδίζουμε στις μικρές, τακτικές βραδιές όπου η προμήθειά τους δεν βγάζει νόημα — όχι στο μεγάλο όνομα.

### 32.3 Τα τέσσερα αγκίστρια — υποχρεωτικά στο Phase 1 🔒

| # | Αγκίστρι | Κόστος τώρα | Κόστος αν το ξεχάσουμε |
|---|---|---|---|
| 1 | **Το QR περιέχει υπογεγραμμένο token**, ποτέ ωμό id ☑ | Μηδέν | Αλλαγή μορφής QR σε τυπωμένα/σταλμένα αποδεικτικά |
| 2 | **`reservations.resource_id` nullable** — ένα event δεν έχει συγκεκριμένο resource | Μηδέν | Migration σε ζωντανό πίνακα κρατήσεων |
| 3 | **Check-in idempotent** (ήδη στο §23) | Μηδέν | Ξαναγράψιμο της μόνης ενέργειας που ο scanner χρειάζεται |
| 4 | **`party_size` = ποσότητα**, όχι δεμένο στη χωρητικότητα του resource | Μηδέν | Αλλαγή σημασιολογίας σε υπάρχοντα δεδομένα |

Με αυτά, το event γίνεται αργότερα **άλλο ένα booking type** με `booking_mode: event` και `capacity`, χωρίς να αγγίξει τη μηχανή διαθεσιμότητας.

### 32.4 Η φθηνή πρώτη έκδοση, όταν έρθει η ώρα

Ο scanner **δεν χρειάζεται δική μας εφαρμογή**. Το QR περιέχει υπογεγραμμένο URL· ο πορτιέρης το σκανάρει με **την κάμερα του κινητού του**, ανοίγει σελίδα που δείχνει ✓ ΕΓΚΥΡΟ / ✗ ΧΡΗΣΙΜΟΠΟΙΗΜΕΝΟ με όνομα και ποσότητα, και κουμπί «μπήκε». Μία μέρα δουλειά αντί για project PWA — online-only, αλλά για 100 άτομα με αξιοπρεπές wifi στην πόρτα αρκεί.

**Πότε χτίζεται:** μετά την πρώτη σεζόν, και **με συγκεκριμένο πελάτη που έχει αληθινό event**. Ο scanner δοκιμάζεται σε πραγματική πόρτα πριν πουληθεί σε πέντε μαγαζιά.

Η πρώτη μισή γραμμή υπάρχει ήδη από το M6: μέλος του προσωπικού που ανοίγει τη σελίδα αποδεικτικού βλέπει κουμπί **Check in**, πάνω σε check-in που είναι ήδη idempotent (αγκίστρι 3). Δεκαπέντε γραμμές, καμία νέα οθόνη, και το QR παύει να είναι διακοσμητικό. Ό,τι μένει για τον πορτιέρη είναι ο ρόλος και η offline συμπεριφορά — τα δύο δύσκολα.

---

## 33. Δημόσιο προφίλ & ευρετηρίαση

Η σελίδα κράτησης **ευρετηριάζεται** από τις μηχανές αναζήτησης: ο πελάτης αποκτά δεύτερη σελίδα που μπορεί να βγει στις αναζητήσεις για το όνομά του, χωρίς να πληρώσει τίποτα παραπάνω. Έτσι δουλεύουν και οι σελίδες του OpenTable και του resOS.

Για να μην καταλήξουμε όμως με 200 σχεδόν ίδιες σελίδες που το Google τις βλέπει ως doorway pages και τις τιμωρεί **όλες μαζί**, η ευρετηρίαση έχει προϋποθέσεις.

### 33.1 Πεδία προφίλ επιχείρησης

Μπαίνουν στις **ρυθμίσεις της επιχείρησης** και τα συμπληρώνει ο εστιάτορας. Είναι τα ίδια πεδία που κάνουν και τη σελίδα χρήσιμη για τον τουρίστα — δεν τα ζητάμε «για το SEO».

```
venues
  # Ταυτότητα
  name, description            ⭐ ελεύθερο κείμενο, 2–3 παράγραφοι
  logo_path, hero_path, accent_color, hero_overlay    (§5.5)
  gallery                      ⭐ φωτογραφίες χώρου (Media Library)

  # Τοποθεσία
  address, city, postal_code, region, country         ⭐
  lat, lng                     ⭐ για χάρτη & schema.org

  # Επικοινωνία
  phone, public_email, website_url, social json       ⭐

  # Κατηγορία
  venue_category               ⭐ restaurant | beach_bar | taverna | cafe | club
  cuisine                      ⭐ προαιρετικό — "Mediterranean", "Seafood"
  price_range                  ⭐ € | €€ | €€€

  # Ευρετηρίαση
  is_indexable                 ⭐ default true, διακόπτης στις ρυθμίσεις
  meta_title, meta_description ⭐ nullable — παράγονται αυτόματα αν κενά

opening_hours
  scope: venue | booking_type  ⭐ ΝΕΟ
```

> ⚠️ **Οι ώρες λειτουργίας δεν είναι οι ώρες κρατήσεων.** Ένα εστιατόριο ανοιχτό 19:00–01:00 μπορεί να δέχεται κρατήσεις μέχρι τις 23:00. Το `scope: venue` κρατά τις **πραγματικές** ώρες για τη σελίδα και το schema.org· το `scope: booking_type` κρατά τις ώρες που δέχεται κρατήσεις (§6.1). Στο onboarding οι πρώτες **προσυμπληρώνονται** από τις δεύτερες, ώστε να μην τα γράψει δύο φορές.

**Κανένα από αυτά δεν είναι υποχρεωτικό** για να λειτουργήσει το σύστημα (§1.1). Ο εστιάτορας μπορεί να δέχεται κρατήσεις με μόνο ένα όνομα. Αλλά:

- Ο **onboarding wizard** ζητά τα βασικά (όνομα, διεύθυνση, περιγραφή, μία φωτογραφία) σε ένα βήμα — όχι φόρμα δεκαπέντε πεδίων
- Στις ρυθμίσεις υπάρχει **δείκτης πληρότητας προφίλ**: «*Η σελίδα σου είναι 60% συμπληρωμένη. Πρόσθεσε φωτογραφίες για να εμφανίζεσαι καλύτερα στο Google.*» Αυτό είναι που τα γεμίζει στην πράξη — όχι τα υποχρεωτικά πεδία, που απλώς κάνουν τον κόσμο να γράφει «.» για να προσπεράσει
- Αν το προφίλ είναι πολύ φτωχό, η σελίδα μπαίνει **αυτόματα σε `noindex`** μέχρι να συμπληρωθεί: καλύτερα αόρατη παρά τιμωρημένη, και μαζί της όλες οι υπόλοιπες

### 33.2 Τεχνική ευρετηρίαση

- **`schema.org/Restaurant`** (ή `LocalBusiness` ανάλογα με το `venue_category`) σε JSON-LD: όνομα, διεύθυνση, γεωγραφικό στίγμα, τηλέφωνο, ωράριο, εύρος τιμών, φωτογραφίες
- **`ReserveAction`** μέσα στο ίδιο JSON-LD — ανοίγει τον δρόμο για το **Reserve with Google** του Phase 2 χωρίς δεύτερη δουλειά
- **`noindex` σε κάθε παραλλαγή με query params** (`?date=`, `?people=`, `?channel=`) — αλλιώς κάθε αναζήτηση γεννά διπλότυπη σελίδα
- **Canonical** στη σκέτη `/reservation/{slug}`
- **`sitemap.xml`** με τις σελίδες των ενεργών, ευρετηριάσιμων tenants· αφαίρεση σε λήξη συνδρομής ή διαγραφή
- **Open Graph & Twitter cards** από το hero και την περιγραφή — έτσι φαίνεται σωστά όταν το μοιράζεται ο ίδιος στα social
- **Χάρτης χωρίς cookies**: στατική εικόνα με link που ανοίγει τον χάρτη σε νέα καρτέλα, ποτέ ενσωματωμένο iframe τρίτου (§24.7)
- **`noindex` σε όλο το dashboard** και σε κάθε σελίδα πίσω από login

Το structured data θέλει τα δεδομένα να υπάρχουν στο markup **από την αρχή** — γι' αυτό μπαίνει στο M4 και όχι αργότερα.
