# Backend zadaci — zahtjevi profesorke (Raspored.docx)

Ovo je lista svih stavki iz `Raspored.docx`, filtrirana i klasifikovana. Uključuje samo stvari
koje se tiču **backenda** (PHP API/admin logika i Java algoritam za generisanje rasporeda);
stavke koje su čisto imenovanje/tekst u UI-ju ili zahtijevaju odluku profesorke/tima su
označene kao takve, ne kao bagovi.

Legenda: `[x]` = urađeno, `[ ]` = nije urađeno (backlog), ~~precrtano~~ = završeno.

---

## Peta runda — ponovna provjera svih stavki iz Raspored.docx

Svih 32 stavki iz `Raspored.docx` provjereno u kodu i uživo (HTTP + browser testovi). Stavke koje
su ranije bile označene kao "frontend, van obima" su takođe provjerene - većina je u međuvremenu
urađena (mala slova u naslovima, "Rok za unos kolokvijuma", "Rasporedi", ć/č u PDF-u).
Urađeno u ovoj rundi (ono što je još falilo):

- [x] **Admin mijenja generisani raspored** — klik na čas u tabeli rasporeda otvara prozor za
      izmjenu dana, vremena i sale. Pomjera se cijeli blok (npr. predavanja 10-13h ili vježbe+lab).
      Server (`admin_panel.php?action=move_block`) odbija sukob sale, profesora (u istom periodu
      zimski/ljetnji) i semestra, dnevni fond >6h, van 8-17h, salu drugog fakulteta, kapacitet i
      rač. salu za lab; termin van raspoloživosti profesora je upozorenje koje admin potvrđuje.
      Zaključan raspored se ne može mijenjati. Paralelne grupe se pomjeraju/mijenjaju salu posebno.
- [x] **Raspoloživost - dodatni zahtjevi po terminu** — ispod kalendara za svaki termin: predmet
      (vezivanje predmeta za taj dan/termin), "Potrebna računarska sala" i izbor sale. Čuvaju se,
      prikazuju profesoru i adminu, a generator ih poštuje: rač. sala je obavezna, izabrana sala
      ima prednost (ako je slobodna). Ranije je čuvanje iz UI-ja ova polja brisalo.
- [x] **Događaji koje profesori unose u kalendar** se sada vide adminu ("Informacije i događaji" ->
      "Događaji profesora").
- [x] Kalendar profesora: uz naziv praznika oznaka "(neradni dan)" / "(radni dan)" i različita boja.
- [x] Lista profesora: kolona "Uloga" (Admin / Predavač / Saradnik).
- [x] Stranica naloga: objašnjenje razlike profesor/nalog i značenja "Povezan profesor".
- [x] Sitno: typo "nalozima profeora", polje semestra u izmjeni predmeta dozvoljavalo 1-12 (baza 1-6).

Ostaje otvoreno (odluka proizvoda, ne bag): spajanje "Profesori" i "Nalozi" u jednu stranicu
"Upravljanje korisnicima".

---

## Četvrta runda — kompletno testiranje, izmjene raspoloživosti profesora, generisanje

Testirano na lokalnoj bazi iz `db/seed.sql`: automatska provjera svih generisanih rasporeda
(`php test/check_schedule.php [schedule_id...]` - sukobi sala/profesora/semestra, dnevni limit
6h, fond časova, raspoloživost dan+VRIJEME, onlajn/rač. sala/kapacitet, tuđa zauzetost sala,
neaktivni predmeti/sale/profesori), izmjene raspoloživosti kroz pravi UI endpoint profesora pa
generisanje kroz admin endpoint (kao dugme), HTTP/sigurnosni testovi i browser testovi
(Playwright: generisanje, zaključavanje, kolokvijumi, PDF, kalendar raspoloživosti, "Moj raspored").
Ukupno ~330 provjera, sve prolaze osim jednog kozmetičkog upozorenja (vidi dolje).

**Glavni nalaz - uzrok primjedbe "poslije izmjene raspoloživosti ništa se ne mijenja":**
prije ove runde je u SVAKOM od 6 generisanih rasporeda 73-88 termina bilo van profesorovog
slobodnog vremena. Popravke u Java algoritmu ([EventValidationService.java](public/java/EventValidationService.java)):

- [x] ~~Algoritam čita samo DAN iz raspoloživosti, ne i VRIJEME~~ — termin mora cijelim trajanjem
      upasti u slobodni termin (susjedni termini se spajaju, npr. 8-9:30 + 9:30-11 = 8-11).
- [x] ~~Vježbe/lab se raspoređuju po danima PREDAVAČA~~ — sada po raspoloživosti asistenta.
      Posljedica stare greške: profesor sa jednim slobodnim danom nije dobijao NIJEDAN predmet
      (vježbe moraju biti drugi dan, a birale su se samo među njegovim danima).
- [x] ~~Dan predavanja se bira samo jednom~~ — ako za prvi mogući dan vježbe nemaju gdje,
      probaju se i ostali dani predavanja.
