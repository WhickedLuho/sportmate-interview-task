# Beadási összefoglaló – GitHub repository-szinkronizáció

## Ráfordított idő

A feladatra hozzávetőlegesen **8 óra aktív munkát** fordítottam. Ebbe beletartozott a struktúra megtervezése, a megvalósítás és továbbfejlesztés, a kód átnézése, a tesztelés, a javítási lehetőségek átbeszélése és a folytatható, oldalankénti lekérés implementálása is.

## Elkészült alapfunkciók

- Friss Laravel alkalmazás a hivatalos Vue starter kitre építve, Inertia.js és Vue használatával.
- GitHub-felhasználó vagy szervezet hozzáadása és helyi mentése. A név normalizálása és formai validációja megtörténik; ugyanaz a felhasználó ugyanazt a célpontot nem veheti fel kétszer.
- Elkülönített GitHub-kliens, külső adatokat feldolgozó DTO-k, szinkronizációs szolgáltatás és Laravel queue job.
- A repository-adatok helyi SQLite-adatbázisban tárolódnak. A lista saját adatbázis-lekérdezéseket használ.
- Új repository-k létrehozása és a meglévők frissítése upserttel. Az egyediséget célpontonként az adatbázis `(sync_target_id, external_id)` korlátozása is biztosítja.
- Migrationök, idegen kulcsok és kaszkádolt törlési kapcsolatok.
- Célpontlista, célpont hozzáadása, szinkronizáció indítása, állapotok, utolsó sikeres szinkronizáció és felhasználónak szánt hibaüzenetek megjelenítése.
- Repository-lista névvel, leírással, URL-lel, programozási nyelvvel, csillagszámmal, nyitott issue-számmal, archivált állapottal és GitHub szerinti frissítési idővel.
- Keresés teljes repository-névben és leírásban; célpont- és nyelvszűrés; név, csillagszám, issue-szám és frissítési idő szerinti rendezés; oldalanként 25 találat.
- Elkészült unit- és integrációs tesztek a specifikáció három alapforgatókönyvéhez: célpont létrehozása, szinkronizáció fake GitHub-válasszal, duplikációk elkerülése. A GitHubot érintő automatizált tesztek fake/mock HTTP-válaszokat használnak.
- A telepítési és működési leírás a `README.md` fájlban, az AI-eszközök használatának részletes bemutatása az `AI_USAGE.md` fájlban található.

## Az alapfeladaton túl elkészült részek

- **Felhasználónként elkülönített adatok:** minden felhasználó a saját célpontjait és repository-jait látja. Más felhasználó célpontjának kezelését a policy 404 válasszal tiltja.
- **Folytatható lapozás:** egy job-végrehajtás egy GitHub-oldalt kér le, legfeljebb 100 repository-val. A mentett oldal és a következő oldal sorszáma közös tranzakcióban kerül az adatbázisba; több oldal esetén a job visszakerül a queue-ba.
- **Stop/Resume:** a várakozó vagy rate limit miatt szünetelő szinkronizáció megállítható, majd a már mentett oldalak után folytatható.
- **Régi jobok elleni védelem:** külön szinkronizációs futásazonosító és dispatch-azonosító védi az újabb folyamatot az elavult joboktól és hibavisszahívásoktól. A párhuzamos végrehajtást célpontonkénti overlap lock is korlátozza.
- **Atomi indítás:** a célpont lefoglalása és a database queue-bejegyzés ugyanazon adatbázis-kapcsolat közös tranzakciójában készül el.
- **Hibakezelés:** külön kezelés van a nem létező célpontra, az átmeneti GitHub-hibákra, a rate limitre és a váratlan válaszokra. A felhasználó biztonságos üzenetet kap, a technikai részletek naplóba kerülnek.
- **Rate limit és retry:** a visszajelzett várakozási időhöz 5 másodperces ráhagyás tartozik. A job eredeti, 2 órás retry-határideje az oldalankénti folytatáskor is megmarad; az átmeneti kivételekhez 30 másodperces backoff és 3-as `maxExceptions` tartozik.
- **Ütemezés:** óránként futó parancs választja ki a frissítésre esedékes, nem aktív célpontokat, az utolsó próbálkozástól számított 55 perces küszöbbel.
- **Hiányzó queue-bejegyzések helyreállítása:** az ötpercenként futó recovery parancs a legalább öt perce változatlan, aktív állapotú, megfelelő queue-bejegyzés nélküli célpontokat hibás, folytatható állapotba teszi.
- **Eltűnt repository-k kezelése:** egy teljes szinkronizáció végén a nem látott rekordok visszafordítható `missing_at` jelölést kapnak. Alapértelmezetten rejtettek, külön kapcsolóval megjeleníthetők; visszatéréskor a jelölés törlődik.
- **Állapotfrissítés:** a célpontoldal aktív szinkronizáció mellett négy másodpercenként frissíti az állapotadatokat.
- **Fejlesztői környezet és ellenőrzések:** Docker Compose webalkalmazással, külön workerrel, schedulerrel és helyi Adminerrel; PHPUnit, Pint, PHPStan, frontend lint/format és Vue TypeScript ellenőrzési parancsok; GitHub Actions ellenőrzési workflow.

