
# InternFlow

[🇬🇧 Read in English](README.md)

**InternFlow** është një sistem web për menaxhimin e praktikave profesionale të studentëve, i zhvilluar në kuadër të punimit të diplomës bachelor.

Aplikacioni lidh studentët, koordinatorët akademikë, mbikëqyrësit e kompanive dhe administratorët në një platformë të përbashkët për menaxhimin dhe përcjelljen e praktikave profesionale, nga regjistrimi dhe miratimi fillestar deri te përfundimi i detyrave dhe vlerësimi përfundimtar.

## Teknologjitë e përdorura

- **Frontend:** React, Vite, JavaScript
- **Backend:** Laravel (PHP)
- **Baza e të dhënave:** PostgreSQL
- **Autentikimi:** Laravel Sanctum
- **Komunikimi në kohë reale:** Laravel Reverb dhe WebSockets

## Funksionalitetet kryesore

### Studenti
- Regjistron dhe dorëzon aplikimin për praktikë për miratim akademik.
- Shikon detajet e praktikës dhe përcjell statusin e aplikimit.
- Shikon detyrat e caktuara dhe dorëzon punën e përfunduar.
- Regjistron aktivitetet dhe orët e punës gjatë praktikës.
- Komunikon me koordinatorin akademik dhe mbikëqyrësin e kompanisë.
- Merr njoftime për ndryshimet dhe aktivitetet e rëndësishme.
- Shikon feedback-un dhe vlerësimin përfundimtar të praktikës.

### Koordinatori akademik
- Shqyrton aplikimet për praktikë dhe mund t'i miratojë, refuzojë ose të kërkojë korrigjime.
- Përcjell ecurinë e praktikave dhe aktivitetet e studentëve.
- Shqyrton informacionet dhe vlerësimet e praktikave.
- Zyrtarizon përfundimin e praktikës pasi të jenë plotësuar kushtet e kërkuara.

### Mbikëqyrësi i kompanisë
- Regjistrohet si mbikëqyrës dhe lidhet me një kompani ekzistuese ose regjistron një kompani të re.
- Përcjell studentët dhe praktikat që i mbikëqyr.
- Krijon dhe menaxhon detyrat e praktikës.
- Shqyrton dorëzimet e detyrave dhe jep feedback.
- Vlerëson performancën e studentit në përfundim të praktikës.

### Administratori
- Menaxhon përdoruesit, rolet, statuset e llogarive, koordinatorët dhe kompanitë.
- Ka qasje në të dhënat e praktikave dhe informacionet administrative.
- Shqyrton historikun e veprimeve dhe aktivitetet e rëndësishme në sistem.

### Funksionalitete të tjera
- Kontrolli i qasjes sipas roleve të përdoruesve.
- Komunikimi përmes mesazheve në kohë reale ndërmjet pjesëmarrësve të autorizuar.
- Njoftimet për aktivitetet dhe ndryshimet e rëndësishme.
- Verifikimi i kushteve për përfundimin e praktikës.
- Regjistrimi i historikut të veprimeve përmes auditimit.

## Struktura e projektit

Projekti është i organizuar në dy pjesë kryesore:

- `frontend/` — Aplikacioni React i zhvilluar me Vite.
- `backend/` — Aplikacioni Laravel i lidhur me PostgreSQL.

Frontend-i përfshin ndërfaqet dhe ndërveprimin me përdoruesit, ndërsa backend-i përpunon kërkesat, zbaton rregullat e sistemit, menaxhon autentikimin dhe komunikon me bazën e të dhënave.

## Instalimi dhe ekzekutimi në mjedis lokal

### Parakushtet

Para ekzekutimit të aplikacionit, duhet të jenë të instaluara:

- Node.js dhe npm
- PHP dhe Composer
- PostgreSQL

### Frontend

Hapni terminalin në direktoriumin e projektit dhe ekzekutoni:

```sh
cd frontend
npm install
npm run dev
```

Frontend-i ekzekutohet si parazgjedhje në adresën:

`http://localhost:5173`

### Backend

1. Hapni direktoriumin `backend/`.
2. Kopjoni skedarin `.env.example` në `.env`.
3. Konfiguroni lidhjen me PostgreSQL në skedarin `.env`.
4. Krijoni një bazë të të dhënave me emrin `internflow`.
5. Nëse nevojitet, konfiguroni të dhënat e administratorit fillestar.

Variablat përkatëse të konfigurimit janë:

```env
DB_CONNECTION=pgsql
DB_DATABASE=internflow
DB_USERNAME=your_postgres_username
DB_PASSWORD=your_postgres_password

ADMIN_EMAIL=your_admin_email
ADMIN_PASSWORD=your_admin_password
```

Administratori fillestar krijohet përmes seeder-it vetëm nëse janë përcaktuar të dyja vlerat `ADMIN_EMAIL` dhe `ADMIN_PASSWORD`.

Ekzekutoni komandat e mëposhtme nga direktoriumi kryesor i projektit:

```sh
cd backend
composer install
php artisan migrate
php artisan db:seed
php artisan serve
```

Backend-i ekzekutohet si parazgjedhje në adresën:

`http://localhost:8000`

**Shënim për sigurinë:** Skedarët lokalë `.env`, fjalëkalimet dhe të dhënat e tjera konfidenciale nuk duhet të publikohen në GitHub.

## Autentikimi dhe konfigurimi i sesioneve

InternFlow përdor Laravel Sanctum për autentikimin e aplikacionit SPA përmes cookies dhe sesioneve.

Variablat `FRONTEND_URL` dhe `SANCTUM_STATEFUL_DOMAINS` duhet të konfigurohen sipas adresës së frontend-it.

Adresat e paracaktuara gjatë zhvillimit lokal janë:

- Frontend: `http://localhost:5173`
- Backend: `http://localhost:8000`

Autentikimi përfshin kyçjen, çkyçjen, rikthimin e sesionit aktual, regjistrimin e studentëve dhe regjistrimin e mbikëqyrësve të kompanive.

## Testimi

### Frontend

Nga direktoriumi `frontend/` ekzekutoni:

```sh
npm run test
npm run lint
npm run build
```

Këto komanda përdoren për ekzekutimin e testeve, kontrollimin e kodit dhe krijimin e versionit të aplikacionit për përdorim në prodhim.

### Backend

Testet e backend-it përdorin një bazë të veçantë PostgreSQL me emrin `internflow_testing`.

Para ekzekutimit të testeve që kërkojnë migrime, duhet të krijohet kjo bazë dhe të konfigurohen të dhënat për lidhjen me PostgreSQL.

Testet e backend-it mund të ekzekutohen nga direktoriumi `backend/` me komandën:

```sh
php artisan test
```

## Dizajnimi i bazës së të dhënave

Në PostgreSQL janë përdorur kufizime për të ruajtur saktësinë e të dhënave dhe për të mbështetur procesin e praktikave.

Kufizimet `CHECK` përdoren për:

- Statuset e praktikave dhe të proceseve
- Prioritetet dhe progresin e detyrave
- Vendimet e feedback-ut
- Renditjen e datave të praktikës
- Numrat e versioneve të dorëzimeve
- Orët e aktiviteteve
- Pikët e vlerësimit

Në vend të tipeve `ENUM` të PostgreSQL janë përdorur kolona tekstuale me kufizime `CHECK`, në mënyrë që vlerat e lejuara të mund të ndryshohen më lehtë përmes migrimeve të ardhshme.

Kufizimet e çelësave të huaj mbrojnë të dhënat historike të praktikave nga fshirja e paqëllimshme. Në rastet përkatëse përdoret `SET NULL`, në mënyrë që historiku të ruhet edhe kur fshihen përdorues të lidhur me verifikimin ose auditimin.

## Përfundimi i praktikës

Për ta përfunduar zyrtarisht një praktikë, duhet të plotësohen këto kushte:

1. Studenti duhet t'i ketë dorëzuar të gjitha detyrat e kërkuara.
2. Mbikëqyrësi i kompanisë duhet t'i ketë miratuar dorëzimet e detyrave.
3. Mbikëqyrësi duhet të ketë dhënë vlerësimin përfundimtar të praktikës.
4. Duhet të ketë arritur data e planifikuar e mbarimit të praktikës.

Pasi të jenë plotësuar këto kushte, koordinatori akademik mund ta zyrtarizojë përfundimin e praktikës.

## Projekti akademik

InternFlow është zhvilluar në kuadër të punimit të diplomës bachelor në programin **Shkenca Kompjuterike dhe Inxhinieri në UBT**.

**Titulli i punimit:** Dizajnimi dhe implementimi i një sistemi për menaxhimin e praktikave profesionale të studentëve.

Projekti përfshin dizajnimin, implementimin dhe testimin e një sistemi web për menaxhimin e praktikave profesionale.