- [x] ~~Predmet koji nije 4-6h se tiho izbacuje~~ ("Projekat" 0+0+2 nikad nije bio u rasporedu) —
      sada važi: predavanja u jednom bloku ≤6h, vježbe+lab u jednom bloku ≤6h.
- [x] ~~Zimski i ljetnji semestar dijele istu sedmičnu mrežu~~ — sala/profesor zauzet u zimskom
      semestru je slobodan u ljetnjem u isto vrijeme. Bez ovoga je profesor sa predmetima u oba
      semestra trošio slobodne termine duplo (npr. prof. sa 4 predmeta Engleskog po 2h = 8h
      je imao 7h raspoloživosti). "Moj raspored" zato ima prekidač Zimski/Ljetnji semestar.
- [x] ~~Deaktiviran predmet / deaktiviran profesor i dalje ulaze u raspored~~ — preskaču se.
- [x] ~~Provjera konflikata/raspoloživosti samo za PRVOG predavača~~ (backlog iz 1. runde) —
      provjeravaju se svi profesori vezani za termin (npr. dva predavača na predmetu).
- [x] ~~Paralelne grupe: termin grupe vezan za SVE profesore predmeta~~ (drugi profesor je u
      "Moj raspored" vidio i tuđu grupu u istom terminu) — veže se samo profesor te grupe;
      grupa 2 poštuje i raspoloživost svog profesora.
- [x] ~~Poruka "Ispravno generisani svi rasporedi" iako fali po 8 predmeta~~ — poruka sada navodi
      neraspoređene predmete, u koliko od 6 rasporeda i zašto.

Rezultat na seed podacima: 21-22/30 predmeta po rasporedu, 0 prekršaja (prije: 22-23/30 uz 73-88
termina van raspoloživosti). Preostali neraspoređeni su realno nemogući sa seed raspoloživošću
(npr. asistent sa 8h vježbi u semestru ima 7h slobodno u okviru 8-17h) - admin ih vidi u poruci.

**Ostali bagovi pronađeni i popravljeni:**

- [x] `professor_api.php` `save_availability` je UVIJEK padao (PDO šalje PHP `false` kao `''` u
      boolean kolonu) - vezivanje termina za predmet nikad nije moglo da se sačuva. Dodata i
      validacija vremena i provjera da je predmet profesorov.
- [x] Profesor je mogao mijenjati sedmice kolokvijuma TUĐEG predmeta (`save_colloquium_weeks`).
- [x] Reset lozinke: neograničen broj pokušaja za 6-cifreni kod (sada 5), i verifikacija
      vlastitog koda je ostajala važeća kad se u istoj sesiji zatraži kod za DRUGI email -
      profesor je mogao promijeniti lozinku tuđeg naloga. Kod se generiše sa `random_int`.
- [x] Stored XSS: imena predmeta/profesora u tabeli rasporeda i kolokvijuma su išla u `innerHTML`
      bez escapovanja, a profesor sam mijenja svoje ime -> JS bi se izvršio u admin sesiji.
- [x] `?action=getschedule` je bio dostupan bez prijave.
- [x] "Moj raspored" je uzimao samo najveći zaključani `schedule_id`, a admin zaključava zimski
      i ljetnji iz različitih rasporeda - profesor je vidio pogrešan zimski raspored.
- [x] Zaključavanje: stari zaključani termini su ostajali zaključani poslije novog
      zaključavanja/otključavanja (sada jedno stanje, kao i `config.schedule_locked`).
- [x] Kolokvijumi: padali na neradne praznike (Nova godina, Veliki petak...) - te sedmice se
      preskaču; predmet bez vježbi (samo lab/predavanja) nije dobijao kolokvijume; šablon se
      birao proizvoljno kad postoji više zaključanih rasporeda; generisani kolokvijumi (i sami
      `locked_by_admin`) su držali raspored "zaključanim" i poslije otključavanja.
- [x] Dodavanje/aktiviranje akademske godine je ostavljalo DVIJE aktivne godine (Java i PHP
      čitaju `LIMIT 1` bez redoslijeda - nasumični datumi semestara/zauzetost).

**Nije mijenjano (napomene):**
- jsPDF-autotable u konzoli javlja da široka tabela za 2pt ne staje na stranu - PDF izgleda
  ispravno, kozmetika.
- Sat u raspoloživosti sa 15-min granulacijom: časovi počinju na pun sat, pa prozor 10:15-14:15
  daje časove 11-14.
- SMTP pristupni podaci su hardkodovani u PHP fajlovima (i u git istoriji) - preporuka: premjestiti
  u env varijable i rotirati lozinku.
- Nema CSRF zaštite na admin formama; prijava nema ograničenje broja pokušaja.

---

## Treća runda — odgovor na konkretnu primjedbu klijentkinje (email)

Nakon druge runde, profesorka je poslala konkretnu povratnu informaciju sa 4 primjedbe. Evo
statusa svake, i šta je urađeno po tom osnovu:

