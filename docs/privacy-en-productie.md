# Privacy (DPIA-nota) en productie-checklist — local_wisa

Deze nota beschrijft welke persoonsgegevens `local_wisa` verwerkt, wat de
privacy provider wél en níét afdekt, en welke stappen nodig zijn om de plugin
veilig in productie te nemen. Bedoeld als input voor de DPIA/GBA-registratie van
de school en als overdrachtsdocument voor bevriende scholen die de plugin
overnemen.

## 1. Welke persoonsgegevens worden verwerkt?

De plugin haalt gegevens uit WISA/Schoolware en schrijft ze naar Moodle:

| Gegeven | Bron (WISA-query) | Bestemming in Moodle |
|---|---|---|
| Voornaam, achternaam | MCVOD_STUD / MCVOD_LKR | `user.firstname` / `user.lastname` |
| E-mailadres | MCVOD_STUD / MCVOD_LKR | `user.email` |
| Gebruikersnaam (ll_id of ps_code) | MCVOD_STUD / MCVOD_LKR | `user.username` |
| Stabiele sleutel (idnumber) | IDNUMBER of USERNAME | `user.idnumber` |
| Inschrijving (klas ↔ persoon) | MCVOD_INS / MCVOD_UIT | manuele inschrijving in de cursus |
| Logregels (naam/idnumber + actie) | — | `local_wisa_log` |

Doel: het automatisch aanmaken en up-to-date houden van cursisten- en
leraaraccounts en hun inschrijvingen. Rechtsgrond: de uitvoering van de
onderwijsopdracht / wettelijke leerlingenadministratie.

## 2. Bewaartermijn

- **Accounts en inschrijvingen**: blijven in Moodle volgens het reguliere
  bewaarbeleid van de school (de plugin verwijdert ze niet — zie §4).
- **Logregels** (`local_wisa_log`): standaard 30 dagen, instelbaar; de dagelijkse
  opschoontaak verwijdert oudere regels.

## 3. Wat doet de privacy provider?

`\local_wisa\privacy\provider` is geïmplementeerd en:

- declareert de `local_wisa_log`-tabel en de externe WISA-API als databron;
- exporteert de logregels van een gebruiker bij een data-exportverzoek;
- verwijdert de logregels van een gebruiker bij een verwijderverzoek (gematcht op
  `objectid = username OF idnumber`).

## 4. Beperkingen — expliciet te documenteren in de DPIA

1. **Aangemaakte accounts blijven bestaan.** De Moodle-accounts die de sync
   aanmaakt zijn kern-`user`-records (vallen onder Moodle's eigen privacy
   provider, niet onder deze plugin) en worden **niet** verwijderd wanneer een
   persoon uit WISA verdwijnt. Ontmantel ze via de normale Moodle-procedure voor
   gebruikersverwijdering.
2. **Bulk-verwijdering op systeemniveau is bewust een no-op** voor de
   operationele logtabel. Verwijderverzoeken per gebruiker worden wél uitgevoerd.
3. **Log-matching is heuristisch.** `objectid` is een vrij tekstveld; de match op
   username/idnumber kan samengestelde inschrijvings-logsleutels missen.
4. **Credentials in de query-string.** De WISA-API verwacht `_username_`/`_password_`
   als URL-parameters (over HTTPS). Ze worden in de logs van déze plugin
   gemaskeerd en redirects worden niet gevolgd, maar ze kunnen nog steeds
   voorkomen in de toegangslogs van de WISA/Schoolware-webserver en in een
   tussenliggende proxy. Bespreek met de leverancier of authenticatie via POST of
   een Authorization-header mogelijk is.

## 5. Productie-checklist (go-live)

De geplande sync staat **gepauzeerd tot je de eerste volledige load goedkeurt** —
er wordt niets automatisch geschreven.

1. Configureer de plugin; test met **WISA-verbinding testen**.
2. Ga naar **Beheer → Plug-ins → Lokale plug-ins → WISA-preview en goedkeuring**.
3. Bekijk de **veld-mapping** en klik **Preview tonen** om te tellen wat de eerste
   load zou aanmaken (schrijft niets).
4. Klik **Goedkeuren en de eerste volledige load starten**. Dit schakelt de
   testmodus uit, draait de eerste load en opent de poort zodat de geplande
   delta-sync het daarna overneemt.
5. Voor een zeer grote dataset: draai de eerste load via CLI
   (`php local/wisa/cli/test_sync.php --approve`; vooraf tellen met `--preview`),
   zodat de web-`max_execution_time` niet in de weg zit.
6. Controleer de inschrijvings-veiligheidsrem (`unenrol_safety_max` /
   `unenrol_safety_pct`). Een `SAFETY STOP` in het log betekent dat een
   uitschrijfgolf is geblokkeerd — controleer dan de MCVOD_UIT-data.
7. Schakel de **nachtelijke reconciliatie** pas in nadat de delta-sync zich
   bewezen heeft. Met **Goedkeuring resetten** kun je de sync weer pauzeren.

> **Let op (bekende valkuil):** bij elke plugin-upgrade kan Moodle de
> scheduled task opnieuw inschakelen. Wil je de automatische sync (tijdelijk)
> uit, controleer dan na een upgrade **Beheer → Server → Taken → Geplande taken**
> en zet *WISA-synchronisatietaak* opnieuw op uitgeschakeld.
