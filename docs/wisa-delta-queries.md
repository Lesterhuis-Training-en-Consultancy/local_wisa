# WISA delta-queries voor local_wisa

Vijf queries voor het WISA-querybeheer (*Extra → systeemgegevens → querybeheer*) die
alleen **gewijzigde records sinds een tijdstip** teruggeven, in plaats van telkens de
volledige dataset. Geschreven tegen het echte CVO-datamodel (geverifieerd op de lokale
kopie van de databank, 2026-06-11).

## Conventies

- **Querynamen zijn max. 10 tekens** (`Q_CODE` is `CHAR(10)`; langere namen geven HTTP 500
  op de REST-API). Alle namen hieronder passen.
- **`:sinds`** is de delta-parameter. De REST-API mapt URL-parameters op named parameters,
  dus de aanroep wordt:
  `...QUERY/MCVOD_STUD?sinds=2026-06-11%2014:35:00&format=json&_username_=...&_password_=...`
  Formaat: `YYYY-MM-DD HH:MM:SS` (URL-encoded). Firebird cast die string naar timestamp.
- **Full load = zelfde query** met `sinds=1900-01-01 00:00:00`. Aparte full-queries zijn
  dus niet nodig.
- Voor het testen in **wisarestapi** (lokale .NET-API): vervang `:sinds` door `@sinds`
  (FbParameter-syntax); in het WISA-querybeheer is het `:sinds`.
- De veldnamen (aliassen) zijn identiek aan Barts `MCVO_*`-queries zodat de plugin
  (`KLAS_ID`, `USERNAME`, `FIRSTNAME`, `LASTNAME`, `EMAIL`, `FULLNAME`, `BEGINDATUM`,
  `EINDDATUM`) zonder veldmapping-wijzigingen blijft werken. Extra kolommen negeert de
  plugin gewoon.
- "Actief venster" = `imv_tot >= CURRENT_DATE` (lopende + toekomstige cursussen).
  Wil je afgelopen cursussen nog X dagen meenemen (bv. voor nazorg/evaluatie):
  `imv.imv_tot >= DATEADD(-30 DAY TO CURRENT_DATE)`.

Volumes op de echte data (lokale kopie): ±2.127 actieve cursusinstanties, ±8.220 actieve
inschrijvingen, ±248 actieve lesgevers. Dat is je first-load; daarna is een delta-call
vrijwel leeg (rustige week: 1-3 records).

---

## 1. MCVOD_C — cursussen (delta)

Eén rij per **ingerichte modulevariant** (= het Moodle-cursusniveau; `KLAS_ID` = `imv_id`,
zoals in Barts `MCVO_C`).

```sql
SELECT
  imv.imv_id           AS KLAS_ID,
  vwk.vwk_code         AS SHORTNAME,
  imv.imv_omschrijving AS FULLNAME,
  imv.imv_van          AS BEGINDATUM,
  imv.imv_tot          AS EINDDATUM,
  imv.imv_lesvan       AS LESVAN,
  imv.imv_lestot       AS LESTOT,
  imv.imv_afgelastop   AS AFGELASTOP,
  imv.imv_veranderdop  AS VERANDERDOP,
  'Opleidingsaanbod' || COALESCE(' / ' || (
     SELECT FIRST 1 TRIM(vog.vog_omschrijving)
     FROM vwomodulevarinopleidingsvar mio
     JOIN vwoopleidingsvariant voo ON voo.voo_id = mio.mio_opleidingsvariant_fk
     JOIN vwoopleidinghist voh ON voh.voh_vwoopleiding_fk = voo.voo_vwoopleiding_fk
          AND (voh.voh_geldigtot IS NULL OR voh.voh_geldigtot >= CURRENT_DATE)
     JOIN vwoopleidingsgebied vog ON vog.vog_id = voh.voh_vwoopleidingsgebied_fk
     WHERE mio.mio_modulevariant_fk = imv.imv_vwomodulevariant_fk
     ORDER BY vog.vog_omschrijving
  ), '') AS CATEGORY
FROM vwoingmodulevariant imv
LEFT JOIN vwoklas vwk ON vwk.vwk_id = imv.imv_vwoklas_fk
WHERE imv.imv_tot >= CURRENT_DATE
  AND (imv.imv_veranderdop >= :sinds OR vwk.vwk_veranderdop >= :sinds)
ORDER BY imv.imv_id
```

- `AFGELASTOP` zit erbij zodat een afgelaste cursus wél in de delta verschijnt
  (de plugin kan die later verbergen i.p.v. nooit te weten dat hij geannuleerd is).
- **`CATEGORY`** geeft het pad `Opleidingsaanbod / <opleidingsgebied>` terug; de plugin
  (categoriemodus "uit WISA-feed") maakt die geneste categorieën aan. Het opleidingsgebied
  komt uit de keten `modulevariant → opleidingsvariant → opleiding → vwoopleidinghist →
  vwoopleidingsgebied` (de indeling die ook Barts `MCVO_C` gebruikt). Gemeten op de
  databankkopie: **100% dekking** (909/909 actieve modules), waarvan 95% precies één
  opleidingsgebied heeft.