1. **"Poslije izmjene raspoloživosti, ništa se ne mijenja u rasporedu"** — glavni uzrok je bio
   weekday bug iz prve runde (već popravljen). Provjereno: algoritam i dalje čita samo DAN iz
   raspoloživosti, ne i VRIJEME (`start_time`/`end_time` se ne koriste u generisanju) - ostaje
   kao poznato ograničenje, nije dodatno rađeno ove runde.
2. **"Nastavnik nije u konačnom rasporedu"** — nije bilo moguće potvrditi bez konkretnog imena;
   najvjerovatnije objašnjenje je da je raspored zaključan PRIJE nego što je taj nastavnik dobio
   predmet, pa treba ponovo generisati i zaključati.
3. [x] ~~"Nastava 8-17h, dnevni fond ne smije preći 6 časova"~~ — **URAĐENO**: dodat tvrdi limit
   od 6 sati dnevno po godini/semestru (preko SVIH predmeta te godine kombinovano, ne po
   predmetu) u Java algoritmu. Prvi test je otkrio da pohlepni algoritam (bez povratka) bez
   ravnomjerne raspodjele po danima ima znatno veći procenat neuspješno raspoređenih predmeta
   pod ovim novim ograničenjem (u jednom poretku prioriteta palo je sa 24/30 na 15/30) — popravljeno
   dodavanjem sortiranja dana po trenutnoj popunjenosti (najmanje popunjen dan se prvi probava),
   čime se uspješnost vratila na normalan nivo (23/30) uz i dalje potpuno poštovanje limita.
   Potvrđeno na 6 svježih punih generisanja - nula prekoračenja.
4. [x] ~~"Ne može se provjeriti raspoređivanje po salama, čudna zauzetost FIT-a"~~ — **URAĐENO**:
   Java algoritam sada čita `room_occupancy` (ista tabela koju koristi "Zauzetost sala" stranica)
   i izbjegava sale koje su zauzete od strane DRUGIH fakulteta pri biranju termina. Usput je
   otkriven i uzrok "čudne zauzetosti FIT-a": u bazi postoji ~193 reda gdje je "FIT" označen kao
   zauzimalac skoro svake sale, skoro cijeli radni dan (8-17h), svih 5 dana - što izgleda kao
   test/probni podatak, ne stvarna tuđa rezervacija. Da se to tretira kao prava zauzetost, FIT-ov
   sopstveni algoritam ne bi imao gdje da generiše sopstveni raspored - zato su redovi sa
   `faculty_code = 'FIT'` namjerno isključeni iz provjere (samo TUĐI fakulteti realno blokiraju
   sale). Dodata i napomena o ovome direktno na "Zauzetost sala" stranici u adminu.

**Usput otkriven i popravljen značajan bag** (nije bio dio primjedbi, ali bez njega frontend
promjene iz ove runde ne bi ni bile vidljive): `academic_event.day` je varchar kolona u koju Java
upisuje IME dana ("ponedeljak"...). Admin (`getschedule`) i profesorski (`get_professor_schedule`)
PHP endpoint su oba radila `(int)$row['day']` da bi dobili broj dana (1-5) za iscrtavanje tabele -
što na imenu dana UVIJEK daje 0! Ovo je do sada bilo "slučajno maskirano" jer su STARIJI rasporedi
(generisani prije popravke weekday bug-a iz prve runde) imali `day` upisan kao cifra-string
("1".."5"), na čemu `(int)` cast ispravno radi. Čim je weekday bug popravljen (ispravno), svježe
generisani rasporedi su počeli da se prikazuju PRAZNI u oba panela (potvrđeno uživo u browseru -
raspored sa 127 termina se prikazivao kao prazna tabela). Popravljeno dodavanjem
`dayNameToWeekdayNum()` helper funkcije (mapira i imena dana i stare cifre-stringove u 1-5) na oba
mjesta.

### Frontend izmjene ove runde (da se promjene mogu vizuelno pokazati)

- Red "Dnevni fond" ispod svake sedmične tabele u admin prikazu generisanog rasporeda (po
  semestru), sa bojom (zeleno ≤6h, crveno >6h) - vizuelna potvrda da se limit poštuje.
- Napomena na "Zauzetost sala" stranici koja objašnjava FIT vs. tuđe zauzetosti (vidi tačku 4
  gore).
- Formular za izmjenu predmeta (admin.js) dopunjen poljima iz druge runde koja su ranije bila
  samo u formi za DODAVANJE, ne i za IZMJENU: `expected_students`, `parallel_groups`, `is_online`,
  `requires_computer_lab`.
- Formular za izmjenu sale (admin.js) dopunjen poljem `faculty_code` ("kome sala pripada").
- Profesorov "Moj raspored" kalendar sada prikazuje tip časa i ime predavača u naslovu događaja
  (ranije je backend ovo vraćao, ali JS to nije koristio - vidi napomenu iz druge runde).

**Napomena o testiranju:** ova runda je prvi put uključivala i stvarno vizuelno testiranje kroz
browser (ne samo API/CLI), što je i otkrilo gorenavedeni day-mapping bag - bez tog testiranja bag
bi ostao neotkriven jer sve API provjere (curl/CLI) prolaze ispravno, problem je bio isključivo u
tome kako se `day` broj koristi za iscrtavanje HTML tabele.