## Fontosabb döntések és kompromisszumok

### Integráció és adatmodell

A GitHub HTTP-kommunikáció, a válaszok validálása és adatmodellre alakítása, az adatbázisírás, a queue-viselkedés és a webes vezérlés külön felelősségek. A célpont felvétele nem indít hálózati ellenőrzést: a GitHubon való létezés az első szinkronizációnál derül ki.

A célpont típusa a repository-válaszok tulajdonostípusából származik; repository nélküli, korábban nem ismert célpontnál ismeretlen maradhat. Az `open_issues_count` a GitHub által szolgáltatott érték, amely a nyitott pull requesteket is magában foglalja.

Azonos GitHub-repository külön célpontok alatt külön helyi rekordként tárolható. Ez egyszerűsíti a felhasználónkénti elkülönítést, cserébe az azonos accountot követő felhasználók lekérései és adatai nincsenek közösítve.

### Tranzakciók és részleges eredmények

A hálózati hívás nem tart nyitva adatbázis-tranzakciót. Az egyes oldalak mentése és a kurzor előreléptetése atomi. Egy későbbi oldal hibája esetén a már mentett oldalak megmaradnak és böngészhetők; a korábbi sikeres szinkronizáció időpontja nem változik. A megjelenített adatok így egy folyamatban lévő frissítés alatt különböző időpontokból származhatnak.

A GitHub oldalankénti listája nem pillanatfelvétel. Egy hosszú vagy megszakítás után folytatott futás során repository-k hozzáadása, átnevezése vagy eltűnése eltolhatja az oldalak határait. A hiányjelölés ezért visszafordítható, és nem tekinthető önálló törlési bizonyítéknak.

A lekérés legfeljebb 100 oldalt támogat. Ha további oldalra lenne szükség, a futás hibás állapotba kerül, megőrzi a már mentett adatokat, és nem végez végső hiányjelölést. Változatlan konfiguráció mellett az oldalhatárt elért futás Resume művelete is ugyanebbe a korlátba ütközik.

### Queue és üzemeltetés

A szinkronizáció kifejezetten database queue-t és a célpontokkal közös adatbázis-kapcsolatot igényel. A job timeoutja 60 másodperc, az overlap lock élettartama 75 másodperc, a queue `retry_after` értéke alapértelmezetten 90 másodperc.

A Stop a várakozó dispatch érvénytelenítését jelenti. Nem szakítja meg a már futó HTTP-kérést, nem törli a régi queue-payloadot, és nem kapcsolja ki az ütemezett frissítést. A régi job ébredéskor kihagyja a munkát; a scheduler később ismét folytathatja az esedékes célpontot.

A recovery nem helyettesíti a worker monitorozását: ha a worker áll, de a megfelelő queue-bejegyzések megvannak, a célpontok várakoznak tovább.

A már `failed` állapotú célpont folytatását az alkalmazás Resume műveletével vagy az esedékes célpontok ütemezésével kell indítani. A Laravel `queue:retry` parancsa önmagában nem állítja vissza a célpont aktív állapotát, ezért az újra queue-ba tett régi job kihagyhatja a szinkronizációt. Az alkalmazásban kezelt végleges GitHub-hibák nem feltétlenül hoznak létre `failed_jobs` rekordot.

### Indexek, keresés és cache

Az egyediségi indexek a duplikációkat akadályozzák meg. További célponttal kezdődő összetett indexek támogatják a csillagszám, frissítési idő és nyelv szerinti lekérdezéseket, valamint a futásonként látott repository-k kezelését. Több célpont összesített rendezésénél ezek nem feltétlenül váltják ki az adatbázis rendezési munkáját.