- De `CATEGORY` is een **scalaire subquery** (`FIRST 1 … ORDER BY vog_omschrijving`):
  bewust, want module↔opleidingsgebied is N:M (±5% van de modules zit in meerdere). Zonder
  `FIRST 1` zou de query meerdere rijen per `KLAS_ID` geven (= dubbele cursussen). De
  keuzeregel is "alfabetisch eerste opleidingsgebied"; wil je een andere voorrang
  (bv. op lestijden), pas dan de `ORDER BY` in de subquery aan.
- Wil je `Opleidingsaanbod` als top weglaten en de opleidingsgebieden meteen op het hoogste
  niveau: vervang de hele expressie door enkel de subquery (zonder `'Opleidingsaanbod' || …`).

## 2. MCVOD_STUD — cursisten (delta)

```sql
SELECT DISTINCT
  ll.ll_id            AS USERNAME,
  ll.ll_voornaam      AS FIRSTNAME,
  ll.ll_naam          AS LASTNAME,
  ll.ll_email         AS EMAIL,
  ll.ll_geboortedatum AS GEBOORTEDATUM,
  ll.ll_veranderdop   AS VERANDERDOP
FROM leerling ll
JOIN inuit iu                ON iu.iu_leerling_fk = ll.ll_id
JOIN vwoloopbaan vl          ON vl.vl_inuit_fk = iu.iu_id
JOIN vwoingmodulevariant imv ON imv.imv_id = vl.vl_ingvwomodulevariant_fk
WHERE imv.imv_tot >= CURRENT_DATE
  AND vl.vl_stopzettingsdatum IS NULL
  AND (ll.ll_veranderdop >= :sinds OR vl.vl_veranderdop >= :sinds)
```

- **Let op de tweede delta-conditie** `vl.vl_veranderdop >= :sinds`: een bestaande cursist
  die zich nieuw inschrijft is zelf niet gewijzigd — zonder die conditie zou Moodle het
  account niet (tijdig) aanmaken en faalt de inschrijving in stap 4.
- De joins beperken de feed tot cursisten met een actieve inschrijving in een actieve
  cursus (i.p.v. alle 71.694 leerlingen in de databank).
- ⚠️ **Te verifiëren**: deze query gebruikt `ll_id` als `USERNAME` (de waarden op de
  testserver — 7564, 114377, … — zijn nummers). Open Barts `MCVO_STUD` in het querybeheer
  en check of hij `ll_id` dan wel `iu_stamboeknummer` gebruikt; neem hetzelfde veld zodat
  bestaande Moodle-accounts blijven matchen. Voor leraren is het `ps_code`
  (login-formaat, bevestigd door de testdata).

## 3. MCVOD_LKR — leraren (delta)

```sql
SELECT DISTINCT
  ps.ps_code          AS USERNAME,
  ps.ps_voornaam      AS FIRSTNAME,
  ps.ps_naam          AS LASTNAME,
  ps.ps_email         AS EMAIL,
  ps.ps_geboortedatum AS GEBOORTEDATUM,
  ps.ps_veranderdop   AS VERANDERDOP
FROM personeel ps
JOIN vwoingmodulevariant imv ON imv.imv_lesgever_fk = ps.ps_id
WHERE imv.imv_tot >= CURRENT_DATE
  AND (ps.ps_veranderdop >= :sinds OR imv.imv_veranderdop >= :sinds)
```

- Zelfde principe: `imv_veranderdop` vangt het geval "bestaande leraar krijgt een
  nieuwe lesopdracht".
- Alleen personeel dat effectief lesgeeft in een actieve cursus komt mee.

## 4. MCVOD_INS — inschrijvingen, globaal (delta)

**Vervangt de per-cursus-aanroepen** (`MCVO_STUD?KLAS_ID=…` + `MCVO_LKR?KLAS_ID=…`).
Eén call levert alle koppels cursus↔persoon met een rol, i.p.v. 2 calls per cursus
(nu 68 calls per syncrun). Belangrijk: de huidige `MCVO_STUD` op de testserver
**negeert `KLAS_ID` volledig** (elke "klaslijst" = alle 136 cursisten), dus dit is ook
een correctheidsfixt, geen pure optimalisatie.

```sql
SELECT
  imv.imv_id                    AS KLAS_ID,
  CAST(ll.ll_id AS VARCHAR(20)) AS USERNAME,
  'student'                     AS ROL,
  vl.vl_van                     AS VAN,
  vl.vl_tot                     AS TOT,
  vl.vl_veranderdop             AS VERANDERDOP
FROM vwoloopbaan vl
JOIN inuit iu                ON iu.iu_id = vl.vl_inuit_fk
JOIN leerling ll             ON ll.ll_id = iu.iu_leerling_fk
JOIN vwoingmodulevariant imv ON imv.imv_id = vl.vl_ingvwomodulevariant_fk
WHERE imv.imv_tot >= CURRENT_DATE
  AND vl.vl_stopzettingsdatum IS NULL
  AND (vl.vl_veranderdop >= :sinds OR imv.imv_veranderdop >= :sinds)

UNION ALL

SELECT
  imv.imv_id,
  ps.ps_code,
  'teacher',
  imv.imv_van,
  imv.imv_tot,
  imv.imv_veranderdop
FROM vwoingmodulevariant imv
JOIN personeel ps ON ps.ps_id = imv.imv_lesgever_fk
WHERE imv.imv_tot >= CURRENT_DATE
  AND imv.imv_veranderdop >= :sinds
```