---

## Admin panel — Upravljanje profesorima

- [x] "Upravljanje Profesorima" → malo p — **FRONTEND** (samo tekst naslova), van dogovorenog obima. **[5. runda: urađeno]**
- [x] Preimenovati u "Upravljanje korisnicima" + prikaz uloge (admin/predavač/saradnik) uz ime — **[5. runda: uloga (Admin/Predavač/Saradnik) prikazana uz ime na listi profesora; preimenovanje/spajanje stranica ostaje odluka proizvoda]**
      **ODLUKA PROIZVODA**: ovo mijenja model podataka/UX, ne "bag". Vidi i stavku o razlici
      profesori/nalozi niže.
- [x] ~~Nema mogućnosti brisanja naloga; duga lista bez pretrage~~ — **URAĐENO**: dodata prava
      (trajna) brisanja za profesore ([admin_panel.php](public/views/admin_panel.php)), uz
      hvatanje FK grešaka ("Ne možete trajno obrisati... probajte deaktivirati") kad postoje
      povezani podaci. Dodata pretraga po imenu/emailu (`?page=profesori&q=...`).
- [x] "Deadline" → "Rok za unos kol." — **FRONTEND** (tekst labele), van obima. **[5. runda: urađeno - "Rok za unos kolokvijuma"]**

## Admin panel — Upravljanje predmetima

- [x] "Upravljanje Predmetima" → malo p — **FRONTEND**, van obima. **[5. runda: urađeno]**
- [x] ~~Ograničenje na 2 predavača (1 prof + 1 asistent) po predmetu~~ — **URAĐENO (glavni uzrok)**:
      `course_professor` veze nikad nisu bile ograničene u bazi/PHP-u — admin je uvijek mogao
      dodati 2-3 profesora. Pravi bag je bio u Java algoritmu za generisanje rasporeda
      ([EventValidationService.java](public/java/EventValidationService.java)):
      `findLectureProfessor`/`findExerciseProfessor` su uzimali samo PRVOG pronađenog profesora/
      asistenta, pa su dodatni predavači nestajali iz generisanog rasporeda. Popravljeno tako da
      se generisani termin (`saveToAcademicEvent`) sada poveže sa **svim** profesorima/
      asistentima dodijeljenim predmetu (`event_professor`), ne samo sa jednim. Testirano uživo:
      predmet sa 2 predavača → oba se pojave u generisanom terminu.
      **Preostalo (backlog, nije urađeno)**: sama provjera dostupnosti/konflikata i dalje se radi
      samo prema PRVOM predavaču/asistentu — ako drugi/treći predavač ima drugačiju
      raspoloživost, to se trenutno ne provjerava. Za potpuno rješenje treba proširiti
      `hasConflict`/`hasConflictForBlock` da provjeravaju SVE dodijeljene profesore, što je veća
      izmjena algoritma i traži pažljivije testiranje.
- [x] ~~Nije moguće izbrisati predmet, samo deaktivirati~~ — **URAĐENO**: dodato trajno brisanje
      predmeta (uz brisanje `course_professor` veza), sa zaštitom od brisanja ako postoje
      podaci vezani za raspored/kolokvijume.

## Admin panel — Upravljanje salama

- [x] "Upravljanje Salama" → malo s — **FRONTEND**, van obima. **[5. runda: urađeno]**
- [x] ~~Nije moguće izbrisati salu, samo deaktivirati~~ — **URAĐENO**: dodato trajno brisanje sale,
      sa zaštitom od brisanja ako postoje podaci vezani za nju (raspored, zauzetost).
- [x] ~~Kategorija "čija je sala" (FIT ima 101, 104, 109, P09; druge na zahtjev)~~ — **URAĐENO**:
      dodata kolona `room.faculty_code` (npr. "FIT"), formulari za dodavanje/izmjenu sale, i
      prikaz u tabeli salama ("Pripada": FIT / *Dijeljena / na zahtjev*).
      **Riješeno (treća runda)**: `admin.js` edit-forma za salu sada uključuje i polje
      `faculty_code`, popunjava se automatski pri kliku na "Uredi".

## Admin panel — Upravljanje nalozima

- [x] "Upravljanje Nalozima" → malo n — **FRONTEND**, van obima. **[5. runda: urađeno]**
- [x] "Šta je professor a šta user?" / razlika Upravljanje profesorima vs. Upravljanje nalozima — **[5. runda: objašnjenje dodato na stranicu naloga]**
      **ODLUKA PROIZVODA**, ne bag: `professor` je akademski profil (ime, email — koristi se u
      rasporedu), `user_account` su login kredencijali (opciono povezani sa profesorom preko
      `professor_id`). Ovo razdvajanje je namjerno u šemi baze, ali UI ga očigledno ne objašnjava
      dovoljno jasno. Predlažemo da se ovo razriješi zajedno sa profesorkom (npr. kratak opis u
      UI-ju) prije bilo kakve promjene modela podataka.