Az ütemező `last_attempted_at` alapján választ, miközben a meglévő `(status, last_synced_at)` index nem pontosan ezt a lekérdezést célozza. További idő esetén a tényleges lekérdezési terv alapján vizsgálnám felül az indexeket.

A keresés `LIKE '%kifejezés%'` alapú; nincs full-text kereső. A `%` és `_` karakterek jelenleg SQL-helyettesítő karakterek. A pontosan `0` keresőkifejezést a feltételes query-építés jelenleg figyelmen kívül hagyja. A célpontnév normalizálása tömbként küldött bemenetnél validációs visszajelzés helyett hibát okozhat; ez további bemeneti robusztussági feladat.

A repository-listához és a szűrőopciókhoz nincs eredménycache. A queue- és overlap-védelem ugyanakkor használ cache-t. Egy később bevezetett listacache-nél felhasználónkénti kulcsokat és az oldalanként látható változásokhoz illeszkedő invalidálást terveznék.

## Befejezetlen és további időre hagyott részek

- Külön operációs rendszerfolyamatban futó workerrel végzett automatizált timeout-, retry-kimerülési és összeomlási tesztek; a tervezett esetek skipped tesztvázakban is szerepelnek.
- Szinkronizációs futások tartós története, részletes metrikák és üzemeltetési riasztások.
- Ütemezett indítások időbeli elosztása és további API-kvóta-optimalizálás.
- A hiányzóként jelölt repository-k külön megerősítő ellenőrzése.
- A keresési és bemeneti peremesetek javítása, az indexek mérés alapján történő felülvizsgálata.
- Célpont törlése a felületről, REST/mobile API és README-tartalomban történő keresés. Ezek nem részei a működő alapfolyamatnak; a bemásolt specifikáció nem írja elő mindegyiket.
- Éles telepítés, éles email-küldés és teljes üzemeltetési konfiguráció. A helyi email-ellenőrzéshez szükséges üzenet a logba kerül.

## Milyen irányban folytatnám, és miért?

1. **A hibás bemenetek, keresési peremesetek és a failed jobok folytatási útjának pontosítása.** Ezek kis terjedelmű változásokkal javítanák a kiszámítható működést.
2. **Worker-folyamattal végzett hibaforgatókönyv-tesztek és monitorozás.** A háttérben futó integráció üzemeltetési megbízhatósága fontosabb lenne új felületi funkcióknál.
3. **Szinkronizációs előzmények és megerősített hiánykezelés.** Ezek segítenék a hibák visszakövetését és csökkentenék a változó lapozásból eredő téves következtetéseket.
4. **Ütemezés és lekérdezések optimalizálása mérés alapján.** Nagyobb adatmennyiségnél az API-kvóta, a queue várakozási ideje és a tényleges SQL-lekérdezési tervek alapján választanék további fejlesztést.

## Ellenőrzés és AI-használat

Az `AI_USAGE.md` korábbi fejlesztési ellenőrzésként **197 sikeres, 11 kihagyott tesztet és 1 524 assertiont** rögzít. A 2026. október 5-i átvizsgálás során a teljes PHPUnit suite-ot újra lefuttattuk a Dockerben, ugyanezzel az eredménnyel. A 11 kihagyott eset szándékos, névvel és indoklással ellátott tervezett teszt.

A Pint mind a 101 PHP-fájlt megfelelőnek találta, a PHPStan nem jelzett hibát. A Dockerben a frontend lint/format, a Vue TypeScript ellenőrzés és a production build is sikeres volt; a meglévő diff whitespace-ellenőrzése szintén sikeres. A build opcionális font fallback optimalizálás hiányára figyelmeztetett, de sikeresen befejeződött. Az ellenőrzés elején a Docker nem volt elérhető, és a Windows alatti frontend lint környezeti hibával megállt; a Docker elindítása után a fenti ellenőrzéseket sikeresen megismételtük a projekt konténerében.

A bemeneti típushibát, a `0` keresés kihagyását és a `failed` állapotú régi payload kihagyását külön, kizárólag memóriabeli SQLite-on végzett diagnosztikai futás is megerősítette. A fejlesztői adatbázist nem használtuk ehhez. Friss böngészős ellenőrzés nem történt.

Az AI-használatot és a jelentős mértékben AI-val készített részeket az `AI_USAGE.md` részletezi. Ez a beadási összefoglaló is Codex segítségével, a jelenlegi kód és dokumentáció átnézése, valamint a saját időráfordításom megadása alapján készült.
