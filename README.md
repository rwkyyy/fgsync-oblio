# FGSync for Oblio 
## Facturare Gestiune Sincronizare pentru Oblio 

[![Versiune WordPress.org](https://img.shields.io/wordpress/plugin/v/fgsync-oblio.svg?logo=wordpress&logoColor=white&label=wp.org)](https://ro.wordpress.org/plugins/fgsync-oblio/)
[![Testat până la](https://img.shields.io/wordpress/plugin/tested/fgsync-oblio.svg?label=testat%20p%C3%A2n%C4%83%20la)](https://ro.wordpress.org/plugins/fgsync-oblio/)
[![Descărcări](https://img.shields.io/wordpress/plugin/dt/fgsync-oblio.svg?label=desc%C4%83rc%C4%83ri)](https://ro.wordpress.org/plugins/fgsync-oblio/)
[![Rating](https://img.shields.io/wordpress/plugin/rating/fgsync-oblio.svg?label=rating)](https://ro.wordpress.org/plugins/fgsync-oblio/#reviews)
[![CI](https://github.com/rwkyyy/oblio-fgwoo/actions/workflows/ci.yml/badge.svg)](https://github.com/rwkyyy/oblio-fgwoo/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4?logo=php&logoColor=white)](composer.json)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.2%2B-7f54b3?logo=woocommerce&logoColor=white)](https://ro.wordpress.org/plugins/fgsync-oblio/)
[![HPOS](https://img.shields.io/badge/HPOS-compatibil-4c1)](fgsync-oblio.php)
[![Licență](https://img.shields.io/badge/licen%C8%9B%C4%83-GPL--2.0--or--later-blue)](LICENSE)

Integrare independentă WooCommerce cu [Oblio.eu](https://www.oblio.eu): emitere automată de facturi,
proforme, avize și storno, procesare pe cozi (queue) și sincronizare stoc pe mai multe gestiuni.

Plugin open-source independent, nedezvoltat, neaprobat și nesusținut de Oblio.eu. Oblio este un
serviciu terț, este necesar un cont Oblio activ.

Acesta este repository-ul de dezvoltare. Pluginul este publicat pe WordPress.org la
**[ro.wordpress.org/plugins/fgsync-oblio](https://ro.wordpress.org/plugins/fgsync-oblio/)** - acolo găsiți
versiunea stabilă de instalat. Descrierea completă și changelog-ul publicate pe WordPress.org se află în
[`readme.txt`](readme.txt).

## Funcționalități

* Facturi, proforme, avize și storno - automat pe status de comandă sau manual din ecranul comenzii.
* Procesare pe coadă (Queue / Action Scheduler): emiterea nu blochează niciodată checkout-ul/site-ul/ecranul operatorului.
* Sincronizare stoc în loturi, pe una sau mai multe gestiuni, după SKU.
* Încasare automată ("marcat ca plătit"), configurabilă pe metodă de plată.
* Compatibil HPOS (High-Performance Order Storage) și stocare clasică a comenzilor.
* Compatibil WPML / WooCommerce Multilingual.
* Compatibilitate Bundles (limitat)
* Compatibilitate OSS

Lista completă e în [`readme.txt`](readme.txt), secțiunea `== Description ==`.

## Diferențe față de pluginul original

FGSync este o **reconstrucție independentă**, nu un fork; Codul a fost scris de la zero, folosind
pluginul vechi doar ca referință de funcționalitate.

Motivul principal al reconstrucției a fost **performanța și extensibilitatea**. Pluginul vechi rulează sincron, într-o singură execuție PHP, mai
multe operații care pot lua mult timp pe magazine medii/mari: sincronizarea de stoc (vezi secțiunea Stoc),
emiterea în masă a facturilor din lista de comenzi și chiar încărcarea paginii de Setări. La emiterea automată
a unei facturi (la finalizarea comenzii sau la schimbarea statusului), pluginul vechi poate bloca cererea
clientului sau a operatorului **până la ~60 de secunde** (lacăt de fișier cu așteptare activă, până la 30s,
plus timeout-ul de 30s al cererii către API-ul Oblio), fără reîncercare automată la eșec.

FGSync rulează aceleași operații pe coadă (queue / action scheduler) sau din cache, și adaugă 23 de filtre/hook-uri
proprii pentru cazuri speciale (vezi secțiunea Extensibilitate). Codul e integral rescris pe o bază modernă -
PHP 8.1+ cu tipare stricte (`strict_types`) și namespace-uri în toate cele peste 70 de fișiere din `src/`,
acoperit de o suită de teste automate (PHPUnit) - față de codul vechi, care nu declară o versiune minimă de
PHP și nu folosește tipare stricte. Tabelele de mai jos compară,
funcționalitate cu funcționalitate, FGSync cu pluginul original „WooCommerce Oblio".

**Legendă**: 

✅ funcționează

⚠️ parțial sau nefuncțional

❌ lipsește

`-` nu se aplică. 

**Numele îngroșat marchează o funcționalitate de bază**; un rând care începe cu `↳` descrie o îmbunătățire adăugată de
FGSync peste funcționalitatea de bază listată chiar deasupra lui, nu o funcționalitate separată.

### Facturare (documente)

| Funcționalitate | Integrarea Oblio.eu                                                                                              | FGSync |
|---|------------------------------------------------------------------------------------------------------------------|---|
| **Facturi, emitere automată sau manuală** | ✅                                                                                                                | ✅ |
| ↳ Emitere pe coadă (Action Scheduler), nu mai blochează comanda | `-`                                                                                                              | ✅ |
| ↳ Reîncercare automată cu backoff la eșec | `-`                                                                                                              | ✅ |
| **Proforme** | ✅ (blocate pentru plata cu cardul)                                                                               | ✅ |
| **Ștergere document** | ✅ posibilă doar pentru ultimul document din serie: Oblio refuză ștergerea oricărui alt document, afișat constant | ✅ |
| ↳ Butonul de ștergere e ascuns când documentul nu e ultimul din serie, în loc să apară eroarea Oblio după click | `-`                                                                                                              | ✅ |
| **Storno (credit note), integral și parțial** | ❌                                                                                                                | ✅ automat la rambursarea comenzii |
| **Aviz (notă de livrare)** | ⚠️ codul există, dar nu e funcțional                                                                             | ✅ |
| **Reconciliere pentru documente omise** | ❌                                                                                                                | ✅ job recurent |

### Stoc

| Funcționalitate | Integrarea Oblio.eu | FGSync |
|---|---|---|
| **Procesare a sincronizării de stoc** | ⚠️ o singură execuție PHP citește toate paginile din Oblio și aplică toate modificările; risc de timeout sau epuizare memorie la cataloage mari | ✅ |
| ↳ Pe pagini de 250 de produse, fiecare pagină o sarcină de coadă separată, cu progres salvat între ele | `-` | ✅ |
| ↳ O interogare unică pentru SKU-urile din pagina curentă (până la 500 → 1 interogare per pagină de 250 produse), nu una separată pentru fiecare produs primit de la Oblio | `-` | ✅ |
| ↳ Scrie doar produsele al căror stoc sau preț chiar s-a schimbat, nu pe toate cele primite de la Oblio | `-` | ✅ |
| **Sincronizare stoc programată** | ✅ cron fix, la fiecare oră | ✅ |
| ↳ Interval configurabil (orar, la 6h, la 12h, zilnic) | `-` | ✅ |
| **Sincronizare pe o gestiune** | ✅ | ✅ |
| ↳ Agregare pe mai multe gestiuni deodată | `-` | ✅ |
| **Sincronizare stoc manuală** | ✅ sincronă, blochează pagina | ✅ |
| ↳ Rulează pe fundal, cu bară de progres | `-` | ✅ |
| **Rezervare stoc pentru comenzi neonorate** | ✅ fereastră fixă de 30 de zile | ✅ |
| ↳ Fereastră și statusuri de comandă configurabile | `-` | ✅ |
| **Actualizare preț din Oblio** | ✅ | ✅ |
| ↳ Nu suprascrie prețul când moneda nu se potrivește | `-` | ✅ |

### Fiabilitate și performanță

| Funcționalitate | Integrarea Oblio.eu                    | FGSync |
|---|----------------------------------------|---|
| **Blocaj la emitere concurentă** | ✅ un lacăt global, la nivel de fișier, cu așteptare activă până la 30s | ✅ |
| ↳ Lacăt per comandă, cu expirare automată dacă rămâne blocat | `-`                                    | ✅ |
| **Limitare rată API Oblio** | ⚠️ pauză fixă de 0,5s, doar la paginarea sincronizării de stoc - nicio limitare la emiterea documentelor | ✅ la fiecare emitere (facturi, proforme, avize, storno) |
| ↳ Reprogramare automată în loc de așteptare fixă | `-`                                    | ✅ |
| **Încasare automată ("marcat ca plătit")** | ✅ comutator general cu risc de omitere | ✅ |
| ↳ Configurabilă per metodă de plată, cu excepții | `-`                                    | ✅ |
| **Încărcare pagină Setări (serii, gestiuni)** | ⚠️ până la 4 cereri către API-ul Oblio, la fiecare încărcare, cu pauze fixe totalizând 1,5s | ✅ |
| ↳ Date din cache (până la o săptămână), reîmprospătate doar la cerere sau la schimbarea CIF | `-`                                    | ✅ |

### Extensibilitate

| Funcționalitate | Integrarea Oblio.eu | FGSync |
|---|---|---|
| **Filtre și hook-uri proprii pentru cazuri speciale** | ⚠️ 2 (payload-ul facturii și rezultatul emiterii) | ✅ 23, acoperind facturare, stoc, rezervări, reconciliere, storno și email |

### Compatibilitate

| Funcționalitate | Integrarea Oblio.eu | FGSync |
|---|---|---|
| **High-Performance Order Storage (HPOS)** | ⚠️ parțială | ✅ completă |
| **WPML / WooCommerce Multilingual** | ❌ | ✅ |
| **WooCommerce Bundles** | ❌ | ✅ |
| **Facturare OSS (EUR pentru clienți din afara României)** | ❌ | ✅ opțională |
| **Câmpuri CIF/RC din alte pluginuri de checkout** | ✅ | ✅ |

### Comenzi și interfață admin

| Funcționalitate | Integrarea Oblio.eu | FGSync |
|---|---|---|
| **Coloană cu statusul Oblio în lista de comenzi** | ✅ | ✅ |
| ↳ Indicator de eroare, cu motivul eșecului afișat | `-` | ✅ |
| **Acțiuni în masă: emitere factură** | ⚠️ execuție sincronă, într-o singură cerere HTTP: la multe comenzi selectate deodată, risc de timeout | ✅ |
| ↳ Fiecare comandă selectată e pusă în coadă, nu procesată sincron în cererea admin | `-` | ✅ |
| ↳ Emitere proformă și storno în masă | `-` | ✅ |
| **Filtrare listă de comenzi după statusul Oblio** | ❌ | ✅ |
| **Vizibilitate a motivului de eșec la emitere** | ❌ | ✅ pe ecranul comenzii și în lista de comenzi |

### Automatizări externe

| Funcționalitate | Integrarea Oblio.eu | FGSync |
|---|---|---|
| **Webhook-uri Oblio (confirmare plată cu cardul)** | ✅ | ⚠️ eliminat temporar, revine într-o versiune viitoare |
| **Integrare WooCommerce Returns → storno** | ❌ funcția nu exista în WooCommerce | ✅ experimentală, dezactivată implicit |

### Securitate și mentenanță

| Funcționalitate | Integrarea Oblio.eu | FGSync |
|---|---|---|
| **Stocare cheie API** | ✅ text simplu | ✅ criptată (AES-256-GCM) |
| **Jurnal de activitate** | ✅ fișier JSON brut, fără interfață | ✅ jurnal WooCommerce, cu panou de stare dedicat |
| **Indicator de conexiune în bara de admin** | ❌ | ✅ |
| **Mecanism de actualizare** | ✅ updater propriu, în afara WordPress.org | ✅ standard WordPress.org (SVN) |
| **Import de date din pluginul vechi** | ❌ | ✅ unidirecțional |
| **Pagină „Ajutor"** | ✅ | ❌ plănuit: wiki pe GitHub și secțiune FAQ pe pagina din WordPress.org |
| **Bază de cod** | ⚠️ fără versiune minimă de PHP declarată, cod procedural, fără tipare stricte, fără teste automate | ✅ PHP 8.1+, tipare stricte (`strict_types`) și namespace-uri în tot codul, testat cu PHPUnit |

## Cerințe

* PHP 8.1+
* WordPress 6.5+
* WooCommerce 8.2+

## Dezvoltare

```bash
composer install
composer phpcs      # coding standards
composer phpstan    # analiză statică
composer test       # PHPUnit
```

## Testare / CI

La fiecare push sau pull request pe `main`/`develop`, [GitHub Actions](.github/workflows/ci.yml) rulează,
pe PHP 8.1, 8.2 și 8.3:

* lint PHP pe toate fișierele;
* PHPCS (WordPress Coding Standards) și PHPStan;
* suita PHPUnit.

Release-urile (tag-uri) sunt publicate automat pe SVN-ul WordPress.org

## Suport și contribuții

Probleme și sugestii: [issue tracker-ul de pe GitHub](https://github.com/rwkyyy/oblio-fgwoo/issues) sau
forumul de suport de pe [pagina pluginului](https://ro.wordpress.org/plugins/fgsync-oblio/). Suport
community/best-effort, fără SLA garantat.

## Licență

GPL-2.0-or-later. Vezi [LICENSE](LICENSE).

## Mențiuni
Mulțumiri speciale lui Sorin D. și Aurelian M. pentru răbdarea, ideile și asistența în testarea acestui proiect.