- [x] "Šta znači Povezani profesor pri kreiranju naloga?" — **FRONTEND** (dodati kratak opis/ **[5. runda: objašnjenje dodato na stranicu naloga]**
      tooltip pored polja), van dogovorenog obima ove sesije.
- [x] ~~Inicijalni password se ne šalje korisniku (niko nije dobio šifru)~~ — **URAĐENO**: kada
      admin kreira nalog i poveže ga sa profesorom koji ima email, sistem sada šalje email sa
      korisničkim imenom i lozinkom (PHPMailer, isti SMTP kao ostatak sistema). Ako slanje ne
      uspije, admin panel to eksplicitno javlja ("prenesite lozinku ručno") umjesto da tiho
      "uspije" bez emaila.
      **Napomena**: u ovom sandbox okruženju SMTP izlazna konekcija nije testirana do kraja
      (mrežna ograničenja), pa treba potvrditi slanje na produkciji/lokalno gdje SMTP izlaz radi.
- [x] ~~Nema brisanja naloga; lista nije sortirana po imenu; nema filtera~~ — **URAĐENO**: dodato
      trajno brisanje naloga (sa zaštitom od FK grešaka), lista je sada sortirana po imenu
      povezanog profesora (padne na username za admin nalog bez profesora), dodata pretraga po
      korisničkom imenu/imenu profesora.

## Zauzetost sala

- [x] ~~Da li su zauzetost sala i raspored povezani?~~ — **PRONAĐENO, NIJE POPRAVLJENO (backlog)**:
      trenutno NISU povezani ni u jednom smjeru. `room_occupancy` je čisto ručna admin tabela
      ([OccupancyService.php](src/services/OccupancyService.php)) i Java algoritam za
      generisanje rasporeda je nikad ne čita, pa generisani raspored može dodijeliti salu koja je
      već zauzeta od strane drugog fakulteta, i obrnuto — generisani termini se ne upisuju u
      `room_occupancy`. Ovo zahtijeva izmjenu Java algoritma (da isključi zauzete kombinacije
      sala/dan/vrijeme iz `getSuitableRooms`) — nije urađeno u ovoj sesiji jer dira srž algoritma
      za biranje sala i treba pažljivo testiranje da ne pokvari postojeće generisanje.

## Upravljanje kalendarom i događajima

- [x] "Upravljanje Kalendarom i Događajima" → malo k, malo d — **FRONTEND**, van obima. **[5. runda: stranica je "Informacije i događaji"; prikazuje i događaje profesora]**
- [x] ~~Događaji uneseni od strane korisnika (profesora) se ne prikazuju na admin panelu~~ —
      **VEĆ RADI** u trenutnom kodu: sekcija "Rezervisani termini profesora" na stranici
      Događaji već prikazuje raspoloživost koju su profesori unijeli. Izgleda da je ovo već
      riješeno u međuvremenu (nije urađeno u ovoj sesiji).
- [x] ~~Termini kolokvijuma postavljeni od strane profesora se ne prikazuju~~ — **VEĆ RADI**:
      sekcija "Predmeti i sedmice kolokvijuma" na istoj stranici već prikazuje sedmice
      kolokvijuma koje su profesori izabrali, po predmetu.

## Raspored (generisanje/prikaz)

- [x] Pogrešan naziv kategorije "Dobrodošli u Admin Panel" — **FRONTEND** (tekst), van obima. **[5. runda: urađeno - "Rasporedi"]**
- [x] Slova ć i č se ne prikazuju u PDF-u — **FRONTEND**: PDF se generiše na klijentu (jsPDF, **[5. runda: urađeno - DejaVuSans font, provjereno u generisanom PDF-u]**
      `public/assets/js/jspdf*.js`), ne na backendu — treba embed-ovati font sa ć/č glifovima u
      JS kodu. Van dogovorenog obima ove sesije (nije backend).
- [x] ~~"Fond časova" se ne unosi, pa se generišu razbijeni časovi (npr. 1+4 na dva dana)~~ —
      **URAĐENO (pravi uzrok pronađen i popravljen)**: polja `lectures_per_week` /
      `exercises_per_week` / `labs_per_week` su oduvijek postojala i unosila se ispravno — to
      nije bio problem. Pravi uzrok je otkriven empirijskim testiranjem: `generateScheduleWithYearPriority`
      (metoda koju stvarno pokreće dugme "Generiši raspored časova") raspoređuje predmete u do 3
      odvojene faze po kategoriji (ima lab časove / nema lab i nije onlajn / je onlajn), a te
      kategorije **nisu međusobno isključive** — predmet koji ISTOVREMENO ima lab časove I je
      onlajn (potvrđeno na stvarnom predmetu u bazi: "Mašinsko učenje", P=3 V=1 L=1, online=true)
      se obrađuje u DVIJE faze, svaka nezavisno raspoređuje pun set časova za taj predmet, na
      DRUGAČIJEM danu — rezultat su duplirani/razbacani časovi istog predmeta (npr. vježbe 1h
      utorkom I 1h četvrtkom umjesto 2h jednog dana, ponekad i predavanja duplirana 3h+3h na dva
      dana). Popravljeno dodavanjem provjere u `scheduleCourseForSchedule()`
      ([EventValidationService.java](public/java/EventValidationService.java)): ako je predmet
      već raspoređen u ovom rasporedu (bilo kojom ranijom fazom), preskače se. Potvrđeno uživo:
      prije popravke, 6 od 15 provjerenih generisanih rasporeda je imalo ovaj problem (uvijek isti
      predmet, "Mašinsko učenje"); poslije popravke, 6 novih punih generisanja (`generisiKompletan`)
      - nula fragmentacije.