- `USERNAME` matcht de waarden uit MCVOD_STUD (cursisten) en MCVOD_LKR (leraren);
  de `ROL`-kolom zegt welke Moodle-rol toegekend moet worden.

## 5. MCVOD_UIT — uit- en stopzettingen (delta)

Het ontbrekende stuk voor *unenrol-on-disappearance*: in WISA is een uitschrijving een
**update** (`vl_stopzettingsdatum` of `iu_datumuitschrijving` wordt gezet), geen delete —
dus de delta vangt ze netjes.

```sql
SELECT
  imv.imv_id                    AS KLAS_ID,
  CAST(ll.ll_id AS VARCHAR(20)) AS USERNAME,
  vl.vl_stopzettingsdatum       AS STOPGEZET_OP,
  iu.iu_datumuitschrijving      AS UITGESCHREVEN_OP,
  vl.vl_veranderdop             AS VERANDERDOP
FROM vwoloopbaan vl
JOIN inuit iu                ON iu.iu_id = vl.vl_inuit_fk
JOIN leerling ll             ON ll.ll_id = iu.iu_leerling_fk
JOIN vwoingmodulevariant imv ON imv.imv_id = vl.vl_ingvwomodulevariant_fk
WHERE (vl.vl_stopzettingsdatum IS NOT NULL OR iu.iu_datumuitschrijving IS NOT NULL)
  AND imv.imv_tot >= CURRENT_DATE
  AND (vl.vl_veranderdop >= :sinds OR iu.iu_veranderdop >= :sinds)
```

- `STOPGEZET_OP` = stopzetting van één module-inschrijving;
  `UITGESCHREVEN_OP` = uitschrijving uit het centrum (alle cursussen).
- **`AND imv.imv_tot >= CURRENT_DATE` is essentieel.** Zonder dit venster geeft de query
  *alle uitschrijvingen ooit* terug (±457.000 rijen, gemeten op productie). Met het venster
  blijft het beperkt tot uitschrijvingen uit nog lopende of toekomstige cursussen — de enige
  die relevant zijn om in Moodle te verwerken. De andere MCVOD-queries hebben dit venster al;
  alleen `MCVOD_UIT` miste het.

---

## Testen

Per query, na aanmaken in querybeheer (vervang creds):

```
https://testcvoantwerpen.schoolware.be/webwisad/bin/server.fcgi/QUERY/MCVOD_C?sinds=1900-01-01%2000:00:00&format=json&_username_=USER&_password_=PASS
https://testcvoantwerpen.schoolware.be/webwisad/bin/server.fcgi/QUERY/MCVOD_C?sinds=2026-06-01%2000:00:00&format=json&_username_=USER&_password_=PASS
```

Eerste call = full load (alles in het actieve venster), tweede = delta. Verwacht gedrag:
de delta-call geeft een (bijna) lege array `[]` als er niets wijzigde. HTTP-codes:
401 = creds, 404 = querynaam bestaat niet (typfout?), 500 = querynaam >10 tekens of
SQL-fout in de definitie.

## Wat de plugin nog nodig heeft (latere stap)

1. **`sinds` meesturen**: in `api_client::fetch()` de parameter toevoegen vanuit
   `last_run_time` (bestaat al in de config):
   `date('Y-m-d H:i:s', get_config('local_wisa','last_run_time') ?: 0)`.
   Belangrijk: gebruik het tijdstip van de **vorige geslaagde run**, niet "nu − 5 min"
   (anders mis je wijzigingen als een run uitvalt). Kleine overlap is onschadelijk:
   de sync is idempotent.
2. **MCVOD_INS consumeren** in `enrollment_sync` (één call i.p.v. 2 per cursus; rol uit
   de `ROL`-kolom).
3. **MCVOD_UIT consumeren** → unenrol/suspend in Moodle (nieuw, lost de bekende
   limitatie "no unenrol-on-disappearance" op).
4. **Nachtelijke reconciliatie**: 1× per dag een run met `sinds=1900-01-01` als vangnet
   voor harde deletes (records die echt uit WISA verwijderd zijn laten géén spoor na in
   een delta).

## Aannames die je in het querybeheer even moet checken

| # | Aanname | Hoe checken |
|---|---|---|
| 1 | `USERNAME` cursist = `ll_id` | Open Barts `MCVO_STUD`: gebruikt hij `ll_id` of `iu_stamboeknummer`? Zelfde veld nemen. |
| 2 | `SHORTNAME` = `vwk_code` | Vergelijk met Barts `MCVO_C` (testwaarde was `12345_16`). |
| 3 | `KLAS_ID` = `imv_id` | Vrijwel zeker (veld `IMV_DAVINCIKLAAR` in zijn output verraadt de imv-bron), maar bevestig. |