- [x] ~~Ne postoji opcija generisanja rasporeda kolokvijuma/ispita~~ — **URAĐENO (frontend otkriveno
      na zahtjev)**: dugme "Generiši kolokvijume" i pripadajuća sekcija su ranije bili trajno
      sakriveni (`display:none !important`), iako je logika za automatski prikaz/sakrivanje sekcije
      (na osnovu toga da li generisani raspored sadrži kolokvijume) već postojala, samo zakomentarisana.
      Uklonjeno prisilno sakrivanje i vraćena postojeća logika (`renderScheduleData` sad opet poziva
      `renderColloquiums()` kad ima kolokvijuma). Backend je već ranije potvrđen kao ispravan
      (`generisiKolokvijume` uživo vraća "OK").
- [x] ~~Nedostaje tip časova (predavanje/vježbe) i ime predavača u rasporedu~~ —
      **ADMIN prikaz: VEĆ RADI** (`?action=getschedule` već vraća `type` i `professors`/
      `assistants`). **URAĐENO za profesorov "Moj raspored"**: `get_professor_schedule` u
      [professor_api.php](public/views/api/professor_api.php) sada vraća i `type`/`type_label` i
      ime predavača (`professor`), po uzoru na admin prikaz.
      **Riješeno (treća runda)**: naslov događaja u profesorovom kalendaru sada uključuje tip
      časa i ime predavača.
- [x] Admin nema mogućnost izmjene generisanog rasporeda (sale, paralelne grupe) — **[5. runda: urađeno - vidi "Peta runda"]**
      **POTVRĐENO KAO PRAVI NEDOSTATAK, NIJE IMPLEMENTIRANO (backlog)**: ne postoji nijedan
      endpoint koji dozvoljava izmjenu pojedinačnog generisanog termina (sala/vrijeme). Ovo
      zahtijeva novi API endpoint sa provjerom konflikata prilikom izmjene — nije urađeno u
      ovoj sesiji zbog obima (treba i minimalan UI da bude upotrebljivo), ostaje kao sljedeći
      korak.
- [x] ~~Prije generisanja unijeti dodatne uslove (rač. sala, broj studenata vs. kapacitet, ne
      zahtijeva salu/onlajn, dvije grupe istovremeno)~~ — **URAĐENO**: dodate kolone
      `course.requires_computer_lab`, `course.expected_students`, `course.parallel_groups` (šema
      + forma za dodavanje/izmjenu predmeta + prikaz u tabeli predmeta, kolona "Uslovi"). Java
      algoritam sada: (1) bira salu sa `is_computer_lab=true` za predmet kad je
      `requires_computer_lab` uključeno, nezavisno od `labs_per_week`; (2) koristi
      `expected_students` kao minimalni traženi kapacitet sale umjesto fiksnog broja; (3) za
      `is_online` predmete više NE dodjeljuje fizičku salu (`room_id` ostaje NULL, provjerava se
      samo dostupnost profesora) — ranije se onlajn predmetu i dalje dodjeljivala prava sala; (4)
      za `parallel_groups > 1`, nova metoda `scheduleParallelGroups()` postavlja dodatne grupe u
      ISTI dan/termin kao prva grupa, u drugoj slobodnoj sali i sa drugim dodijeljenim
      predavačem/asistentom (ako je dostupan preko `course_professor`) — ako druga sala ili
      profesor nisu slobodni, ta grupa se best-effort preskače bez rušenja ostatka generisanja.
      Sve testirano uživo na kopiranom test-predmetu (parallel_groups=2 + 2 predavača → dvije
      paralelne grupe u istom terminu, različite sale/predavači; is_online=true → `room_id` NULL
      u svim generisanim terminima; requires_computer_lab + expected_students=15 → predmet
      dosljedno dobija računarsku salu kapaciteta ≥15); test podaci potom vraćeni na
      početno stanje.

---

## Profesor panel

- [x] ~~Zaboravljena lozinka → "Došlo je do greške. Pokušajte ponovo."~~ — **URAĐENO (pravi uzrok
      pronađen i popravljen)**: `authorization.php` i `forgot_password.php` su zvali
      `../../src/api/password_reset.php`, putanju koja je VAN javnog `public/` docroot-a —
      potvrđeno uživo (404 Not Found), zbog čega je JS dobijao HTML 404 stranicu umjesto JSON-a i
      padao u generičku grešku. Dodat je `public/api/password_reset.php` (isti obrazac kao
      postojeći `public/api/schedule_lock.php`) i ispravljene obje putanje. Potvrđeno uživo:
      endpoint sada vraća ispravan JSON (200) umjesto 404.
- [x] ~~Kalendar: naziv praznika / oznaka neradnog dana~~ — **URAĐENO**: `holiday` tabela je već
      imala kolone `name` i `is_working_day`, ali `get_holidays` u profesorskom API-ju je vraćao
      samo `date`/`name`. Dopunjeno da vraća i `is_working_day` (bool).
      **Napomena (frontend, van obima)**: kalendar UI treba dopuniti da tu vrijednost i prikaže
      (npr. da oboji neradne dane).
- [x] ~~Greška pri slanju zahtjeva za kolokvijume (ne može da se sačuva)~~ — **NIJE
      REPRODUKOVANO / VEĆ RADI**: testirano uživo (`save_colloquium_weeks`), radi ispravno i
      vraća `success:true`. Moguće da je ovo već popravljeno ranije, ili se javljalo u specifičnoj
      okolnosti koja se u ovoj sesiji nije mogla reprodukovati.
- [x] ~~Raspoloživost: mogu se unijeti samo termini, nema dodatnih zahtjeva (rač. sala, izbor sale,
      vezivanje predmeta za dan)~~ — **DJELIMIČNO URAĐENO**: dodate kolone
      `professor_availability.requires_computer_lab`, `.preferred_room_id`, `.course_id` (šema +
      `save_availability` u [professor_api.php](public/views/api/professor_api.php) sada prima i
      čuva sve tri, ako ih frontend pošalje uz termin). Admin panel (Događaji →
      "Rezervisani termini profesora") sada prikazuje te zahtjeve uz svaki termin.
      **Vezivanje predmeta za dan JE integrisano u Java algoritam**: nova
      `getPreferredDaysForCourse(profesor, predmet)` - ako profesor ima termin raspoloživosti
      eksplicitno vezan za taj predmet (`course_id`), generisanje tog predmeta koristi ISKLJUČIVO
      te dane; inače se ponaša kao i do sad (opšta raspoloživost). Testirano uživo: profesor
      vezan za predmet samo za četvrtak → predmet se generisao baš u četvrtak.
      **Preostalo (backlog)**: `requires_computer_lab`/`preferred_room_id` PO TERMINU su
      sačuvani i vidljivi adminu, ali nisu još povezani sa biranjem sale u algoritmu (trenutni
      algoritam bira salu na nivou PREDMETA - vidi `course.requires_computer_lab` gore - a ne na
      nivou pojedinačnog termina raspoloživosti); dublje povezivanje bi tražilo veću izmjenu
      strukture pretrage termina i nije urađeno u ovoj sesiji.
      **Napomena (frontend, van obima)**: forma za raspoloživost u `professor_panel.php` trenutno
      šalje samo `day`/`from`/`to` po terminu — da bi profesor stvarno mogao UNIJETI ova tri nova
      polja, formu treba dopuniti (checkbox za rač. salu, dropdown za salu, dropdown za predmet).
- [x] ~~Moj raspored časova — ne prikazuje se ništa~~ — **URAĐENO**: `get_professor_schedule` je
      birao "najstariji od posljednjih 6" generisanih rasporeda (`array_reverse` pa `[0]`), umjesto
      trenutno važećeg. Popravljeno da bira **najnoviji zaključani (locked_by_admin) raspored**,
      a ako još ništa nije zaključano, pada nazad na najnoviji generisani — potvrđeno uživo
      (prije: pogrešan/stariji `schedule_id`, poslije: ispravan zaključani raspored).

---

## Usput pronađeno (nije iz docx-a, ali relevantno)

- `src/api/auth.php` je mrtav kod — nikad se ne poziva iz aplikacije (stvarna prijava je
  implementirana direktno u `authorization.php`). Nije dirano, samo napomena za buduće čišćenje.
- Java bug: `professor_availability.weekday` se čuva kao int (1-5), ali
  `EventValidationService.getPreferredDays()` ga je čitao kao ime dana (`rs.getString`), pa se
  nikad nije poklapalo sa "ponedeljak"/"utorak"/... — posljedica: svi generisani termini su
  padali na ponedjeljak (default fallback). **Popravljeno** (mapiranje int → ime dana) i
  potvrđeno uživo generisanjem termina koji sada padaju na različite dane (ponedeljak, utorak,
  srijeda), ne samo na ponedjeljak.

---

## Rezime

**Urađeno i testirano uživo, prva runda (11 stavki):**
1. Popravljen weekday bag u Java algoritmu (svi termini padali na ponedjeljak)
2. Generisani termini se sada vezuju za SVE dodijeljene predavače/asistente predmeta (ne samo prvog)
3. Popravljena slomljena ruta za reset lozinke (404 → radi)
4. Email sa inicijalnom lozinkom pri kreiranju naloga
5. Trajno brisanje profesora/predmeta/sala/naloga (uz zaštitu od brisanja povezanih podataka)
6. Pretraga na listama profesora/predmeta/sala/naloga
7. Sortiranje liste naloga po imenu (umjesto po username-u)
8. Kolona "čija je sala" (faculty_code) na salama
9. `get_holidays` vraća i oznaku neradnog dana
10. "Moj raspored" prikazuje ispravan (zaključani) raspored umjesto proizvoljnog starog
11. "Moj raspored" sada uključuje tip časa i ime predavača

**Urađeno i testirano uživo, druga runda (5 stavki, na zahtjev):**
12. Pravi uzrok fragmentacije "fonda časova" pronađen i popravljen (dupliranje termina kad
    predmet upadne u više od jedne faze generisanja - npr. ima labove I je onlajn)
13. Otkriveno dugme "Generiši kolokvijume" (uklonjen `display:none`, vraćena postojeća ali
    zakomentarisana logika prikaza)
14. Dodatni uslovi predmeta prije generisanja: `requires_computer_lab`, `expected_students`,
    `parallel_groups` - šema + forma + algoritam (bira rač. salu, poštuje kapacitet, onlajn
    predmeti ne dobijaju salu, paralelne grupe dobijaju drugu salu/predavača u istom terminu)
15. Raspoloživost profesora: dodatna polja `requires_computer_lab`/`preferred_room_id`/
    `course_id` po terminu (šema + API + prikaz adminu); vezivanje predmeta za konkretan dan je
    i integrisano u algoritam (`getPreferredDaysForCourse`)

**Već radilo (pronađeno, nije trebalo popravku): 3 stavke** — rezervisani termini profesora,
sedmice kolokvijuma na admin panelu, slanje zahtjeva za kolokvijume.

**Urađeno i testirano uživo (kroz browser), treća runda (na direktnu primjedbu klijentkinje):**
16. Dnevni limit od 6 sati po godini/semestru (preko svih predmeta te godine) u Java algoritmu,
    uz load-balancing raspodjelu po danima da limit ne obori procenat uspješnosti generisanja
17. Algoritam sada izbjegava sale zauzete od strane DRUGIH fakulteta (`room_occupancy`); FIT-ove
    sopstvene (test) unose namjerno ne tretira kao prepreku - vidi objašnjenje "čudne zauzetosti"
18. **Značajan bag pronađen i popravljen**: `(int)$row['day']` na varchar koloni sa imenom dana
    je uvijek davalo 0 - svježe generisani rasporedi su se prikazivali PRAZNI i admin panelu i
    profesorskom panelu (stariji rasporedi su "slučajno" radili jer im je `day` bio cifra-string
    iz perioda prije weekday-bug popravke). Popravljeno u oba endpointa.
19. Frontend: red "Dnevni fond" (sa bojom) ispod svake sedmične tabele u generisanom rasporedu
20. Frontend: napomena o FIT vs. tuđoj zauzetosti na "Zauzetost sala" stranici
21. Frontend: edit-forma za predmet dopunjena novim poljima iz 2. runde (ranije samo u "Dodaj")
22. Frontend: edit-forma za salu dopunjena poljem "kome pripada"
23. Frontend: profesorov kalendar sada prikazuje tip časa i ime predavača

**Već radilo (pronađeno, nije trebalo popravku): 3 stavke** — rezervisani termini profesora,
sedmice kolokvijuma na admin panelu, slanje zahtjeva za kolokvijume.

**Backend spreman, čeka frontend izmjenu (napomene):** kalendar UI treba dopuniti da prikaže
naziv praznika/neradnog dana (podatak već stiže), forma za raspoloživost treba dopuniti da
profesor uopšte MOŽE unijeti rač. salu/izbor sale/predmet po terminu (backend ih već prima i
čuva ako stignu, i vezivanje za predmet već utiče na algoritam čim se jednom unese).

**Napomena (4. runda):** puna provjera konflikata za sve predavače i poštovanje VREMENA iz
raspoloživosti su urađeni u četvrtoj rundi (vidi gore).

**Ostaje kao backlog (veći/rizičniji zahvati, nisu rađeni ovu sesiju):** puna provjera
konflikata za sve dodijeljene predavače (ne samo prvog), mogućnost izmjene generisanog rasporeda
od strane admina (sala/vrijeme pojedinačnog termina), povezivanje
`requires_computer_lab`/`preferred_room_id` PO TERMINU raspoloživosti sa algoritmom (trenutno se
ti zahtjevi čuvaju i vide, ali algoritam i dalje bira salu na nivou predmeta, ne pojedinačnog
termina), poštovanje KONKRETNOG vremena (ne samo dana) iz raspoloživosti profesora.

**Van obima (frontend/tekst/dizajn odluke):** sve stavke označene kao "malo p/s/k/d" (samo
veličina slova u naslovu), ć/č u PDF-u (klijentski jsPDF), preimenovanje kategorija, i pitanja
koja traže odluku profesorke o modelu podataka (profesor vs. nalog).
